import { describe, expect, it, vi } from 'vitest';
import { WorkerHost } from './session.worker';

describe('WorkerHost voice routing', () => {
  it('routes accepted-call audio control and frames to the protocol session', () => {
    const host = new WorkerHost({ post: () => {} });
    const session = {
      startVoiceCall: vi.fn(),
      closeVoiceCall: vi.fn(),
      sendVoiceAudioFormat: vi.fn(),
      sendVoiceAudioFrame: vi.fn(),
    };
    (host as unknown as { session: typeof session }).session = session;

    host.handle({ c: 'voiceCallStart' });
    host.handle({ c: 'voiceAudioFormat', sampleRate: 48000, channels: 1 });
    host.handle({ c: 'voiceAudioFrame', data: new Uint8Array([9, 8]) });
    host.handle({ c: 'voiceCallClose' });

    expect(session.startVoiceCall).toHaveBeenCalledOnce();
    expect(session.sendVoiceAudioFormat).toHaveBeenCalledWith(48000, 1);
    expect(session.sendVoiceAudioFrame).toHaveBeenCalledWith(new Uint8Array([9, 8]));
    expect(session.closeVoiceCall).toHaveBeenCalledOnce();
  });
});
