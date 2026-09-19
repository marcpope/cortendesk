// Shared UI helpers for the desktop client (app.ts) and the file-transfer
// window (file-app.ts): icons, state labels, saved-credential storage, session
// config plumbing. app.ts re-exports everything here for back-compat.
import type { DisplayInfo, SessionConfig, SessionState, UiCommand } from '../core/contracts';
import type { DisplayRect } from '../input/mouse-keyboard';

export type RdGlobalConfig = {
  peerId?: string;
  serverKeyB64: string;
  wsIdUrl: string;
  wsRelayUrl: string;
  myId: string;
  myName: string;
  /** Console version, injected per request — see OVERLAY_VERSION. */
  version?: string;
  workerUrl?: string;
  osLoginUrl?: string;
  csrfToken?: string;
};

export const QUALITY = { best: 4, balanced: 3, speed: 2 } as const; // ImageQuality enum values

/**
 * Fallback only. The real version is injected per request by the console
 * (window.__RD__.version) and read via overlayVersion() below.
 *
 * The web client is not released independently — it only ships inside a
 * CortenDesk release — so it carries the project version rather than one of
 * its own. Taking it at runtime matters: the bundle is rebuilt only when
 * webclient/src changes, so a version baked in at build time would still claim
 * the older release on every release that did not touch the client.
 */
export const OVERLAY_VERSION = 'dev';

export function overlayVersion(cfg?: { version?: string } | null): string {
  const v = cfg?.version?.trim();
  return v ? `v${v}` : OVERLAY_VERSION;
}

export const STATE_LABEL: Record<SessionState, string> = {
  connecting: 'Connecting',
  rendezvous: 'Contacting server',
  relay: 'Opening relay',
  handshake: 'Securing channel',
  login: 'Authenticating',
  streaming: 'Connected',
  error: 'Error',
  closed: 'Disconnected',
  needAccept: 'Waiting for remote user to accept',
};

export function formatDuration(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

export function formatMbps(mbps: number): string {
  if (!Number.isFinite(mbps) || mbps < 0) return '0.00 Mbps';
  const n = mbps >= 100 ? mbps.toFixed(0) : mbps >= 10 ? mbps.toFixed(1) : mbps.toFixed(2);
  return `${n} Mbps`;
}

/**
 * Strip whitespace from a device ID.
 *
 * The RustDesk client displays IDs grouped as "123 456 789", so that is what
 * people copy and type. The spaces are presentation only — the ID is
 * "123456789" — and an ID with them in it simply is not found.
 *
 * Whitespace only, deliberately: IDs are not always numeric (a device can be
 * given a custom alphanumeric ID), so stripping anything broader would break
 * legitimate IDs. \s covers the non-breaking space that copying from a
 * rendered UI can produce.
 */
export function normalizePeerId(raw: string): string {
  return raw.replace(/\s+/g, '');
}

export function peerIdFromSearch(search: string): string | null {
  const id = normalizePeerId(new URLSearchParams(search).get('id') ?? '');
  return id ? id : null;
}

// ?lo=1 marks "the user logged out on purpose" — it suppresses saved-password
// auto-login until the next explicit connect. Set by the disconnect button so
// a reload after logging out stays on the connect screen.
export function loggedOutFromSearch(search: string): boolean {
  return new URLSearchParams(search).get('lo') === '1';
}

export function resolveWorkerUrl(
  cfgUrl: string | undefined,
  attrUrl: string | null | undefined,
  baseUrl: string,
): string {
  if (cfgUrl) return cfgUrl;
  if (attrUrl) return attrUrl;
  return new URL('session.worker.js', baseUrl).href;
}

export function cursorCss(pngDataUrl: string, hotx: number, hoty: number): string {
  const x = Math.max(0, Math.round(hotx));
  const y = Math.max(0, Math.round(hoty));
  return `url("${pngDataUrl}") ${x} ${y}, auto`;
}

// "Save password" persists only h1 = SHA256(pw||salt) (hex), keyed by device —
// never the plaintext. h1 is a per-device login credential (same thing the
// native RustDesk client stores), so it is scoped per peerId.
const SAVED_PW_PREFIX = 'cortendesk.rdpw.';
export function loadSavedHash(peerId: string): string | null {
  try {
    return localStorage.getItem(SAVED_PW_PREFIX + peerId);
  } catch {
    return null;
  }
}
export function saveSavedHash(peerId: string, hashHex: string): void {
  try {
    localStorage.setItem(SAVED_PW_PREFIX + peerId, hashHex);
  } catch {
    /* storage unavailable / full — non-fatal */
  }
}
export function clearSavedHash(peerId: string): void {
  try {
    localStorage.removeItem(SAVED_PW_PREFIX + peerId);
  } catch {
    /* non-fatal */
  }
}

export function buildSessionConfig(
  g: RdGlobalConfig,
  peerId: string,
  password: string,
  savedHashHex?: string,
  connType?: SessionConfig['connType'],
  osPassword?: string,
): SessionConfig {
  return {
    peerId,
    serverKeyB64: g.serverKeyB64,
    wsIdUrl: g.wsIdUrl,
    wsRelayUrl: g.wsRelayUrl,
    password,
    myId: g.myId,
    myName: g.myName,
    savedHashHex,
    ...(connType ? { connType } : {}),
    ...(osPassword ? { osPassword } : {}),
  };
}

export function displayToRect(d: DisplayInfo): DisplayRect {
  return { x: d.x, y: d.y, width: d.width, height: d.height };
}

export function placePopover(
  anchor: { left: number; top: number; bottom: number; width: number },
  popover: { width: number; height: number },
  viewport: { width: number; height: number },
  preferAbove = false,
): { left: number; top: number; maxHeight: number } {
  const margin = 8;
  const gap = 10;
  const belowTop = anchor.bottom + gap;
  const aboveBottom = anchor.top - gap;
  const belowSpace = Math.max(0, viewport.height - margin - belowTop);
  const aboveSpace = Math.max(0, aboveBottom - margin);
  const preferredSpace = preferAbove ? aboveSpace : belowSpace;
  const alternateSpace = preferAbove ? belowSpace : aboveSpace;
  const openAbove = popover.height > preferredSpace && alternateSpace > preferredSpace
    ? !preferAbove
    : preferAbove;
  const maxHeight = Math.max(0, Math.floor(openAbove ? aboveSpace : belowSpace));
  const visibleHeight = Math.min(Math.max(0, popover.height), maxHeight);
  const rawTop = openAbove ? aboveBottom - visibleHeight : belowTop;
  const maxTop = Math.max(margin, viewport.height - margin - visibleHeight);
  const top = Math.max(margin, Math.min(rawTop, maxTop));
  const centeredLeft = anchor.left + anchor.width / 2 - popover.width / 2;
  const maxLeft = Math.max(margin, viewport.width - popover.width - margin);
  const left = Math.max(margin, Math.min(centeredLeft, maxLeft));
  return { left: Math.round(left), top: Math.round(top), maxHeight };
}

/**
 * Is diagnostic logging on?
 *
 * Enabled by `?debug=1` or by setting `window.__rdDebug = true` at runtime, so
 * a session already in progress can be instrumented without a reload — which
 * matters when the thing being diagnosed only happens once you are connected.
 *
 * Off by default: these logs fire per input event, and a remote session
 * generates a lot of those.
 */
export function debugEnabled(search: string, win: unknown): boolean {
  if ((win as { __rdDebug?: unknown } | undefined)?.__rdDebug === true) return true;
  const v = new URLSearchParams(search).get('debug');
  return v === '1' || v === 'true';
}

/**
 * Fold the host's Misc.switch_display into the display list, in place.
 *
 * The host's geometry wins over the PeerInfo snapshot: PeerInfo is taken at
 * login and can be stale by the time anyone switches monitors (resolution
 * changed, displays re-arranged, a monitor that was offline then). Since input
 * coordinates are absolute virtual-desktop positions, a stale origin sends
 * every click to the wrong monitor.
 *
 * Zero width/height mean "unchanged" rather than "collapse this display" —
 * writing them through would leave the coordinate mapping dividing by a size
 * the capture is not actually using.
 */
export function applySwitchDisplay(
  displays: DisplayInfo[],
  ev: {
    index: number; x: number; y: number; width: number; height: number;
    cursorEmbedded?: boolean;
    originalResolution?: { width: number; height: number };
    resolutions?: Array<{ width: number; height: number }>;
  },
): void {
  const d = displays[ev.index];
  if (!d) return;
  d.x = ev.x;
  d.y = ev.y;
  if (ev.width > 0) d.width = ev.width;
  if (ev.height > 0) d.height = ev.height;
  if (ev.cursorEmbedded !== undefined) d.cursorEmbedded = ev.cursorEmbedded;
  if (ev.originalResolution) d.originalResolution = ev.originalResolution;
  if (ev.resolutions) d.resolutions = ev.resolutions;
}

export type IconName =
  | 'pointer'
  | 'touch'
  | 'typeText'
  | 'chat'
  | 'more'
  | 'check'
  | 'chevronDown'
  | 'info'
  | 'send'
  | 'fullscreen'
  | 'fullscreenExit'
  | 'monitor'
  | 'keyboard'
  | 'refresh'
  | 'clipboard'
  | 'pulse'
  | 'power'
  | 'folderTransfer'
  | 'folder'
  | 'folderOpen'
  | 'file'
  | 'drive'
  | 'arrowUp'
  | 'home'
  | 'newFolder'
  | 'trash'
  | 'rename'
  | 'sendRight'
  | 'sendLeft'
  | 'fileUpload'
  | 'eye'
  | 'eyeOff'
  | 'close';

export const ICONS: Record<IconName, { ri: string; svg: string }> = {
  pointer: {
    ri: '',
    svg: '<path d="M5 3.5 19 10.8l-6.1 1.6L9.2 18 5 3.5Z"/>',
  },
  touch: {
    ri: '',
    svg: '<path d="M9 11.5V5a1.7 1.7 0 0 1 3.4 0v5.2M12.4 10.2V8.8a1.7 1.7 0 0 1 3.4 0v2.4M15.8 11.2v-.6a1.6 1.6 0 0 1 3.2.3c0 4.3-.8 6-2 7.6-1 1.4-2.6 2-4.6 2-2.9 0-4-1-6.2-4.6L4.7 13.9a1.5 1.5 0 0 1 2.5-1.6L9 14.6"/>',
  },
  typeText: {
    ri: '',
    svg: '<path d="M5 6.5V5h14v1.5M12 5v14M9.5 19h5"/>',
  },
  chat: {
    ri: '',
    svg: '<path d="M4 5.5h16v11H10l-4.5 3.6v-3.6H4v-11Z"/>',
  },
  more: {
    ri: '',
    svg: '<circle cx="5.5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="18.5" cy="12" r="1"/>',
  },
  check: {
    ri: '',
    svg: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  },
  chevronDown: {
    ri: '',
    svg: '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/>',
  },
  info: {
    ri: '',
    svg: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 7.8h.01"/>',
  },
  send: {
    ri: '',
    svg: '<path d="M4 11.5 20 4l-4.5 16-4-6.5L4 11.5ZM11.5 13.5 20 4"/>',
  },
  fullscreen: {
    ri: 'ri-fullscreen-line',
    svg: '<path d="M8 3H4a1 1 0 0 0-1 1v4M16 3h4a1 1 0 0 1 1 1v4M8 21H4a1 1 0 0 1-1-1v-4M16 21h4a1 1 0 0 0 1-1v-4"/>',
  },
  fullscreenExit: {
    ri: 'ri-fullscreen-exit-line',
    svg: '<path d="M9 3v5a1 1 0 0 1-1 1H3M15 3v5a1 1 0 0 0 1 1h5M9 21v-5a1 1 0 0 0-1-1H3M15 21v-5a1 1 0 0 1 1-1h5"/>',
  },
  monitor: {
    ri: 'ri-computer-line',
    svg: '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M9 20h6M12 16v4"/>',
  },
  keyboard: {
    ri: 'ri-keyboard-box-line',
    svg: '<rect x="2" y="6" width="20" height="12" rx="1"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>',
  },
  refresh: {
    ri: 'ri-refresh-line',
    svg: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
  },
  clipboard: {
    ri: 'ri-clipboard-line',
    svg: '<rect x="5" y="5" width="14" height="16" rx="1"/><rect x="9" y="3" width="6" height="4" rx="1"/>',
  },
  pulse: {
    ri: 'ri-pulse-line',
    svg: '<polyline points="3 12 7 12 10 5 14 19 17 12 21 12"/>',
  },
  power: {
    ri: 'ri-shut-down-line',
    svg: '<path d="M12 3v8M6.2 6.2a8 8 0 1 0 11.6 0"/>',
  },
  folderTransfer: {
    ri: 'ri-folder-transfer-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/><path d="M9 13h6M13 10.5 15.5 13 13 15.5"/>',
  },
  folder: {
    ri: 'ri-folder-3-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/>',
  },
  folderOpen: {
    ri: 'ri-folder-open-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h8a1 1 0 0 1 1 1v2M3 19l2.4-8.5A1 1 0 0 1 6.4 9.8H21a1 1 0 0 1 1 1.2L19.8 19a1 1 0 0 1-1 .8H4a1 1 0 0 1-1-.8Z"/>',
  },
  file: {
    ri: 'ri-file-line',
    svg: '<path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/>',
  },
  drive: {
    ri: 'ri-hard-drive-2-line',
    svg: '<rect x="3" y="13" width="18" height="7" rx="1"/><path d="M5 13 8 5h8l3 8M17 16.5h.01"/>',
  },
  arrowUp: {
    ri: 'ri-arrow-up-line',
    svg: '<path d="M12 20V4M5 11l7-7 7 7"/>',
  },
  home: {
    ri: 'ri-home-4-line',
    svg: '<path d="M4 11 12 4l8 7M6 10v10h12V10"/>',
  },
  newFolder: {
    ri: 'ri-folder-add-line',
    svg: '<path d="M3 7V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/><path d="M12 10.5v5M9.5 13h5"/>',
  },
  trash: {
    ri: 'ri-delete-bin-line',
    svg: '<path d="M4 7h16M9 7V4h6v3M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/>',
  },
  rename: {
    ri: 'ri-edit-line',
    svg: '<path d="m14.5 5.5 4 4L8 20H4v-4L14.5 5.5ZM12.5 7.5l4 4"/>',
  },
  sendRight: {
    ri: 'ri-arrow-right-line',
    svg: '<path d="M4 12h15M13 6l6 6-6 6"/>',
  },
  sendLeft: {
    ri: 'ri-arrow-left-line',
    svg: '<path d="M20 12H5M11 6l-6 6 6 6"/>',
  },
  fileUpload: {
    ri: 'ri-file-upload-line',
    svg: '<path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5M12 17v-6M9.5 13.5 12 11l2.5 2.5"/>',
  },
  eye: {
    ri: 'ri-eye-line',
    svg: '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/>',
  },
  eyeOff: {
    ri: 'ri-eye-off-line',
    svg: '<path d="M4 4l16 16M9.9 5.9A10.6 10.6 0 0 1 12 5.5c6.4 0 10 6.5 10 6.5a17.5 17.5 0 0 1-3.2 3.9M6.1 8A16.9 16.9 0 0 0 2 12s3.6 6.5 10 6.5c1 0 2-.15 2.9-.42"/>',
  },
  close: {
    ri: 'ri-close-line',
    svg: '<path d="M6 6l12 12M18 6 6 18"/>',
  },
};

let remixAvailable: boolean | undefined;
function hasRemixIcons(): boolean {
  if (remixAvailable === undefined) {
    try {
      remixAvailable = typeof document !== 'undefined' && !!document.fonts?.check('16px remixicon');
    } catch {
      remixAvailable = false;
    }
  }
  return remixAvailable;
}

export function iconHtml(name: IconName): string {
  const ic = ICONS[name];
  // An entry with no ri class always renders its inline svg — used for icons
  // whose Remix name is not stable across the font versions installs carry.
  if (ic.ri && hasRemixIcons()) return `<i class="${ic.ri}"></i>`;
  return (
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
    `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ic.svg}</svg>`
  );
}

/**
 * Translate literal text into the key events that type it on the peer.
 *
 * `press: true` is RustDesk's "down and up in one message" — no held state to
 * leave stuck if the session drops mid-string. ASCII goes as `chr`, anything
 * beyond as `unicode`, and the two control characters that matter in pasted
 * text (newline, tab) as their control keys. ControlKey values: Return=27,
 * Tab=31 — fixed by the protobuf, not by us. Carriage returns are dropped so
 * CRLF text does not double-newline.
 */
export function buildTypeCommands(text: string): UiCommand[] {
  const out: UiCommand[] = [];
  const press = (keyKind: 'chr' | 'control' | 'unicode', value: number): void => {
    out.push({ c: 'key', down: false, press: true, keyKind, value, modifiers: [] });
  };
  for (const ch of text) {
    if (ch === '\r') continue;
    if (ch === '\n') { press('control', 27); continue; }
    if (ch === '\t') { press('control', 31); continue; }
    const cp = ch.codePointAt(0);
    if (cp === undefined) continue;
    press(cp <= 127 ? 'chr' : 'unicode', cp);
  }
  return out;
}

export function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
