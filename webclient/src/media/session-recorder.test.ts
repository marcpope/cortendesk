import { describe, expect, it, vi } from 'vitest';
import {
  LocalSessionRecorder,
  chooseRecordingMime,
  type RecordingEnvironment,
  type RecordingStream,
} from './session-recorder';

function track(kind: 'video' | 'audio') {
  return { kind, stop: vi.fn() } as unknown as MediaStreamTrack;
}

function stream(video: MediaStreamTrack[] = [], audio: MediaStreamTrack[] = []): RecordingStream {
  return {
    getVideoTracks: () => video,
    getAudioTracks: () => audio,
    getTracks: () => [...video, ...audio],
  };
}

describe('recording MIME selection', () => {
  it('chooses the first browser-supported WebM profile', () => {
    expect(chooseRecordingMime((mime) => mime === 'video/webm;codecs=vp8,opus')).toEqual({
      mimeType: 'video/webm;codecs=vp8,opus', extension: 'webm',
    });
  });

  it('fails closed when MediaRecorder has no supported video format', () => {
    expect(chooseRecordingMime(() => false)).toBeNull();
  });
});

describe('LocalSessionRecorder', () => {
  it('combines visible video and remote audio, then downloads only after stop', () => {
    const video = track('video');
    const audio = track('audio');
    const combined = stream([video], [audio]);
    const recorder = {
      state: 'inactive',
      ondataavailable: null as ((event: { data: Blob }) => void) | null,
      onstop: null as (() => void) | null,
      onerror: null as (() => void) | null,
      start: vi.fn(function (this: { state: string }) { this.state = 'recording'; }),
      stop: vi.fn(function (this: { state: string; onstop: (() => void) | null }) { this.state = 'inactive'; this.onstop?.(); }),
    };
    const download = vi.fn();
    const states: boolean[] = [];
    const tap = { stream: stream([], [audio]), close: vi.fn() };
    const env: RecordingEnvironment = {
      isTypeSupported: (mime) => mime === 'video/webm;codecs=vp8,opus',
      createStream: (tracks) => {
        expect(tracks).toEqual([video, audio]);
        return combined;
      },
      createRecorder: () => recorder,
      download,
      now: () => 1234,
    };
    const surface = { captureStream: () => stream([video], []) };
    const local = new LocalSessionRecorder(surface, () => tap, (active) => states.push(active), env);

    expect(local.start()).toEqual({ ok: true });
    expect(states).toEqual([true]);
    recorder.ondataavailable?.({ data: new Blob(['chunk'], { type: 'video/webm' }) });
    expect(download).not.toHaveBeenCalled();

    local.stop();

    expect(recorder.stop).toHaveBeenCalledOnce();
    expect(download).toHaveBeenCalledWith(expect.any(Blob), 'webm', 1234);
    expect(tap.close).toHaveBeenCalledOnce();
    expect(states).toEqual([true, false]);
  });

  it('cleans up the audio tap when stream assembly fails closed', () => {
    const video = track('video');
    const tap = { stream: stream([], [track('audio')]), close: vi.fn() };
    const local = new LocalSessionRecorder(
      { captureStream: () => stream([video], []) },
      () => tap,
      vi.fn(),
      {
        isTypeSupported: () => true,
        createStream: () => { throw new Error('stream failure'); },
        createRecorder: vi.fn(),
        download: vi.fn(),
        now: () => 0,
      },
    );

    expect(local.start()).toEqual({ ok: false, reason: 'The browser could not assemble the recording stream.' });
    expect(tap.close).toHaveBeenCalledOnce();
  });

  it('does not start when visible video capture is unavailable', () => {
    const local = new LocalSessionRecorder({}, () => null, vi.fn(), {
      isTypeSupported: () => true,
      createStream: () => stream(),
      createRecorder: vi.fn(),
      download: vi.fn(),
      now: () => 0,
    });
    expect(local.start()).toEqual({ ok: false, reason: 'This browser cannot capture the visible remote video.' });
  });
});
