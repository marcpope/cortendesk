// Remembered session settings (issue #92). Display and media choices made in
// the session menus are saved as global defaults (not per device) and applied
// to the next connection, including the options sent in the LoginRequest.
//
// localStorage can throw (private windows, blocked storage), so every access
// is guarded and a page-lifetime copy is kept in memory as the fallback.
import type { InitialSessionOptions, SessionConfig, UiCommand } from '../core/contracts';
import { SupportedDecoding_PreferCodec } from '../gen/message';
import { QUALITY } from './common';

export const CODECS = ['auto', 'vp9', 'h264', 'h265', 'vp8', 'av1'] as const;
export type CodecName = (typeof CODECS)[number];

export type SessionPrefs = {
  /** Image quality preset (ImageQuality enum value). */
  quality: number;
  /** Custom quality slider, 10-100. */
  customQuality: number;
  /** The slider was moved after the last preset pick, so it is what the peer uses. */
  customQualityActive: boolean;
  /** Custom FPS slider, 5-120 in steps of 5. Also the adaptive FPS ceiling. */
  customFps: number;
  adaptiveFps: boolean;
  codec: CodecName;
  fitMode: 'fit' | 'actual';
  showRemoteCursor: boolean;
  followRemoteCursor: boolean;
  followRemoteWindow: boolean;
  /** 1 or 2 (Enlarge remote cursor). */
  cursorScale: number;
  clipboard: boolean;
  remoteAudio: boolean;
  audioMuted: boolean;
  /** Playback volume, 0-1. */
  audioVolume: number;
};

export const DEFAULT_PREFS: Readonly<SessionPrefs> = Object.freeze({
  quality: QUALITY.balanced,
  customQuality: 75,
  customQualityActive: false,
  customFps: 30,
  adaptiveFps: false,
  codec: 'auto',
  fitMode: 'fit',
  showRemoteCursor: false,
  followRemoteCursor: false,
  followRemoteWindow: false,
  cursorScale: 1,
  clipboard: true,
  remoteAudio: true,
  audioMuted: false,
  audioVolume: 1,
});

export const PREFS_KEY = 'cortendesk.rdprefs.v1';

type PrefsStore = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

let memory: SessionPrefs | undefined;

function defaultStore(): PrefsStore | undefined {
  try {
    return globalThis.localStorage ?? undefined;
  } catch {
    return undefined; // the getter itself throws when storage is blocked
  }
}

function bool(v: unknown, fallback: boolean): boolean {
  return typeof v === 'boolean' ? v : fallback;
}

function intIn(v: unknown, min: number, max: number, fallback: number, step = 1): number {
  return typeof v === 'number' && Number.isInteger(v) && v >= min && v <= max && v % step === 0 ? v : fallback;
}

/** Coerce anything into valid prefs; each unknown or invalid field takes its default. */
export function validatePrefs(raw: unknown): SessionPrefs {
  const d = DEFAULT_PREFS;
  const r = raw !== null && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, unknown>) : {};
  const qualities: number[] = [QUALITY.best, QUALITY.balanced, QUALITY.speed];
  return {
    quality: typeof r.quality === 'number' && qualities.includes(r.quality) ? r.quality : d.quality,
    customQuality: intIn(r.customQuality, 10, 100, d.customQuality),
    customQualityActive: bool(r.customQualityActive, d.customQualityActive),
    customFps: intIn(r.customFps, 5, 120, d.customFps, 5),
    adaptiveFps: bool(r.adaptiveFps, d.adaptiveFps),
    codec: typeof r.codec === 'string' && (CODECS as readonly string[]).includes(r.codec) ? (r.codec as CodecName) : d.codec,
    fitMode: r.fitMode === 'fit' || r.fitMode === 'actual' ? r.fitMode : d.fitMode,
    showRemoteCursor: bool(r.showRemoteCursor, d.showRemoteCursor),
    followRemoteCursor: bool(r.followRemoteCursor, d.followRemoteCursor),
    followRemoteWindow: bool(r.followRemoteWindow, d.followRemoteWindow),
    cursorScale: r.cursorScale === 1 || r.cursorScale === 2 ? r.cursorScale : d.cursorScale,
    clipboard: bool(r.clipboard, d.clipboard),
    remoteAudio: bool(r.remoteAudio, d.remoteAudio),
    audioMuted: bool(r.audioMuted, d.audioMuted),
    audioVolume: typeof r.audioVolume === 'number' && Number.isFinite(r.audioVolume) && r.audioVolume >= 0 && r.audioVolume <= 1
      ? r.audioVolume
      : d.audioVolume,
  };
}

export function loadPrefs(store: PrefsStore | undefined = defaultStore()): SessionPrefs {
  let raw: string | null = null;
  try {
    raw = store?.getItem(PREFS_KEY) ?? null;
  } catch {
    raw = null;
  }
  if (raw === null) return { ...(memory ?? DEFAULT_PREFS) };
  try {
    return validatePrefs(JSON.parse(raw));
  } catch {
    return { ...DEFAULT_PREFS }; // corrupt JSON
  }
}

export function savePrefs(prefs: SessionPrefs, store: PrefsStore | undefined = defaultStore()): void {
  const valid = validatePrefs(prefs);
  memory = valid;
  try {
    store?.setItem(PREFS_KEY, JSON.stringify(valid));
  } catch {
    /* storage unavailable or full: the in-memory copy still covers this page */
  }
}

export function resetPrefs(store: PrefsStore | undefined = defaultStore()): SessionPrefs {
  memory = undefined;
  try {
    store?.removeItem(PREFS_KEY);
  } catch {
    /* non-fatal */
  }
  return { ...DEFAULT_PREFS };
}

/** Test hook: forget the in-memory fallback. */
export function clearPrefsMemory(): void {
  memory = undefined;
}

const PREFER: Record<CodecName, SupportedDecoding_PreferCodec> = {
  auto: SupportedDecoding_PreferCodec.Auto,
  vp9: SupportedDecoding_PreferCodec.VP9,
  h264: SupportedDecoding_PreferCodec.H264,
  h265: SupportedDecoding_PreferCodec.H265,
  vp8: SupportedDecoding_PreferCodec.VP8,
  av1: SupportedDecoding_PreferCodec.AV1,
};

/**
 * The peer-side options to send at login. Only values that differ from the
 * built-in defaults are included, so a browser with nothing remembered sends
 * exactly what it sent before this existed and the peer keeps its own defaults.
 *
 * Quality follows the peer's rule that the last quality message wins: the
 * custom value is sent only when the slider was the last thing touched.
 */
export function initialOptionsFor(p: SessionPrefs): InitialSessionOptions {
  const d = DEFAULT_PREFS;
  const out: InitialSessionOptions = {};
  if (p.customQualityActive) out.customImageQuality = p.customQuality;
  else if (p.quality !== d.quality) out.imageQuality = p.quality;
  if (p.customFps !== d.customFps) out.customFps = p.customFps;
  if (p.codec !== d.codec) out.preferCodec = PREFER[p.codec];
  if (p.showRemoteCursor || p.followRemoteCursor) out.showRemoteCursor = true;
  if (p.followRemoteCursor) out.followRemoteCursor = true;
  if (p.followRemoteWindow) out.followRemoteWindow = true;
  if (!p.clipboard) out.disableClipboard = true;
  if (!p.remoteAudio) out.disableAudio = true;
  return out;
}

/** A desktop connection's config with the remembered options attached; other connection types are returned as is. */
export function withRememberedOptions(config: SessionConfig, prefs: SessionPrefs): SessionConfig {
  return (config.connType ?? 'default') === 'default'
    ? { ...config, initialOptions: initialOptionsFor(prefs) }
    : config;
}

/**
 * Commands that move a live session from `live` back to `defaults`. Only the
 * peer-side settings that differ are sent; local ones (scale, cursor size,
 * playback volume) need no message.
 */
export function resetCommands(live: SessionPrefs, defaults: SessionPrefs = DEFAULT_PREFS): UiCommand[] {
  const out: UiCommand[] = [];
  if (live.customQualityActive !== defaults.customQualityActive || live.quality !== defaults.quality) {
    out.push({ c: 'quality', imageQuality: defaults.quality });
  }
  if (live.customFps !== defaults.customFps || live.adaptiveFps !== defaults.adaptiveFps) {
    out.push({ c: 'customFps', fps: defaults.customFps });
  }
  if (live.codec !== defaults.codec) out.push({ c: 'preferredCodec', prefer: PREFER[defaults.codec] });
  for (const option of ['showRemoteCursor', 'followRemoteCursor', 'followRemoteWindow'] as const) {
    if (live[option] !== defaults[option]) out.push({ c: 'displayOption', option, enabled: defaults[option] });
  }
  if (live.clipboard !== defaults.clipboard) out.push({ c: 'clipboardEnabled', enabled: defaults.clipboard });
  if (live.remoteAudio !== defaults.remoteAudio) out.push({ c: 'remoteAudio', enabled: defaults.remoteAudio });
  return out;
}
