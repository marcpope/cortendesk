import { describe, expect, it, vi } from 'vitest';
import { opusOutputSampleRate, VoiceCaptureController, type VoiceCaptureDeps, type VoiceReader } from './voice-capture';

type Deferred<T> = { promise: Promise<T>; resolve(value: T): void; reject(error: Error): void };
function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  return { promise: new Promise<T>((r, j) => { resolve = r; reject = j; }), resolve, reject };
}

function tick(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('VoiceCaptureController', () => {
  it('retires a microphone request that resolves after cleanup', async () => {
    const media = deferred<{ getAudioTracks(): Array<{ stop(): void }> }>();
    const track = { stop: vi.fn() };
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: () => media.promise,
      createProcessor: vi.fn(),
      createEncoder: vi.fn(),
    }, { sendFormat: vi.fn(), sendFrame: vi.fn() });

    const preparing = capture.prepare();
    await tick();
    capture.stop();
    media.resolve({ getAudioTracks: () => [track] });

    await expect(preparing).resolves.toMatchObject({ ok: false });
    expect(track.stop).toHaveBeenCalledOnce();
  });

  it('rejects unsupported Opus before requesting microphone permission', async () => {
    const getUserMedia = vi.fn();
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => false,
      getUserMedia,
      createProcessor: vi.fn(),
      createEncoder: vi.fn(),
    }, { sendFormat: vi.fn(), sendFrame: vi.fn() });

    await expect(capture.prepare()).resolves.toMatchObject({ ok: false });
    expect(getUserMedia).not.toHaveBeenCalled();
  });

  it('does not let a retired microphone rejection stop a replacement stream', async () => {
    const firstMedia = deferred<{ getAudioTracks(): Array<{ stop(): void }> }>();
    const replacementTrack = { stop: vi.fn() };
    let call = 0;
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: () => call++ === 0
        ? firstMedia.promise
        : Promise.resolve({ getAudioTracks: () => [replacementTrack] }),
      createProcessor: vi.fn(),
      createEncoder: vi.fn(),
    }, { sendFormat: vi.fn(), sendFrame: vi.fn() });

    const retired = capture.prepare();
    await tick();
    await expect(capture.prepare()).resolves.toMatchObject({ ok: true });
    firstMedia.reject(new Error('late old permission failure'));

    await expect(retired).resolves.toMatchObject({ ok: false });
    expect(replacementTrack.stop).not.toHaveBeenCalled();
    capture.stop();
  });

  it('requests a constrained microphone, encodes at the input rate and announces the Opus output rate before packets', async () => {
    const reads: Deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>[] = [];
    const sent: Array<string | Uint8Array> = [];
    let constraints: MediaTrackConstraints | undefined;
    const encoderConfigs: Array<{ sampleRate: number; channels: number }> = [];
    const track = { stop: vi.fn() };
    const reader: VoiceReader = {
      read: () => { const next = deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>(); reads.push(next); return next.promise; },
      cancel: vi.fn(async () => {}),
    };
    const deps: VoiceCaptureDeps = {
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: async (value: MediaStreamConstraints) => {
        constraints = typeof value.audio === 'object' ? value.audio : undefined;
        return { getAudioTracks: () => [track] };
      },
      createProcessor: () => ({ readable: { getReader: () => reader } }),
      createEncoder: (config, { output }) => {
        encoderConfigs.push(config);
        return { encodeQueueSize: 0, encode: () => output({ byteLength: 3, copyTo: (dest) => dest.set([1, 2, 3]) }), close: vi.fn() };
      },
    };
    const capture = new VoiceCaptureController(deps, { sendFormat: (rate, channels) => sent.push(`${rate}/${channels}`), sendFrame: (data) => sent.push(data) });

    expect((await capture.prepare()).ok).toBe(true);
    expect(constraints).toMatchObject({
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
      sampleRate: { ideal: 48000 },
      channelCount: { ideal: 1 },
    });
    expect(sent).toEqual([]);

    capture.start();
    reads[0]!.resolve({ done: false, value: { sampleRate: 44100, numberOfChannels: 2, close: vi.fn() } });
    await tick();
    expect(encoderConfigs).toEqual([{ sampleRate: 44100, channels: 2 }]);
    expect(sent[0]).toBe('48000/2');
    expect(sent[1]).toEqual(new Uint8Array([1, 2, 3]));
  });

  it('rejects microphone inputs that are neither mono nor stereo', async () => {
    const pending = deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>();
    const track = { stop: vi.fn() };
    const data = { sampleRate: 48000, numberOfChannels: 3, close: vi.fn() };
    const createEncoder = vi.fn();
    const sendFormat = vi.fn();
    const onError = vi.fn();
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: async () => ({ getAudioTracks: () => [track] }),
      createProcessor: () => ({ readable: { getReader: () => ({ read: () => pending.promise, cancel: vi.fn() }) } }),
      createEncoder,
    }, { sendFormat, sendFrame: vi.fn(), onError });

    await capture.prepare();
    capture.start();
    pending.resolve({ done: false, value: data });
    await tick();

    expect(createEncoder).not.toHaveBeenCalled();
    expect(sendFormat).not.toHaveBeenCalled();
    expect(onError).toHaveBeenCalledWith('Only mono or stereo microphone input is supported.');
    expect(data.close).toHaveBeenCalledOnce();
    expect(track.stop).toHaveBeenCalledOnce();
  });

  it('stops reader, encoder, tracks and blocks late frames on cleanup', async () => {
    const pending = deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>();
    const sendFrame = vi.fn();
    const track = { stop: vi.fn() };
    const reader = { read: () => pending.promise, cancel: vi.fn(async () => {}) };
    const encoder = { encodeQueueSize: 0, encode: vi.fn(), close: vi.fn() };
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: async () => ({ getAudioTracks: () => [track] }),
      createProcessor: () => ({ readable: { getReader: () => reader } }),
      createEncoder: () => encoder,
    }, { sendFormat: vi.fn(), sendFrame });

    await capture.prepare();
    capture.start();
    capture.stop();
    pending.resolve({ done: false, value: { sampleRate: 48000, numberOfChannels: 1, close: vi.fn() } });
    await tick();

    expect(reader.cancel).toHaveBeenCalledOnce();
    expect(track.stop).toHaveBeenCalledOnce();
    expect(sendFrame).not.toHaveBeenCalled();
  });

  it('ignores a delayed encoder error from a retired capture epoch', async () => {
    const tracks = [{ stop: vi.fn() }, { stop: vi.fn() }];
    const readers = tracks.map(() => {
      let first = true;
      const pending = deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>();
      return {
        read: () => {
          if (!first) return pending.promise;
          first = false;
          return Promise.resolve({ done: false, value: { sampleRate: 48000, numberOfChannels: 1, close: vi.fn() } });
        },
        cancel: vi.fn(async () => {}),
      };
    });
    const encoderErrors: Array<(error: Error) => void> = [];
    const onError = vi.fn();
    let streamIndex = 0;
    let readerIndex = 0;
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: async () => ({ getAudioTracks: () => [tracks[streamIndex++]!] }),
      createProcessor: () => ({ readable: { getReader: () => readers[readerIndex++]! } }),
      createEncoder: (_config, callbacks) => {
        encoderErrors.push(callbacks.error);
        return { encodeQueueSize: 0, encode: vi.fn(), close: vi.fn() };
      },
    }, { sendFormat: vi.fn(), sendFrame: vi.fn(), onError });

    await capture.prepare();
    capture.start();
    await tick();
    capture.stop();
    await capture.prepare();
    capture.start();
    await tick();

    encoderErrors[0]!(new Error('late old encoder error'));
    expect(onError).not.toHaveBeenCalled();
    expect(tracks[1]!.stop).not.toHaveBeenCalled();
    capture.stop();
  });

  it('reports encoder failures and releases microphone resources', async () => {
    const pending = deferred<{ done: boolean; value?: { sampleRate: number; numberOfChannels: number; close(): void } }>();
    const track = { stop: vi.fn() };
    const onError = vi.fn();
    const capture = new VoiceCaptureController({
      available: () => true,
      supportsOpus: async () => true,
      getUserMedia: async () => ({ getAudioTracks: () => [track] }),
      createProcessor: () => ({ readable: { getReader: () => ({ read: () => pending.promise, cancel: vi.fn() }) } }),
      createEncoder: () => { throw new Error('encoder failed'); },
    }, { sendFormat: vi.fn(), sendFrame: vi.fn(), onError });

    await capture.prepare();
    capture.start();
    pending.resolve({ done: false, value: { sampleRate: 48000, numberOfChannels: 1, close: vi.fn() } });
    await tick();

    expect(onError).toHaveBeenCalled();
    expect(track.stop).toHaveBeenCalledOnce();
  });
});

describe('opusOutputSampleRate', () => {
  it('announces 48 kHz for rates Chromium resamples, and the native rate for Opus-native ones', () => {
    expect(opusOutputSampleRate(44100)).toBe(48000);
    expect(opusOutputSampleRate(48000)).toBe(48000);
    expect(opusOutputSampleRate(96000)).toBe(48000);
    for (const rate of [8000, 12000, 16000, 24000]) expect(opusOutputSampleRate(rate)).toBe(rate);
  });
});
