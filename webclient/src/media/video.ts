import type { SessionStats } from '../core/contracts';
import {
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  type EncodedVideoFrame,
  type VideoFrame as ProtoVideoFrame,
} from '../gen/message';

export type EncodedCase = 'vp9s' | 'vp8s' | 'h264s' | 'h265s' | 'av1s';

// WebCodecs codec strings per VideoFrame oneof case. vp8/vp9/av1 arrive as raw
// bitstream and h264/h265 as Annex B — no `description` for any of them.
const CODEC_STRING: Record<EncodedCase, string> = {
  vp9s: 'vp09.00.10.08',
  vp8s: 'vp8',
  h264s: 'avc1.640033',
  h265s: 'hev1.1.6.L93.B0',
  av1s: 'av01.0.08M.08',
};

const MAX_DECODE_FAIL = 3;
const REFRESH_DEBOUNCE_MS = 1000;
const STATS_INTERVAL_MS = 1000;

/**
 * Backpressure: above this many chunks queued in the decoder, delta frames are
 * dropped until it drains.
 *
 * Without a cap, a device that cannot decode as fast as frames arrive grows the
 * queue without bound, and every frame is displayed later than the last — the
 * picture stays smooth while drifting further behind reality, which is worse
 * than a visible stutter because the operator does not realise they are acting
 * on a stale screen. Dropping deltas costs picture quality until the next key
 * frame and keeps latency bounded.
 */
const MAX_DECODE_QUEUE = 30;

/**
 * How long frames may arrive with nothing rendered before assuming the decoder
 * is wedged and asking the peer for a fresh key frame.
 *
 * The failure this catches is silent: chunks are accepted, decode() does not
 * throw, and no error callback fires, but no VideoFrame ever comes out. Error
 * handling never engages because nothing reports an error — the session simply
 * shows a frozen or black picture. Only comparing frames in against frames out
 * detects it.
 */
const STALL_TIMEOUT_MS = 2500;

async function probe(codec: string): Promise<boolean> {
  try {
    const res = await VideoDecoder.isConfigSupported({ codec, optimizeForLatency: true });
    return res.supported === true;
  } catch {
    return false;
  }
}

/**
 * Can this context play H.264 through Media Source Extensions?
 *
 * The fallback for origins where WebCodecs is missing. MSE is NOT
 * secure-context gated, which is the whole reason a plain-http session is
 * possible at all — see mse-video.ts.
 */
export function mseH264Available(): boolean {
  const MS = (globalThis as { MediaSource?: typeof MediaSource }).MediaSource;
  if (!MS?.isTypeSupported) return false;
  try {
    return MS.isTypeSupported('video/mp4; codecs="avc1.640033"');
  } catch {
    return false;
  }
}

export async function probeSupportedDecoding(): Promise<SupportedDecoding> {
  let vp9 = false;
  let vp8 = false;
  let h264 = false;
  let av1 = false;
  if (typeof VideoDecoder !== 'undefined') {
    [vp9, vp8, h264, av1] = await Promise.all([
      probe(CODEC_STRING.vp9s),
      probe(CODEC_STRING.vp8s),
      probe(CODEC_STRING.h264s),
      probe(CODEC_STRING.av1s),
    ]);
  } else if (mseH264Available()) {
    // No WebCodecs, but MSE can play H.264. Advertise H.264 and nothing else:
    // claiming a codec MSE cannot mux would leave the peer sending a stream
    // this client can never display.
    h264 = true;
  }
  // ability_h265 deliberately stays 0 even where 'hev1.1.6.L93.B0' probes ok:
  // hardware HEVC decoders routinely accept the config then fail on real
  // streams. Never set i444/prefer_chroma either — I420 default only.
  return SupportedDecoding.fromPartial({
    ability_vp9: vp9 ? 1 : 0,
    ability_vp8: vp8 ? 1 : 0,
    ability_h264: h264 ? 1 : 0,
    ability_av1: av1 ? 1 : 0,
    ability_h265: 0,
    prefer: SupportedDecoding_PreferCodec.Auto,
  });
}

export class VideoPipeline {
  // Set by the session: fires once per codec the pipeline disables, so a fresh
  // supported_decoding (minus disabledCodecs()) can be re-advertised.
  onNeedReadvertise: ((disabled: EncodedCase) => void) | null = null;

  private decoder: VideoDecoder | null = null;
  private currentCase: EncodedCase | null = null;
  private ctx: OffscreenCanvasRenderingContext2D | null = null;
  private awaitingKey = true;
  private failStreak = 0;
  private readonly disabled = new Set<EncodedCase>();
  private lastRefreshMs = 0;
  private framesDropped = 0;
  private readonly startedAtMs = Date.now();
  private windowStartMs = Date.now();
  private windowFrames = 0;
  private windowBytes = 0;
  private lastW = 0;
  private lastH = 0;
  private closed = false;
  // Stall detection: frames rendered out, and when the last one arrived.
  private framesOut = 0;
  private lastOutputMs = Date.now();

  constructor(
    private readonly canvas: OffscreenCanvas,
    private readonly onAck: () => void,
    private readonly onNeedRefresh: () => void,
    private readonly onStats: (s: Partial<SessionStats>) => void,
  ) {}

  disabledCodecs(): EncodedCase[] {
    return [...this.disabled];
  }

  pushFrame(vf: ProtoVideoFrame): void {
    if (this.closed || typeof VideoDecoder === 'undefined') return;
    const u = vf.union;
    if (!u) return;
    let kase: EncodedCase;
    let frames: EncodedVideoFrame[];
    switch (u.$case) {
      case 'vp9s':
        kase = 'vp9s';
        frames = u.vp9s.frames;
        break;
      case 'vp8s':
        kase = 'vp8s';
        frames = u.vp8s.frames;
        break;
      case 'h264s':
        kase = 'h264s';
        frames = u.h264s.frames;
        break;
      case 'h265s':
        kase = 'h265s';
        frames = u.h265s.frames;
        break;
      case 'av1s':
        kase = 'av1s';
        frames = u.av1s.frames;
        break;
      default:
        return; // rgb/yuv raw frames are never sent over relay to us
    }
    if (this.disabled.has(kase)) {
      this.requestRefresh();
      return;
    }
    if (!this.decoder || this.currentCase !== kase || this.decoder.state === 'closed') {
      if (!this.configure(kase)) return;
    }
    for (const f of frames) {
      if (this.awaitingKey && !f.key) {
        this.framesDropped++;
        this.requestRefresh();
        continue;
      }
      // Backpressure. Key frames always go through — dropping one would leave
      // the decoder with nothing to resynchronise on and turn a slow session
      // into a stuck one.
      if (!f.key && (this.decoder?.decodeQueueSize ?? 0) > MAX_DECODE_QUEUE) {
        this.framesDropped++;
        // Later deltas build on the one just dropped: skip them too and wait
        // for the key frame asked for here, rather than decode a smeared
        // picture until it arrives.
        this.awaitingKey = true;
        this.requestRefresh();
        continue;
      }
      try {
        this.windowBytes += f.data.byteLength;
        this.decoder!.decode(
          new EncodedVideoChunk({
            type: f.key ? 'key' : 'delta',
            timestamp: Number(f.pts) * 1000, // pts is ms; WebCodecs wants µs
            data: f.data,
          }),
        );
        this.awaitingKey = false;
        this.checkStall();
      } catch {
        this.noteFailure(kase);
        if (this.disabled.has(kase) || !this.decoder || this.decoder.state === 'closed') break;
      }
    }
  }

  reset(): void {
    this.teardownDecoder();
    this.currentCase = null;
    this.awaitingKey = true;
    this.failStreak = 0;
    // Without clearing these, a reconnect inherits the old counters and the
    // stall check fires immediately on a session that is perfectly healthy.
    this.framesOut = 0;
    this.lastOutputMs = Date.now();
  }

  close(): void {
    this.closed = true;
    this.teardownDecoder();
  }

  private configure(kase: EncodedCase): boolean {
    this.teardownDecoder();
    this.awaitingKey = true;
    this.failStreak = 0;
    try {
      const dec = new VideoDecoder({
        output: (frame) => this.handleOutput(frame),
        error: () => this.fatal(kase),
      });
      dec.configure({ codec: CODEC_STRING[kase], optimizeForLatency: true });
      this.decoder = dec;
      this.currentCase = kase;
      return true;
    } catch {
      this.fatal(kase);
      return false;
    }
  }

  private handleOutput(frame: VideoFrame): void {
    this.failStreak = 0;
    this.framesOut++;
    this.lastOutputMs = Date.now();
    try {
      const w = frame.displayWidth;
      const h = frame.displayHeight;
      if (this.canvas.width !== w || this.canvas.height !== h) {
        this.canvas.width = w;
        this.canvas.height = h;
      }
      this.ctx ??= this.canvas.getContext('2d');
      this.ctx?.drawImage(frame, 0, 0);
      this.lastW = w;
      this.lastH = h;
    } finally {
      frame.close(); // decoder-owned; must release before the next output
    }
    this.onAck();
    this.tickStats();
  }

  private noteFailure(kase: EncodedCase): void {
    this.framesDropped++;
    this.awaitingKey = true;
    this.failStreak++;
    if (this.failStreak >= MAX_DECODE_FAIL) this.fatal(kase);
    else this.requestRefresh();
  }

  private fatal(kase: EncodedCase): void {
    if (this.closed) return;
    this.teardownDecoder();
    this.currentCase = null;
    if (!this.disabled.has(kase)) {
      this.disabled.add(kase);
      this.onNeedReadvertise?.(kase);
    }
    this.requestRefresh();
  }

  private teardownDecoder(): void {
    const dec = this.decoder;
    this.decoder = null;
    if (dec && dec.state !== 'closed') {
      try {
        dec.close();
      } catch {
        // already closed by an error callback
      }
    }
  }

  private requestRefresh(): void {
    const now = Date.now();
    if (now - this.lastRefreshMs < REFRESH_DEBOUNCE_MS) return;
    this.lastRefreshMs = now;
    this.onNeedRefresh();
  }

  /**
   * Frames going in, nothing coming out — ask for a key frame.
   *
   * Gated on time since the last rendered frame, NOT on "has never produced
   * one". A decoder that works for ten minutes and then wedges is the more
   * common case, and a total-output check would never catch it. Because this
   * runs only from the decode path it cannot fire on an idle session, where no
   * chunks arrive and a frozen picture is simply a static screen.
   */
  private checkStall(): void {
    if (this.closed) return;
    if (Date.now() - this.lastOutputMs < STALL_TIMEOUT_MS) return;
    // Reset the clock so this asks once per timeout window, not once per chunk.
    this.lastOutputMs = Date.now();
    this.awaitingKey = true;
    this.requestRefresh();
  }

  private tickStats(): void {
    this.windowFrames++;
    const now = Date.now();
    const elapsed = now - this.windowStartMs;
    if (elapsed < STATS_INTERVAL_MS) return;
    this.onStats({
      codec: this.currentCase ? this.currentCase.slice(0, -1) : '',
      width: this.lastW,
      height: this.lastH,
      fps: Math.round((this.windowFrames * 1000) / elapsed),
      mbps: Math.round(((this.windowBytes * 8) / (elapsed / 1000) / 1e6) * 100) / 100,
      framesDropped: this.framesDropped,
      startedAtMs: this.startedAtMs,
    });
    this.windowStartMs = now;
    this.windowFrames = 0;
    this.windowBytes = 0;
  }
}
