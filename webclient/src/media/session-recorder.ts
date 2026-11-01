import { tl } from '../i18n';
export type RecordingAudioTap = { stream: { getAudioTracks(): MediaStreamTrack[] }; close(): void };

export type RecordingFormat = { mimeType: string; extension: 'webm' | 'mp4' };

const RECORDING_FORMATS: RecordingFormat[] = [
  { mimeType: 'video/webm;codecs=vp9,opus', extension: 'webm' },
  { mimeType: 'video/webm;codecs=vp8,opus', extension: 'webm' },
  { mimeType: 'video/webm', extension: 'webm' },
  { mimeType: 'video/mp4', extension: 'mp4' },
];

export function chooseRecordingMime(isTypeSupported: (mime: string) => boolean): RecordingFormat | null {
  return RECORDING_FORMATS.find((format) => isTypeSupported(format.mimeType)) ?? null;
}

export interface RecordingStream {
  getVideoTracks(): MediaStreamTrack[];
  getAudioTracks(): MediaStreamTrack[];
  getTracks(): MediaStreamTrack[];
}

export interface RecordingSurface {
  captureStream?(frameRate?: number): RecordingStream;
}

export interface RecordingRecorder {
  state: string;
  ondataavailable: ((event: { data: Blob }) => void) | null;
  onstop: (() => void) | null;
  onerror: (() => void) | null;
  start(timeslice?: number): void;
  stop(): void;
}

export interface RecordingEnvironment {
  isTypeSupported(mime: string): boolean;
  createStream(tracks: MediaStreamTrack[]): RecordingStream;
  createRecorder(stream: RecordingStream, mimeType: string): RecordingRecorder;
  download(blob: Blob, extension: string, startedAtMs: number): void;
  now(): number;
}

export type RecordingStartResult = { ok: true } | { ok: false; reason: string };

export class LocalSessionRecorder {
  private recorder: RecordingRecorder | null = null;
  private combined: RecordingStream | null = null;
  private audioTap: RecordingAudioTap | null = null;
  private chunks: Blob[] = [];
  private format: RecordingFormat | null = null;
  private startedAtMs = 0;
  private active = false;

  constructor(
    private readonly surface: RecordingSurface,
    private readonly createAudioTap: () => RecordingAudioTap | null,
    private readonly onState: (active: boolean, startedAtMs?: number) => void,
    private readonly env: RecordingEnvironment = browserRecordingEnvironment,
  ) {}

  start(): RecordingStartResult {
    if (this.active) return { ok: false, reason: tl('recording_is_already_active') };
    if (typeof this.surface.captureStream !== 'function') {
      return { ok: false, reason: tl('this_browser_cannot_capture_visible') };
    }
    const format = chooseRecordingMime((mime) => this.env.isTypeSupported(mime));
    if (!format) return { ok: false, reason: tl('this_browser_has_no_supported') };

    let videoStream: RecordingStream;
    try {
      videoStream = this.surface.captureStream(30);
    } catch {
      return { ok: false, reason: tl('visible_remote_video_could_not') };
    }
    const videoTracks = videoStream.getVideoTracks();
    if (!videoTracks.length) return { ok: false, reason: tl('visible_remote_video_has_no') };

    try {
      this.audioTap = this.createAudioTap();
      const audioTracks = this.audioTap?.stream.getAudioTracks() ?? [];
      this.combined = this.env.createStream([...videoTracks, ...audioTracks]);
    } catch {
      for (const track of videoTracks) {
        try { track.stop(); } catch { /* already stopped */ }
      }
      this.audioTap?.close();
      this.audioTap = null;
      return { ok: false, reason: tl('browser_could_not_assemble_recording') };
    }
    try {
      this.recorder = this.env.createRecorder(this.combined, format.mimeType);
    } catch {
      this.cleanup(false);
      return { ok: false, reason: tl('browser_could_not_create_session') };
    }

    this.chunks = [];
    this.format = format;
    this.startedAtMs = this.env.now();
    this.recorder.ondataavailable = (event) => {
      if (event.data.size > 0) this.chunks.push(event.data);
    };
    this.recorder.onstop = () => {
      if (this.chunks.length && this.format) {
        const blob = new Blob(this.chunks, { type: this.format.mimeType });
        this.env.download(blob, this.format.extension, this.startedAtMs);
      }
      this.cleanup(true);
    };
    this.recorder.onerror = () => this.cleanup(false);

    try {
      this.recorder.start(1000);
    } catch {
      this.cleanup(false);
      return { ok: false, reason: tl('browser_refused_start_session_recording') };
    }
    this.active = true;
    this.onState(true, this.startedAtMs);
    return { ok: true };
  }

  stop(): void {
    if (!this.recorder || !this.active) return;
    if (this.recorder.state !== 'inactive') this.recorder.stop();
    else this.cleanup(false);
  }

  close(): void {
    if (this.recorder && this.active && this.recorder.state !== 'inactive') {
      this.recorder.stop();
      return;
    }
    this.cleanup(false);
  }

  private cleanup(notify: boolean): void {
    const wasActive = this.active;
    const recorder = this.recorder;
    this.active = false;
    if (recorder) {
      recorder.ondataavailable = null;
      recorder.onstop = null;
      recorder.onerror = null;
    }
    for (const track of this.combined?.getTracks() ?? []) {
      try { track.stop(); } catch { /* already stopped */ }
    }
    this.audioTap?.close();
    this.audioTap = null;
    this.combined = null;
    this.recorder = null;
    this.chunks = [];
    this.format = null;
    if (wasActive || notify) this.onState(false);
  }
}

export const browserRecordingEnvironment: RecordingEnvironment = {
  isTypeSupported: (mime) => typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(mime),
  createStream: (tracks) => new MediaStream(tracks),
  createRecorder: (stream, mimeType) =>
    new MediaRecorder(stream as MediaStream, { mimeType }) as unknown as RecordingRecorder,
  download: (blob, extension, startedAtMs) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const stamp = new Date(startedAtMs).toISOString().replace(/[:.]/g, '-');
    link.href = url;
    link.download = `cortendesk-session-${stamp}.${extension}`;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
  },
  now: () => Date.now(),
};
