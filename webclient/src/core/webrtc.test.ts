import { describe, expect, it } from 'vitest';
import { RendezvousMessage } from '../gen/rendezvous';
import {
  MAX_FRAGMENT_PAYLOAD,
  Reassembler,
  applicationMid,
  buildIceCandidate,
  decodeEndpoint,
  encodeEndpoint,
  fragment,
  parseIceServers,
  parseRemoteCandidate,
  sdpFingerprint,
  shouldSendCandidate,
  stripCandidates,
} from './webrtc';

const CHROME_OFFER = [
  'v=0',
  'o=- 4611731400430051336 2 IN IP4 127.0.0.1',
  's=-',
  't=0 0',
  'a=group:BUNDLE 0',
  'a=msid-semantic: WMS',
  'm=application 9 UDP/DTLS/SCTP webrtc-datachannel',
  'c=IN IP4 0.0.0.0',
  'a=candidate:1 1 udp 2122260223 192.0.2.10 54321 typ host generation 0',
  'a=end-of-candidates',
  'a=ice-ufrag:abcd',
  'a=ice-pwd:0123456789012345678901',
  'a=fingerprint:sha-256 0A:1B:2C:3D',
  'a=setup:actpass',
  'a=mid:0',
  'a=sctp-port:5000',
  '',
].join('\r\n');

describe('endpoint envelope', () => {
  it('round-trips, without candidate lines, and declares all-ICE', () => {
    const s = encodeEndpoint({ type: 'offer', sdp: CHROME_OFFER, icePolicy: 'all' });
    expect(s.startsWith('webrtc://')).toBe(true);
    const json = JSON.parse(atob(s.slice('webrtc://'.length)));
    expect(Object.keys(json).sort()).toEqual(['ice_policy', 'sdp', 'type']);
    const e = decodeEndpoint(s);
    expect(e.type).toBe('offer');
    expect(e.icePolicy).toBe('all');
    expect(e.sdp).not.toContain('a=candidate:');
    expect(e.sdp).not.toContain('a=end-of-candidates');
    expect(e.sdp).toContain('a=fingerprint:sha-256 0A:1B:2C:3D\r\n');
  });

  it('refuses anything else', () => {
    expect(() => decodeEndpoint('nope')).toThrow();
    expect(() => decodeEndpoint('webrtc://' + btoa('{"type":"pranswer","sdp":"v=0"}'))).toThrow();
    expect(() => decodeEndpoint('webrtc://' + btoa('{"type":"answer"}'))).toThrow();
    expect(() => decodeEndpoint('webrtc://%%%')).toThrow();
  });

  it('keeps CRLF line endings when stripping', () => {
    expect(stripCandidates('v=0\na=candidate:x\na=mid:0\n')).toBe('v=0\r\na=mid:0\r\n');
  });
});

describe('fingerprint and mid', () => {
  it('takes a session-level fingerprint before a media one', () => {
    const sdp = 'v=0\r\na=fingerprint:sha-256 AA\r\nm=application 9 x\r\na=fingerprint:sha-256 BB\r\n';
    expect(sdpFingerprint(sdp)).toBe('sha-256 AA');
  });

  it('falls back to the first application section', () => {
    expect(sdpFingerprint(CHROME_OFFER)).toBe('sha-256 0A:1B:2C:3D');
    const sdp = 'v=0\r\nm=audio 9 x\r\na=fingerprint:sha-256 AU\r\nm=application 9 x\r\na=fingerprint:sha-256 AP\r\n';
    expect(sdpFingerprint(sdp)).toBe('sha-256 AP');
    expect(sdpFingerprint('v=0\r\n')).toBe('');
  });

  it('finds the application mid', () => {
    expect(applicationMid(CHROME_OFFER)).toBe('0');
    expect(applicationMid('v=0\r\nm=audio 9\r\na=mid:a\r\n')).toBeNull();
  });
});

describe('candidates', () => {
  it('sends UDP candidates only, never mDNS names', () => {
    expect(shouldSendCandidate({ candidate: 'candidate:1 1 udp 1 203.0.113.5 5000 typ srflx' })).toBe(true);
    expect(shouldSendCandidate({ candidate: 'candidate:1 1 UDP 1 203.0.113.5 5000 typ relay' })).toBe(true);
    expect(shouldSendCandidate({ candidate: 'candidate:1 1 udp 1 4b1c-x.local 5000 typ host' })).toBe(false);
    expect(shouldSendCandidate({ candidate: 'candidate:1 1 tcp 1 203.0.113.5 9 typ host tcptype active' })).toBe(false);
    expect(shouldSendCandidate({ candidate: '' })).toBe(false);
  });

  it('repairs an empty sdpMid from the device', () => {
    const json = JSON.stringify({ candidate: 'candidate:1 1 udp 1 198.51.100.7 6000 typ host', sdpMid: '', sdpMLineIndex: 0, usernameFragment: null });
    expect(parseRemoteCandidate(json, '0')).toEqual({ candidate: 'candidate:1 1 udp 1 198.51.100.7 6000 typ host', sdpMid: '0', sdpMLineIndex: 0 });
    expect(parseRemoteCandidate('{"candidate":""}', '0')).toBeNull();
    expect(parseRemoteCandidate('not json', '0')).toBeNull();
  });

  it('addresses an IceCandidate to the device', () => {
    const m = RendezvousMessage.decode(buildIceCandidate({ peerId: '123', sessionKey: 'sha-256 AA', candidate: '{"candidate":"c"}' }));
    expect(m.union?.$case).toBe('ice_candidate');
    if (m.union?.$case !== 'ice_candidate') return;
    expect(m.union.ice_candidate.id).toBe('123');
    expect(m.union.ice_candidate.session_key).toBe('sha-256 AA');
    expect(m.union.ice_candidate.candidate).toBe('{"candidate":"c"}');
  });
});

describe('framing', () => {
  it('sends an empty message as one end byte', () => {
    expect(fragment(new Uint8Array())).toEqual([Uint8Array.of(0)]);
  });

  it('splits at 60000 bytes and marks all but the last', () => {
    const msg = new Uint8Array(MAX_FRAGMENT_PAYLOAD * 2 + 5).map((_, i) => i & 0xff);
    const frags = fragment(msg);
    expect(frags.map((f) => [f[0], f.length])).toEqual([
      [1, MAX_FRAGMENT_PAYLOAD + 1],
      [1, MAX_FRAGMENT_PAYLOAD + 1],
      [0, 6],
    ]);
    const r = new Reassembler();
    expect(r.push(frags[0]!)).toBeNull();
    expect(r.push(frags[1]!)).toBeNull();
    expect(r.push(frags[2]!)).toEqual(msg);
    // and it is ready for the next one
    expect(r.push(Uint8Array.of(0, 9))).toEqual(Uint8Array.of(9));
  });

  it('keeps an exactly-full message in one fragment', () => {
    expect(fragment(new Uint8Array(MAX_FRAGMENT_PAYLOAD)).length).toBe(1);
  });

  it('treats framing errors as the end of the stream', () => {
    expect(() => new Reassembler().push(new Uint8Array())).toThrow();
    expect(() => new Reassembler().push(Uint8Array.of(2, 1))).toThrow();
    expect(() => new Reassembler().push(Uint8Array.of(1))).toThrow();
    const small = new Reassembler(4);
    small.push(Uint8Array.of(1, 1, 2, 3));
    expect(() => small.push(Uint8Array.of(0, 4, 5))).toThrow();
  });
});

describe('ICE servers', () => {
  it('turns the console list into RTCIceServer entries', () => {
    expect(parseIceServers('stun:stun.example.com, turn://rd:p%2Cw@turn.example.com:3479, stuns://[2001:db8::1]')).toEqual([
      { urls: 'stun:stun.example.com:3478' },
      { urls: 'turn:turn.example.com:3479', username: 'rd', credential: 'p,w' },
      { urls: 'stuns:[2001:db8::1]:3478' },
    ]);
  });

  it('skips what the client would skip', () => {
    expect(parseIceServers('turn:turn.example.com, stun:2001:db8::1, stun:x.example.com:99999, http://x, , stun:a.example.com/p')).toEqual([]);
  });
});
