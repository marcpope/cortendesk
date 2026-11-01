// RustDesk 1.5 WebRTC transport: the wire pieces that need no browser API.
//
// - The offer/answer envelope: "webrtc://" + standard base64 (padded) of the
//   UTF-8 JSON {type, sdp, ice_policy?}, with every a=candidate and
//   a=end-of-candidates line removed from the SDP (candidates trickle).
// - The session key: the offer's a=fingerprint value, verbatim. Session-level
//   attribute first, else the first one in the first application section.
// - Candidates: JSON RTCIceCandidateInit strings in rendezvous IceCandidate.
// - Framing on the data channel: each channel message is one fragment, a
//   header byte (0 = last, 1 = more) and up to 60000 payload bytes. The
//   reassembled unit is one protobuf Message, unencrypted (DTLS carries it).
import { IceCandidate, RendezvousMessage } from '../gen/rendezvous';

export const ENDPOINT_PREFIX = 'webrtc://';
export const DATA_CHANNEL_LABEL = 'bootstrap';
export const MAX_FRAGMENT_PAYLOAD = 60000;
const FRAG_END = 0;
const FRAG_MORE = 1;
/** Remote candidates held before the answer is applied; the oldest go first. */
export const MAX_PENDING_REMOTE_CANDIDATES = 64;
/** Each local candidate goes out twice: the hop to the device may be UDP. */
export const CANDIDATE_RESEND_MS = 400;
/** How long a relay that paired first waits for WebRTC before it is used. */
export const RELAY_FALLBACK_DELAY_MS = 2500;
/** Largest message we accept from the peer (the protocol's own limit). */
export const MAX_RECV_MESSAGE = 0x3fffffff;

export type Endpoint = { type: 'offer' | 'answer'; sdp: string; icePolicy?: string };

/** RTCIceServer without depending on the DOM lib (the worker has none). */
export type IceServerConfig = { urls: string; username?: string; credential?: string };

const utf8Enc = new TextEncoder();
const utf8Dec = new TextDecoder('utf-8', { fatal: true });

function toBase64(bytes: Uint8Array): string {
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  }
  return btoa(bin);
}

function fromBase64(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

/** The SDP without candidate lines, which trickle separately. */
export function stripCandidates(sdp: string): string {
  return sdp
    .split(/\r?\n/)
    .filter((l) => l !== '' && !l.startsWith('a=candidate:') && !l.startsWith('a=end-of-candidates'))
    .map((l) => `${l}\r\n`)
    .join('');
}

export function encodeEndpoint(e: Endpoint): string {
  const json: Record<string, string> = { type: e.type, sdp: stripCandidates(e.sdp) };
  if (e.icePolicy) json.ice_policy = e.icePolicy;
  return ENDPOINT_PREFIX + toBase64(utf8Enc.encode(JSON.stringify(json)));
}

/** Throws on anything that is not a well-formed envelope. */
export function decodeEndpoint(s: string): Endpoint {
  if (!s.startsWith(ENDPOINT_PREFIX)) throw new Error('not a webrtc endpoint');
  const json = JSON.parse(utf8Dec.decode(fromBase64(s.slice(ENDPOINT_PREFIX.length)))) as Record<string, unknown>;
  if ((json.type !== 'offer' && json.type !== 'answer') || typeof json.sdp !== 'string') {
    throw new Error('malformed webrtc endpoint');
  }
  const e: Endpoint = { type: json.type, sdp: json.sdp };
  if (typeof json.ice_policy === 'string') e.icePolicy = json.ice_policy;
  return e;
}

/**
 * The a=fingerprint value ("sha-256 AB:CD:..."), as written: the
 * session-level one, else the first in the first application section.
 */
export function sdpFingerprint(sdp: string): string {
  let section: 'session' | 'application' | 'other' = 'session';
  let seenApplication = false;
  for (const line of sdp.split(/\r?\n/)) {
    if (line.startsWith('m=')) {
      section = !seenApplication && line.startsWith('m=application') ? 'application' : 'other';
      if (section === 'application') seenApplication = true;
      continue;
    }
    if (line.startsWith('a=fingerprint:') && section !== 'other') {
      // A session-level value wins over any media one, so stop at the first.
      return line.slice('a=fingerprint:'.length).trim();
    }
  }
  return '';
}

/** The a=mid of the first application section, or null. */
export function applicationMid(sdp: string): string | null {
  let inApp = false;
  for (const line of sdp.split(/\r?\n/)) {
    if (line.startsWith('m=')) {
      if (inApp) return null;
      inApp = line.startsWith('m=application');
      continue;
    }
    if (inApp && line.startsWith('a=mid:')) return line.slice('a=mid:'.length).trim();
  }
  return null;
}

export type CandidateInit = { candidate: string; sdpMid?: string | null; sdpMLineIndex?: number | null; usernameFragment?: string | null };

/**
 * Should a local candidate be sent? The device turns mDNS off, so a browser's
 * ".local" host candidate can never be resolved there, and it does not use
 * TCP candidates. Both would only use up its 64-candidate queue.
 */
export function shouldSendCandidate(c: CandidateInit): boolean {
  if (!c.candidate) return false;
  const parts = c.candidate.split(' ');
  // candidate:<foundation> <component> <transport> <priority> <address> <port> typ <type> ...
  const transport = (parts[2] ?? '').toLowerCase();
  const address = parts[4] ?? '';
  return transport === 'udp' && !address.toLowerCase().endsWith('.local');
}

/**
 * A device candidate, made acceptable to a browser: an empty sdpMid is not a
 * valid mid, so point it at the answer's application section. Null when the
 * string carries no candidate.
 */
export function parseRemoteCandidate(json: string, mid: string | null): CandidateInit | null {
  let c: CandidateInit;
  try {
    c = JSON.parse(json) as CandidateInit;
  } catch {
    return null;
  }
  if (!c || typeof c.candidate !== 'string' || c.candidate === '') return null;
  const out: CandidateInit = { candidate: c.candidate };
  if (c.sdpMid) out.sdpMid = c.sdpMid;
  else if (mid !== null) out.sdpMid = mid;
  out.sdpMLineIndex = typeof c.sdpMLineIndex === 'number' ? c.sdpMLineIndex : 0;
  if (c.usernameFragment) out.usernameFragment = c.usernameFragment;
  return out;
}

export function buildIceCandidate(o: { peerId: string; sessionKey: string; candidate: string }): Uint8Array {
  return RendezvousMessage.encode({
    union: {
      $case: 'ice_candidate',
      ice_candidate: IceCandidate.fromPartial({ id: o.peerId, session_key: o.sessionKey, candidate: o.candidate }),
    },
  }).finish();
}

/** One logical message as channel messages, in order. Never interleave two. */
export function fragment(msg: Uint8Array): Uint8Array[] {
  if (msg.length === 0) return [Uint8Array.of(FRAG_END)];
  const out: Uint8Array[] = [];
  for (let off = 0; off < msg.length; off += MAX_FRAGMENT_PAYLOAD) {
    const chunk = msg.subarray(off, Math.min(off + MAX_FRAGMENT_PAYLOAD, msg.length));
    const frag = new Uint8Array(chunk.length + 1);
    frag[0] = off + chunk.length < msg.length ? FRAG_MORE : FRAG_END;
    frag.set(chunk, 1);
    out.push(frag);
  }
  return out;
}

/**
 * Joins fragments back into messages. push() returns the completed message,
 * null while one is still incomplete, and throws on a framing error, which
 * ends the session: an empty channel message (the peer's end of stream), an
 * unknown header, an empty "more" fragment, or a message over the limit.
 */
export class Reassembler {
  private parts: Uint8Array[] = [];
  private size = 0;

  constructor(private readonly limit = MAX_RECV_MESSAGE) {}

  push(frag: Uint8Array): Uint8Array | null {
    if (frag.length === 0) throw new Error('webrtc stream ended');
    const hdr = frag[0];
    const payload = frag.subarray(1);
    if (hdr !== FRAG_END && hdr !== FRAG_MORE) throw new Error(`webrtc framing error: header ${hdr}`);
    if (hdr === FRAG_MORE && payload.length === 0) throw new Error('webrtc framing error: empty fragment');
    this.size += payload.length;
    if (this.size > this.limit) throw new Error('webrtc message too large');
    if (hdr === FRAG_MORE) {
      this.parts.push(payload.slice());
      return null;
    }
    if (this.parts.length === 0) {
      this.size = 0;
      return payload.slice();
    }
    this.parts.push(payload);
    const out = new Uint8Array(this.size);
    let off = 0;
    for (const p of this.parts) {
      out.set(p, off);
      off += p.length;
    }
    this.parts = [];
    this.size = 0;
    return out;
  }
}

/**
 * The console's `ice-servers` list (the client option's format) as
 * RTCIceServer entries. Entries the RustDesk client would skip are skipped
 * here too; the console refuses them on save anyway.
 */
export function parseIceServers(list: string): IceServerConfig[] {
  const out: IceServerConfig[] = [];
  for (const raw of list.split(',')) {
    const entry = raw.trim();
    const m = /^(stuns?|turns?):(\/\/)?(.+)$/i.exec(entry);
    if (!m) continue;
    const scheme = m[1]!.toLowerCase();
    let rest = m[3]!;
    let username: string | undefined;
    let credential: string | undefined;
    if (m[2] && rest.includes('@')) {
      const at = rest.indexOf('@');
      const userinfo = rest.slice(0, at);
      rest = rest.slice(at + 1);
      const colon = userinfo.indexOf(':');
      if (colon < 0) continue;
      try {
        username = decodeURIComponent(userinfo.slice(0, colon));
        credential = decodeURIComponent(userinfo.slice(colon + 1));
      } catch {
        continue;
      }
    }
    if (scheme.startsWith('turn') && !(username && credential)) continue;
    if (/[/?#@\s]/.test(rest) || rest === '') continue;
    const bracketed = rest.startsWith('[');
    if (!bracketed && rest.split(':').length > 2) continue; // bare IPv6 has no clear port
    const port = bracketed ? /\]:(\d+)$/.exec(rest)?.[1] : /:(\d+)$/.exec(rest)?.[1];
    const hasPort = bracketed ? rest.includes(']:') : rest.includes(':');
    if (hasPort && (port === undefined || Number(port) < 1 || Number(port) > 65535)) continue;
    const server: IceServerConfig = { urls: `${scheme}:${hasPort ? rest : `${rest}:3478`}` };
    if (username !== undefined) {
      server.username = username;
      server.credential = credential;
    }
    out.push(server);
  }
  return out;
}

/** What the browser uses when the console sets no ICE servers. */
export const DEFAULT_ICE_SERVERS: IceServerConfig[] = [
  { urls: 'stun:stun.cloudflare.com:3478' },
  { urls: 'stun:stun.l.google.com:19302' },
];
