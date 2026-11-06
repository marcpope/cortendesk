// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SessionConfig, SessionEvent, UiCommand } from '../core/contracts';
import { TerminalPanel } from './terminal-panel';
import { SENTINEL } from '../input/soft-keyboard';

const terminal = vi.hoisted(() => ({
  write: vi.fn(),
  reset: vi.fn(),
  focus: vi.fn(),
  dispose: vi.fn(),
  fit: vi.fn(),
  scrollLines: vi.fn(),
  bufferType: 'normal',
  mouseTracking: 'none',
  selection: vi.fn(() => ''),
  keyHandler: undefined as ((event: KeyboardEvent) => boolean) | undefined,
  options: {} as Record<string, unknown>,
  data: undefined as ((data: string) => void) | undefined,
  binary: undefined as ((data: string) => void) | undefined,
  resize: undefined as ((size: { rows: number; cols: number }) => void) | undefined,
}));

vi.mock('@xterm/xterm', () => ({
  Terminal: class {
    options: Record<string, unknown>;
    textarea = document.createElement('textarea');
    element: HTMLElement | undefined;
    write = terminal.write;
    reset = terminal.reset;
    focus = terminal.focus;
    dispose = terminal.dispose;
    getSelection = terminal.selection;
    scrollLines = terminal.scrollLines;
    rows = 24;
    buffer = { get active() { return { type: terminal.bufferType }; } };
    modes = { get mouseTrackingMode() { return terminal.mouseTracking; } };
    constructor(options: Record<string, unknown>) {
      if ('overviewRuler' in options && options.overviewRuler === undefined) {
        throw new TypeError('overviewRuler must not be explicitly undefined');
      }
      this.options = new Proxy(options, {
        set: (target, property, value) => {
          Reflect.set(target, property, value);
          if (property === 'disableStdin') this.textarea.readOnly = !!value;
          return true;
        },
      });
      terminal.options = this.options;
    }
    loadAddon(): void {}
    open(root: HTMLElement): void {
      this.element = document.createElement('div');
      this.element.className = 'xterm';
      this.element.appendChild(this.textarea);
      root.appendChild(this.element);
    }
    input(data: string): void { terminal.data?.(data); }
    attachCustomKeyEventHandler(callback: (event: KeyboardEvent) => boolean): void { terminal.keyHandler = callback; }
    onData(callback: (data: string) => void): void { terminal.data = callback; }
    onBinary(callback: (data: string) => void): void { terminal.binary = callback; }
    onResize(callback: (size: { rows: number; cols: number }) => void): void { terminal.resize = callback; }
  },
}));

vi.mock('@xterm/addon-fit', () => ({
  FitAddon: class { fit = terminal.fit; },
}));

vi.mock('./webrtc-host', () => ({
  webRtcFor: () => ({ close: vi.fn() }),
  isRtcEvent: () => false,
}));

class FakeWorker {
  static latest: FakeWorker;
  onmessage: ((event: MessageEvent<SessionEvent>) => void) | null = null;
  onerror: (() => void) | null = null;
  postMessage = vi.fn<(command: UiCommand, transfer?: Transferable[]) => void>();
  terminate = vi.fn();
  constructor() { FakeWorker.latest = this; }
  emit(event: SessionEvent): void { this.onmessage?.({ data: event } as MessageEvent<SessionEvent>); }
}

const config: SessionConfig = {
  peerId: '123456789', serverKeyB64: '', wsIdUrl: 'ws://localhost/ws/id',
  wsRelayUrl: 'ws://localhost/ws/relay', password: '', myId: 'web-test', myName: 'Test',
  webrtc: { iceServers: [] },
};

let panel: TerminalPanel;
let sessionConfig: SessionConfig;
let observerCallback: () => void;
const observerDisconnect = vi.fn();
const toast = vi.fn();

beforeEach(() => {
  vi.clearAllMocks();
  terminal.bufferType = 'normal';
  terminal.mouseTracking = 'none';
  terminal.selection.mockReset().mockReturnValue('');
  vi.useFakeTimers();
  vi.stubGlobal('Worker', FakeWorker);
  vi.stubGlobal('ResizeObserver', class {
    constructor(callback: () => void) { observerCallback = callback; }
    observe(): void {}
    disconnect = observerDisconnect;
  });
  document.body.innerHTML = '';
  localStorage.clear();
  sessionConfig = { ...config };
  panel = new TerminalPanel({ root: document.body, workerUrl: '/worker.js', getConfig: () => sessionConfig, toast });
  panel.open();
});

afterEach(() => {
  panel.destroy();
  vi.runAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function openRemote(): FakeWorker {
  panel.connect();
  const worker = FakeWorker.latest;
  worker.emit({ t: 'state', state: 'streaming' });
  worker.emit({
    t: 'terminalOpened', terminalId: 7, success: true, message: '', pid: 1,
    serviceId: '', persistentSessions: [], replayTerminalOutput: false,
  });
  return worker;
}

describe('interactive terminal panel', () => {
  function shortcut(label: string): HTMLButtonElement {
    return [...document.querySelectorAll<HTMLButtonElement>('.rd-terminal-key')].find((button) => button.textContent === label)!;
  }

  function mobileTerminal(): HTMLTextAreaElement {
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })));
    panel.destroy();
    panel.open();
    return document.querySelector<HTMLTextAreaElement>('.rd-terminal-output textarea')!;
  }

  it('coalesces single-finger swipes into local scrollback updates without opening the keyboard', () => {
    const textarea = mobileTerminal();
    openRemote();
    terminal.options.fontSize = 10;
    terminal.options.lineHeight = 2;
    const target = document.querySelector<HTMLElement>('.rd-terminal-output .xterm')!;
    const touch = (type: string, y: number): Event => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'touches', { value: type === 'touchend' ? [] : [{ identifier: 1, clientX: 20, clientY: y }] });
      return event;
    };
    target.dispatchEvent(touch('touchstart', 150));
    const move = touch('touchmove', 100);
    target.dispatchEvent(move);
    target.dispatchEvent(touch('touchmove', 50));
    expect(terminal.scrollLines).not.toHaveBeenCalled();
    vi.advanceTimersByTime(20);
    expect(terminal.scrollLines).toHaveBeenCalledExactlyOnceWith(10);
    target.dispatchEvent(touch('touchend', 50));
    target.dispatchEvent(touch('touchmove', 200));
    vi.advanceTimersByTime(20);
    expect(terminal.scrollLines).toHaveBeenCalledOnce();
    expect(move.defaultPrevented).toBe(true);
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
  });

  it('limits full-screen application wheel batches and flushes without leaving delayed scrolling', () => {
    mobileTerminal();
    openRemote();
    terminal.mouseTracking = 'vt200';
    terminal.options.fontSize = 10;
    terminal.options.lineHeight = 2;
    const target = document.querySelector<HTMLElement>('.rd-terminal-output .xterm')!;
    const wheels: WheelEvent[] = [];
    target.addEventListener('wheel', (event) => wheels.push(event as WheelEvent));
    const touch = (type: string, y: number): void => {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'touches', { value: type === 'touchend' ? [] : [{ identifier: 1, clientX: 20, clientY: y }] });
      target.dispatchEvent(event);
    };
    touch('touchstart', 300);
    touch('touchmove', 220);
    vi.advanceTimersByTime(20);
    expect(wheels).toHaveLength(1);
    expect(wheels[0]!.deltaMode).toBe(1);
    expect(wheels[0]!.deltaY).toBe(4);
    touch('touchmove', 20);
    vi.advanceTimersByTime(16);
    expect(wheels).toHaveLength(1);
    touch('touchend', 20);
    expect(wheels).toHaveLength(2);
    expect(wheels[1]!.deltaY).toBe(5);
    vi.advanceTimersByTime(100);
    expect(wheels).toHaveLength(2);
    expect(terminal.scrollLines).not.toHaveBeenCalled();
  });

  it('cancels a queued touch scroll when the panel is destroyed', () => {
    mobileTerminal();
    openRemote();
    const target = document.querySelector<HTMLElement>('.rd-terminal-output .xterm')!;
    for (const [type, y] of [['touchstart', 200], ['touchmove', 100]] as const) {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'touches', { value: [{ identifier: 1, clientX: 20, clientY: y }] });
      target.dispatchEvent(event);
    }
    panel.destroy();
    vi.advanceTimersByTime(100);
    expect(terminal.scrollLines).not.toHaveBeenCalled();
  });

  it('isolates wheel events from the parent page without cancelling xterm handling', () => {
    const target = document.querySelector('textarea')!;
    const inner = vi.fn();
    const outer = vi.fn();
    target.addEventListener('wheel', inner);
    document.body.addEventListener('wheel', outer);
    const event = new WheelEvent('wheel', { deltaY: 100, bubbles: true, cancelable: true });
    target.dispatchEvent(event);
    document.body.removeEventListener('wheel', outer);
    expect(inner).toHaveBeenCalledOnce();
    expect(outer).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
  });

  it('does not automatically focus or enable the system keyboard on mobile', () => {
    const textarea = mobileTerminal();
    terminal.focus.mockClear();
    openRemote();
    panel.open();
    expect(terminal.focus).not.toHaveBeenCalled();
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
  });

  it('uses the same thin scrollbar reservation on mobile and desktop', () => {
    mobileTerminal();
    expect(terminal.options.overviewRuler).toEqual({ width: 4 });
    expect(terminal.options.theme).toMatchObject({ overviewRulerBorder: '#00000000' });
    panel.destroy();
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
    panel.open();
    expect(terminal.options.overviewRuler).toEqual({ width: 4 });
  });

  it('synchronizes keyboard mode without widening the scrollbar when switching viewport sizes', () => {
    const textarea = mobileTerminal();
    openRemote();
    const output = document.querySelector<HTMLElement>('.rd-terminal-output')!;
    Object.defineProperties(output, { clientWidth: { value: 800 }, clientHeight: { value: 500 } });
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
    observerCallback();
    expect(textarea.readOnly).toBe(false);
    expect(terminal.options.overviewRuler).toEqual({ width: 4 });
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })));
    observerCallback();
    expect(textarea.readOnly).toBe(true);
    expect(terminal.options.overviewRuler).toEqual({ width: 4 });
  });

  it('does not repeatedly blur the textarea when touching the terminal with the keyboard already closed', () => {
    const textarea = mobileTerminal();
    openRemote();
    const blur = vi.spyOn(textarea, 'blur');
    textarea.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    textarea.dispatchEvent(new Event('touchstart', { bubbles: true }));
    expect(blur).not.toHaveBeenCalled();
    expect(textarea.readOnly).toBe(true);
  });

  it('toggles the mobile keyboard only through the dedicated button', () => {
    const textarea = mobileTerminal();
    openRemote();
    terminal.focus.mockClear();
    shortcut('Esc').click();
    shortcut('Ctrl').click();
    expect(terminal.focus).not.toHaveBeenCalled();
    expect(textarea.readOnly).toBe(true);
    const keyboard = document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!;
    keyboard.click();
    expect(terminal.focus).toHaveBeenCalledOnce();
    expect(textarea.readOnly).toBe(false);
    expect(textarea.inputMode).toBe('text');
    expect(keyboard.hasAttribute('aria-pressed')).toBe(false);
    const blur = vi.spyOn(textarea, 'blur');
    keyboard.click();
    expect(blur).toHaveBeenCalledOnce();
    expect(terminal.focus).toHaveBeenCalledOnce();
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
    shortcut('Esc').click();
    expect(textarea.readOnly).toBe(true);
    keyboard.click();
    expect(terminal.focus).toHaveBeenCalledTimes(2);
    expect(textarea.readOnly).toBe(false);
    expect(textarea.inputMode).toBe('text');
  });

  it('places backspace at the end of the second shortcut row without removing the inverted-T arrows', () => {
    const textarea = mobileTerminal();
    openRemote();
    const keys = [...document.querySelectorAll<HTMLButtonElement>('.rd-terminal-key')];
    expect(keys[14]).toBe(shortcut('⌫'));
    expect(keys[5]).toBe(shortcut('↑'));
    expect(keys.slice(11, 14).map((button) => button.textContent)).toEqual(['←', '↓', '→']);
    expect(keys.some((button) => button.textContent === '-')).toBe(false);
    expect(keys[18]).toBe(shortcut('^'));
    const keysSent: KeyboardEvent[] = [];
    textarea.addEventListener('keydown', (event) => keysSent.push(event));
    shortcut('⌫').click();
    expect(keysSent).toHaveLength(1);
    expect(keysSent[0]).toMatchObject({ key: 'Backspace', code: 'Backspace', keyCode: 8 });
    expect(textarea.readOnly).toBe(true);
    expect(terminal.focus).not.toHaveBeenCalled();
  });

  it('keeps the requested keyboard available when touching the terminal without cancelling scrolling', () => {
    const textarea = mobileTerminal();
    openRemote();
    const keyboard = document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!;
    keyboard.click();
    const touch = new Event('touchstart', { bubbles: true, cancelable: true });
    textarea.dispatchEvent(touch);
    expect(touch.defaultPrevented).toBe(false);
    expect(textarea.readOnly).toBe(false);
    expect(keyboard.hasAttribute('aria-pressed')).toBe(false);
  });

  it('locks keyboard input after a manual dismissal and does not reopen it through shortcuts', () => {
    const textarea = mobileTerminal();
    openRemote();
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    textarea.dispatchEvent(new Event('blur'));
    terminal.focus.mockClear();
    shortcut('Esc').click();
    textarea.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(terminal.focus).not.toHaveBeenCalled();
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
  });

  it('detects a dismissed system keyboard from visual viewport changes', () => {
    let height = 844;
    const viewport = new EventTarget();
    Object.defineProperty(viewport, 'height', { get: () => height });
    vi.stubGlobal('visualViewport', viewport);
    const textarea = mobileTerminal();
    openRemote();
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    height = 500;
    viewport.dispatchEvent(new Event('resize'));
    expect(textarea.readOnly).toBe(false);
    const focusCount = terminal.focus.mock.calls.length;
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    expect(terminal.focus.mock.calls.length).toBe(focusCount);
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
    height = 844;
    viewport.dispatchEvent(new Event('resize'));
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    height = 500;
    viewport.dispatchEvent(new Event('resize'));
    expect(textarea.readOnly).toBe(false);
    height = 844;
    viewport.dispatchEvent(new Event('resize'));
    expect(textarea.readOnly).toBe(true);
    expect(textarea.inputMode).toBe('none');
  });

  it('keeps deleting through repeated software-keyboard edits even when the hidden input reaches its sentinel', () => {
    const textarea = mobileTerminal();
    openRemote();
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    const keys: KeyboardEvent[] = [];
    textarea.addEventListener('keydown', (event) => keys.push(event));
    for (let index = 0; index < 8; index++) {
      textarea.value = SENTINEL.slice(0, -1);
      textarea.dispatchEvent(new InputEvent('input', { inputType: 'deleteContentBackward', bubbles: true }));
      expect(textarea.value).toBe(SENTINEL);
    }
    expect(keys).toHaveLength(8);
    expect(keys.every((event) => event.keyCode === 8)).toBe(true);
  });

  it('does not send a backspace twice when a hardware deletion is followed by its input echo', () => {
    const textarea = mobileTerminal();
    openRemote();
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    const keys: KeyboardEvent[] = [];
    textarea.addEventListener('keydown', (event) => keys.push(event));
    textarea.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', code: 'Backspace', keyCode: 8, bubbles: true, cancelable: true }));
    textarea.value = SENTINEL.slice(0, -1);
    textarea.dispatchEvent(new InputEvent('input', { inputType: 'deleteContentBackward', bubbles: true }));
    expect(keys).toHaveLength(1);
    expect(textarea.value).toBe(SENTINEL);
  });

  it('keeps Chinese composition commits from being sent twice through xterm and the mobile input model', () => {
    const textarea = mobileTerminal();
    const worker = openRemote();
    document.querySelector<HTMLButtonElement>('[data-terminal-keyboard]')!.click();
    worker.postMessage.mockClear();
    textarea.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }));
    textarea.value = SENTINEL + '\u4f60';
    textarea.dispatchEvent(new InputEvent('input', { inputType: 'insertCompositionText', bubbles: true }));
    textarea.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }));
    textarea.dispatchEvent(new InputEvent('input', { inputType: 'insertText', bubbles: true }));
    expect(worker.postMessage).toHaveBeenCalledExactlyOnceWith(
      { c: 'terminalData', terminalId: 7, data: new TextEncoder().encode('\u4f60') },
      [expect.any(ArrayBuffer)],
    );
  });

  it('keeps desktop keyboard input available without the mobile keyboard button', () => {
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
    openRemote();
    const textarea = document.querySelector<HTMLTextAreaElement>('.rd-terminal-output textarea')!;
    expect(textarea.readOnly).toBe(false);
    expect(terminal.focus).toHaveBeenCalled();
  });

  it('shows shortcuts only after the terminal opens and clears modifiers on disconnect', () => {
    const keys = document.querySelector<HTMLElement>('.rd-terminal-keys')!;
    expect(keys.hidden).toBe(true);
    openRemote();
    expect(keys.hidden).toBe(false);
    shortcut('Ctrl').click();
    expect(shortcut('Ctrl').getAttribute('aria-pressed')).toBe('true');
    panel.disconnect();
    expect(keys.hidden).toBe(true);
    expect(shortcut('Ctrl').getAttribute('aria-pressed')).toBe('false');
    expect(shortcut('Esc').disabled).toBe(true);
  });

  it.each(['Ctrl', 'Shift', 'Alt'])('keeps %s pressed until tapped again', (label) => {
    openRemote();
    const modifier = shortcut(label);
    modifier.click();
    expect(modifier.getAttribute('aria-pressed')).toBe('true');
    shortcut('Tab').click();
    expect(modifier.getAttribute('aria-pressed')).toBe('true');
    modifier.click();
    expect(modifier.getAttribute('aria-pressed')).toBe('false');
  });

  it('uses xterm keyboard events for single-tap keys and sticky modifiers', () => {
    openRemote();
    const events: KeyboardEvent[] = [];
    document.querySelector('textarea')!.addEventListener('keydown', (event) => events.push(event as KeyboardEvent));
    shortcut('Shift').click();
    shortcut('Tab').click();
    shortcut('Tab').click();
    expect(events).toHaveLength(2);
    expect(events.every((event) => event.keyCode === 9 && event.shiftKey)).toBe(true);
    expect(shortcut('Tab').hasAttribute('aria-pressed')).toBe(false);
  });

  it('applies a held Ctrl to software keyboard text without releasing it', () => {
    openRemote();
    const events: KeyboardEvent[] = [];
    document.querySelector('textarea')!.addEventListener('keydown', (event) => events.push(event as KeyboardEvent));
    shortcut('Ctrl').click();
    terminal.data?.('c');
    terminal.data?.('d');
    expect(events.map((event) => event.code)).toEqual(['KeyC', 'KeyD']);
    expect(events.every((event) => event.ctrlKey)).toBe(true);
    expect(shortcut('Ctrl').getAttribute('aria-pressed')).toBe('true');
  });

  it('uses existing shifted character mappings for software keyboard input', () => {
    const worker = openRemote();
    shortcut('Shift').click();
    terminal.data?.('6');
    expect(worker.postMessage).toHaveBeenLastCalledWith(
      { c: 'terminalData', terminalId: 7, data: new TextEncoder().encode('^') },
      [expect.any(ArrayBuffer)],
    );
  });

  it('sends the Ctrl+C shortcut as one interrupt and keeps held modifiers', () => {
    const worker = openRemote();
    shortcut('Shift').click();
    worker.postMessage.mockClear();
    shortcut('Ctrl+C').click();
    expect(worker.postMessage).toHaveBeenCalledExactlyOnceWith(
      { c: 'terminalData', terminalId: 7, data: new Uint8Array([3]) },
      [expect.any(ArrayBuffer)],
    );
    expect(shortcut('Shift').getAttribute('aria-pressed')).toBe('true');
  });

  it('hides connection options while connecting and connected, then restores them on disconnect', () => {
    const controls = document.querySelector<HTMLElement>('.rd-terminal-consent')!;
    const connect = controls.querySelector<HTMLButtonElement>('[data-terminal-connect]')!;
    expect(controls.hidden).toBe(false);
    const worker = openRemote();
    expect(controls.hidden).toBe(true);
    expect(connect.disabled).toBe(true);
    panel.disconnect();
    expect(controls.hidden).toBe(false);
    expect(connect.disabled).toBe(false);
    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'terminalClose', terminalId: 7 });
  });

  it('restores connection options after a connection error', () => {
    panel.connect();
    FakeWorker.latest.onerror?.();
    expect(document.querySelector<HTMLElement>('.rd-terminal-consent')!.hidden).toBe(false);
    expect(document.querySelector<HTMLButtonElement>('[data-terminal-connect]')!.disabled).toBe(false);
  });

  it('restores connection options and releases the worker when the shell exits', () => {
    const worker = openRemote();
    worker.emit({ t: 'terminalClosed', terminalId: 7, exitCode: 0 });
    expect(panel.connected).toBe(false);
    expect(document.querySelector<HTMLElement>('.rd-terminal-consent')!.hidden).toBe(false);
    expect(document.querySelector<HTMLElement>('.rd-terminal-keys')!.hidden).toBe(true);
  });

  it.each([
    { ctrlKey: true, shiftKey: true },
    { metaKey: true },
  ])('copies the selection with the platform shortcut: %j', (modifiers) => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    terminal.selection.mockReturnValue('selected output');
    const event = new KeyboardEvent('keydown', { code: 'KeyC', cancelable: true, ...modifiers });
    expect(terminal.keyHandler?.(event)).toBe(false);
    expect(event.defaultPrevented).toBe(true);
    expect(writeText).toHaveBeenCalledExactlyOnceWith('selected output');
    terminal.keyHandler?.(new KeyboardEvent('keyup', { code: 'KeyC', ...modifiers }));
    expect(writeText).toHaveBeenCalledOnce();
  });

  it('leaves plain Ctrl+C available to interrupt the remote shell', () => {
    const event = new KeyboardEvent('keydown', { code: 'KeyC', ctrlKey: true, cancelable: true });
    expect(terminal.keyHandler?.(event)).toBe(true);
    expect(event.defaultPrevented).toBe(false);
  });

  it('does not overwrite the clipboard when nothing is selected', () => {
    const writeText = vi.fn();
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    terminal.keyHandler?.(new KeyboardEvent('keydown', { code: 'KeyC', ctrlKey: true, shiftKey: true }));
    expect(writeText).not.toHaveBeenCalled();
  });

  it('reports a clipboard error without an unhandled rejection', async () => {
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
    terminal.selection.mockReturnValue('selected output');
    terminal.keyHandler?.(new KeyboardEvent('keydown', { code: 'KeyC', ctrlKey: true, shiftKey: true }));
    await Promise.resolve();
    expect(toast).toHaveBeenCalledOnce();
  });

  function forceRelay(checked: boolean): void {
    const checkbox = document.querySelector<HTMLInputElement>('[data-terminal-force-relay]')!;
    checkbox.checked = checked;
    checkbox.dispatchEvent(new Event('change'));
  }

  it('allows WebRTC when force relay is unchecked and WebRTC is enabled', () => {
    forceRelay(false);
    panel.connect();
    expect(document.querySelector<HTMLInputElement>('[data-terminal-force-relay]')!.checked).toBe(false);
    expect(document.querySelector('.rd-terminal-head select')).toBeNull();
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: config.webrtc }),
    });
  });

  it('forces relay by default without mutating the shared connection config', () => {
    expect(document.querySelector<HTMLInputElement>('[data-terminal-force-relay]')!.checked).toBe(true);
    panel.connect();
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: undefined }),
    });
    expect(sessionConfig.webrtc).toBe(config.webrtc);
  });

  it('defaults to checked when the panel is reopened', () => {
    forceRelay(false);
    panel.destroy();
    panel.open();
    expect(document.querySelector<HTMLInputElement>('[data-terminal-force-relay]')!.checked).toBe(true);
    panel.connect();
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: undefined }),
    });
  });

  it('does not bypass the administrator WebRTC setting', () => {
    sessionConfig = { ...config, webrtc: undefined };
    panel.destroy();
    panel.open();
    forceRelay(false);
    panel.connect();
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: undefined }),
    });
  });

  it('does not disconnect a live session when the checkbox is changed', () => {
    const worker = openRemote();
    worker.postMessage.mockClear();
    forceRelay(false);
    expect(panel.connected).toBe(true);
    expect(worker.postMessage).not.toHaveBeenCalled();
  });

  it('applies the checkbox on the next connection', () => {
    forceRelay(false);
    const worker = openRemote();
    forceRelay(true);
    panel.disconnect();
    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'terminalClose', terminalId: 7 });
    expect(panel.connected).toBe(false);
    panel.connect();
    expect(FakeWorker.latest).not.toBe(worker);
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: undefined }),
    });
  });

  it('shows the actual relay transport when WebRTC falls back', () => {
    forceRelay(false);
    const worker = openRemote();
    worker.emit({ t: 'transport', kind: 'relay' });
    expect(document.querySelector('[data-terminal-status]')!.textContent).toContain('Relay');
    expect(document.querySelector<HTMLInputElement>('[data-terminal-force-relay]')!.checked).toBe(false);
  });

  it.each([false, true])('uses the connection page force-relay choice: %s', (forced) => {
    panel.destroy();
    panel = new TerminalPanel({
      root: document.body,
      workerUrl: '/worker.js',
      getConfig: () => sessionConfig,
      toast: vi.fn(),
      page: {
        persistent: () => false,
        forceRelay: () => forced,
        onProgress: vi.fn(),
        onLive: vi.fn(),
        onTwoFactor: vi.fn(),
        onCredentials: vi.fn(),
        onLoginError: vi.fn(),
        onEnded: vi.fn(),
      },
    });
    panel.open();
    panel.connect();
    expect(FakeWorker.latest.postMessage).toHaveBeenCalledWith({
      c: 'connectTerminal', config: expect.objectContaining({ webrtc: forced ? undefined : config.webrtc }),
    });
    expect(sessionConfig.webrtc).toBe(config.webrtc);
    expect(document.querySelector('.rd-terminal-head select')).toBeNull();
  });

  it('mounts an embedded terminal without a separate command form', () => {
    expect(document.querySelector('.rd-terminal-output textarea')).not.toBeNull();
    expect(document.querySelector('.rd-terminal-input-row')).toBeNull();
    expect(terminal.options.disableStdin).toBe(true);
  });

  it.each(['a', '\r', '\t', '\u0003', '\u001b[A', '\u007f', '\u4e2d\u6587', 'pasted\rtext'])(
    'sends input immediately without appending a newline: %j', (input) => {
      const worker = openRemote();
      terminal.data?.(input);
      expect(worker.postMessage).toHaveBeenLastCalledWith(
        { c: 'terminalData', terminalId: 7, data: new TextEncoder().encode(input) },
        [expect.any(ArrayBuffer)],
      );
    },
  );

  it('ignores input before opening and after disconnecting', () => {
    panel.connect();
    const worker = FakeWorker.latest;
    terminal.data?.('before');
    expect(worker.postMessage.mock.calls.some(([command]) => command.c === 'terminalData')).toBe(false);
    panel.disconnect();
    worker.postMessage.mockClear();
    terminal.data?.('after');
    expect(worker.postMessage).not.toHaveBeenCalled();
    expect(terminal.options.disableStdin).toBe(true);
  });

  it('preserves ANSI sequences, carriage returns and split UTF-8 output', () => {
    const worker = openRemote();
    const data = new TextEncoder().encode('\u001b[31mred\u001b[0m\r\u4e2d');
    const first = data.slice(0, -1);
    const second = data.slice(-1);
    worker.emit({ t: 'terminalData', terminalId: 7, data: first, compressed: false });
    worker.emit({ t: 'terminalData', terminalId: 7, data: second, compressed: false });
    expect(terminal.write.mock.calls).toEqual([[first], [second]]);
  });

  it('forwards binary input without UTF-8 re-encoding', () => {
    const worker = openRemote();
    terminal.binary?.('\u00ff\u0080');
    expect(worker.postMessage).toHaveBeenLastCalledWith(
      { c: 'terminalData', terminalId: 7, data: new Uint8Array([255, 128]) },
      [expect.any(ArrayBuffer)],
    );
  });

  it('normalizes standalone C1 controls while preserving split UTF-8 C1 sequences', () => {
    const worker = openRemote();
    worker.emit({ t: 'terminalData', terminalId: 7, data: new Uint8Array([0x9b, 0x31, 0x6d, 0xc2]), compressed: false });
    worker.emit({ t: 'terminalData', terminalId: 7, data: new Uint8Array([0x9b]), compressed: false });
    expect(terminal.write.mock.calls).toEqual([
      [new Uint8Array([0xc2, 0x9b, 0x31, 0x6d, 0xc2])],
      [new Uint8Array([0x9b])],
    ]);
  });

  it('fits the viewport and sends the measured dimensions to the remote terminal', () => {
    const worker = openRemote();
    const output = document.querySelector<HTMLElement>('.rd-terminal-output')!;
    Object.defineProperties(output, { clientWidth: { value: 900 }, clientHeight: { value: 500 } });
    terminal.fit.mockImplementationOnce(() => terminal.resize?.({ rows: 28, cols: 105 }));
    observerCallback();
    expect(terminal.fit).toHaveBeenCalled();
    expect(worker.postMessage).toHaveBeenLastCalledWith({ c: 'terminalResize', terminalId: 7, rows: 28, cols: 105 });
  });

  it('disables keyboard input when the remote shell exits', () => {
    const worker = openRemote();
    worker.emit({ t: 'terminalClosed', terminalId: 7, exitCode: 0 });
    worker.postMessage.mockClear();
    terminal.data?.('ignored');
    expect(worker.postMessage).not.toHaveBeenCalled();
    expect(terminal.options.disableStdin).toBe(true);
    expect(terminal.options.cursorBlink).toBe(false);
  });

  it('closes the remote terminal and disposes the renderer on destroy', () => {
    const worker = openRemote();
    panel.destroy();
    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'terminalClose', terminalId: 7 });
    expect(terminal.dispose).toHaveBeenCalledOnce();
    expect(observerDisconnect).toHaveBeenCalledOnce();
    expect(document.querySelector('.rd-terminal-modal')).toBeNull();
  });
});