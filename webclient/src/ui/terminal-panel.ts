import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import type { SessionConfig, SessionEvent, SessionState, UiCommand } from '../core/contracts';
import { disconnectIndependentWorker } from './advanced-worker-lifecycle';
import { htl, tl, tn } from '../i18n';
import { ModifierLatches, XKEY_PAGES, charToKey, type Mod, type PhysKey, type XKey } from '../input/extra-keys';
import { SoftKeyboardInput, sinkAttributes, type SoftKeyAction } from '../input/soft-keyboard';
import { escapeHtml, iconHtml } from './common';
import { isRtcEvent, webRtcFor, type WebRtcHost } from './webrtc-host';

/** A device that logs in but never answers the terminal open is treated as unable to. */
export const TERMINAL_OPEN_TIMEOUT_MS = 20_000;
const utf8 = new TextEncoder();
const MOBILE_TERMINAL_QUERY = '(max-width: 640px), (pointer: coarse)';
const REMOTE_SCROLL_INTERVAL_MS = 40;
const TOUCH_SCROLL_MULTIPLIER = 2;
const REMOTE_SCROLL_MAX_LINES = 5;
const terminalKey = (id: string): XKey => XKEY_PAGES[0]!.keys.find((candidate) => candidate.id === id)!;
const characterKey = (character: string): XKey => {
  const physical = charToKey(character)!;
  return { id: character, label: character, key: physical.key, mods: physical.shift ? ['shift'] : [] };
};
const TERMINAL_KEYS: readonly XKey[] = [
  terminalKey('esc'), characterKey('/'), characterKey('|'), terminalKey('home'), terminalKey('end'), terminalKey('up'), characterKey('\\'),
  terminalKey('tab'), { id: 'interrupt', label: 'Ctrl+C', key: { code: 'KeyC', key: 'c' }, mods: ['ctrl'] }, characterKey('~'), terminalKey('left'), terminalKey('down'), terminalKey('right'), terminalKey('bksp'),
  terminalKey('ctrl'), terminalKey('shift'), terminalKey('alt'), characterKey('^'), terminalKey('pgup'), terminalKey('pgdn'), terminalKey('enter'),
];
const DOM_KEY_CODES: Record<string, number> = {
  Escape: 27, Tab: 9, Enter: 13, Backspace: 8, Space: 32,
  Home: 36, End: 35, ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40,
  PageUp: 33, PageDown: 34, Insert: 45, Delete: 46,
  Semicolon: 186, Equal: 187, Comma: 188, Minus: 189, Period: 190, Slash: 191,
  Backquote: 192, BracketLeft: 219, Backslash: 220, BracketRight: 221, Quote: 222,
};
const SHIFTED_CHARACTERS = new Map<string, string>();
for (let codePoint = 32; codePoint < 127; codePoint++) {
  const character = String.fromCharCode(codePoint);
  const physical = charToKey(character);
  if (physical?.shift) SHIFTED_CHARACTERS.set(physical.key.code, character);
}

class TerminalC1ByteNormalizer {
  private utf8ContinuationBytes = 0;

  reset(): void {
    this.utf8ContinuationBytes = 0;
  }

  push(data: Uint8Array): Uint8Array {
    const normalized: number[] = [];
    for (const byte of data) {
      if (this.utf8ContinuationBytes > 0) {
        if (byte >= 0x80 && byte <= 0xbf) {
          normalized.push(byte);
          this.utf8ContinuationBytes -= 1;
          continue;
        }
        this.utf8ContinuationBytes = 0;
      }
      if (byte >= 0xc2 && byte <= 0xdf) this.utf8ContinuationBytes = 1;
      else if (byte >= 0xe0 && byte <= 0xef) this.utf8ContinuationBytes = 2;
      else if (byte >= 0xf0 && byte <= 0xf4) this.utf8ContinuationBytes = 3;
      if (byte >= 0x80 && byte <= 0x9f) normalized.push(0xc2, byte);
      else normalized.push(byte);
    }
    return Uint8Array.from(normalized);
  }
}

function pageMessages(): Record<string, string> | undefined {
  return (globalThis as { __RD__?: { messages?: Record<string, string> } }).__RD__?.messages;
}

/**
 * Page mode: the terminal is the whole page and logs in on its own (password,
 * 2FA). The page owns the connect card; these hooks keep it in step.
 */
export type TerminalPageHooks = {
  /** Keep the terminal running after disconnect (read at connect time). */
  persistent(): boolean;
  forceRelay(): boolean;
  onProgress(state: SessionState, detail?: string): void;
  /** Logged in; the terminal is opening. */
  onLive(): void;
  onTwoFactor(wrong: boolean): void;
  /** fromAddressBook: the console's credential for this connection only, never to be saved. */
  onCredentials(hashHex: string, fromAddressBook?: boolean): void;
  /** The device refused the login. The connection is closed. */
  onLoginError(message: string): void;
  /** The connection ended, with the reason; byUser for the Disconnect button. */
  onEnded(message: string, byUser: boolean): void;
};

type TerminalPanelDeps = {
  root: HTMLElement;
  workerUrl: string;
  getConfig(): SessionConfig | null;
  toast(message: string): void;
  page?: TerminalPageHooks;
};

export class TerminalPanel {
  private worker: Worker | undefined;
  private rtc: WebRtcHost | undefined;
  private shell: HTMLElement | undefined;
  private output: HTMLElement | undefined;
  private terminal: Terminal | undefined;
  private fitAddon: FitAddon | undefined;
  private status: HTMLElement | undefined;
  private persistent: HTMLInputElement | undefined;
  private forceRelay: HTMLInputElement | undefined;
  private shortcuts: HTMLElement | undefined;
  private keyboardInputAllowed = false;
  private softInput: SoftKeyboardInput | undefined;
  private keyboardViewport: VisualViewport | undefined;
  private keyboardBaseHeight = 0;
  private keyboardObserved = false;
  private readonly keyboardResize = (): void => this.observeKeyboardViewport();
  private touchScroll: { identifier: number; x: number; y: number; lineHeight: number } | undefined;
  private scrollFrame: number | undefined;
  private scrollDelta = 0;
  private scrollRemainder = 0;
  private lastRemoteScroll: number | undefined;
  private readonly modifiers = new ModifierLatches();
  private generatingKey = false;
  private pasting = false;
  private inputRevision = 0;
  private actualTransport: 'relay' | 'webrtc' | 'webrtc-turn' | undefined;
  private byteNormalizer = new TerminalC1ByteNormalizer();
  private terminalId = 0;
  private opened = false;
  private resizeObserver: ResizeObserver | undefined;
  private lastRows = 24;
  private lastCols = 80;
  private serviceStorageKey = '';
  private persistentWanted = false;
  private peerUnsupported = false;
  private openTimer: ReturnType<typeof setTimeout> | undefined;

  constructor(private readonly deps: TerminalPanelDeps) {}

  open(): void {
    if (this.shell) {
      this.focusTerminal();
      return;
    }
    const page = !!this.deps.page;
    const shell = document.createElement('section');
    shell.className = page ? 'rd-terminal-page' : 'rd-terminal-modal';
    if (!page) {
      shell.setAttribute('role', 'dialog');
      shell.setAttribute('aria-modal', 'true');
    }
    shell.setAttribute('aria-label', tl('remote_terminal'));
    const close = page
      ? `<button type="button" class="rd-terminal-disconnect" data-terminal-close>${htl('disconnect')}</button>`
      : `<button type="button" data-terminal-close aria-label="${htl('close_remote_terminal')}">×</button>`;
    // Page mode asks for persistence on the connect card, before login.
    const consent = page ? '' : `
        <div class="rd-terminal-consent">
          <div class="rd-terminal-options">
            <label><input type="checkbox" data-terminal-persistent> ${htl('keep_terminal_available_after_disconnect')}</label>
            <label><input type="checkbox" data-terminal-force-relay checked> ${htl('force_relay_connection')}</label>
          </div>
          <button type="button" data-terminal-connect>${htl('connect_terminal')}</button>
        </div>`;
    shell.innerHTML = `
      <div class="rd-terminal-card">
        <header class="rd-terminal-head">
          <div><strong data-terminal-title>${htl('remote_terminal')}</strong><span data-terminal-status>${htl('not_connected')}</span></div>
          ${close}
        </header>${consent}
        <div class="rd-terminal-output" aria-label="${htl('remote_terminal_output')}"></div>
      </div>`;
    this.deps.root.appendChild(shell);
    this.shell = shell;
    this.output = shell.querySelector<HTMLElement>('.rd-terminal-output')!;
    this.status = shell.querySelector<HTMLElement>('[data-terminal-status]')!;
    this.persistent = shell.querySelector<HTMLInputElement>('[data-terminal-persistent]') ?? undefined;
    this.forceRelay = shell.querySelector<HTMLInputElement>('[data-terminal-force-relay]') ?? undefined;
    this.terminal = new Terminal({
      cursorBlink: false,
      disableStdin: true,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
      fontSize: 14,
      lineHeight: 1.2,
      scrollback: 5000,
      overviewRuler: { width: 4 },
      theme: { background: '#080b0f', foreground: '#d9e3ee', cursor: '#d9e3ee', overviewRulerBorder: '#00000000' },
    });
    this.fitAddon = new FitAddon();
    this.terminal.loadAddon(this.fitAddon);
    this.terminal.open(this.output);
    this.terminal.textarea?.setAttribute('aria-label', tl('remote_terminal'));
    this.wireSoftInput();
    this.terminal.textarea?.addEventListener('blur', () => {
      if (this.isMobile() && this.keyboardInputAllowed) this.lockKeyboard();
    });
    shell.addEventListener('pointerdown', (event) => {
      if (this.isMobile() && this.keyboardInputAllowed) event.preventDefault();
    }, { capture: true });
    for (const type of ['pointerdown', 'mousedown', 'click'] as const) {
      this.output.addEventListener(type, (event) => event.stopPropagation());
    }
    this.wireScrolling();
    this.terminal.onData((data) => this.sendInput(data));
    this.terminal.textarea?.addEventListener('paste', () => {
      this.pasting = true;
      queueMicrotask(() => {
        this.pasting = false;
        if (this.keyboardInputAllowed && !this.softInput?.isComposing) this.softInput?.reset();
      });
    }, { capture: true });
    this.terminal.onBinary((data) => {
      if (this.opened) this.sendBytes(Uint8Array.from(data, (char) => char.charCodeAt(0)));
    });
    this.terminal.attachCustomKeyEventHandler((event) => {
      if (this.generatingKey) return event.type === 'keydown';
      if (event.code === 'KeyC' && !event.altKey && ((event.ctrlKey && event.shiftKey) || event.metaKey)) {
        event.preventDefault();
        if (event.type === 'keydown' && !event.repeat) this.copySelection();
        return false;
      }
      if (event.ctrlKey && event.shiftKey && event.code === 'KeyV') return false;
      if (event.metaKey && event.code === 'KeyV') return false;
      if (event.type === 'keydown' && this.modifiers.active().length && event.code && !event.isComposing && !['Control', 'Shift', 'Alt', 'Meta', 'CapsLock', 'Dead', 'Process', 'Unidentified'].includes(event.key)) {
        event.preventDefault();
        const held: Mod[] = [];
        if (event.ctrlKey) held.push('ctrl');
        if (event.altKey) held.push('alt');
        if (event.shiftKey) held.push('shift');
        if (event.metaKey) held.push('meta');
        this.pressKey({ code: event.code, key: event.key }, held);
        return false;
      }
      return true;
    });
    this.terminal.onResize(({ rows, cols }) => {
      this.lastRows = rows;
      this.lastCols = cols;
      if (this.opened) {
        this.worker?.postMessage({ c: 'terminalResize', terminalId: this.terminalId, rows, cols } satisfies UiCommand);
      }
    });
    this.createShortcuts();
    this.resize();

    shell.querySelector<HTMLButtonElement>('[data-terminal-close]')!.addEventListener('click', () => {
      if (page) this.disconnect();
      else this.destroy();
    });
    shell.querySelector<HTMLButtonElement>('[data-terminal-connect]')?.addEventListener('click', () => this.connect());
    if (typeof ResizeObserver !== 'undefined') {
      this.resizeObserver = new ResizeObserver(() => this.resize());
      this.resizeObserver.observe(this.output);
    }
  }

  /** Header title; the page shows the device id here. */
  setTitle(text: string): void {
    const title = this.shell?.querySelector<HTMLElement>('[data-terminal-title]');
    if (title) title.textContent = text;
  }

  get connected(): boolean {
    return !!this.worker;
  }

  connect(): void {
    if (this.worker) return;
    const config = this.deps.getConfig();
    if (!config) {
      this.deps.toast(tl('connect_device_before_opening_terminal'));
      return;
    }
    const forceRelay = this.deps.page ? this.deps.page.forceRelay() : (this.forceRelay?.checked ?? false);
    this.serviceStorageKey = `rd:terminal-service:${config.peerId}`;
    const persistent = this.deps.page ? this.deps.page.persistent() : (this.persistent?.checked ?? false);
    this.persistentWanted = persistent;
    this.peerUnsupported = false;
    let serviceId = '';
    if (persistent) {
      try { serviceId = localStorage.getItem(this.serviceStorageKey) ?? ''; } catch { /* non-fatal */ }
    }
    const terminalConfig: SessionConfig = {
      ...config,
      connType: 'terminal',
      terminalPersistent: persistent,
      terminalServiceId: serviceId,
      messages: config.messages ?? pageMessages(),
      webrtc: forceRelay ? undefined : config.webrtc,
    };
    this.actualTransport = undefined;
    this.setInputEnabled(false);
    this.setStatus(tl('connecting_2'));
    this.byteNormalizer.reset();
    this.terminal?.reset();
    this.resize();
    this.worker = new Worker(this.deps.workerUrl, { type: 'module' });
    this.setConnectionControlsVisible(false);
    const rtc = webRtcFor(this.worker);
    this.rtc = rtc;
    this.worker.onmessage = (event: MessageEvent<SessionEvent>) => {
      if (!isRtcEvent(rtc, event.data)) this.onEvent(event.data);
    };
    this.worker.onerror = () => this.fail(tl('terminal_worker_failed'));
    this.worker.postMessage({ c: 'connectTerminal', config: terminalConfig } satisfies UiCommand);
  }

  /** Answer the device's 2FA prompt (page mode). */
  submitTwoFactor(code: string): void {
    this.worker?.postMessage({ c: 'auth2fa', code } satisfies UiCommand);
  }

  /** Close the terminal and the connection, keeping the page (page mode). */
  disconnect(): void {
    if (!this.worker) return;
    this.stopWorker(true);
    this.opened = false;
    this.setInputEnabled(false);
    this.setStatus(tl('terminal_disconnected'));
    this.deps.page?.onEnded(tl('terminal_disconnected'), true);
  }

  private onEvent(event: SessionEvent): void {
    const page = this.deps.page;
    switch (event.t) {
      case 'state':
        if (event.state === 'streaming') {
          // RustDesk before 1.4.1 has no terminal: it takes the connection as
          // a desktop session and reports no terminal feature.
          if (this.peerUnsupported) {
            this.fail(tl('terminal_not_supported'));
            break;
          }
          this.setStatus(tl('opening_terminal'));
          page?.onLive();
          this.worker?.postMessage({
            c: 'terminalOpen', terminalId: 0, rows: this.lastRows, cols: this.lastCols,
          } satisfies UiCommand);
          clearTimeout(this.openTimer);
          this.openTimer = setTimeout(() => {
            if (this.worker && !this.opened) this.fail(tl('remote_terminal_could_not_be'));
          }, TERMINAL_OPEN_TIMEOUT_MS);
        } else if (event.state === 'error' || event.state === 'closed') {
          this.fail(event.detail || (event.state === 'closed' ? tl('terminal_disconnected') : tl('terminal_connection_failed')));
        } else {
          this.setStatus(event.detail || event.state);
          page?.onProgress(event.state, event.detail);
        }
        break;
      case 'peerInfo':
        this.peerUnsupported = !event.terminalSupported;
        break;
      case 'credentials':
        page?.onCredentials(event.hashHex, event.fromAddressBook);
        break;
      case 'twoFactor':
        if (page) page.onTwoFactor(event.wrong);
        else this.fail(tl('terminal_connection_failed'));
        break;
      case 'msgbox': {
        const text = event.title && event.text ? `${event.title}: ${event.text}` : event.text || event.title;
        if (page && text) this.deps.toast(text);
        break;
      }
      case 'loginError':
        if (page) {
          this.stop(event.message);
          page.onLoginError(event.message);
        } else {
          this.fail(event.message);
        }
        break;
      case 'terminalOpened':
        if (!event.success) {
          this.fail(event.message || tl('remote_terminal_could_not_be'));
          break;
        }
        clearTimeout(this.openTimer);
        this.terminalId = event.terminalId;
        this.opened = true;
        this.setInputEnabled(true);
        this.setConnectedStatus(event.replayTerminalOutput);
        if (event.serviceId && this.persistentWanted) {
          try { localStorage.setItem(this.serviceStorageKey, event.serviceId); } catch { /* non-fatal */ }
        }
        if (event.persistentSessions.length) {
          this.appendText(`\n${tn('terminal_extra_sessions', event.persistentSessions.length)}\n`);
        }
        this.resize();
        this.worker?.postMessage({
          c: 'terminalResize', terminalId: this.terminalId, rows: this.lastRows, cols: this.lastCols,
        } satisfies UiCommand);
        this.focusTerminal();
        break;
      case 'transport':
        this.actualTransport = event.kind;
        if (this.opened) this.setConnectedStatus();
        break;
      case 'terminalData':
        this.terminal?.write(this.byteNormalizer.push(event.data));
        break;
      case 'terminalClosed': {
        const message = tl('terminal_exited', { code: event.exitCode });
        this.stop(message);
        page?.onEnded(message, false);
        break;
      }
      case 'terminalError':
        this.fail(event.message);
        break;
    }
  }

  private sendBytes(data: Uint8Array): void {
    this.worker?.postMessage({ c: 'terminalData', terminalId: this.terminalId, data } satisfies UiCommand, [data.buffer]);
  }

  private sendInput(data: string): void {
    if (!this.opened) return;
    this.inputRevision++;
    if (this.generatingKey || this.pasting || !this.modifiers.active().length || /[\x00-\x1f\x7f]/.test(data)) {
      this.sendBytes(utf8.encode(data));
      return;
    }
    for (const character of data) {
      const physical = charToKey(character);
      if (physical) this.pressKey(physical.key, physical.shift ? ['shift'] : []);
      else this.sendBytes(utf8.encode(character));
    }
  }

  private createShortcuts(): void {
    const shortcuts = document.createElement('div');
    shortcuts.className = 'rd-terminal-keys';
    shortcuts.setAttribute('role', 'toolbar');
    shortcuts.setAttribute('aria-label', tl('extra_keys'));
    shortcuts.hidden = true;
    const keyboard = `<button type="button" class="rd-terminal-key" data-terminal-keyboard aria-label="${htl('on_screen_keyboard')}" title="${htl('on_screen_keyboard')}" disabled>${iconHtml('keyboard')}</button>`;
    shortcuts.innerHTML = TERMINAL_KEYS.map((key, index) =>
      `${index === 7 ? keyboard : ''}<button type="button" class="rd-terminal-key${key.id === 'interrupt' || key.id === 'enter' ? ' rd-terminal-key-wide' : ''}" data-terminal-key="${index}"${'mod' in key ? ` data-terminal-mod="${key.mod}" aria-pressed="false"` : ''} title="${escapeHtml('key' in key ? key.key.key : key.label)}" disabled>${escapeHtml(key.label)}</button>`,
    ).join('');
    shortcuts.addEventListener('pointerdown', (event) => {
      event.preventDefault();
      event.stopPropagation();
    });
    for (const type of ['touchstart', 'touchend'] as const) {
      shortcuts.addEventListener(type, (event) => event.stopPropagation(), { passive: true });
    }
    shortcuts.addEventListener('contextmenu', (event) => event.preventDefault());
    shortcuts.addEventListener('click', (event) => {
      event.stopPropagation();
      const keyboard = (event.target as Element).closest<HTMLButtonElement>('[data-terminal-keyboard]');
      if (keyboard && !keyboard.disabled && this.opened) {
        this.toggleKeyboard();
        return;
      }
      const button = (event.target as Element).closest<HTMLButtonElement>('[data-terminal-key]');
      if (!button || button.disabled || !this.opened) return;
      const key = TERMINAL_KEYS[Number(button.dataset.terminalKey)];
      if (!key) return;
      if ('mod' in key) {
        if (this.modifiers.get(key.mod) === 'off') this.modifiers.lock(key.mod);
        else this.modifiers.tap(key.mod, Date.now());
        this.syncModifiers();
      } else if (key.id === 'interrupt') {
        this.terminal?.input('\x03');
        this.softInput?.reseed();
      } else if ('key' in key) {
        this.pressKey(key.key, key.mods);
      }
    });
    this.shell?.querySelector('.rd-terminal-card')?.appendChild(shortcuts);
    this.shortcuts = shortcuts;
  }

  private isMobile(): boolean {
    return typeof matchMedia === 'function' && matchMedia(MOBILE_TERMINAL_QUERY).matches;
  }

  private wireScrolling(): void {
    const output = this.output;
    if (!output) return;
    output.addEventListener('wheel', (event) => event.stopPropagation(), { passive: true });
    output.addEventListener('touchstart', (event) => {
      this.stopTouchScroll();
      const touch = event.touches?.length === 1 ? event.touches[0] : undefined;
      const screen = this.terminal?.element?.querySelector<HTMLElement>('.xterm-screen');
      const measuredHeight = screen?.clientHeight ? screen.clientHeight / (this.terminal?.rows || this.lastRows) : 0;
      const lineHeight = measuredHeight || (this.terminal?.options.fontSize || 14) * (this.terminal?.options.lineHeight || 1.2);
      this.touchScroll = touch ? { identifier: touch.identifier, x: touch.clientX, y: touch.clientY, lineHeight } : undefined;
      if (touch) event.stopPropagation();
    }, { passive: true });
    output.addEventListener('touchmove', (event) => {
      const touch = event.touches?.length === 1 ? event.touches[0] : undefined;
      if (!touch || !this.touchScroll || touch.identifier !== this.touchScroll.identifier) {
        this.stopTouchScroll();
        return;
      }
      const deltaY = this.touchScroll.y - touch.clientY;
      this.touchScroll.x = touch.clientX;
      this.touchScroll.y = touch.clientY;
      event.stopPropagation();
      if (!deltaY) return;
      if (event.cancelable) event.preventDefault();
      this.scrollDelta += deltaY;
      this.queueTouchScroll();
    }, { passive: false });
    for (const type of ['touchend', 'touchcancel'] as const) {
      output.addEventListener(type, (event) => {
        if (this.touchScroll) event.stopPropagation();
        this.stopTouchScroll(type === 'touchend');
      }, { passive: true });
    }
  }

  private queueTouchScroll(): void {
    if (this.scrollFrame !== undefined) return;
    this.scrollFrame = requestAnimationFrame(() => {
      this.scrollFrame = undefined;
      this.flushTouchScroll();
    });
  }

  private flushTouchScroll(force = false): void {
    const terminal = this.terminal;
    const touch = this.touchScroll;
    if (!terminal || !touch || !this.scrollDelta) return;
    const local = terminal.buffer.active.type === 'normal' && terminal.modes.mouseTrackingMode === 'none';
    const now = performance.now();
    if (!local && !force && this.lastRemoteScroll !== undefined && now - this.lastRemoteScroll < REMOTE_SCROLL_INTERVAL_MS) {
      this.queueTouchScroll();
      return;
    }
    const pixels = this.scrollDelta * TOUCH_SCROLL_MULTIPLIER + this.scrollRemainder;
    this.scrollDelta = 0;
    const step = touch.lineHeight * (local ? 1 : 2);
    const lines = Math.trunc(pixels / step);
    this.scrollRemainder = pixels % step;
    if (!lines) return;
    if (local) {
      terminal.scrollLines(lines);
    } else {
      this.lastRemoteScroll = now;
      const target = terminal.element?.querySelector('.xterm-screen') ?? terminal.element;
      target?.dispatchEvent(new WheelEvent('wheel', {
        deltaY: Math.max(-REMOTE_SCROLL_MAX_LINES, Math.min(REMOTE_SCROLL_MAX_LINES, lines)), deltaMode: 1,
        clientX: touch.x, clientY: touch.y, bubbles: true, cancelable: true,
      }));
    }
  }

  private stopTouchScroll(flush = false): void {
    if (this.scrollFrame !== undefined) cancelAnimationFrame(this.scrollFrame);
    this.scrollFrame = undefined;
    if (flush) this.flushTouchScroll(true);
    this.touchScroll = undefined;
    this.scrollDelta = 0;
    this.scrollRemainder = 0;
    this.lastRemoteScroll = undefined;
  }

  private focusTerminal(): void {
    if (!this.isMobile()) this.terminal?.focus();
  }

  private updateKeyboardInput(): void {
    const textarea = this.terminal?.textarea;
    if (!textarea) return;
    const allowed = !this.isMobile() || this.keyboardInputAllowed;
    textarea.readOnly = !allowed || !this.opened;
    textarea.inputMode = allowed && this.opened ? 'text' : 'none';
  }

  private toggleKeyboard(): void {
    if (!this.opened) return;
    if (this.keyboardInputAllowed) {
      this.lockKeyboard();
      this.terminal?.textarea?.blur();
      return;
    }
    this.terminal?.textarea?.blur();
    this.keyboardBaseHeight = Math.max(this.keyboardBaseHeight, this.keyboardViewport?.height || window.innerHeight);
    this.keyboardObserved = false;
    this.keyboardInputAllowed = true;
    this.updateKeyboardInput();
    this.terminal?.focus();
    this.softInput?.reset();
  }

  private lockKeyboard(): void {
    this.keyboardInputAllowed = false;
    this.keyboardObserved = false;
    this.softInput?.reset();
    this.updateKeyboardInput();
  }

  private observeKeyboardViewport(): void {
    const height = this.keyboardViewport?.height || window.innerHeight;
    if (!this.isMobile() || !this.keyboardInputAllowed) {
      this.keyboardBaseHeight = height;
      this.keyboardObserved = false;
      return;
    }
    const threshold = Math.max(80, this.keyboardBaseHeight * 0.15);
    if (height < this.keyboardBaseHeight - threshold) this.keyboardObserved = true;
    else if (this.keyboardObserved) this.lockKeyboard();
  }

  private wireSoftInput(): void {
    const textarea = this.terminal?.textarea;
    const output = this.output;
    if (!textarea || !output) return;
    for (const [name, value] of Object.entries(sinkAttributes(true))) textarea.setAttribute(name, value);
    this.softInput = new SoftKeyboardInput(textarea, (action) => this.sendSoftInput(action));
    this.updateKeyboardInput();
    this.keyboardViewport = window.visualViewport ?? undefined;
    this.keyboardBaseHeight = this.keyboardViewport?.height || window.innerHeight;
    this.keyboardViewport?.addEventListener('resize', this.keyboardResize);
    window.addEventListener('resize', this.keyboardResize);
    const active = (event: Event): boolean => this.opened && this.isMobile() && this.keyboardInputAllowed && !this.generatingKey && event.target === textarea;
    for (const type of ['keydown', 'keyup', 'keypress'] as const) {
      output.addEventListener(type, (event) => {
        if (!active(event)) return;
        const localCopy = event.code === 'KeyC' && (event.metaKey || (event.ctrlKey && event.shiftKey));
        const localPaste = event.code === 'KeyV' && (event.ctrlKey || event.metaKey);
        if (localCopy || localPaste) return;
        event.stopPropagation();
        if (type === 'keydown') this.softInput?.keydown(event);
        else if (type === 'keyup') this.softInput?.keyup(event);
      }, true);
    }
    output.addEventListener('beforeinput', (event) => {
      if (!active(event)) return;
      event.stopPropagation();
      this.softInput?.beforeinput(event as InputEvent);
    }, true);
    output.addEventListener('input', (event) => {
      if (!active(event)) return;
      event.stopPropagation();
      this.softInput?.input();
    }, true);
    for (const type of ['compositionstart', 'compositionupdate', 'compositionend'] as const) {
      output.addEventListener(type, (event) => {
        if (!active(event)) return;
        event.stopPropagation();
        if (type === 'compositionstart') this.softInput?.compositionstart();
        else if (type === 'compositionend') this.softInput?.compositionend();
      }, true);
    }
  }

  private sendSoftInput(action: SoftKeyAction): void {
    if (action.k === 'text') this.sendInput(action.text);
    else if (action.k === 'key') this.pressKey({ code: action.key, key: action.key }, action.ctrl ? ['ctrl'] : []);
    else if (action.down) {
      const event = action.event;
      const held: Mod[] = [];
      if (event.ctrlKey) held.push('ctrl');
      if (event.altKey) held.push('alt');
      if (event.shiftKey) held.push('shift');
      if (event.metaKey) held.push('meta');
      this.pressKey({ code: event.code, key: event.key }, held);
    }
  }

  private pressKey(physical: PhysKey, additional: readonly Mod[] = []): void {
    const terminal = this.terminal;
    const textarea = terminal?.textarea;
    if (!this.opened || !terminal || !textarea) return;
    const held = new Set([...this.modifiers.active(), ...additional]);
    const key = held.has('shift') ? (SHIFTED_CHARACTERS.get(physical.code) ?? physical.key) : physical.key;
    const keyCode = DOM_KEY_CODES[physical.code]
      ?? (/^Key[A-Z]$/.test(physical.code) ? physical.code.charCodeAt(3) : /^Digit[0-9]$/.test(physical.code) ? physical.code.charCodeAt(5) : 0);
    const init = { key, code: physical.code, keyCode, which: keyCode, bubbles: true, cancelable: true, ctrlKey: held.has('ctrl'), altKey: held.has('alt'), shiftKey: held.has('shift'), metaKey: held.has('meta') };
    const before = this.inputRevision;
    const originalOption = terminal.options.macOptionIsMeta;
    this.generatingKey = true;
    try {
      if (held.has('alt')) terminal.options.macOptionIsMeta = true;
      textarea.dispatchEvent(new KeyboardEvent('keydown', init));
      if (before === this.inputRevision && key.length === 1 && !held.has('ctrl') && !held.has('alt') && !held.has('meta')) terminal.input(key);
      textarea.dispatchEvent(new KeyboardEvent('keyup', init));
      if (physical.code === 'Enter' || (physical.code === 'KeyC' && held.has('ctrl'))) this.softInput?.reseed();
    } finally {
      terminal.options.macOptionIsMeta = originalOption;
      this.generatingKey = false;
    }
  }

  private syncModifiers(): void {
    for (const button of this.shortcuts?.querySelectorAll<HTMLButtonElement>('[data-terminal-mod]') ?? []) {
      button.setAttribute('aria-pressed', String(this.modifiers.get(button.dataset.terminalMod as Mod) !== 'off'));
    }
  }

  private copySelection(): void {
    const text = this.terminal?.getSelection();
    if (!text) return;
    const fallback = (): void => {
      try {
        if (document.execCommand('copy')) return;
      } catch {}
      this.deps.toast(tl('clipboard_unavailable_permission_denied'));
    };
    if (typeof navigator.clipboard?.writeText === 'function') {
      void navigator.clipboard.writeText(text).catch(fallback);
    } else {
      fallback();
    }
  }

  private resize(): void {
    if (!this.output?.clientWidth || !this.output.clientHeight) return;
    this.updateKeyboardInput();
    this.fitAddon?.fit();
  }

  private appendText(text: string): void {
    this.terminal?.write(text.replace(/\r?\n/g, '\r\n'));
  }

  private setStatus(message: string): void {
    if (this.status) this.status.textContent = message;
  }

  private setConnectedStatus(replaying = false): void {
    const message = replaying ? tl('connected_replaying_buffered_output') : tl('connected');
    const labels = { relay: 'transport_relay', webrtc: 'transport_webrtc', 'webrtc-turn': 'transport_webrtc_turn' };
    this.setStatus(this.actualTransport ? `${message} (${tl(labels[this.actualTransport])})` : message);
  }

  private setInputEnabled(enabled: boolean): void {
    if (this.shortcuts) {
      this.shortcuts.hidden = !enabled;
      for (const button of this.shortcuts.querySelectorAll<HTMLButtonElement>('button')) button.disabled = !enabled;
    }
    if (!enabled) {
      this.stopTouchScroll();
      this.lockKeyboard();
      this.modifiers.clear();
      this.syncModifiers();
    }
    if (!this.terminal) return;
    this.terminal.options.disableStdin = !enabled;
    this.terminal.options.cursorBlink = enabled;
    this.updateKeyboardInput();
  }

  private setConnectionControlsVisible(visible: boolean): void {
    const controls = this.shell?.querySelector<HTMLElement>('.rd-terminal-consent');
    if (!controls) return;
    controls.hidden = !visible;
    for (const control of controls.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, button')) {
      control.disabled = !visible;
    }
  }

  private stopWorker(closeTerminal: boolean): void {
    clearTimeout(this.openTimer);
    this.openTimer = undefined;
    const worker = this.worker;
    this.worker = undefined;
    this.setConnectionControlsVisible(true);
    if (!worker) return;
    // Nothing from a worker being shut down may overwrite the reason it was.
    worker.onmessage = null;
    worker.onerror = null;
    if (closeTerminal && this.opened) {
      worker.postMessage({ c: 'terminalClose', terminalId: this.terminalId } satisfies UiCommand);
    }
    disconnectIndependentWorker(worker);
    this.rtc?.close();
    this.rtc = undefined;
  }

  private stop(message: string): void {
    this.opened = false;
    this.setInputEnabled(false);
    this.setStatus(message);
    this.stopWorker(false);
  }

  private fail(message: string): void {
    const live = !!this.worker;
    this.stop(message);
    this.appendText(`\n[${message}]\n`);
    if (live) this.deps.page?.onEnded(message, false);
  }

  destroy(): void {
    this.stopTouchScroll();
    this.stopWorker(true);
    this.keyboardViewport?.removeEventListener('resize', this.keyboardResize);
    window.removeEventListener('resize', this.keyboardResize);
    this.keyboardViewport = undefined;
    this.resizeObserver?.disconnect();
    this.resizeObserver = undefined;
    this.terminal?.dispose();
    this.terminal = undefined;
    this.fitAddon = undefined;
    this.shell?.remove();
    this.shell = undefined;
    this.output = undefined;
    this.status = undefined;
    this.persistent = undefined;
    this.forceRelay = undefined;
    this.shortcuts = undefined;
    this.keyboardInputAllowed = false;
    this.softInput = undefined;
    this.modifiers.clear();
    this.actualTransport = undefined;
    this.opened = false;
    this.byteNormalizer.reset();
  }
}
