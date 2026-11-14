import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {},
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({
    ourBoxPk: new Uint8Array(32),
    sealed: new Uint8Array(48),
    key: new Uint8Array(32),
  }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import type { SessionConfig, SessionState, Transport } from '../core/contracts';
import { WorkerHost, type SessionLike } from './session.worker';

function makeHarness() {
  const calls = {
    restartRemoteDevice: vi.fn(),
    requestElevation: vi.fn(),
    setPrivacyMode: vi.fn(),
    setBlockInput: vi.fn(),
    setLockAfterSessionEnd: vi.fn(),
  };
  const session = {
    currentState: 'streaming',
    start: vi.fn(),
    onSignalingBytes: vi.fn(),
    onRelayBytes: vi.fn(),
    relayOpened: vi.fn(),
    setSupportedDecoding: vi.fn(),
    sendMouse: vi.fn(),
    sendKey: vi.fn(),
    switchDisplay: vi.fn(),
    ctrlAltDel: vi.fn(),
    refresh: vi.fn(),
    setQuality: vi.fn(),
    sendClipboardText: vi.fn(),
    sendChat: vi.fn(),
    sendFileAction: vi.fn(),
    sendFileResponse: vi.fn(),
    disconnect: vi.fn(),
    ...calls,
  } as unknown as SessionLike;
  const host = new WorkerHost({ post: vi.fn() });
  (host as unknown as { session: SessionLike }).session = session;
  return { host, calls };
}

describe('WorkerHost security-control bridge', () => {
  let h: ReturnType<typeof makeHarness>;

  beforeEach(() => {
    h = makeHarness();
  });

  it('forwards restart and direct elevation requests', () => {
    h.host.handle({ c: 'restartRemoteDevice' });
    h.host.handle({ c: 'requestElevation' });
    expect(h.calls.restartRemoteDevice).toHaveBeenCalledOnce();
    expect(h.calls.requestElevation).toHaveBeenCalledOnce();
  });

  it('keeps waiting past the connection watchdog when manual acceptance is required', async () => {
    vi.useFakeTimers();
    const post = vi.fn();
    const close = vi.fn();
    const transport: Transport = {
      send: vi.fn(), onMessage: vi.fn(), onClose: vi.fn(), close,
    };
    let currentState: SessionState = 'connecting';
    let emitState: ((state: SessionState, detail?: string) => void) | undefined;
    const session = {
      get currentState() { return currentState; },
      start: vi.fn(), onSignalingBytes: vi.fn(), onRelayBytes: vi.fn(), relayOpened: vi.fn(),
      setSupportedDecoding: vi.fn(), sendMouse: vi.fn(), sendKey: vi.fn(), switchDisplay: vi.fn(),
      ctrlAltDel: vi.fn(), refresh: vi.fn(), setQuality: vi.fn(), restartRemoteDevice: vi.fn(),
      requestElevation: vi.fn(), setPrivacyMode: vi.fn(), setBlockInput: vi.fn(),
      setLockAfterSessionEnd: vi.fn(), sendClipboardText: vi.fn(), sendChat: vi.fn(),
      sendFileAction: vi.fn(), sendFileResponse: vi.fn(), disconnect: vi.fn(),
    } as unknown as SessionLike;
    const host = new WorkerHost({
      post,
      ready: async () => {},
      openWs: async () => transport,
      createSession: (_config, sinks) => {
        emitState = (state, detail) => {
          currentState = state;
          sinks.emit({ t: 'state', state, detail });
        };
        session.start = vi.fn();
        return session;
      },
    });
    const config: SessionConfig = {
      peerId: '123456789', serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      wsIdUrl: 'ws://example.test/id', wsRelayUrl: 'ws://example.test/relay', password: '',
      myId: 'controller', myName: 'Controller',
    };

    host.handle({ c: 'connectFile', config });
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    vi.advanceTimersByTime(5_000);
    emitState?.('needAccept', 'Waiting for the remote user');
    vi.advanceTimersByTime(25_000);

    expect(close).not.toHaveBeenCalled();
    expect(post).not.toHaveBeenCalledWith(expect.objectContaining({ t: 'state', state: 'error' }));
    emitState?.('streaming');
    expect(post).toHaveBeenCalledWith(expect.objectContaining({ t: 'state', state: 'streaming' }));
    vi.useRealTimers();
  });

  it('forwards privacy, block-input, and lock state explicitly', () => {
    h.host.handle({ c: 'privacyMode', implKey: 'privacy_mode_impl_virtual_display', on: true });
    h.host.handle({ c: 'blockInput', on: false });
    h.host.handle({ c: 'lockAfterSessionEnd', on: true });
    expect(h.calls.setPrivacyMode).toHaveBeenCalledWith('privacy_mode_impl_virtual_display', true);
    expect(h.calls.setBlockInput).toHaveBeenCalledWith(false);
    expect(h.calls.setLockAfterSessionEnd).toHaveBeenCalledWith(true);
  });
});
