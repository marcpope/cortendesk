import { describe, it, expect, vi, afterEach } from 'vitest';
import { AudioPipeline } from './audio';

type AudioOutputCb = (data: FakeAudioData) => void;

class StubAudioChunk {
  type: string;
  timestamp: number;
  data: Uint8Array;
  constructor(init: { type: string; timestamp: number; data: Uint8Array }) {
    this.type = init.type;
    this.timestamp = init.timestamp;
    this.data = init.data;
  }
}

class FakeAudioData {
  closed = false;
  constructor(
    readonly numberOfFrames: number,
    readonly numberOfChannels: number,
    readonly sampleRate: number,
    private readonly planes: Float32Array[],
  ) {}
  copyTo(dest: Float32Array, opts: { planeIndex: number; format: string }) {
    expect(opts.format).toBe('f32-planar');
    dest.set(this.planes[opts.planeIndex]!);
  }
  close() {
    this.closed = true;
  }
}

function makeStubAudioDecoder(opts: { failDecode?: boolean } = {}) {
  const instances: InstanceType<typeof StubAudioDecoder>[] = [];
  const chunks: StubAudioChunk[] = [];
  class StubAudioDecoder {
    state = 'unconfigured';
    output: AudioOutputCb;
    config: { codec: string; sampleRate: number; numberOfChannels: number } | null = null;
    constructor(init: { output: AudioOutputCb; error: (e: unknown) => void }) {
      this.output = init.output;
      instances.push(this);
    }
    configure(cfg: { codec: string; sampleRate: number; numberOfChannels: number }) {
      this.config = cfg;
      this.state = 'configured';
    }
    decode(chunk: StubAudioChunk) {
      chunks.push(chunk);
      if (opts.failDecode) throw new Error('decode failed');
    }
    close() {
      this.state = 'closed';
    }
  }
  return { StubAudioDecoder, instances, chunks };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('AudioPipeline', () => {
  it('is inert without AudioDecoder (Safari/Node): no throws, no output', () => {
    expect(typeof AudioDecoder).toBe('undefined');
    const pipe = new AudioPipeline();
    const onPcm = vi.fn();
    pipe.onPcm = onPcm;
    pipe.setFormat(48000, 2);
    pipe.pushFrame(new Uint8Array([1, 2, 3]));
    pipe.close();
    expect(onPcm).not.toHaveBeenCalled();
  });

  it('configures opus with the announced format and feeds monotonic timestamps', () => {
    const { StubAudioDecoder, instances, chunks } = makeStubAudioDecoder();
    vi.stubGlobal('AudioDecoder', StubAudioDecoder);
    vi.stubGlobal('EncodedAudioChunk', StubAudioChunk);
    const pipe = new AudioPipeline();
    pipe.setFormat(48000, 2);
    expect(instances[0]!.config).toEqual({ codec: 'opus', sampleRate: 48000, numberOfChannels: 2 });
    pipe.pushFrame(new Uint8Array([1]));
    pipe.pushFrame(new Uint8Array([2]));
    expect(chunks.map((c) => c.timestamp)).toEqual([0, 10_000]);
    expect(chunks.every((c) => c.type === 'key')).toBe(true);
    pipe.close();
    expect(instances[0]!.state).toBe('closed');
  });

  it('interleaves planar PCM and closes the AudioData', () => {
    const { StubAudioDecoder, instances } = makeStubAudioDecoder();
    vi.stubGlobal('AudioDecoder', StubAudioDecoder);
    vi.stubGlobal('EncodedAudioChunk', StubAudioChunk);
    const pipe = new AudioPipeline();
    const onPcm = vi.fn<(pcm: Float32Array, sampleRate: number, channels: number) => void>();
    pipe.onPcm = onPcm;
    pipe.setFormat(48000, 2);
    const data = new FakeAudioData(3, 2, 48000, [
      new Float32Array([1, 2, 3]),
      new Float32Array([10, 20, 30]),
    ]);
    instances[0]!.output(data);
    expect(onPcm).toHaveBeenCalledTimes(1);
    const [pcm, rate, chs] = onPcm.mock.calls[0]!;
    expect(Array.from(pcm)).toEqual([1, 10, 2, 20, 3, 30]);
    expect(rate).toBe(48000);
    expect(chs).toBe(2);
    expect(data.closed).toBe(true);
    pipe.close();
  });

  it('tears down on decode failure and becomes a no-op', () => {
    const { StubAudioDecoder, instances, chunks } = makeStubAudioDecoder({ failDecode: true });
    vi.stubGlobal('AudioDecoder', StubAudioDecoder);
    vi.stubGlobal('EncodedAudioChunk', StubAudioChunk);
    const pipe = new AudioPipeline();
    pipe.setFormat(48000, 1);
    pipe.pushFrame(new Uint8Array([1]));
    expect(instances[0]!.state).toBe('closed');
    pipe.pushFrame(new Uint8Array([2]));
    expect(chunks).toHaveLength(1);
    pipe.close();
  });
});
