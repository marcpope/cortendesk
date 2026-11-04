import type { DisplayInfo, Encryptor, SessionConfig, SessionEvent, SessionState } from './contracts';
import {
  ChatMessage,
  Clipboard,
  ClipboardFormat,
  ControlKey,
  CursorData,
  FileAction,
  FileResponse,
  FileTransferSendConfirmRequest,
  ImageQuality,
  KeyEvent,
  KeyboardMode,
  Message,
  Misc,
  MouseEvent,
  OptionMessage,
  OptionMessage_BoolOption,
  PeerInfo,
  PermissionInfo_Permission,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  CaptureDisplays,
  DisplayResolution,
  Resolution,
  SwitchDisplay,
  ToggleVirtualDisplay,
  VideoFrame,
} from '../gen/message';
import { ConnType } from '../gen/rendezvous';
import { PlainChannel, StreamCipher, decodeB64 } from './crypto';
import { buildPublicKeyMessage, verifyPeerSignedId, verifyServerRelayPk } from './handshake';
import { buildLoginRequest, computeLoginH1, loginHashFromH1 } from './auth';
import { buildPunchHoleRequest, parseRendezvous } from './signaling';
import { buildRequestRelay } from './relay';
import {
  CANDIDATE_RESEND_MS,
  MAX_PENDING_REMOTE_CANDIDATES,
  RELAY_FALLBACK_DELAY_MS,
  applicationMid,
  buildIceCandidate,
  decodeEndpoint,
  parseRemoteCandidate,
  sdpFingerprint,
  shouldSendCandidate,
  type CandidateInit,
} from './webrtc';
import {
  buildBlockInputOption,
  buildDirectElevation,
  buildLockAfterSessionEndOption,
  buildPrivacyToggle,
  buildRestartRemoteDevice,
  parsePrivacyModeImpls,
} from './session-controls';
import { parseAdvancedPeerCapabilities } from './advanced-capabilities';
import { hasText, tl } from '../i18n';

export const CLIENT_VERSION = '1.4.0';

// Per-frame flow control, the default. With ack ON the host sends one frame
// and waits for our video_received before capturing the next: it can never
// flood the decoder, but it caps the frame rate at one frame per relay round
// trip (about 10 fps at 100 ms). The worker turns it off for the WebCodecs
// path, whose decoder drops frames when its queue backs up (media/video.ts);
// the MSE fallback has no such queue and keeps it on. The native client never
// asks for acks.
export const VIDEO_ACK_REQUIRED = true;
// Drop real-time voice frames before the relay queue can grow without bound.
export const VOICE_RELAY_BUFFER_LIMIT = 128 * 1024;

export interface SessionSinks {
  sendSignaling(bytes: Uint8Array): void;
  sendRelay(bytes: Uint8Array): void;
  relayBuffered(): number;
  emit(ev: SessionEvent): void;
  onVideo(frame: VideoFrame): void;
  onAudioFormat(sampleRate: number, channels: number): void;
  onAudioFrame(data: Uint8Array): void;
  openRelay(relayServer: string): void;
  closeAll(): void;
  onCursor?(cursor: CursorData): void;
  onCursorId?(id: bigint): void;
  onClipboard?(clipboard: Clipboard): void; // compressed or non-text payloads the worker must decode
  onFileResponse?(fr: FileResponse): void; // file-transfer connections: dir/block/digest/done/error
  onFileSendConfirm?(c: FileTransferSendConfirmRequest): void; // upload go-ahead from the peer
  // RustDesk 1.5 WebRTC, all optional: without them the session is relay-only.
  webrtcAnswer?(sdp: string): void; // apply the device's answer
  webrtcRemoteCandidate?(c: CandidateInit): void; // after the answer, in order
  webrtcClose?(): void; // WebRTC lost the race or failed: close the peer connection
  selectTransport?(t: 'relay' | 'webrtc'): void; // where sendRelay() goes from now on
  closeRelay?(): void; // WebRTC is secured; the raced relay is no longer needed
  // Address-book sign-in: h1 (hex) for this device's salt from the console, or
  // null when there is none. Only wired when config.addressBook is set.
  addressBookHash?(salt: string): Promise<string | null>;
}

/** An offer made by the page before start(): its envelope and session key. */
export type WebRtcOffer = { endpoint: string; sessionKey: string };

type RtcRace = {
  sessionKey: string;
  /** The device's own fingerprint, from its answer; the signed one must match. */
  answerFp: string;
  mid: string | null;
  answered: boolean;
  /** Race decided or WebRTC abandoned: no more candidates either way. */
  done: boolean;
  localQueue: string[];
  remoteQueue: string[];
  timers: Array<ReturnType<typeof setTimeout>>;
};

const utf8Enc = new TextEncoder();
const utf8Dec = new TextDecoder();

function bytesToHex(b: Uint8Array): string {
  let s = '';
  for (const x of b) s += x.toString(16).padStart(2, '0');
  return s;
}
function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length >> 1);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
  return out;
}

function randomSessionId(): bigint {
  const b = new Uint8Array(8);
  globalThis.crypto.getRandomValues(b);
  b[0]! &= 0x7f; // keep below 2^63: safe for both int64 and uint64 writers
  return new DataView(b.buffer).getBigUint64(0);
}

// Core baseline: VP9/VP8 only. Codec probing (h264/h265/av1, i444) is layered
// on by the worker via its own SupportedDecoding once WebCodecs support is known.
function baselineDecoding(): SupportedDecoding {
  return SupportedDecoding.fromPartial({
    ability_vp9: 1,
    ability_vp8: 1,
    prefer: SupportedDecoding_PreferCodec.Auto,
  });
}

// Replies that mean "a person on the remote side has to click Accept", not
// "your credentials were refused". "No Password Access" is what a peer in
// click-to-approve mode sends to clients 1.2.0+ right after it has opened its
// accept prompt (issue #98); treating it as a failure tore the socket down and
// took the prompt with it.
const NO_PASSWORD_ACCESS = 'No Password Access';

// The device's 2FA replies (client.rs REQUIRE_2FA, LOGIN_MSG_2FA_WRONG). It
// keeps the connection open after either and waits for an Auth2FA message.
const REQUIRE_2FA = '2FA Required';
const WRONG_2FA = 'Wrong 2FA Code';

function isManualAccept(error: string): boolean {
  if (error === NO_PASSWORD_ACCESS) return true;
  const e = error.toLowerCase();
  return e.includes('wait') && e.includes('accept');
}

/**
 * A host sends a PeerInfo carrying only the display list whenever its displays
 * change (display_service.rs) — on Wayland that happens right after login.
 * Fill in who the peer is and what it supports from the login PeerInfo, or the
 * UI loses its platform and version (and with them Map keyboard mode) and its
 * privacy/terminal/camera features.
 */
export function withLoginPeerInfo(pi: PeerInfo, login: PeerInfo | undefined): PeerInfo {
  if (pi.platform || !login) return pi;
  return {
    ...pi,
    username: login.username,
    hostname: login.hostname,
    platform: login.platform,
    version: login.version,
    features: pi.features ?? login.features,
    platform_additions: pi.platform_additions || login.platform_additions,
  };
}

export class Session {
  /** Display index we asked for and have not had confirmed yet; null when settled. */
  private pendingDisplay: number | null = null;
  /** The last PeerInfo that said who the peer is (the login one). */
  private loginPeerInfo: PeerInfo | undefined;

  private readonly config: SessionConfig;
  private readonly sinks: SessionSinks;
  private readonly serverEdPk: Uint8Array;
  private readonly sessionId: bigint;
  private state: SessionState = 'connecting';
  private cipher: Encryptor | undefined;
  private peerEdPk: Uint8Array | undefined;
  private uuid = '';
  private decoding: SupportedDecoding = baselineDecoding();
  private loginSent = false;
  /** Only an exact response to this controller-generated timestamp can start capture. */
  private pendingVoiceCallTimestamp: bigint | null = null;
  private voiceCallAccepted = false;
  // WebRTC race state; undefined when no offer was made.
  private rtc: RtcRace | undefined;
  /** The path the handshake runs on; undefined while the race is open. */
  private transport: 'relay' | 'webrtc' | undefined;
  /** The relay paired (the device's SignedId arrived) while WebRTC could still win. */
  private heldRelayFrame: Uint8Array | undefined;
  /** Arrival order of the held results (0 = none); the first one wins. */
  private heldRelayAt = 0;
  /** WebRTC connected through TURN, which waits like a relay. */
  private heldTurnAt = 0;
  private heldSeq = 0;
  private heldRtcFrame: Uint8Array | undefined;
  private raceTimer: ReturnType<typeof setTimeout> | undefined;
  private relayGone = false;

  constructor(config: SessionConfig, sinks: SessionSinks) {
    this.config = config;
    this.sinks = sinks;
    this.serverEdPk = decodeB64(config.serverKeyB64);
    this.sessionId = config.sessionId ? BigInt(config.sessionId) : randomSessionId();
  }

  get currentState(): SessionState {
    return this.state;
  }

  private get videoAck(): boolean {
    return this.config.videoAck ?? VIDEO_ACK_REQUIRED;
  }

  private get connType(): ConnType {
    switch (this.config.connType) {
      case 'fileTransfer': return ConnType.FILE_TRANSFER;
      case 'viewCamera': return ConnType.VIEW_CAMERA;
      case 'terminal': return ConnType.TERMINAL;
      default: return ConnType.DEFAULT_CONN;
    }
  }

  start(offer?: WebRtcOffer): void {
    if (offer) {
      this.rtc = {
        sessionKey: offer.sessionKey,
        answerFp: '',
        mid: null,
        answered: false,
        done: false,
        localQueue: [],
        remoteQueue: [],
        timers: [],
      };
    }
    this.sinks.sendSignaling(
      buildPunchHoleRequest({
        peerId: this.config.peerId,
        licenceKey: this.config.serverKeyB64,
        version: CLIENT_VERSION,
        connType: this.connType,
        token: this.config.signalToken,
        webrtcOffer: offer?.endpoint,
      }),
    );
    this.setState('rendezvous');
  }

  onSignalingBytes(b: Uint8Array): void {
    if (this.state === 'error' || this.state === 'closed') return;
    let parsed;
    try {
      parsed = parseRendezvous(b);
    } catch {
      this.fail(tl('error_malformed_rendezvous'));
      return;
    }
    switch (parsed.kind) {
      case 'relayResponse': {
        if (!parsed.pk || parsed.pk.length === 0) {
          this.fail(tl('error_relay_response_unsigned'));
          return;
        }
        try {
          this.peerEdPk = verifyServerRelayPk(parsed.pk, this.serverEdPk, this.config.peerId);
        } catch (e) {
          this.fail(tl('error_server_trust_failed', { message: (e as Error).message }));
          return;
        }
        if (this.uuid !== '') return; // a retried request's second reply
        this.uuid = parsed.uuid;
        this.applyAnswer(parsed.webrtcAnswer);
        this.setState('relay');
        this.sinks.openRelay(parsed.relayServer);
        return;
      }
      case 'iceCandidate':
        this.onRemoteCandidate(parsed.sessionKey, parsed.candidate);
        return;
      case 'punchHoleResponse':
        // The server's own text when it sends one: it says why (e.g. not approved).
        if (parsed.failure) {
          // The plain-language reason, with the server's code kept so a
          // pasted error still says exactly what failed.
          const key = `failure_${parsed.failure.toLowerCase()}`;
          this.fail(parsed.message || `${tl(hasText(key) ? key : 'failure_other')} (${parsed.failure})`);
        }
        return;
      default:
        return;
    }
  }

  relayOpened(): void {
    // WebRTC already won: a relay socket that finishes opening only now is
    // not wanted, and its RequestRelay must not go down the data channel.
    if (this.transport === 'webrtc') {
      this.sinks.closeRelay?.();
      return;
    }
    this.sinks.sendRelay(
      buildRequestRelay({
        licenceKey: this.config.serverKeyB64,
        peerId: this.config.peerId,
        uuid: this.uuid,
        connType: this.connType,
      }),
    );
    this.setState('handshake');
  }

  async onRelayBytes(b: Uint8Array): Promise<void> {
    if (this.state === 'error' || this.state === 'closed') return;
    if (this.transport === 'webrtc') return; // the raced relay, kept only as a fallback
    if (!this.cipher) {
      if (this.rtc && !this.rtc.done) {
        this.holdRelay(b);
        return;
      }
      this.onFirstRelayFrame(b);
      return;
    }
    await this.onSessionBytes(b);
  }

  /** A reassembled data-channel message (core/webrtc.ts Reassembler). */
  async onWebRtcMessage(b: Uint8Array): Promise<void> {
    if (this.state === 'error' || this.state === 'closed') return;
    if (this.transport === undefined && this.rtc && !this.rtc.done) {
      // The device sends its SignedId the moment the channel opens, which
      // can be before the race is decided.
      this.heldRtcFrame ??= b;
      return;
    }
    if (this.transport !== 'webrtc') return;
    if (!this.cipher) {
      this.onFirstWebRtcFrame(b);
      return;
    }
    await this.onSessionBytes(b);
  }

  private async onSessionBytes(b: Uint8Array): Promise<void> {
    if (!this.cipher) return;
    let pt: Uint8Array;
    try {
      pt = this.cipher.open(b);
    } catch {
      this.fail('decrypt failed');
      return;
    }
    let msg: Message;
    try {
      msg = Message.decode(pt);
    } catch {
      this.fail('malformed message');
      return;
    }
    await this.dispatch(msg);
  }

  // First relay frame is a PLAINTEXT Message{signed_id}; reply PublicKey in
  // plaintext, then install the stream cipher for everything after.
  private onFirstRelayFrame(b: Uint8Array): void {
    let msg: Message;
    try {
      msg = Message.decode(b);
    } catch {
      this.fail('malformed handshake message');
      return;
    }
    if (msg.union?.$case !== 'signed_id') {
      this.fail(`expected signed_id, got ${msg.union?.$case ?? 'empty message'}`);
      return;
    }
    if (!this.peerEdPk) {
      this.fail('signed_id before relay response');
      return;
    }
    let id: string;
    let boxPk: Uint8Array;
    try {
      ({ id, boxPk } = verifyPeerSignedId(msg.union.signed_id.id, this.peerEdPk));
    } catch {
      this.fail('peer trust link failed: bad SignedId signature');
      return;
    }
    if (id.split('\0')[0] !== this.config.peerId) {
      this.fail(`peer id mismatch in SignedId: got "${id}"`);
      return;
    }
    const { bytes, key } = buildPublicKeyMessage(boxPk);
    this.sinks.sendRelay(bytes);
    this.cipher = new StreamCipher(key);
    this.sinks.emit({ t: 'transport', kind: 'relay' });
  }

  // ---- RustDesk 1.5 WebRTC ----
  //
  // The browser asks for a relay (it cannot punch holes) and offers WebRTC
  // alongside. The device answers in its RelayResponse, so both paths open
  // at once and race, as in the desktop client: a direct WebRTC connection
  // wins outright; a relay that pairs first waits RELAY_FALLBACK_DELAY_MS
  // for it, and so does WebRTC through TURN. If the WebRTC handshake then
  // fails, the session carries on over the relay.

  /** A local ICE candidate from the page, as JSON RTCIceCandidateInit. */
  onLocalCandidate(json: string): void {
    const rtc = this.rtc;
    if (!rtc || rtc.done) return;
    let c: CandidateInit;
    try {
      c = JSON.parse(json) as CandidateInit;
    } catch {
      return;
    }
    if (!shouldSendCandidate(c)) return;
    // The device only knows our session key once it has the offer, so
    // nothing goes out before its answer is back.
    if (rtc.answered) this.sendCandidate(json);
    else rtc.localQueue.push(json);
  }

  /** The data channel opened; viaTurn when the selected pair is relayed. */
  onWebRtcOpen(viaTurn: boolean): void {
    const rtc = this.rtc;
    if (!rtc || rtc.done || this.state === 'error' || this.state === 'closed') return;
    if (!viaTurn) {
      this.commitWebRtc('webrtc');
      return;
    }
    this.heldTurnAt = ++this.heldSeq;
    this.armRaceTimer();
  }

  /** The peer connection failed or closed. */
  onWebRtcClosed(): void {
    if (this.state === 'error' || this.state === 'closed' || !this.rtc) return;
    if (this.transport === 'webrtc') {
      if (this.cipher) this.fail(tl('connection_lost', { which: 'WebRTC' }));
      else this.fallBackToRelay();
      return;
    }
    if (this.transport === 'relay') return;
    this.endRace();
    this.heldTurnAt = 0;
    if (this.relayGone) {
      this.fail(tl('connection_lost_relay'));
      return;
    }
    if (this.heldRelayFrame) this.commitRelay();
  }

  /** The relay socket closed. True when that ends the session. */
  onRelayClosed(): boolean {
    this.relayGone = true;
    if (this.transport === 'webrtc') return false;
    if (this.transport === undefined && this.rtc && !this.rtc.done) return false;
    return true;
  }

  private applyAnswer(endpoint: string): void {
    const rtc = this.rtc;
    if (!rtc || rtc.answered) return;
    let sdp = '';
    try {
      if (endpoint) sdp = decodeEndpoint(endpoint).sdp;
    } catch {
      sdp = '';
    }
    rtc.answerFp = sdpFingerprint(sdp);
    if (!sdp || !rtc.answerFp) {
      // No answer: the device has WebRTC off, is behind a proxy, or is too
      // old. The relay goes ahead alone.
      this.endRace();
      return;
    }
    rtc.answered = true;
    rtc.mid = applicationMid(sdp);
    this.sinks.webrtcAnswer?.(sdp);
    for (const json of rtc.remoteQueue.splice(0)) this.deliverRemoteCandidate(json);
    for (const json of rtc.localQueue.splice(0)) this.sendCandidate(json);
  }

  private onRemoteCandidate(sessionKey: string, json: string): void {
    const rtc = this.rtc;
    if (!rtc || rtc.done || sessionKey !== rtc.sessionKey || json === '') return;
    if (rtc.answered) {
      this.deliverRemoteCandidate(json);
      return;
    }
    if (rtc.remoteQueue.length >= MAX_PENDING_REMOTE_CANDIDATES) rtc.remoteQueue.shift();
    rtc.remoteQueue.push(json);
  }

  private deliverRemoteCandidate(json: string): void {
    const c = parseRemoteCandidate(json, this.rtc?.mid ?? null);
    if (c) this.sinks.webrtcRemoteCandidate?.(c);
  }

  private sendCandidate(json: string): void {
    const rtc = this.rtc;
    if (!rtc) return;
    const bytes = buildIceCandidate({ peerId: this.config.peerId, sessionKey: rtc.sessionKey, candidate: json });
    this.sinks.sendSignaling(bytes);
    rtc.timers.push(setTimeout(() => {
      if (!rtc.done && this.state !== 'error' && this.state !== 'closed') this.sinks.sendSignaling(bytes);
    }, CANDIDATE_RESEND_MS));
  }

  private holdRelay(b: Uint8Array): void {
    if (this.heldRelayFrame) return;
    this.heldRelayFrame = b;
    this.heldRelayAt = ++this.heldSeq;
    this.armRaceTimer();
  }

  private armRaceTimer(): void {
    if (this.raceTimer !== undefined) return;
    this.raceTimer = setTimeout(() => {
      this.raceTimer = undefined;
      if (this.transport !== undefined || this.state === 'error' || this.state === 'closed') return;
      // The first result to arrive wins once the window is up.
      if (this.heldRelayFrame && (!this.heldTurnAt || this.heldRelayAt <= this.heldTurnAt)) this.commitRelay();
      else if (this.heldTurnAt) this.commitWebRtc('webrtc-turn');
    }, this.config.webrtc?.relayHoldMs ?? RELAY_FALLBACK_DELAY_MS);
  }

  /** Stop exchanging candidates; the outcome is settled. */
  private endRace(): void {
    const rtc = this.rtc;
    if (!rtc || rtc.done) return;
    rtc.done = true;
    for (const t of rtc.timers.splice(0)) clearTimeout(t);
    if (this.raceTimer !== undefined) {
      clearTimeout(this.raceTimer);
      this.raceTimer = undefined;
    }
  }

  private webRtcKind: 'webrtc' | 'webrtc-turn' = 'webrtc';

  private commitWebRtc(kind: 'webrtc' | 'webrtc-turn'): void {
    this.endRace();
    this.transport = 'webrtc';
    this.webRtcKind = kind;
    this.sinks.selectTransport?.('webrtc');
    this.setState('handshake');
    // The device speaks first: its SignedId arrives on the channel.
    const held = this.heldRtcFrame;
    this.heldRtcFrame = undefined;
    if (held) this.onFirstWebRtcFrame(held);
  }

  private commitRelay(): void {
    this.endRace();
    this.transport = 'relay';
    this.sinks.webrtcClose?.();
    this.sinks.selectTransport?.('relay');
    const held = this.heldRelayFrame;
    this.heldRelayFrame = undefined;
    if (held) this.onFirstRelayFrame(held);
  }

  /** WebRTC won but could not be secured: use the relay that raced it. */
  private fallBackToRelay(): void {
    if (this.relayGone) {
      this.fail(tl('connection_lost_relay'));
      return;
    }
    this.commitRelay();
  }

  // Over WebRTC the device signs its own DTLS fingerprint into the SignedId.
  // DTLS has already checked the channel against the fingerprint in the
  // answer; matching the two binds the channel to the device's identity, so
  // a server that swapped the SDP cannot sit in the middle. No secretbox
  // layer follows: the PublicKey only completes the device's handshake.
  private onFirstWebRtcFrame(b: Uint8Array): void {
    let msg: Message;
    try {
      msg = Message.decode(b);
    } catch {
      this.fallBackToRelay();
      return;
    }
    if (msg.union?.$case !== 'signed_id' || !this.peerEdPk || !this.rtc) {
      this.fallBackToRelay();
      return;
    }
    let id: string;
    let boxPk: Uint8Array;
    let dtlsFingerprint: string;
    try {
      ({ id, boxPk, dtlsFingerprint } = verifyPeerSignedId(msg.union.signed_id.id, this.peerEdPk));
    } catch {
      this.fallBackToRelay();
      return;
    }
    if (id.split('\0')[0] !== this.config.peerId || !dtlsFingerprint || dtlsFingerprint !== this.rtc.answerFp) {
      this.fallBackToRelay();
      return;
    }
    const { bytes } = buildPublicKeyMessage(boxPk);
    this.sinks.sendRelay(bytes);
    this.cipher = new PlainChannel();
    this.sinks.closeRelay?.();
    this.sinks.emit({ t: 'transport', kind: this.webRtcKind });
  }

  private async dispatch(msg: Message): Promise<void> {
    const u = msg.union;
    switch (u?.$case) {
      case 'test_delay':
        // Echo verbatim, immediately — the peer measures RTT from this.
        if (!u.test_delay.from_client) {
          this.sealSend(
            Message.encode({ union: { $case: 'test_delay', test_delay: u.test_delay } }).finish(),
          );
        }
        return;
      case 'hash': {
        this.setState('login');
        let passwordHash: Uint8Array;
        const plaintextPassword = this.config.password;
        this.config.password = '';
        if (this.config.savedHashHex) {
          // Reuse a remembered h1 (SHA256(pw||salt)) — no plaintext needed.
          passwordHash = await loginHashFromH1(hexToBytes(this.config.savedHashHex), u.hash.challenge);
        } else if (plaintextPassword.length > 0) {
          const h1 = await computeLoginH1(plaintextPassword, u.hash.salt);
          this.sinks.emit({ t: 'credentials', hashHex: bytesToHex(h1) });
          passwordHash = await loginHashFromH1(h1, u.hash.challenge);
        } else if (this.config.addressBook && this.sinks.addressBookHash) {
          // The console turns the device's salt into h1 from an address book
          // entry; the password itself never reaches the browser. No entry,
          // or no answer: fall through to the empty password, as before.
          const h1Hex = await this.sinks.addressBookHash(u.hash.salt).catch(() => null);
          if (this.state === 'error' || this.state === 'closed') return;
          if (h1Hex && /^[0-9a-f]{64}$/i.test(h1Hex)) {
            this.sinks.emit({ t: 'credentials', hashHex: h1Hex.toLowerCase(), fromAddressBook: true });
            passwordHash = await loginHashFromH1(hexToBytes(h1Hex), u.hash.challenge);
          } else {
            passwordHash = new Uint8Array(0);
          }
        } else {
          passwordHash = new Uint8Array(0); // empty password -> interactive accept
        }
        // A remembered codec preference rides in the login's decoding block,
        // the same place a live choice goes before login (issue #92).
        const remembered = this.config.initialOptions?.preferCodec;
        if (remembered !== undefined) {
          const preferred = this.decodingWithPreference(remembered);
          if (preferred) this.decoding = preferred;
        }
        this.loginSent = true;
        // An empty password is a request to be let in by hand: the remote
        // opens its accept prompt and, in password-or-click mode, says
        // nothing until the person answers. Enter needAccept now so the UI
        // says so and the connect watchdog stands down (issue #98).
        const manualAccept = passwordHash.length === 0;
        this.sealSend(
          buildLoginRequest({
            peerId: this.config.peerId,
            passwordHash,
            myId: this.config.myId,
            myName: this.config.myName,
            sessionId: this.sessionId,
            version: CLIENT_VERSION,
            supportedDecoding: this.decoding,
            videoAckRequired: this.videoAck,
            fileTransfer:
              this.config.connType === 'fileTransfer' ? { dir: '', showHidden: false } : undefined,
            viewCamera: this.config.connType === 'viewCamera',
            terminal: this.config.connType === 'terminal'
              ? {
                  serviceId: this.config.terminalServiceId ?? '',
                  persistent: this.config.terminalPersistent ?? false,
                }
              : undefined,
            initialOptions: this.config.initialOptions,
          }),
        );
        if (manualAccept) this.setState('needAccept', tl('waiting_for_remote_user_accept_2'));
        return;
      }
      case 'login_response': {
        const lr = u.login_response.union;
        if (lr?.$case === 'error') {
          if (isManualAccept(lr.error)) this.setState('needAccept', lr.error);
          else if (lr.error === REQUIRE_2FA || lr.error === WRONG_2FA) {
            this.sinks.emit({ t: 'twoFactor', wrong: lr.error === WRONG_2FA });
          } else this.sinks.emit({ t: 'loginError', message: lr.error });
          return;
        }
        if (lr?.$case === 'peer_info') {
          this.emitPeerInfo(lr.peer_info);
          this.setState('streaming');
        }
        return;
      }
      case 'peer_info': // mid-session display-list change
        this.emitPeerInfo(u.peer_info);
        return;
      case 'video_frame':
        this.sinks.onVideo(u.video_frame);
        if (this.videoAck) {
          this.sendMisc({ $case: 'video_received', video_received: true });
        }
        return;
      case 'audio_frame':
        this.sinks.onAudioFrame(u.audio_frame.data);
        return;
      case 'cursor_data':
        this.sinks.onCursor?.(u.cursor_data);
        return;
      case 'cursor_id':
        this.sinks.onCursorId?.(u.cursor_id);
        return;
      case 'cursor_position':
        this.sinks.emit({ t: 'cursorPos', x: u.cursor_position.x, y: u.cursor_position.y });
        return;
      case 'clipboard':
        this.handleClipboard(u.clipboard);
        return;
      case 'multi_clipboards':
        for (const cb of u.multi_clipboards.clipboards) this.handleClipboard(cb);
        return;
      case 'file_response':
        this.sinks.onFileResponse?.(u.file_response);
        return;
      case 'file_action':
        // The one FileAction that flows peer -> controller: send_confirm for a
        // file we are uploading whose target did not need an overwrite prompt.
        if (u.file_action.union?.$case === 'send_confirm') {
          this.sinks.onFileSendConfirm?.(u.file_action.union.send_confirm);
        }
        return;
      case 'terminal_response': {
        const response = u.terminal_response.union;
        switch (response?.$case) {
          case 'opened':
            this.sinks.emit({
              t: 'terminalOpened',
              terminalId: response.opened.terminal_id,
              success: response.opened.success,
              message: response.opened.message,
              pid: response.opened.pid,
              serviceId: response.opened.service_id,
              persistentSessions: response.opened.persistent_sessions,
              replayTerminalOutput: response.opened.replay_terminal_output,
            });
            break;
          case 'data':
            this.sinks.emit({
              t: 'terminalData',
              terminalId: response.data.terminal_id,
              data: response.data.data,
              compressed: response.data.compressed,
            });
            break;
          case 'closed':
            this.sinks.emit({
              t: 'terminalClosed',
              terminalId: response.closed.terminal_id,
              exitCode: response.closed.exit_code,
            });
            break;
          case 'error':
            this.sinks.emit({
              t: 'terminalError',
              terminalId: response.error.terminal_id,
              message: response.error.message,
            });
            break;
        }
        return;
      }
      case 'misc':
        this.dispatchMisc(u.misc.union);
        return;
      case 'message_box':
        this.sinks.emit({
          t: 'msgbox',
          msgtype: u.message_box.msgtype,
          title: u.message_box.title,
          text: u.message_box.text,
          link: u.message_box.link,
        });
        return;
      case 'voice_call_request': {
        // RustDesk's controlling side does not accept incoming connect calls.
        // A remote hang-up is the sole inbound voice request we act upon.
        const hadCall = this.pendingVoiceCallTimestamp !== null || this.voiceCallAccepted;
        if (!u.voice_call_request.is_connect && hadCall) {
          this.pendingVoiceCallTimestamp = null;
          this.voiceCallAccepted = false;
          this.sinks.emit({ t: 'voiceCall', state: 'closed', detail: tl('remote_user_ended_call') });
        }
        return;
      }
      case 'voice_call_response': {
        // Match RustDesk's replay/attack boundary: consume the outstanding
        // request on the first response, even when its timestamp is wrong.
        const pending = this.pendingVoiceCallTimestamp;
        this.pendingVoiceCallTimestamp = null;
        if (pending === null) return;
        if (u.voice_call_response.req_timestamp !== pending) {
          this.sinks.emit({
            t: 'voiceCall',
            state: 'closed',
            detail: tl('voice_call_response_could_not'),
          });
          return;
        }
        this.voiceCallAccepted = u.voice_call_response.accepted;
        this.sinks.emit({ t: 'voiceCall', state: u.voice_call_response.accepted ? 'accepted' : 'rejected' });
        return;
      }
      default:
        return; // TODO: file_*, switch_sides — out of scope for core
    }
  }

  private dispatchMisc(u: Misc['union']): void {
    switch (u?.$case) {
      case 'chat_message':
        // Chat is a Misc member, not a top-level Message — same channel as
        // switch_display and refresh_video. Empty texts are keepalive noise.
        if (u.chat_message.text) this.sinks.emit({ t: 'chat', text: u.chat_message.text });
        return;
      case 'permission_info':
        this.sinks.emit({
          t: 'permission',
          kind: PermissionInfo_Permission[u.permission_info.permission] ?? String(u.permission_info.permission),
          enabled: u.permission_info.enabled,
        });
        return;
      case 'audio_format':
        this.sinks.onAudioFormat(u.audio_format.sample_rate, u.audio_format.channels);
        return;
      case 'back_notification': {
        const n = u.back_notification;
        if (n.union?.$case === 'privacy_mode_state') {
          this.sinks.emit({
            t: 'privacyMode',
            state: n.union.privacy_mode_state,
            details: n.details,
            implKey: n.impl_key,
          });
        } else if (n.union?.$case === 'block_input_state') {
          this.sinks.emit({
            t: 'blockInput',
            state: n.union.block_input_state,
            details: n.details,
          });
        }
        return;
      }
      case 'elevation_response':
        this.sinks.emit({
          t: 'elevation',
          state: u.elevation_response ? 'failed' : 'pending',
          detail: u.elevation_response,
        });
        return;
      case 'portable_service_running':
        if (u.portable_service_running) {
          this.sinks.emit({ t: 'elevation', state: 'succeeded', detail: '' });
        }
        return;
      case 'close_reason':
        this.setState('closed', u.close_reason, true);
        this.sinks.closeAll();
        return;
      case 'uac':
        // Remote UAC prompt opened/closed. The host's capture pipeline restarts
        // around the secure-desktop switch; the worker kicks the video stream.
        this.sinks.emit({ t: 'uac', on: u.uac });
        return;
      case 'switch_display': {
        // The host's confirmation of which display it is now capturing, and
        // where that display sits in the virtual desktop. Dropping this was a
        // real bug: input coordinates are absolute virtual-desktop positions,
        // so the origin here is what decides which monitor a click lands on.
        const s = u.switch_display;
        // Pruning the capture set (see switchDisplay) stops the old display's
        // service broadcasting, but not instantly: its snapshot can already be
        // in flight when our CaptureDisplays arrives. Until the host confirms
        // the display we actually asked for, a message about a different one is
        // that stale broadcast, and following it is what put input back on the
        // monitor we had just left.
        if (this.pendingDisplay !== null && s.display !== this.pendingDisplay) return;
        this.pendingDisplay = null;
        this.sinks.emit({
          t: 'switchDisplay',
          index: s.display,
          x: s.x,
          y: s.y,
          width: s.width,
          height: s.height,
          cursorEmbedded: s.cursor_embedded,
          originalResolution: s.original_resolution
            ? { width: s.original_resolution.width, height: s.original_resolution.height }
            : undefined,
          resolutions: (s.resolutions?.resolutions ?? []).map((r) => ({ width: r.width, height: r.height })),
        });
        return;
      }
      case 'follow_current_display':
        if (Number.isSafeInteger(u.follow_current_display) && u.follow_current_display >= 0) {
          this.sinks.emit({ t: 'followDisplay', index: u.follow_current_display });
        }
        return;
      default:
        return; // TODO: back_notification, supported_encoding, ...
    }
  }

  private handleClipboard(cb: Clipboard): void {
    if (!cb.compress && cb.format === ClipboardFormat.Text) {
      this.sinks.emit({ t: 'clipboard', text: utf8Dec.decode(cb.content) });
      return;
    }
    this.sinks.onClipboard?.(cb); // zstd / non-text: worker's job
  }

  private emitPeerInfo(update: PeerInfo): void {
    const pi = withLoginPeerInfo(update, this.loginPeerInfo);
    if (update.platform) this.loginPeerInfo = update;
    const advanced = parseAdvancedPeerCapabilities(pi.platform_additions);
    const resolutions = (pi.resolutions?.resolutions ?? []).map((r) => ({ width: r.width, height: r.height }));
    const displays: DisplayInfo[] = pi.displays.map((d, index) => ({
      index,
      x: d.x,
      y: d.y,
      width: d.width,
      height: d.height,
      name: d.name,
      scale: d.scale || 1,
      online: d.online,
      cursorEmbedded: d.cursor_embedded,
      originalResolution: d.original_resolution
        ? { width: d.original_resolution.width, height: d.original_resolution.height }
        : undefined,
      resolutions: index === pi.current_display ? resolutions : [],
    }));
    this.sinks.emit({
      t: 'peerInfo',
      displays,
      username: pi.username,
      hostname: pi.hostname,
      platform: pi.platform,
      platformAdditions: pi.platform_additions,
      version: pi.version,
      ...(this.state === 'streaming' ? {} : { current: pi.current_display }),
      privacyModeSupported: pi.features?.privacy_mode ?? false,
      privacyModeImpls: parsePrivacyModeImpls(pi.platform_additions),
      terminalSupported: pi.features?.terminal ?? false,
      viewCameraSupported: advanced.viewCamera,
    });
  }

  // Replaces the VP9/VP8 baseline with the worker's real WebCodecs probe.
  // Before the Hash arrives the override rides inside the LoginRequest; after
  // login it is re-advertised via Misc{option.supported_decoding} (e.g. when
  // the video pipeline disables a codec that fails to decode).
  setSupportedDecoding(sd: SupportedDecoding): void {
    this.decoding = sd;
    if (this.loginSent) {
      this.sendMisc({
        $case: 'option',
        option: OptionMessage.fromPartial({ supported_decoding: sd }),
      });
    }
  }

  // ---- outbound controls (post-handshake; silently dropped before cipher) ----

  sendMouse(mask: number, x: number, y: number, modifiers: number[]): void {
    this.sendMessage({
      $case: 'mouse_event',
      mouse_event: MouseEvent.fromPartial({ mask, x, y, modifiers: modifiers as ControlKey[] }),
    });
  }

  sendKey(
    down: boolean,
    press: boolean,
    keyKind: 'chr' | 'control' | 'unicode' | 'map',
    value: number,
    modifiers: number[],
  ): void {
    // Map mode carries the host-platform keycode in `chr`.
    const union: KeyEvent['union'] =
      keyKind === 'chr' || keyKind === 'map'
        ? { $case: 'chr', chr: value }
        : keyKind === 'unicode'
          ? { $case: 'unicode', unicode: value }
          : { $case: 'control_key', control_key: value as ControlKey };
    this.sendMessage({
      $case: 'key_event',
      key_event: KeyEvent.fromPartial({
        down,
        press,
        union,
        modifiers: modifiers as ControlKey[],
        mode: keyKind === 'map' ? KeyboardMode.Map : KeyboardMode.Legacy,
      }),
    });
  }

  switchDisplay(index: number): void {
    // While this is outstanding, a switch_display naming a DIFFERENT display is
    // a stale broadcast rather than news — see dispatchMisc.
    this.pendingDisplay = index;
    this.sendMisc({ $case: 'switch_display', switch_display: SwitchDisplay.fromPartial({ display: index }) });
    // Prune the capture set, or the host keeps us subscribed to the old
    // display's video service. connection.rs switch_display_to only
    // unsubscribes the old service for clients BELOW 1.2.4:
    //
    //   // For versions greater than 1.2.4, a `CaptureDisplays` message will
    //   // be sent immediately. Unnecessary capturers will be removed then.
    //
    // We advertise 1.4.0, so the host waits for this message and we never sent
    // one. The old service kept broadcasting at us and replayed its stored
    // snapshot — a switch_display for the display we had just left, which put
    // our display index back and sent every subsequent click to the wrong
    // monitor. It was also still encoding a display nobody was watching.
    this.sendMisc({
      $case: 'capture_displays',
      capture_displays: CaptureDisplays.fromPartial({ set: [index] }),
    });
  }

  changeDisplayResolution(display: number, width: number, height: number): boolean {
    if (
      !Number.isSafeInteger(display) || display < 0 ||
      !Number.isSafeInteger(width) || !Number.isSafeInteger(height) ||
      width < 1 || height < 1 || width > 16384 || height > 16384
    ) {
      return false;
    }
    this.sendMisc({
      $case: 'change_display_resolution',
      change_display_resolution: DisplayResolution.fromPartial({
        display,
        resolution: Resolution.fromPartial({ width, height }),
      }),
    });
    return true;
  }

  toggleVirtualDisplay(display: number, on: boolean): boolean {
    if (!Number.isSafeInteger(display) || display < -1) return false;
    this.sendMisc({
      $case: 'toggle_virtual_display',
      toggle_virtual_display: ToggleVirtualDisplay.fromPartial({ display, on }),
    });
    return true;
  }

  ctrlAltDel(): void {
    this.sendMessage({
      $case: 'key_event',
      key_event: KeyEvent.fromPartial({
        down: false,
        press: true,
        union: { $case: 'control_key', control_key: ControlKey.CtrlAltDel },
        mode: KeyboardMode.Legacy,
      }),
    });
  }

  refresh(): void {
    this.sendMisc({ $case: 'refresh_video', refresh_video: true });
  }

  setQuality(imageQuality: number): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({ image_quality: imageQuality as ImageQuality }),
    });
  }

  restartRemoteDevice(): void {
    this.sendMisc(buildRestartRemoteDevice());
  }

  requestElevation(): void {
    this.sendMisc(buildDirectElevation());
  }

  setPrivacyMode(implKey: string, on: boolean): void {
    this.sendMisc(buildPrivacyToggle(implKey, on));
  }

  setBlockInput(on: boolean): void {
    this.sendMisc({ $case: 'option', option: buildBlockInputOption(on) });
  }

  setLockAfterSessionEnd(on: boolean): void {
    this.sendMisc({ $case: 'option', option: buildLockAfterSessionEndOption(on) });
  }

  setCustomQuality(quality: number): boolean {
    if (!Number.isSafeInteger(quality) || quality < 10 || quality > 100) return false;
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({ custom_image_quality: quality << 8 }),
    });
    return true;
  }

  setCustomFps(fps: number): boolean {
    if (!Number.isSafeInteger(fps) || fps < 5 || fps > 120) return false;
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({ custom_fps: fps }),
    });
    return true;
  }

  setDisplayOption(
    option: 'showRemoteCursor' | 'followRemoteCursor' | 'followRemoteWindow',
    enabled: boolean,
  ): void {
    const value = enabled ? OptionMessage_BoolOption.Yes : OptionMessage_BoolOption.No;
    const partial = option === 'showRemoteCursor'
      ? { show_remote_cursor: value }
      : option === 'followRemoteCursor'
        ? { follow_remote_cursor: value }
        : { follow_remote_window: value };
    this.sendMisc({ $case: 'option', option: OptionMessage.fromPartial(partial) });
  }

  setPreferredCodec(prefer: SupportedDecoding_PreferCodec): boolean {
    const sd = this.decodingWithPreference(prefer);
    if (!sd) return false;
    this.setSupportedDecoding(sd);
    return true;
  }

  /** The current decoding block with `prefer` set, or null when we cannot decode it. */
  private decodingWithPreference(prefer: SupportedDecoding_PreferCodec): SupportedDecoding | null {
    const supported = prefer === SupportedDecoding_PreferCodec.Auto ||
      (prefer === SupportedDecoding_PreferCodec.VP9 && this.decoding.ability_vp9 > 0) ||
      (prefer === SupportedDecoding_PreferCodec.H264 && this.decoding.ability_h264 > 0) ||
      (prefer === SupportedDecoding_PreferCodec.H265 && this.decoding.ability_h265 > 0) ||
      (prefer === SupportedDecoding_PreferCodec.VP8 && this.decoding.ability_vp8 > 0) ||
      (prefer === SupportedDecoding_PreferCodec.AV1 && this.decoding.ability_av1 > 0);
    return supported ? SupportedDecoding.fromPartial({ ...this.decoding, prefer }) : null;
  }

  setRemoteAudioEnabled(enabled: boolean): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({
        disable_audio: enabled ? OptionMessage_BoolOption.No : OptionMessage_BoolOption.Yes,
      }),
    });
  }

  setClipboardEnabled(enabled: boolean): void {
    this.sendMisc({
      $case: 'option',
      option: OptionMessage.fromPartial({
        disable_clipboard: enabled ? OptionMessage_BoolOption.No : OptionMessage_BoolOption.Yes,
      }),
    });
  }

  setClientRecording(recording: boolean): void {
    this.sendMisc({ $case: 'client_record_status', client_record_status: recording });
  }

  sendClipboardText(text: string): void {
    this.sendMessage({
      $case: 'clipboard',
      clipboard: Clipboard.fromPartial({
        compress: false,
        content: utf8Enc.encode(text),
        format: ClipboardFormat.Text,
      }),
    });
  }

  sendChat(text: string): void {
    // ChatMessage.text is plain UTF-8; protobuf handles the encoding. There is
    // no per-message id or ack in the protocol, so the UI echoes what it sent
    // rather than waiting for confirmation.
    this.sendMisc({ $case: 'chat_message', chat_message: ChatMessage.fromPartial({ text }) });
  }

  startVoiceCall(): void {
    if (this.pendingVoiceCallTimestamp !== null) return;
    // Milliseconds since epoch is a positive signed 64-bit value through 2262.
    const reqTimestamp = BigInt(Math.max(1, Date.now()));
    this.pendingVoiceCallTimestamp = reqTimestamp;
    this.sendMessage({
      $case: 'voice_call_request',
      voice_call_request: { is_connect: true, req_timestamp: reqTimestamp },
    });
    this.sinks.emit({ t: 'voiceCall', state: 'waiting' });
  }

  closeVoiceCall(): void {
    this.pendingVoiceCallTimestamp = null;
    this.voiceCallAccepted = false;
    const reqTimestamp = BigInt(Math.max(1, Date.now()));
    this.sendMessage({
      $case: 'voice_call_request',
      voice_call_request: { is_connect: false, req_timestamp: reqTimestamp },
    });
    this.sinks.emit({ t: 'voiceCall', state: 'closed' });
  }

  sendVoiceAudioFormat(sampleRate: number, channels: number): void {
    if (!this.voiceCallAccepted) return;
    this.sendMisc({ $case: 'audio_format', audio_format: { sample_rate: sampleRate, channels } });
  }

  sendVoiceAudioFrame(data: Uint8Array): void {
    if (!this.voiceCallAccepted) return;
    if (this.sinks.relayBuffered() >= VOICE_RELAY_BUFFER_LIMIT) return;
    this.sendMessage({ $case: 'audio_frame', audio_frame: { data } });
  }

  openTerminal(terminalId: number, rows: number, cols: number): void {
    this.sendMessage({
      $case: 'terminal_action',
      terminal_action: { union: { $case: 'open', open: { terminal_id: terminalId, rows, cols } } },
    });
  }

  sendTerminalData(terminalId: number, data: Uint8Array): void {
    this.sendMessage({
      $case: 'terminal_action',
      terminal_action: {
        union: { $case: 'data', data: { terminal_id: terminalId, data, compressed: false } },
      },
    });
  }

  resizeTerminal(terminalId: number, rows: number, cols: number): void {
    this.sendMessage({
      $case: 'terminal_action',
      terminal_action: { union: { $case: 'resize', resize: { terminal_id: terminalId, rows, cols } } },
    });
  }

  closeTerminal(terminalId: number): void {
    this.sendMessage({
      $case: 'terminal_action',
      terminal_action: { union: { $case: 'close', close: { terminal_id: terminalId } } },
    });
  }

  // File-transfer connections: outbound FileAction (requests and upload control),
  // and FileResponse for the payload we send when uploading (blocks/done/error
  // always travel as file_response regardless of direction).
  sendFileAction(union: NonNullable<FileAction['union']>): void {
    this.sendMessage({ $case: 'file_action', file_action: { union } });
  }

  sendFileResponse(union: NonNullable<FileResponse['union']>): void {
    this.sendMessage({ $case: 'file_response', file_response: { union } });
  }

  /** The code the device asked for with a twoFactor event. */
  sendAuth2fa(code: string): void {
    if (!this.loginSent || this.state === 'streaming' || this.state === 'error' || this.state === 'closed') return;
    this.sendMessage({ $case: 'auth_2fa', auth_2fa: { code: code.trim(), hwid: new Uint8Array(0) } });
  }

  disconnect(): void {
    if (this.state !== 'closed') this.setState('closed');
    this.sinks.closeAll();
  }

  // ---- internals ----

  private sendMessage(union: NonNullable<Message['union']>): void {
    if (!this.cipher) return;
    this.sealSend(Message.encode({ union }).finish());
  }

  private sendMisc(union: NonNullable<Misc['union']>): void {
    this.sendMessage({ $case: 'misc', misc: { union } });
  }

  private sealSend(bytes: Uint8Array): void {
    if (!this.cipher) return;
    this.sinks.sendRelay(this.cipher.seal(bytes));
  }

  private setState(state: SessionState, detail?: string, peerInitiated = false): void {
    this.state = state;
    if (state === 'closed' || state === 'error') {
      this.pendingVoiceCallTimestamp = null;
      this.voiceCallAccepted = false;
    }
    const event: SessionEvent = detail === undefined ? { t: 'state', state } : { t: 'state', state, detail };
    if (peerInitiated && event.t === 'state') event.peerInitiated = true;
    this.sinks.emit(event);
  }

  private fail(detail: string): void {
    this.setState('error', detail);
    this.sinks.closeAll();
  }
}
