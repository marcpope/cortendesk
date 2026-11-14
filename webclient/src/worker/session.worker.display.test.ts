import { describe, expect, it, vi } from 'vitest';
import { SupportedDecoding, SupportedDecoding_PreferCodec } from '../gen/message';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {}, decodeB64: () => new Uint8Array(32), signOpen: (bytes: Uint8Array) => bytes,
  generateBoxKeyPair: () => ({ publicKey: new Uint8Array(32), privateKey: new Uint8Array(32) }),
  sealBox: (bytes: Uint8Array) => bytes, deriveSessionCipher: () => ({ seal: (b: Uint8Array) => b, open: (b: Uint8Array) => b }),
}));
vi.mock('../media/codecs', () => ({
  probeSupportedDecoding: async () => SupportedDecoding.fromPartial({ ability_vp9: 1 }),
  CodecAwareVideoPipeline: class {},
}));

import { WorkerHost, codecNames } from './session.worker';

describe('display worker commands', () => {
  it('forwards validated display, quality, codec, and cursor commands to Session', () => {
    const session = {
      changeDisplayResolution: vi.fn(), toggleVirtualDisplay: vi.fn(), setCustomQuality: vi.fn(),
      setCustomFps: vi.fn(), setPreferredCodec: vi.fn(), setDisplayOption: vi.fn(),
    };
    const host = new WorkerHost({ post: vi.fn() });
    (host as unknown as { session: typeof session }).session = session;

    host.handle({ c: 'displayResolution', display: 1, width: 1600, height: 900 } as never);
    host.handle({ c: 'virtualDisplay', display: 2, on: true } as never);
    host.handle({ c: 'customQuality', quality: 75 } as never);
    host.handle({ c: 'customFps', fps: 60 } as never);
    host.handle({ c: 'preferredCodec', prefer: SupportedDecoding_PreferCodec.H264 } as never);
    host.handle({ c: 'displayOption', option: 'showRemoteCursor', enabled: true } as never);

    expect(session.changeDisplayResolution).toHaveBeenCalledWith(1, 1600, 900);
    expect(session.toggleVirtualDisplay).toHaveBeenCalledWith(2, true);
    expect(session.setCustomQuality).toHaveBeenCalledWith(75);
    expect(session.setCustomFps).toHaveBeenCalledWith(60);
    expect(session.setPreferredCodec).toHaveBeenCalledWith(SupportedDecoding_PreferCodec.H264);
    expect(session.setDisplayOption).toHaveBeenCalledWith('showRemoteCursor', true);
  });

  it('reports only decoder capabilities that are really available', () => {
    expect(codecNames(SupportedDecoding.fromPartial({ ability_vp9: 1, ability_h264: 1, ability_av1: 1 })))
      .toEqual(['auto', 'vp9', 'h264', 'av1']);
  });
});
