import { afterEach, describe, expect, it, vi } from 'vitest';
import type { SessionConfig } from '../core/contracts';
import {
  TERMINAL_OPEN_TIMEOUT_MS,
  TerminalPanel,
  type TerminalPageHooks,
} from './terminal-panel';

describe('terminal errors', () => {
  afterEach(() => vi.useRealTimers());

  it('disconnects and terminates its independent worker after a terminal error', () => {
    vi.useFakeTimers();
    const panel = new TerminalPanel({
      root: {} as HTMLElement,
      workerUrl: 'worker.js',
      getConfig: () => null,
      toast: () => {},
    }) as any;
    const worker = { postMessage: vi.fn(), terminate: vi.fn() };
    panel.worker = worker;
    panel.status = { textContent: '' };

    panel.onEvent({ t: 'terminalError', message: 'Terminal failed' });

    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'disconnect' });
    expect(panel.worker).toBeUndefined();
    vi.advanceTimersByTime(249);
    expect(worker.terminate).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(worker.terminate).toHaveBeenCalledOnce();
  });
});

describe('terminal page mode (own login)', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  function pagePanel(config: SessionConfig | null = null) {
    const hooks = {
      persistent: vi.fn(() => false),
      forceRelay: vi.fn(() => false),
      onProgress: vi.fn(),
      onLive: vi.fn(),
      onTwoFactor: vi.fn(),
      onCredentials: vi.fn(),
      onLoginError: vi.fn(),
      onEnded: vi.fn(),
    } satisfies TerminalPageHooks;
    const toast = vi.fn();
    const panel = new TerminalPanel({
      root: {} as HTMLElement,
      workerUrl: 'worker.js',
      getConfig: () => config,
      toast,
      page: hooks,
    }) as any;
    const worker = { postMessage: vi.fn(), terminate: vi.fn(), onmessage: vi.fn(), onerror: vi.fn() };
    panel.worker = worker;
    panel.status = { textContent: '' };
    return { panel, hooks, worker, toast };
  }

  const baseConfig: SessionConfig = {
    peerId: '123456789', serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    wsIdUrl: 'ws://example.test/id', wsRelayUrl: 'ws://example.test/relay', password: 'pw',
    myId: 'web-1', myName: 'Admin', connType: 'terminal', sessionId: '42',
  };

  it('connects with connType terminal and the card persistence choice', () => {
    const posted: unknown[] = [];
    class FakeWorker {
      onmessage: unknown = null;
      onerror: unknown = null;
      postMessage(msg: unknown): void { posted.push(msg); }
      terminate(): void {}
    }
    vi.stubGlobal('Worker', FakeWorker);
    vi.stubGlobal('localStorage', { getItem: (key: string) => (key === 'rd:terminal-service:123456789' ? 'svc-9' : null), setItem: vi.fn() });
    const { panel, hooks } = pagePanel(baseConfig);
    panel.worker = undefined;
    hooks.persistent.mockReturnValue(true);

    panel.connect();

    expect(posted).toContainEqual({
      c: 'connectTerminal',
      config: expect.objectContaining({
        peerId: '123456789', password: 'pw', connType: 'terminal', sessionId: '42',
        terminalPersistent: true, terminalServiceId: 'svc-9',
      }),
    });
  });

  it('hands a refused login to the page and closes the connection', () => {
    vi.useFakeTimers();
    const { panel, hooks, worker } = pagePanel();

    panel.onEvent({ t: 'loginError', message: 'Wrong Password' });

    expect(hooks.onLoginError).toHaveBeenCalledWith('Wrong Password');
    expect(hooks.onEnded).not.toHaveBeenCalled();
    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'disconnect' });
    expect(worker.onmessage).toBeNull();
    expect(panel.worker).toBeUndefined();
  });

  it('asks the page for a 2FA code and sends it on the open connection', () => {
    const { panel, hooks, worker } = pagePanel();

    panel.onEvent({ t: 'twoFactor', wrong: true });
    panel.submitTwoFactor('654321');

    expect(hooks.onTwoFactor).toHaveBeenCalledWith(true);
    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'auth2fa', code: '654321' });
    expect(panel.worker).toBe(worker);
  });

  it('passes the password hash on so the page can save it', () => {
    const { panel, hooks } = pagePanel();
    panel.onEvent({ t: 'credentials', hashHex: 'ab'.repeat(32) });
    expect(hooks.onCredentials).toHaveBeenCalledWith('ab'.repeat(32), undefined);
  });

  it('marks an address-book hash so the page does not save it', () => {
    const { panel, hooks } = pagePanel();
    panel.onEvent({ t: 'credentials', hashHex: 'cd'.repeat(32), fromAddressBook: true });
    expect(hooks.onCredentials).toHaveBeenCalledWith('cd'.repeat(32), true);
  });

  it('refuses a device without the terminal feature instead of opening one', () => {
    vi.useFakeTimers();
    const { panel, hooks, worker } = pagePanel();

    panel.onEvent({ t: 'peerInfo', terminalSupported: false });
    panel.onEvent({ t: 'state', state: 'streaming' });

    expect(hooks.onLive).not.toHaveBeenCalled();
    expect(worker.postMessage).not.toHaveBeenCalledWith(expect.objectContaining({ c: 'terminalOpen' }));
    expect(hooks.onEnded).toHaveBeenCalledWith(
      'This device has no remote terminal. It needs RustDesk 1.4.1 or later on Windows, macOS or Linux.', false,
    );
  });

  it('opens the terminal once logged in, and gives up if the device never answers', () => {
    vi.useFakeTimers();
    const { panel, hooks, worker } = pagePanel();

    panel.onEvent({ t: 'peerInfo', terminalSupported: true });
    panel.onEvent({ t: 'state', state: 'streaming' });
    expect(hooks.onLive).toHaveBeenCalledOnce();
    expect(worker.postMessage).toHaveBeenCalledWith(expect.objectContaining({ c: 'terminalOpen', terminalId: 0 }));

    vi.advanceTimersByTime(TERMINAL_OPEN_TIMEOUT_MS);
    expect(hooks.onEnded).toHaveBeenCalledWith('The remote terminal could not be opened', false);
  });

  it('keeps the connection once the terminal opens in time', () => {
    vi.useFakeTimers();
    const { panel, hooks } = pagePanel();
    panel.shell = { querySelector: () => null };

    panel.onEvent({ t: 'peerInfo', terminalSupported: true });
    panel.onEvent({ t: 'state', state: 'streaming' });
    panel.onEvent({
      t: 'terminalOpened', terminalId: 0, success: true, message: '', pid: 1, serviceId: '',
      persistentSessions: [], replayTerminalOutput: false,
    });
    vi.advanceTimersByTime(TERMINAL_OPEN_TIMEOUT_MS * 2);

    expect(hooks.onEnded).not.toHaveBeenCalled();
    expect(panel.status.textContent).toBe('Connected');
  });

  it('reports progress and the peer closing the connection', () => {
    vi.useFakeTimers();
    const { panel, hooks } = pagePanel();

    panel.onEvent({ t: 'state', state: 'rendezvous' });
    panel.onEvent({ t: 'state', state: 'error', detail: 'The device is offline' });

    expect(hooks.onProgress).toHaveBeenCalledWith('rendezvous', undefined);
    expect(hooks.onEnded).toHaveBeenCalledWith('The device is offline', false);
    expect(hooks.onEnded).toHaveBeenCalledOnce();
  });

  it('marks a Disconnect click as the user\'s own', () => {
    vi.useFakeTimers();
    const { panel, hooks, worker } = pagePanel();
    panel.opened = true;
    panel.shell = { querySelector: () => null };

    panel.disconnect();

    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'terminalClose', terminalId: 0 });
    expect(hooks.onEnded).toHaveBeenCalledWith('Terminal disconnected', true);
  });

  it('shows peer message boxes as toasts', () => {
    const { panel, toast } = pagePanel();
    panel.onEvent({ t: 'msgbox', msgtype: 'info', title: 'Note', text: 'Hello', link: '' });
    expect(toast).toHaveBeenCalledWith('Note: Hello');
  });
});
