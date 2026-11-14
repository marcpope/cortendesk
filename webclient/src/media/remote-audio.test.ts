import { describe, expect, it } from 'vitest';
import { RemoteAudioPlayback, type RemoteAudioContext } from './remote-audio';

type FakeNode = { connections: unknown[]; connect(destination: unknown): void; disconnect(): void };

class FakeContext implements RemoteAudioContext {
  currentTime = 0;
  state: AudioContextState = 'suspended';
  readonly destination = {};
  readonly gains: Array<FakeNode & { gain: { value: number } }> = [];
  resumeCalls = 0;
  readonly sources: Array<FakeNode & { buffer: { getChannelData(channel: number): Float32Array } | null; startedAt?: number; onended: (() => void) | null; stopped: boolean; stop(): void }> = [];

  createBuffer(channels: number, frames: number): { getChannelData(channel: number): Float32Array } {
    const planes = Array.from({ length: channels }, () => new Float32Array(frames));
    return { getChannelData(channel): Float32Array { return planes[channel]!; } };
  }
  createBufferSource(): (FakeNode & { buffer: { getChannelData(channel: number): Float32Array } | null; startedAt?: number; onended: (() => void) | null; stop(): void; start(when: number): void }) {
    const source = {
      buffer: null,
      connections: [],
      onended: null,
      stopped: false,
      connect(destination: unknown): void { this.connections.push(destination); },
      disconnect(): void { this.connections = []; },
      start(when: number): void { this.startedAt = when; },
      stop(): void { this.stopped = true; },
    } as FakeNode & { buffer: { getChannelData(channel: number): Float32Array } | null; startedAt?: number; onended: (() => void) | null; stopped: boolean; stop(): void; start(when: number): void };
    this.sources.push(source);
    return source;
  }
  createGain(): FakeNode & { gain: { value: number } } {
    const node: FakeNode & { gain: { value: number } } = {
      connections: [],
      gain: { value: 1 },
      connect(destination): void { this.connections.push(destination); },
      disconnect(destination?: unknown): void {
        this.connections = destination === undefined ? [] : this.connections.filter((item) => item !== destination);
      },
    };
    this.gains.push(node);
    return node;
  }
  createMediaStreamDestination(): FakeNode & { stream: MediaStream } {
    return {
      connections: [],
      stream: {} as MediaStream,
      connect(destination): void { this.connections.push(destination); },
      disconnect(): void { this.connections = []; },
    };
  }
  async resume(): Promise<void> {
    this.resumeCalls++;
    this.state = 'running';
  }
}

describe('RemoteAudioPlayback', () => {
  it('stops queued playback and disconnects the audio graph on teardown', () => {
    const context = new FakeContext();
    const playback = new RemoteAudioPlayback(context);
    playback.enqueue(new Float32Array(9_600), 48_000, 2);

    playback.close();

    expect(context.sources[0]?.stopped).toBe(true);
    expect(context.gains.every((gain) => gain.connections.length === 0)).toBe(true);
  });

  it('routes decoded PCM through the volume node', () => {
    const context = new FakeContext();
    const playback = new RemoteAudioPlayback(context);

    expect(playback.enqueue(new Float32Array(9_600), 48_000, 2)).toBe(true);
    expect(context.sources[0]?.connections).toEqual([context.gains[0]]);
  });

  it('applies mute without losing the selected playback volume', () => {
    const context = new FakeContext();
    const playback = new RemoteAudioPlayback(context);

    playback.setVolume(0.25);
    playback.setMuted(true);
    expect(context.gains[1]?.gain.value).toBe(0);

    playback.setMuted(false);
    expect(context.gains[1]?.gain.value).toBe(0.25);
  });

  it('resumes a suspended context only after an explicit user gesture', async () => {
    const context = new FakeContext();
    const playback = new RemoteAudioPlayback(context);

    await playback.resumeFromUserGesture();

    expect(context.resumeCalls).toBe(1);
    expect(context.state).toBe('running');
  });

  it('creates an isolated recording tap without replacing speaker playback', () => {
    const context = new FakeContext();
    const playback = new RemoteAudioPlayback(context);

    const tap = playback.createRecordingTap();

    expect(tap.stream).toBeDefined();
    expect(context.gains[0]?.connections).toContain(context.gains[1]);
    expect(context.gains[0]?.connections.length).toBe(2);
    expect(context.gains[1]?.connections).toContain(context.destination);
    tap.close();
  });
});
