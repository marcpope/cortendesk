import type { SessionConfig, SessionEvent, SessionState, UiCommand } from '../core/contracts';
import { disconnectIndependentWorker } from './advanced-worker-lifecycle';
import { htl, tl, tn } from '../i18n';
import { isRtcEvent, webRtcFor, type WebRtcHost } from './webrtc-host';

const MAX_TERMINAL_TEXT = 1_000_000;
/** A device that logs in but never answers the terminal open is treated as unable to. */
export const TERMINAL_OPEN_TIMEOUT_MS = 20_000;
const utf8 = new TextEncoder();

type TerminalParserState = 'text'|'escape'|'csi'|'osc'|'oscEscape'|'controlString'|'controlEscape';

export class TerminalControlStripper {
  private state: TerminalParserState = 'text';

  reset(): void {
    this.state = 'text';
  }

  push(text: string): string {
    let output = '';
    for (const char of text) {
      const code = char.charCodeAt(0);
      switch (this.state) {
        case 'text':
          if (code === 0x1b) this.state = 'escape';
          else if (code === 0x9b) this.state = 'csi';
          else if (code === 0x9d) this.state = 'osc';
          else if (code === 0x90 || code === 0x98 || code === 0x9e || code === 0x9f) this.state = 'controlString';
          else if (code === 0x0d) output += '\n';
          else if (code === 0x09 || code === 0x0a || (code >= 0x20 && code !== 0x7f && (code < 0x80 || code > 0x9f))) output += char;
          break;
        case 'escape':
          if (char === '[') this.state = 'csi';
          else if (char === ']') this.state = 'osc';
          else if (char === 'P' || char === 'X' || char === '^' || char === '_') this.state = 'controlString';
          else if (code !== 0x1b) this.state = 'text';
          break;
        case 'csi':
          if (code >= 0x40 && code <= 0x7e) this.state = 'text';
          else if (code === 0x1b) this.state = 'escape';
          break;
        case 'osc':
          if (code === 0x07 || code === 0x9c) this.state = 'text';
          else if (code === 0x1b) this.state = 'oscEscape';
          break;
        case 'oscEscape':
          if (char === '\\' || code === 0x07 || code === 0x9c) this.state = 'text';
          else if (code !== 0x1b) this.state = 'osc';
          break;
        case 'controlString':
          if (code === 0x9c) this.state = 'text';
          else if (code === 0x1b) this.state = 'controlEscape';
          break;
        case 'controlEscape':
          if (char === '\\' || code === 0x9c) this.state = 'text';
          else if (code !== 0x1b) this.state = 'controlString';
          break;
      }
    }
    return output;
  }
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

export function stripTerminalControl(text: string): string {
  return new TerminalControlStripper().push(text);
}

export function appendBoundedTerminalText(current: string, incoming: string, max = MAX_TERMINAL_TEXT): string {
  const combined = current + incoming;
  return combined.length <= max ? combined : combined.slice(combined.length - max);
}

/**
 * Page mode: the terminal is the whole page and logs in on its own (password,
 * 2FA). The page owns the connect card; these hooks keep it in step.
 */
export type TerminalPageHooks = {
  /** Keep the terminal running after disconnect (read at connect time). */
  persistent(): boolean;
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
  private output: HTMLPreElement | undefined;
  private input: HTMLInputElement | undefined;
  private status: HTMLElement | undefined;
  private persistent: HTMLInputElement | undefined;
  private decoder = new TextDecoder();
  private byteNormalizer = new TerminalC1ByteNormalizer();
  private controlStripper = new TerminalControlStripper();
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
      this.input?.focus();
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
          <label><input type="checkbox" data-terminal-persistent> ${htl('keep_terminal_available_after_disconnect')}</label>
          <button type="button" data-terminal-connect>${htl('connect_terminal')}</button>
        </div>`;
    shell.innerHTML = `
      <div class="rd-terminal-card">
        <header class="rd-terminal-head">
          <div><strong data-terminal-title>${htl('remote_terminal')}</strong><span data-terminal-status>${htl('not_connected')}</span></div>
          ${close}
        </header>${consent}
        <pre class="rd-terminal-output" tabindex="0" aria-live="polite" aria-label="${htl('remote_terminal_output')}"></pre>
        <form class="rd-terminal-input-row">
          <label for="rd-terminal-input">${htl('command_input')}</label>
          <input id="rd-terminal-input" type="text" autocomplete="off" spellcheck="false" disabled>
          <button type="submit" disabled>${htl('send')}</button>
        </form>
      </div>`;
    this.deps.root.appendChild(shell);
    this.shell = shell;
    this.output = shell.querySelector<HTMLPreElement>('.rd-terminal-output')!;
    this.input = shell.querySelector<HTMLInputElement>('#rd-terminal-input')!;
    this.status = shell.querySelector<HTMLElement>('[data-terminal-status]')!;
    this.persistent = shell.querySelector<HTMLInputElement>('[data-terminal-persistent]') ?? undefined;

    shell.querySelector<HTMLButtonElement>('[data-terminal-close]')!.addEventListener('click', () => {
      if (page) this.disconnect();
      else this.destroy();
    });
    shell.querySelector<HTMLButtonElement>('[data-terminal-connect]')?.addEventListener('click', () => this.connect());
    shell.querySelector<HTMLFormElement>('.rd-terminal-input-row')!.addEventListener('submit', (event) => {
      event.preventDefault();
      this.sendLine();
    });
    this.input.addEventListener('keydown', (event) => this.sendSpecialKey(event));

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
    };
    this.setStatus(tl('connecting_2'));
    this.decoder = new TextDecoder();
    this.byteNormalizer.reset();
    this.controlStripper.reset();
    this.worker = new Worker(this.deps.workerUrl, { type: 'module' });
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
        this.setStatus(event.replayTerminalOutput ? tl('connected_replaying_buffered_output') : tl('connected'));
        if (event.serviceId && this.persistentWanted) {
          try { localStorage.setItem(this.serviceStorageKey, event.serviceId); } catch { /* non-fatal */ }
        }
        if (event.persistentSessions.length) {
          this.appendText(`\n${tn('terminal_extra_sessions', event.persistentSessions.length)}\n`);
        }
        this.input?.focus();
        break;
      case 'terminalData':
        this.appendText(this.controlStripper.push(
          this.decoder.decode(this.byteNormalizer.push(event.data), { stream: true }),
        ));
        break;
      case 'terminalClosed':
        this.opened = false;
        this.setInputEnabled(false);
        this.setStatus(tl('terminal_exited', { code: event.exitCode }));
        break;
      case 'terminalError':
        this.fail(event.message);
        break;
    }
  }

  private sendLine(): void {
    const value = this.input?.value ?? '';
    if (!this.opened || !value) return;
    this.sendBytes(utf8.encode(`${value}\r`));
    if (this.input) this.input.value = '';
  }

  private sendSpecialKey(event: KeyboardEvent): void {
    if (!this.opened) return;
    const special: Record<string, string> = {
      ArrowUp: '\u001b[A', ArrowDown: '\u001b[B', ArrowRight: '\u001b[C', ArrowLeft: '\u001b[D',
      Home: '\u001b[H', End: '\u001b[F', Delete: '\u001b[3~', Tab: '\t',
    };
    let bytes: Uint8Array | undefined;
    if (event.ctrlKey && event.key.length === 1) {
      const code = event.key.toUpperCase().charCodeAt(0);
      if (code >= 64 && code <= 95) bytes = new Uint8Array([code - 64]);
    } else if (special[event.key]) {
      bytes = utf8.encode(special[event.key]!);
    }
    if (!bytes) return;
    event.preventDefault();
    this.sendBytes(bytes);
  }

  private sendBytes(data: Uint8Array): void {
    this.worker?.postMessage({ c: 'terminalData', terminalId: this.terminalId, data } satisfies UiCommand, [data.buffer]);
  }

  private resize(): void {
    if (!this.output) return;
    const style = getComputedStyle(this.output);
    const fontSize = Number.parseFloat(style.fontSize) || 14;
    const lineHeight = Number.parseFloat(style.lineHeight) || fontSize * 1.45;
    const charWidth = fontSize * 0.61;
    const rows = Math.max(8, Math.floor(this.output.clientHeight / lineHeight));
    const cols = Math.max(20, Math.floor(this.output.clientWidth / charWidth));
    if (rows === this.lastRows && cols === this.lastCols) return;
    this.lastRows = rows;
    this.lastCols = cols;
    if (this.opened) {
      this.worker?.postMessage({ c: 'terminalResize', terminalId: this.terminalId, rows, cols } satisfies UiCommand);
    }
  }

  private appendText(text: string): void {
    if (!this.output || !text) return;
    this.output.textContent = appendBoundedTerminalText(this.output.textContent ?? '', text);
    this.output.scrollTop = this.output.scrollHeight;
  }

  private setStatus(message: string): void {
    if (this.status) this.status.textContent = message;
  }

  private setInputEnabled(enabled: boolean): void {
    if (!this.input || !this.shell) return;
    this.input.disabled = !enabled;
    const send = this.shell.querySelector<HTMLButtonElement>('.rd-terminal-input-row button');
    if (send) send.disabled = !enabled;
  }

  private stopWorker(closeTerminal: boolean): void {
    clearTimeout(this.openTimer);
    this.openTimer = undefined;
    const worker = this.worker;
    this.worker = undefined;
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
    this.stopWorker(true);
    this.resizeObserver?.disconnect();
    this.resizeObserver = undefined;
    this.shell?.remove();
    this.shell = undefined;
    this.output = undefined;
    this.input = undefined;
    this.status = undefined;
    this.persistent = undefined;
    this.opened = false;
    this.byteNormalizer.reset();
    this.controlStripper.reset();
    this.decoder = new TextDecoder();
  }
}
