import { tl } from '../i18n';
export interface VoiceTrack { stop(): void }
export interface VoiceStream {
  getAudioTracks(): VoiceTrack[];
  getTracks?(): VoiceTrack[];
}
export interface VoiceAudioData {
  readonly sampleRate: number;
  readonly numberOfChannels: number;
  close(): void;
}
export interface VoiceReader {
  read(): Promise<{ done: boolean; value?: VoiceAudioData }>;
  cancel(reason?: unknown): Promise<void> | void;
}
export interface VoiceProcessor {
  readable: { getReader(): VoiceReader };
}
export interface VoiceEncodedChunk {
  byteLength: number;
  copyTo(destination: Uint8Array): void;
}
export interface VoiceEncoder {
  readonly encodeQueueSize: number;
  encode(data: VoiceAudioData): void;
  close(): void;
}
export type VoiceEncoderConfig = { sampleRate: number; channels: number };
export type VoiceEncoderCallbacks = {
  output(chunk: VoiceEncodedChunk): void;
  error(error: unknown): void;
};
export interface VoiceCaptureDeps {
  available(): boolean;
  supportsOpus(config: VoiceEncoderConfig): Promise<boolean>;
  getUserMedia(constraints: MediaStreamConstraints): Promise<VoiceStream>;
  createProcessor(track: VoiceTrack): VoiceProcessor;
  createEncoder(config: VoiceEncoderConfig, callbacks: VoiceEncoderCallbacks): VoiceEncoder;
}
export interface VoiceCaptureSinks {
  sendFormat(sampleRate: number, channels: number): void;
  sendFrame(data: Uint8Array): void;
  onError?(message: string): void;
}

const PREFERRED_CONFIG: VoiceEncoderConfig = { sampleRate: 48000, channels: 1 };

/**
 * Sample rate to announce in AudioFormat for a given microphone rate. The
 * encoder is configured with the input rate (WebCodecs rejects mismatched
 * AudioData), but Chromium's Opus encoder only keeps 8/12/16/24 kHz input as
 * is and resamples everything else to 48 kHz before encoding. The peer builds
 * its decoder from the announced rate, so announcing 44100 for a 44.1 kHz mic
 * yields packets it cannot decode.
 */
export function opusOutputSampleRate(inputRate: number): number {
  return [8000, 12000, 16000, 24000].includes(inputRate) ? inputRate : 48000;
}
const MIC_CONSTRAINTS: MediaTrackConstraints = {
  echoCancellation: true,
  noiseSuppression: true,
  autoGainControl: true,
  sampleRate: { ideal: PREFERRED_CONFIG.sampleRate },
  channelCount: { ideal: PREFERRED_CONFIG.channels },
};

/** Injectable outgoing Opus capture. Prepared by a user gesture; pumped only after peer acceptance. */
export class VoiceCaptureController {
  private stream: VoiceStream | null = null;
  private reader: VoiceReader | null = null;
  private encoder: VoiceEncoder | null = null;
  private epoch = 0;
  private active = false;

  constructor(private readonly deps: VoiceCaptureDeps, private readonly sinks: VoiceCaptureSinks) {}

  async prepare(): Promise<{ ok: boolean; message?: string }> {
    this.stop();
    const epoch = this.epoch;
    if (!this.deps.available()) {
      return { ok: false, message: tl('voice_calls_require_chrome_or') };
    }
    try {
      if (!await this.deps.supportsOpus(PREFERRED_CONFIG)) {
        return { ok: false, message: tl('this_browser_cannot_encode_opus') };
      }
      if (epoch !== this.epoch) return { ok: false, message: tl('microphone_request_was_cancelled') };
      const stream = await this.deps.getUserMedia({ audio: MIC_CONSTRAINTS });
      if (epoch !== this.epoch) {
        for (const track of stream.getTracks?.() ?? stream.getAudioTracks()) track.stop();
        return { ok: false, message: tl('microphone_request_was_cancelled') };
      }
      this.stream = stream;
      if (stream.getAudioTracks().length === 0) {
        this.stop();
        return { ok: false, message: tl('no_microphone_was_available') };
      }
      return { ok: true };
    } catch {
      if (epoch !== this.epoch) return { ok: false, message: tl('microphone_request_was_cancelled') };
      this.stop();
      return { ok: false, message: tl('microphone_access_was_denied_or') };
    }
  }

  start(): boolean {
    const track = this.stream?.getAudioTracks()[0];
    if (!track || this.active) return false;
    const epoch = ++this.epoch;
    try {
      this.reader = this.deps.createProcessor(track).readable.getReader();
      this.active = true;
      void this.pump(epoch);
      return true;
    } catch {
      this.fail(tl('could_not_start_microphone_capture'));
      return false;
    }
  }

  stop(): void {
    this.active = false;
    this.epoch++;
    const reader = this.reader;
    this.reader = null;
    if (reader) void Promise.resolve(reader.cancel()).catch(() => {});
    const encoder = this.encoder;
    this.encoder = null;
    if (encoder) {
      try { encoder.close(); } catch { /* browser already closed it */ }
    }
    const stream = this.stream;
    this.stream = null;
    for (const track of stream?.getTracks?.() ?? stream?.getAudioTracks() ?? []) {
      try { track.stop(); } catch { /* already stopped */ }
    }
  }

  private fail(message: string): void {
    const wasActive = this.active || this.stream !== null;
    this.stop();
    if (wasActive) this.sinks.onError?.(message);
  }

  private async pump(epoch: number): Promise<void> {
    const reader = this.reader;
    if (!reader) return;
    while (this.active && epoch === this.epoch) {
      let result: { done: boolean; value?: VoiceAudioData };
      try {
        result = await reader.read();
      } catch {
        if (this.active && epoch === this.epoch) this.fail(tl('microphone_capture_failed'));
        return;
      }
      if (result.done) {
        if (this.active && epoch === this.epoch) this.fail(tl('microphone_capture_stopped'));
        return;
      }
      const data = result.value;
      if (!data) continue;
      try {
        if (!this.active || epoch !== this.epoch) continue;
        if (!this.encoder) {
          const channels = data.numberOfChannels;
          if (channels !== 1 && channels !== 2) {
            this.fail(tl('only_mono_or_stereo_microphone'));
            return;
          }
          const config = {
            sampleRate: Number.isFinite(data.sampleRate) && data.sampleRate > 0
              ? Math.round(data.sampleRate)
              : PREFERRED_CONFIG.sampleRate,
            channels,
          };
          const supported = await this.deps.supportsOpus(config);
          if (!this.active || epoch !== this.epoch) return;
          if (!supported) {
            this.fail(tl('this_microphone_format_cannot_be'));
            return;
          }
          this.encoder = this.deps.createEncoder(config, {
            output: (chunk) => {
              if (!this.active || epoch !== this.epoch) return;
              const bytes = new Uint8Array(chunk.byteLength);
              chunk.copyTo(bytes);
              this.sinks.sendFrame(bytes);
            },
            error: () => {
              if (this.active && epoch === this.epoch) this.fail(tl('opus_microphone_encoder_failed'));
            },
          });
          // RustDesk requires AudioFormat before the first AudioFrame.
          this.sinks.sendFormat(opusOutputSampleRate(config.sampleRate), config.channels);
        }
        if (this.encoder.encodeQueueSize < 2) this.encoder.encode(data);
      } catch {
        if (this.active && epoch === this.epoch) this.fail(tl('could_not_start_opus_microphone'));
        return;
      } finally {
        data.close();
      }
    }
  }
}

/** Browser adapter; browser globals stay at this boundary so Node tests use fakes. */
export function browserVoiceCaptureDeps(): VoiceCaptureDeps {
  const g = globalThis as unknown as Record<string, any>;
  const mediaDevices = g.navigator?.mediaDevices;
  const encoderConfig = (config: VoiceEncoderConfig) => ({
    codec: 'opus',
    sampleRate: config.sampleRate,
    numberOfChannels: config.channels,
    bitrate: 64000,
  });
  return {
    available: () => !!(
      g.isSecureContext
      && typeof mediaDevices?.getUserMedia === 'function'
      && typeof g.MediaStreamTrackProcessor === 'function'
      && typeof g.AudioEncoder === 'function'
      && typeof g.AudioEncoder.isConfigSupported === 'function'
    ),
    supportsOpus: async (config) => {
      const result = await g.AudioEncoder.isConfigSupported(encoderConfig(config));
      return result?.supported === true;
    },
    getUserMedia: (constraints) => mediaDevices.getUserMedia(constraints),
    createProcessor: (track) => new g.MediaStreamTrackProcessor({ track }),
    createEncoder: (config, { output, error }) => {
      const encoder = new g.AudioEncoder({ output, error });
      encoder.configure(encoderConfig(config));
      return encoder;
    },
  };
}
