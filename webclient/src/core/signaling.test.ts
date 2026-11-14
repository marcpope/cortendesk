import { describe, it, expect } from 'vitest';
import {
  ConnType,
  NatType,
  PunchHoleResponse_Failure,
  RendezvousMessage,
} from '../gen/rendezvous';
import { buildPunchHoleRequest, buildOnlineRequest, isOnline, parseRendezvous } from './signaling';

describe('buildPunchHoleRequest', () => {
  it('encodes a force-relay DEFAULT_CONN request', () => {
    const bytes = buildPunchHoleRequest({ peerId: '123456789', licenceKey: 'somekey', version: '1.3.0' });
    const rmsg = RendezvousMessage.decode(bytes);
    expect(rmsg.union?.$case).toBe('punch_hole_request');
    if (rmsg.union?.$case !== 'punch_hole_request') return;
    const req = rmsg.union.punch_hole_request;
    expect(req.id).toBe('123456789');
    expect(req.licence_key).toBe('somekey');
    expect(req.conn_type).toBe(ConnType.DEFAULT_CONN);
    expect(req.nat_type).toBe(NatType.SYMMETRIC);
    expect(req.force_relay).toBe(true);
    expect(req.version).toBe('1.3.0');
    expect(req.token).toBe('');
  });

  it('carries the console ticket as the token', () => {
    const bytes = buildPunchHoleRequest({ peerId: '1', licenceKey: 'k', version: '1', token: 'cdw1.9.web-1.ab' });
    const rmsg = RendezvousMessage.decode(bytes);
    if (rmsg.union?.$case !== 'punch_hole_request') throw new Error('not a punch hole request');
    expect(rmsg.union.punch_hole_request.token).toBe('cdw1.9.web-1.ab');
  });
});

describe('parseRendezvous', () => {
  it('parses relay_response with pk', () => {
    const pk = new Uint8Array([1, 2, 3, 4]);
    const bytes = RendezvousMessage.encode({
      union: {
        $case: 'relay_response',
        relay_response: {
          socket_addr: new Uint8Array(),
          uuid: 'uuid-1',
          webrtc_sdp_answer: '',
          relay_server: 'relay.example.com:21117',
          union: { $case: 'pk', pk },
          refuse_reason: '',
          version: '1.3.0',
          feedback: 0,
          socket_addr_v6: new Uint8Array(),
          upnp_port: 0,
        },
      },
    }).finish();
    const parsed = parseRendezvous(bytes);
    expect(parsed).toEqual({
      kind: 'relayResponse',
      uuid: 'uuid-1',
      relayServer: 'relay.example.com:21117',
      pk,
      webrtcAnswer: '',
    });
  });

  it('parses relay_response without pk', () => {
    const bytes = RendezvousMessage.encode({
      union: {
        $case: 'relay_response',
        relay_response: {
          socket_addr: new Uint8Array(),
          uuid: 'uuid-2',
          webrtc_sdp_answer: '',
          relay_server: '',
          union: undefined,
          refuse_reason: '',
          version: '',
          feedback: 0,
          socket_addr_v6: new Uint8Array(),
          upnp_port: 0,
        },
      },
    }).finish();
    const parsed = parseRendezvous(bytes);
    expect(parsed.kind).toBe('relayResponse');
    if (parsed.kind !== 'relayResponse') return;
    expect(parsed.uuid).toBe('uuid-2');
    expect(parsed.pk).toBeUndefined();
  });

  const punchHole = (fields: { failure?: PunchHoleResponse_Failure; other_failure?: string; socket_addr?: Uint8Array }) =>
    RendezvousMessage.encode({
      union: {
        $case: 'punch_hole_response',
        punch_hole_response: {
          socket_addr: fields.socket_addr ?? new Uint8Array(),
          webrtc_sdp_answer: '',
          pk: new Uint8Array(),
          failure: fields.failure ?? PunchHoleResponse_Failure.ID_NOT_EXIST,
          relay_server: '',
          union: undefined,
          other_failure: fields.other_failure ?? '',
          feedback: 0,
          is_udp: false,
          upnp_port: 0,
          socket_addr_v6: new Uint8Array(),
        },
      },
    }).finish();

  it.each([
    [PunchHoleResponse_Failure.ID_NOT_EXIST, 'ID_NOT_EXIST'],
    [PunchHoleResponse_Failure.OFFLINE, 'OFFLINE'],
    [PunchHoleResponse_Failure.LICENSE_MISMATCH, 'LICENSE_MISMATCH'],
    [PunchHoleResponse_Failure.LICENSE_OVERUSE, 'LICENSE_OVERUSE'],
  ])('maps punch_hole_response failure enum %i -> %s', (failure, expected) => {
    expect(parseRendezvous(punchHole({ failure }))).toEqual({ kind: 'punchHoleResponse', failure: expected });
  });

  it('maps other_failure to OTHER and keeps the server text', () => {
    expect(parseRendezvous(punchHole({ other_failure: 'nope' }))).toEqual({
      kind: 'punchHoleResponse',
      failure: 'OTHER',
      message: 'nope',
    });
  });

  it('treats non-empty socket_addr as success (no failure)', () => {
    expect(parseRendezvous(punchHole({ socket_addr: new Uint8Array([1, 2, 3, 4, 5, 6]) }))).toEqual({
      kind: 'punchHoleResponse',
    });
  });

  it('parses online_response states', () => {
    const states = new Uint8Array([0b10100000]);
    const bytes = RendezvousMessage.encode({
      union: { $case: 'online_response', online_response: { states } },
    }).finish();
    expect(parseRendezvous(bytes)).toEqual({ kind: 'onlineResponse', states });
  });

  it('returns other for unhandled cases', () => {
    const bytes = RendezvousMessage.encode({
      union: { $case: 'configure_update', configure_update: { serial: 1, rendezvous_servers: [] } },
    }).finish();
    expect(parseRendezvous(bytes)).toEqual({ kind: 'other', $case: 'configure_update' });
  });

  it('returns other/none for an empty message', () => {
    const bytes = RendezvousMessage.encode({ union: undefined }).finish();
    expect(parseRendezvous(bytes)).toEqual({ kind: 'other', $case: 'none' });
  });
});

describe('buildOnlineRequest', () => {
  it('round-trips id and peers', () => {
    const bytes = buildOnlineRequest({ myId: 'me-1', peerIds: ['a', 'b', 'c'] });
    const rmsg = RendezvousMessage.decode(bytes);
    expect(rmsg.union?.$case).toBe('online_request');
    if (rmsg.union?.$case !== 'online_request') return;
    expect(rmsg.union.online_request.id).toBe('me-1');
    expect(rmsg.union.online_request.peers).toEqual(['a', 'b', 'c']);
  });
});

describe('isOnline', () => {
  it('reads bits MSB-first within each byte', () => {
    const states = new Uint8Array([0b10000001, 0b01000000]);
    expect(isOnline(states, 0)).toBe(true);
    expect(isOnline(states, 1)).toBe(false);
    expect(isOnline(states, 6)).toBe(false);
    expect(isOnline(states, 7)).toBe(true);
    expect(isOnline(states, 8)).toBe(false);
    expect(isOnline(states, 9)).toBe(true);
    expect(isOnline(states, 15)).toBe(false);
  });

  it('is false past the end of states', () => {
    expect(isOnline(new Uint8Array([0xff]), 8)).toBe(false);
    expect(isOnline(new Uint8Array(), 0)).toBe(false);
  });
});
