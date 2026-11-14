import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {}, decodeB64: () => new Uint8Array(32), signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class { seal(bytes: Uint8Array): Uint8Array { return bytes; } open(bytes: Uint8Array): Uint8Array { return bytes; } },
}));

import { WorkerHost, type SessionLike } from './session.worker';

function harness() {
  const calls = { setRemoteAudioEnabled: vi.fn(), setClipboardEnabled: vi.fn(), setClientRecording: vi.fn() };
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

describe('media control worker bridge', () => {
  let h: ReturnType<typeof harness>;
  beforeEach(() => { h = harness(); });

  it('forwards audio, clipboard, and recording state', () => {
    h.host.handle({ c: 'remoteAudio', enabled: false });
    h.host.handle({ c: 'clipboardEnabled', enabled: false });
    h.host.handle({ c: 'clientRecording', recording: true });
    expect(h.calls.setRemoteAudioEnabled).toHaveBeenCalledWith(false);
    expect(h.calls.setClipboardEnabled).toHaveBeenCalledWith(false);
    expect(h.calls.setClientRecording).toHaveBeenCalledWith(true);
  });
});
