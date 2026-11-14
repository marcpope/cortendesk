// RustDesk 1.5 WebRTC in the session: the offer, trickled candidates, the
// race against the relay and the handshake over the data channel.
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import sodium from 'libsodium-wrappers';
import type { SessionEvent } from './contracts';
import { Hash, IdPk, Message } from '../gen/message';
import { RelayResponse, RendezvousMessage } from '../gen/rendezvous';
import { sodiumReady } from './crypto';
import { Session, type SessionSinks } from './session';
import { CANDIDATE_RESEND_MS, RELAY_FALLBACK_DELAY_MS, encodeEndpoint, type CandidateInit } from './webrtc';

const PEER_ID = '123456789';
const OFFER_KEY = 'sha-256 0A:0B';
const DEVICE_FP = 'sha-256 DE:VI:CE';
const ANSWER_SDP = [
  'v=0', 'o=- 1 2 IN IP4 0.0.0.0', 's=-', 't=0 0',
  'm=application 9 UDP/DTLS/SCTP webrtc-datachannel', 'c=IN IP4 0.0.0.0',
  'a=ice-ufrag:x', 'a=ice-pwd:y', `a=fingerprint:${DEVICE_FP}`, 'a=setup:active', 'a=mid:0', '',
].join('\r\n');

let serverKp: sodium.KeyPair;
beforeAll(async () => {
  await sodiumReady();
  serverKp = sodium.crypto_sign_keypair();
});
beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

const peerEd = () => sodium.crypto_sign_keypair();

function relayResponse(devicePk: Uint8Array, answer: string): Uint8Array {
  const pk = sodium.crypto_sign(IdPk.encode(IdPk.fromPartial({ id: PEER_ID, pk: devicePk })).finish(), serverKp.privateKey);
  return RendezvousMessage.encode({
    union: {
      $case: 'relay_response',
      relay_response: RelayResponse.fromPartial({ uuid: 'u1', relay_server: 'relay:21117', union: { $case: 'pk', pk }, webrtc_sdp_answer: answer }),
    },
  }).finish();
}

function candidateFrame(sessionKey: string, candidate: string): Uint8Array {
  return RendezvousMessage.encode({
    union: { $case: 'ice_candidate', ice_candidate: { id: '', socket_addr: new Uint8Array(), session_key: sessionKey, candidate } },
  }).finish();
}

function signedId(ed: sodium.KeyPair, box: sodium.KeyPair, dtlsFingerprint = ''): Uint8Array {
  const idpk = IdPk.encode(IdPk.fromPartial({ id: PEER_ID, pk: box.publicKey, dtls_fingerprint: dtlsFingerprint })).finish();
  return Message.encode({ union: { $case: 'signed_id', signed_id: { id: sodium.crypto_sign(idpk, ed.privateKey) } } }).finish();
}

function harness() {
  const events: SessionEvent[] = [];
  const signaling: Uint8Array[] = [];
  const sent: { via: string; bytes: Uint8Array }[] = [];
  const calls: string[] = [];
  const remote: CandidateInit[] = [];
  let transport = 'relay';
  const sinks: SessionSinks = {
    relayBuffered: () => 0,
    sendSignaling: (b) => signaling.push(b),
    sendRelay: (bytes) => sent.push({ via: transport, bytes }),
    emit: (ev) => events.push(ev),
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => calls.push('openRelay'),
    closeAll: () => calls.push('closeAll'),
    webrtcAnswer: (sdp) => calls.push(`answer:${sdp === ANSWER_SDP}`),
    webrtcRemoteCandidate: (c) => remote.push(c),
    webrtcClose: () => calls.push('webrtcClose'),
    selectTransport: (t) => {
      transport = t;
      calls.push(`select:${t}`);
    },
    closeRelay: () => calls.push('closeRelay'),
  };
  const session = new Session(
    {
      peerId: PEER_ID,
      serverKeyB64: sodium.to_base64(serverKp.publicKey, sodium.base64_variants.ORIGINAL),
      wsIdUrl: 'ws://x/id',
      wsRelayUrl: 'ws://x/relay',
      password: 'pw',
      myId: 'web-1',
      myName: 'tester',
    },
    sinks,
  );
  const ed = peerEd();
  const box = sodium.crypto_box_keypair();
  const iceCandidates = () =>
    signaling
      .map((b) => RendezvousMessage.decode(b).union)
      .filter((u) => u?.$case === 'ice_candidate')
      .map((u) => (u?.$case === 'ice_candidate' ? u.ice_candidate : undefined)!);
  const transports = () => events.filter((e) => e.t === 'transport').map((e) => (e.t === 'transport' ? e.kind : ''));
  return { session, events, signaling, sent, calls, remote, ed, box, iceCandidates, transports };
}

const ANSWER = encodeEndpoint({ type: 'answer', sdp: ANSWER_SDP, icePolicy: 'all' });
const LOCAL = JSON.stringify({ candidate: 'candidate:1 1 udp 2 203.0.113.9 4000 typ srflx', sdpMid: '0', sdpMLineIndex: 0 });

function startRacing(h: ReturnType<typeof harness>, answer = ANSWER) {
  h.session.start({ endpoint: 'webrtc://offer', sessionKey: OFFER_KEY });
  h.session.onSignalingBytes(relayResponse(h.ed.publicKey, answer));
  h.session.relayOpened();
}

describe('WebRTC offer and candidates', () => {
  it('puts the offer in the punch request', () => {
    const h = harness();
    h.session.start({ endpoint: 'webrtc://offer', sessionKey: OFFER_KEY });
    const m = RendezvousMessage.decode(h.signaling[0]!);
    expect(m.union?.$case === 'punch_hole_request' && m.union.punch_hole_request.webrtc_sdp_offer).toBe('webrtc://offer');
  });

  it('holds local candidates until the answer, then sends each twice', () => {
    const h = harness();
    h.session.start({ endpoint: 'webrtc://offer', sessionKey: OFFER_KEY });
    h.session.onLocalCandidate(LOCAL);
    h.session.onLocalCandidate(JSON.stringify({ candidate: 'candidate:2 1 udp 2 abc.local 4000 typ host' }));
    expect(h.iceCandidates()).toEqual([]);
    h.session.onSignalingBytes(relayResponse(h.ed.publicKey, ANSWER));
    expect(h.calls).toContain('answer:true');
    expect(h.iceCandidates()).toEqual([{ id: PEER_ID, socket_addr: new Uint8Array(), session_key: OFFER_KEY, candidate: LOCAL }]);
    vi.advanceTimersByTime(CANDIDATE_RESEND_MS);
    expect(h.iceCandidates().length).toBe(2);
  });

  it('buffers device candidates for this session only, then repairs their mid', () => {
    const h = harness();
    h.session.start({ endpoint: 'webrtc://offer', sessionKey: OFFER_KEY });
    const dev = JSON.stringify({ candidate: 'candidate:9 1 udp 1 198.51.100.4 7000 typ host', sdpMid: '', sdpMLineIndex: 0 });
    h.session.onSignalingBytes(candidateFrame(OFFER_KEY, dev));
    h.session.onSignalingBytes(candidateFrame('sha-256 OTHER', dev));
    expect(h.remote).toEqual([]);
    h.session.onSignalingBytes(relayResponse(h.ed.publicKey, ANSWER));
    expect(h.remote).toEqual([{ candidate: 'candidate:9 1 udp 1 198.51.100.4 7000 typ host', sdpMid: '0', sdpMLineIndex: 0 }]);
  });

  it('goes relay-only when the device sends no answer', async () => {
    const h = harness();
    startRacing(h, '');
    h.session.onLocalCandidate(LOCAL);
    expect(h.iceCandidates()).toEqual([]);
    await h.session.onRelayBytes(signedId(h.ed, h.box));
    expect(Message.decode(h.sent[1]!.bytes).union?.$case).toBe('public_key');
    expect(h.transports()).toEqual(['relay']);
  });
});

describe('WebRTC race and handshake', () => {
  it('a direct connection wins, is bound to the signed fingerprint, and drops the relay', async () => {
    const h = harness();
    startRacing(h);
    h.session.onWebRtcOpen(false);
    expect(h.calls).toContain('select:webrtc');
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    const pk = h.sent.find((s) => s.via === 'webrtc')!;
    const msg = Message.decode(pk.bytes);
    expect(msg.union?.$case).toBe('public_key');
    if (msg.union?.$case !== 'public_key') return;
    expect(msg.union.public_key.kx_version).toBe(0);
    // The device can open the sealed key with its box key and the zero nonce.
    expect(sodium.crypto_box_open_easy(msg.union.public_key.symmetric_value, new Uint8Array(24), msg.union.public_key.asymmetric_value, h.box.privateKey).length).toBe(32);
    expect(h.calls).toContain('closeRelay');
    expect(h.transports()).toEqual(['webrtc']);
    // From here messages are plain protobuf: a Hash challenge gets a LoginRequest back.
    await h.session.onWebRtcMessage(Message.encode({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }).finish());
    expect(Message.decode(h.sent.at(-1)!.bytes).union?.$case).toBe('login_request');
  });

  it('ignores a relay socket that opens after WebRTC has won', async () => {
    const h = harness();
    h.session.start({ endpoint: 'webrtc://offer', sessionKey: OFFER_KEY });
    h.session.onSignalingBytes(relayResponse(h.ed.publicKey, ANSWER));
    h.session.onWebRtcOpen(false);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    await h.session.onWebRtcMessage(Message.encode({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }).finish());
    const sentBefore = h.sent.length;
    h.session.relayOpened();
    expect(h.sent.length).toBe(sentBefore); // no RequestRelay down the data channel
    expect(h.session.currentState).toBe('login');
  });

  it('keeps the SignedId that arrives before the race is decided', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    expect(h.sent.filter((s) => s.via === 'webrtc')).toEqual([]);
    h.session.onWebRtcOpen(false);
    expect(Message.decode(h.sent.at(-1)!.bytes).union?.$case).toBe('public_key');
    expect(h.sent.at(-1)!.via).toBe('webrtc');
  });

  it('falls back to the relay when the fingerprint does not match', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onRelayBytes(signedId(h.ed, h.box)); // relay paired first: held
    h.session.onWebRtcOpen(false);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, 'sha-256 SW:AP:PE:D'));
    expect(h.calls).toEqual(expect.arrayContaining(['webrtcClose', 'select:relay']));
    expect(h.calls).not.toContain('closeRelay');
    const last = h.sent.at(-1)!;
    expect(last.via).toBe('relay');
    expect(Message.decode(last.bytes).union?.$case).toBe('public_key');
    expect(h.transports()).toEqual(['relay']);
  });

  it('a relay that pairs first waits for WebRTC, then is used', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onRelayBytes(signedId(h.ed, h.box));
    expect(h.sent.length).toBe(1); // only the RequestRelay so far
    vi.advanceTimersByTime(RELAY_FALLBACK_DELAY_MS);
    expect(h.calls).toContain('webrtcClose');
    expect(Message.decode(h.sent.at(-1)!.bytes).union?.$case).toBe('public_key');
    expect(h.transports()).toEqual(['relay']);
  });

  it('WebRTC still wins inside the relay\'s wait', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onRelayBytes(signedId(h.ed, h.box));
    vi.advanceTimersByTime(RELAY_FALLBACK_DELAY_MS - 100);
    h.session.onWebRtcOpen(false);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    vi.advanceTimersByTime(1000);
    expect(h.transports()).toEqual(['webrtc']);
    expect(h.calls).not.toContain('webrtcClose');
  });

  it('TURN that connects first waits like a relay and then wins', async () => {
    const h = harness();
    startRacing(h);
    h.session.onWebRtcOpen(true);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    await h.session.onRelayBytes(signedId(h.ed, h.box));
    vi.advanceTimersByTime(RELAY_FALLBACK_DELAY_MS);
    expect(h.transports()).toEqual(['webrtc-turn']);
  });

  it('TURN that moves to a direct pair inside the wait wins at once', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onRelayBytes(signedId(h.ed, h.box)); // relay paired first
    h.session.onWebRtcOpen(true);
    await h.session.onWebRtcMessage(signedId(h.ed, h.box, DEVICE_FP));
    vi.advanceTimersByTime(600);
    h.session.onWebRtcOpen(false); // the browser switched to a direct pair
    expect(h.transports()).toEqual(['webrtc']);
    vi.advanceTimersByTime(RELAY_FALLBACK_DELAY_MS);
    expect(h.calls).not.toContain('webrtcClose');
  });

  it('a WebRTC failure during the race leaves the relay to carry on', async () => {
    const h = harness();
    startRacing(h);
    await h.session.onRelayBytes(signedId(h.ed, h.box));
    h.session.onWebRtcClosed();
    expect(Message.decode(h.sent.at(-1)!.bytes).union?.$case).toBe('public_key');
    expect(h.transports()).toEqual(['relay']);
  });

  it('losing the relay is not fatal while WebRTC can win', () => {
    const h = harness();
    startRacing(h);
    expect(h.session.onRelayClosed()).toBe(false);
    h.session.onWebRtcClosed();
    expect(h.events.at(-1)).toMatchObject({ t: 'state', state: 'error' });
  });
});
