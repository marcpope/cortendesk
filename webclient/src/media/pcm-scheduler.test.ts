import { describe, expect, it } from 'vitest';
import { PcmScheduler, type PcmSchedulerContext } from './pcm-scheduler';

type FakeBuffer = {
  channels: Float32Array[];
  getChannelData(channel: number): Float32Array;
};
type FakeSource = {
  buffer: FakeBuffer | null;
  connected: unknown[];
  startedAt?: number;
  stopped: boolean;
  onended: (() => void) | null;
  connect(destination: unknown): void;
  disconnect(): void;
  start(when: number): void;
  stop(): void;
};

class FakeContext implements PcmSchedulerContext {
  currentTime = 10;
  readonly destination = {};
  readonly sources: FakeSource[] = [];

  createBuffer(channels: number, frames: number): FakeBuffer {
    const planes = Array.from({ length: channels }, () => new Float32Array(frames));
    return { channels: planes, getChannelData(channel): Float32Array { return planes[channel]!; } };
  }

  createBufferSource(): FakeSource {
    const source: FakeSource = {
      buffer: null,
      connected: [],
      stopped: false,
      onended: null,
      connect(destination): void { this.connected.push(destination); },
      disconnect(): void { this.connected = []; },
      start(when): void { this.startedAt = when; },
      stop(): void { this.stopped = true; },
    };
    this.sources.push(source);
    return source;
  }
}

describe('PcmScheduler', () => {
  it('resets scheduled sources when the incoming format changes', () => {
    const context = new FakeContext();
    const scheduler = new PcmScheduler(context, { leadSeconds: 0.05, maxQueuedSeconds: 0.5 });

    expect(scheduler.enqueue(new Float32Array(9_600), 48_000, 2)).toBe(true);
    expect(scheduler.enqueue(new Float32Array(4_410), 44_100, 1)).toBe(true);

    expect(context.sources[0]?.stopped).toBe(true);
    expect(context.sources[1]?.startedAt).toBe(10.05);
  });

  it('bounds queued audio and schedules again after an underrun', () => {
    const context = new FakeContext();
    const scheduler = new PcmScheduler(context, { leadSeconds: 0.05, maxQueuedSeconds: 0.35 });
    const block = new Float32Array(4_800 * 2); // 100 ms of stereo PCM at 48 kHz

    expect(scheduler.enqueue(block, 48_000, 2)).toBe(true);
    expect(scheduler.enqueue(block, 48_000, 2)).toBe(true);
    expect(scheduler.enqueue(block, 48_000, 2)).toBe(true);
    expect(scheduler.enqueue(block, 48_000, 2)).toBe(false);
    expect(context.sources.map((source) => source.startedAt)).toEqual([10.05, 10.15, 10.25]);

    context.currentTime = 12;
    expect(scheduler.enqueue(block, 48_000, 2)).toBe(true);
    expect(context.sources.at(-1)?.startedAt).toBe(12.05);
  });
});
