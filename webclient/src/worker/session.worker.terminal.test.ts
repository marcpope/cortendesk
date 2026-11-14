import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {},
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import type { SessionConfig, SessionEvent, Transport } from '../core/contracts';
import { WorkerHost, type SessionLike } from './session.worker';

function harness() {
  const calls = {
    openTerminal: vi.fn(),
    sendTerminalData: vi.fn(),
    resizeTerminal: vi.fn(),
    closeTerminal: vi.fn(),
  };
  const session = {
    currentState: 'streaming', start: vi.fn(), onSignalingBytes: vi.fn(), onRelayBytes: vi.fn(), relayOpened: vi.fn(),
    setSupportedDecoding: vi.fn(), sendMouse: vi.fn(), sendKey: vi.fn(), switchDisplay: vi.fn(), ctrlAltDel: vi.fn(),
    refresh: vi.fn(), setQuality: vi.fn(), sendClipboardText: vi.fn(), sendChat: vi.fn(), sendFileAction: vi.fn(),
    sendFileResponse: vi.fn(), disconnect: vi.fn(), ...calls,
  } as unknown as SessionLike;
  const host = new WorkerHost({ post: vi.fn() });
  (host as unknown as { session: SessionLike }).session = session;
  return { host, calls };
}

describe('terminal worker bridge', () => {
  let h: ReturnType<typeof harness>;
  beforeEach(() => { h = harness(); });

  it('forwards terminal lifecycle and raw input bytes', () => {
    const data = new Uint8Array([3, 65]);
    h.host.handle({ c: 'terminalOpen', terminalId: 9, rows: 32, cols: 120 });
    h.host.handle({ c: 'terminalData', terminalId: 9, data });
    h.host.handle({ c: 'terminalResize', terminalId: 9, rows: 40, cols: 150 });
    h.host.handle({ c: 'terminalClose', terminalId: 9 });

    expect(h.calls.openTerminal).toHaveBeenCalledWith(9, 32, 120);
    expect(h.calls.sendTerminalData).toHaveBeenCalledWith(9, data);
    expect(h.calls.resizeTerminal).toHaveBeenCalledWith(9, 40, 150);
    expect(h.calls.closeTerminal).toHaveBeenCalledWith(9);
  });

  it('forwards a 2FA code to the session', () => {
    const sendAuth2fa = vi.fn();
    (h.host as unknown as { session: { sendAuth2fa: typeof sendAuth2fa } }).session.sendAuth2fa = sendAuth2fa;
    h.host.handle({ c: 'auth2fa', code: '123456' });
    expect(sendAuth2fa).toHaveBeenCalledWith('123456');
  });
});

describe('terminal login watchdog', () => {
  it('stands down while the device waits for a 2FA code', async () => {
    vi.useFakeTimers();
    const post = vi.fn();
    const close = vi.fn();
    const transport: Transport = { send: vi.fn(), onMessage: vi.fn(), onClose: vi.fn(), close };
    let emit: ((ev: SessionEvent) => void) | undefined;
    const session = {
      currentState: 'login', start: vi.fn(), onSignalingBytes: vi.fn(), onRelayBytes: vi.fn(), relayOpened: vi.fn(),
      setSupportedDecoding: vi.fn(), disconnect: vi.fn(),
    } as unknown as SessionLike;
    const host = new WorkerHost({
      post,
      ready: async () => {},
      openWs: async () => transport,
      createSession: (_config, sinks) => {
        emit = sinks.emit;
        return session;
      },
    });
    const config: SessionConfig = {
      peerId: '123456789', serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      wsIdUrl: 'ws://example.test/id', wsRelayUrl: 'ws://example.test/relay', password: '',
      myId: 'controller', myName: 'Controller', connType: 'terminal',
    };

    host.handle({ c: 'connectTerminal', config });
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    vi.advanceTimersByTime(5_000);
    emit?.({ t: 'twoFactor', wrong: false });
    vi.advanceTimersByTime(60_000);

    expect(post).toHaveBeenCalledWith({ t: 'twoFactor', wrong: false });
    expect(post).not.toHaveBeenCalledWith(expect.objectContaining({ t: 'state', state: 'error' }));
    expect(close).not.toHaveBeenCalled();
    vi.useRealTimers();
  });
});
