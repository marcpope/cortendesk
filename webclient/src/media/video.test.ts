import { describe, it, expect, vi, afterEach } from 'vitest';
import { probeSupportedDecoding, VideoPipeline } from './video';
import { SupportedDecoding_PreferCodec, VideoFrame } from '../gen/message';
import type { SessionStats } from '../core/contracts';

type StubFrame = { displayWidth: number; displayHeight: number; close: () => void };
type OutputCb = (frame: StubFrame) => void;
type ErrorCb = (e: unknown) => void;

class StubChunk {
  type: string;
  timestamp: number;
  data: Uint8Array;
  constructor(init: { type: string; timestamp: number; data: Uint8Array }) {
    this.type = init.type;
    this.timestamp = init.timestamp;
    this.data = init.data;
  }
}

function makeStubDecoder(opts: { supported?: string[]; failDecode?: boolean } = {}) {
  const instances: InstanceType<typeof StubVideoDecoder>[] = [];
  const chunks: StubChunk[] = [];
  const configs: Record<string, unknown>[] = [];
  class StubVideoDecoder {
    static async isConfigSupported(cfg: { codec: string }) {
      return { supported: (opts.supported ?? []).includes(cfg.codec) };
    }
    state = 'unconfigured';
    output: OutputCb;
    error: ErrorCb;
    constructor(init: { output: OutputCb; error: ErrorCb }) {
      this.output = init.output;
      this.error = init.error;
      instances.push(this);
    }
    configure(cfg: Record<string, unknown>) {
      configs.push(cfg);
      this.state = 'configured';
    }
    decode(chunk: StubChunk) {
      chunks.push(chunk);
      if (opts.failDecode) throw new Error('decode failed');
      this.output({ displayWidth: 640, displayHeight: 480, close: () => {} });
    }
    close() {
      this.state = 'closed';
    }
  }
  return { StubVideoDecoder, instances, chunks, configs };
}

function stubGlobals(dec: unknown) {
  vi.stubGlobal('VideoDecoder', dec);
  vi.stubGlobal('EncodedVideoChunk', StubChunk);
}

function makeCanvas() {
  const ctx = { drawImage: vi.fn() };
  const canvas = { width: 0, height: 0, getContext: vi.fn(() => ctx) };
  return { canvas: canvas as unknown as OffscreenCanvas, ctx };
}

function vp9Frame(keys: boolean[], bytesPer = 3): VideoFrame {
  return VideoFrame.fromPartial({
    union: {
      $case: 'vp9s',
      vp9s: {
        frames: keys.map((key, i) => ({
          data: new Uint8Array(bytesPer).fill(i + 1),
          key,
          pts: BigInt(i * 16),
        })),
      },
    },
    display: 0,
  });
}

function makePipeline() {
  const { canvas, ctx } = makeCanvas();
  const onAck = vi.fn();
  const onNeedRefresh = vi.fn();
  const onStats = vi.fn<(s: Partial<SessionStats>) => void>();
  const pipe = new VideoPipeline(canvas, onAck, onNeedRefresh, onStats);
  return { pipe, canvas, ctx, onAck, onNeedRefresh, onStats };
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('probeSupportedDecoding (stubbed)', () => {
  it('sets abilities only for codecs that probe ok, never h265', async () => {
    const { StubVideoDecoder } = makeStubDecoder({
      supported: ['vp09.00.10.08', 'vp8', 'hev1.1.6.L93.B0'],
    });
    stubGlobals(StubVideoDecoder);
    const sd = await probeSupportedDecoding();
    expect(sd.ability_vp9).toBe(1);
    expect(sd.ability_vp8).toBe(1);
    expect(sd.ability_h264).toBe(0);
    expect(sd.ability_av1).toBe(0);
    expect(sd.ability_h265).toBe(0); // stays off even when the probe passes
    expect(sd.prefer).toBe(SupportedDecoding_PreferCodec.Auto);
    expect(sd.i444).toBeUndefined();
    expect(sd.prefer_chroma).toBe(0); // I420 default, never set explicitly
  });

  it('returns all-zero abilities when VideoDecoder is unavailable', async () => {
    expect(typeof VideoDecoder).toBe('undefined');
    const sd = await probeSupportedDecoding();
    expect(sd.ability_vp9).toBe(0);
    expect(sd.ability_vp8).toBe(0);
    expect(sd.ability_h264).toBe(0);
    expect(sd.ability_h265).toBe(0);
    expect(sd.ability_av1).toBe(0);
  });
});

describe('VideoPipeline gating and drawing (stubbed)', () => {
  it('drops delta frames before the first keyframe and asks for a refresh', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe, onAck, onNeedRefresh } = makePipeline();
    pipe.pushFrame(vp9Frame([false]));
    expect(chunks).toHaveLength(0);
    expect(onAck).not.toHaveBeenCalled();
    expect(onNeedRefresh).toHaveBeenCalledTimes(1);
    pipe.pushFrame(vp9Frame([true, false]));
    expect(chunks.map((c) => c.type)).toEqual(['key', 'delta']);
    expect(onAck).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('debounces refresh requests to at most one per second', () => {
    const { StubVideoDecoder } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onNeedRefresh } = makePipeline();
    pipe.pushFrame(vp9Frame([false]));
    pipe.pushFrame(vp9Frame([false]));
    vi.advanceTimersByTime(500);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(600);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('converts pts ms to microsecond chunk timestamps and draws to the canvas', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe, canvas, ctx } = makePipeline();
    pipe.pushFrame(vp9Frame([true, false]));
    expect(chunks[0]!.timestamp).toBe(0);
    expect(chunks[1]!.timestamp).toBe(16_000);
    expect((canvas as unknown as { width: number }).width).toBe(640);
    expect((canvas as unknown as { height: number }).height).toBe(480);
    expect(ctx.drawImage).toHaveBeenCalledTimes(2);
    pipe.close();
  });

  it('retries a failing codec in software, then disables it and re-advertises', () => {
    const { StubVideoDecoder, instances, configs } = makeStubDecoder({ failDecode: true });
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onNeedRefresh } = makePipeline();
    const readv = vi.fn();
    pipe.onNeedReadvertise = readv;
    for (let i = 0; i < 3; i++) {
      pipe.pushFrame(vp9Frame([true]));
      vi.advanceTimersByTime(1100);
    }
    expect(configs).toHaveLength(1);
    expect(configs[0]).not.toHaveProperty('hardwareAcceleration');
    expect(pipe.disabledCodecs()).toEqual([]);

    for (let i = 0; i < 2; i++) {
      pipe.pushFrame(vp9Frame([true]));
      vi.advanceTimersByTime(1100);
    }
    expect(configs).toHaveLength(2);
    expect(configs[1]).toMatchObject({ hardwareAcceleration: 'prefer-software' });
    expect(readv).not.toHaveBeenCalled();
    pipe.pushFrame(vp9Frame([true]));
    expect(readv).toHaveBeenCalledTimes(1);
    expect(readv).toHaveBeenCalledWith('vp9s');
    expect(pipe.disabledCodecs()).toEqual(['vp9s']);
    expect(instances.at(-1)!.state).toBe('closed');
    const before = instances.length;
    vi.advanceTimersByTime(1100);
    pipe.pushFrame(vp9Frame([true]));
    expect(instances.length).toBe(before); // disabled codec never reconfigures
    expect(onNeedRefresh.mock.calls.length).toBeGreaterThanOrEqual(3);
    pipe.close();
  });

  it('reset closes the decoder and requires a fresh keyframe', () => {
    const { StubVideoDecoder, instances, chunks } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    const { pipe } = makePipeline();
    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(1);
    pipe.reset();
    expect(instances[0]!.state).toBe('closed');
    pipe.pushFrame(vp9Frame([false])); // delta after reset: dropped, no new decode
    expect(chunks).toHaveLength(1);
    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(2);
    expect(chunks).toHaveLength(2);
    pipe.close();
  });

  it('emits stats once per interval with fps, mbps and codec name', () => {
    const { StubVideoDecoder } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    vi.useFakeTimers();
    const { pipe, onStats } = makePipeline();
    pipe.pushFrame(vp9Frame([true], 125_000));
    expect(onStats).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    pipe.pushFrame(vp9Frame([false], 125_000));
    expect(onStats).toHaveBeenCalledTimes(1);
    const s = onStats.mock.calls[0]![0];
    expect(s.codec).toBe('vp9');
    expect(s.width).toBe(640);
    expect(s.height).toBe(480);
    expect(s.fps).toBe(2);
    expect(s.mbps).toBe(2); // 2 * 125000 B * 8 over 1s = 2 Mbps
    expect(s.framesDropped).toBe(0);
    pipe.close();
  });

  it('ignores rgb/yuv unions and pushFrame is a no-op without WebCodecs', () => {
    const { pipe, onAck } = makePipeline();
    pipe.pushFrame(vp9Frame([true])); // no VideoDecoder global: must not throw
    expect(onAck).not.toHaveBeenCalled();
    const { StubVideoDecoder, instances } = makeStubDecoder();
    stubGlobals(StubVideoDecoder);
    pipe.pushFrame(
      VideoFrame.fromPartial({ union: { $case: 'rgb', rgb: { compress: false } }, display: 0 }),
    );
    expect(instances).toHaveLength(0);
    pipe.close();
  });
});

describe.skipIf(typeof VideoDecoder === 'undefined')('probeSupportedDecoding (real WebCodecs)', () => {
  it('returns a plausible SupportedDecoding', async () => {
    const sd = await probeSupportedDecoding();
    for (const v of [sd.ability_vp9, sd.ability_vp8, sd.ability_h264, sd.ability_av1]) {
      expect([0, 1]).toContain(v);
    }
    expect(sd.ability_h265).toBe(0);
    expect(sd.prefer).toBe(SupportedDecoding_PreferCodec.Auto);
    expect(sd.i444).toBeUndefined();
  });
});

describe('decoder backpressure', () => {
  it('drops delta frames once the decode queue is deep, but never key frames', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    // A decoder that never drains: every decode leaves the queue deeper.
    let depth = 0;
    class Backed extends StubVideoDecoder {
      get decodeQueueSize() {
        return depth;
      }
      override decode(chunk: StubChunk) {
        depth++;
        chunks.push(chunk);
        // Deliberately produces no output — a saturated decoder mid-catch-up.
      }
    }
    stubGlobals(Backed);
    const { pipe, onNeedRefresh } = makePipeline();

    // Key frame first so the pipeline leaves awaitingKey.
    pipe.pushFrame(vp9Frame([true]));
    // Now push far more deltas than the queue cap allows.
    for (let i = 0; i < 60; i++) pipe.pushFrame(vp9Frame([false]));

    const deltas = chunks.filter((c) => c.type === 'delta').length;
    expect(deltas).toBeGreaterThan(0);
    expect(deltas).toBeLessThan(60); // some were dropped rather than queued
    expect(onNeedRefresh).toHaveBeenCalled(); // and a key frame was requested

    // A key frame must still get through even while the queue is deep.
    const before = chunks.filter((c) => c.type === 'key').length;
    pipe.pushFrame(vp9Frame([true]));
    expect(chunks.filter((c) => c.type === 'key').length).toBe(before + 1);
  });

  it('after a drop, skips later deltas until the next key frame', () => {
    const { StubVideoDecoder, chunks } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    let depth = 0;
    class Backed extends StubVideoDecoder {
      get decodeQueueSize() {
        return depth;
      }
    }
    stubGlobals(Backed);
    const { pipe } = makePipeline();
    pipe.pushFrame(vp9Frame([true]));
    depth = 100;
    pipe.pushFrame(vp9Frame([false])); // dropped
    depth = 0;
    pipe.pushFrame(vp9Frame([false])); // queue drained, but its reference is gone
    expect(chunks.filter((c) => c.type === 'delta')).toHaveLength(0);
    pipe.pushFrame(vp9Frame([true]));
    pipe.pushFrame(vp9Frame([false]));
    expect(chunks.map((c) => c.type)).toEqual(['key', 'key', 'delta']);
  });
});

describe('silent stall recovery', () => {
  it('asks for a key frame when chunks are accepted but nothing renders', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    // Accepts everything, throws nothing, never calls output(). This is the
    // failure that error handling cannot see.
    class Silent extends StubVideoDecoder {
      override decode(_chunk: StubChunk) {}
    }
    stubGlobals(Silent);
    const { pipe, onNeedRefresh } = makePipeline();

    pipe.pushFrame(vp9Frame([true]));
    onNeedRefresh.mockClear();

    // Well inside the stall window: no complaint yet.
    vi.advanceTimersByTime(500);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).not.toHaveBeenCalled();

    // Past it: the pipeline should now be asking the peer to resend.
    vi.advanceTimersByTime(5000);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).toHaveBeenCalled();
  });

  it('replaces a wedged decoder instead of feeding it the new key frame (#139)', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder, instances } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    class Silent extends StubVideoDecoder {
      override decode(_chunk: StubChunk) {}
    }
    stubGlobals(Silent);
    const { pipe } = makePipeline();

    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(1);
    vi.advanceTimersByTime(3000);
    pipe.pushFrame(vp9Frame([true]));
    expect(instances).toHaveLength(2);
    expect(instances[0]!.state).toBe('closed');
    pipe.close();
  });

  it('retries in software when a decoder renders nothing (#139)', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder, configs } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    class HardwareSilent extends StubVideoDecoder {
      private software = false;
      override configure(cfg: Record<string, unknown>) {
        super.configure(cfg);
        this.software = cfg.hardwareAcceleration === 'prefer-software';
      }
      override decode(chunk: StubChunk) {
        if (this.software) super.decode(chunk);
      }
    }
    stubGlobals(HardwareSilent);
    const { pipe, ctx } = makePipeline();

    pipe.pushFrame(vp9Frame([true]));
    for (let i = 0; i < 4; i++) {
      vi.advanceTimersByTime(3000);
      pipe.pushFrame(vp9Frame([true]));
    }
    expect(configs.at(-1)).toMatchObject({ hardwareAcceleration: 'prefer-software' });
    expect(ctx.drawImage).toHaveBeenCalled();
    expect(pipe.disabledCodecs()).toEqual([]);
    pipe.close();
  });

  it('gives up on a codec after repeated stalls with nothing rendered', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder, instances } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    class Silent extends StubVideoDecoder {
      override decode(_chunk: StubChunk) {}
    }
    stubGlobals(Silent);
    const { pipe } = makePipeline();
    const readvertise = vi.fn();
    pipe.onNeedReadvertise = readvertise;

    pipe.pushFrame(vp9Frame([true]));
    for (let i = 0; i < 7; i++) {
      vi.advanceTimersByTime(3000);
      pipe.pushFrame(vp9Frame([true]));
    }
    expect(readvertise).toHaveBeenCalledWith('vp9s');
    expect(pipe.disabledCodecs()).toEqual(['vp9s']);
    expect(instances.at(-1)!.state).toBe('closed');
    pipe.close();
  });

  it('logs what the decoder did, once per event, for ?debug=1 (#139)', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    class Silent extends StubVideoDecoder {
      override decode(_chunk: StubChunk) {}
    }
    stubGlobals(Silent);
    const { pipe } = makePipeline();
    const lines: string[] = [];
    pipe.onDebug = (what) => lines.push(what);

    pipe.pushFrame(vp9Frame([false, false]));
    pipe.pushFrame(vp9Frame([true]));
    for (let i = 0; i < 7; i++) {
      vi.advanceTimersByTime(3000);
      pipe.pushFrame(vp9Frame([true]));
    }
    pipe.pushFrame(vp9Frame([true]));
    pipe.pushFrame(vp9Frame([true]));
    expect(lines).toEqual([
      'decoder',
      'waiting for a key frame',
      'stall',
      'decoder',
      'stall',
      'decoder',
      'stall',
      'trying software decoder',
      'decoder',
      'stall',
      'decoder',
      'stall',
      'decoder',
      'stall',
      'codec disabled',
      'frames in a disabled codec',
    ]);
    pipe.close();
  });

  it('logs the first picture of each decoder', () => {
    const { StubVideoDecoder } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    stubGlobals(StubVideoDecoder);
    const { pipe } = makePipeline();
    const lines: Array<[string, Record<string, unknown>]> = [];
    pipe.onDebug = (what, data) => lines.push([what, data]);

    pipe.pushFrame(vp9Frame([true, false, false]));
    expect(lines).toEqual([
      ['decoder', { codec: 'vp9s' }],
      ['first picture', { codec: 'vp9s', width: 640, height: 480 }],
    ]);
    pipe.close();
  });

  it('does not call the first chunk after a long static screen a stall', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder, instances } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    stubGlobals(StubVideoDecoder);
    const { pipe, onNeedRefresh } = makePipeline();

    pipe.pushFrame(vp9Frame([true]));
    onNeedRefresh.mockClear();
    vi.advanceTimersByTime(10 * 60 * 1000);
    pipe.pushFrame(vp9Frame([false]));
    expect(onNeedRefresh).not.toHaveBeenCalled();
    expect(instances).toHaveLength(1);
    pipe.close();
  });

  it('does not fire on a healthy session that keeps rendering', () => {
    vi.useFakeTimers();
    const { StubVideoDecoder } = makeStubDecoder({ supported: ['vp09.00.10.08'] });
    stubGlobals(StubVideoDecoder); // its decode() always outputs a frame
    const { pipe, onNeedRefresh } = makePipeline();

    pipe.pushFrame(vp9Frame([true]));
    onNeedRefresh.mockClear();

    for (let i = 0; i < 20; i++) {
      vi.advanceTimersByTime(1000);
      pipe.pushFrame(vp9Frame([false]));
    }
    expect(onNeedRefresh).not.toHaveBeenCalled();
  });
});
