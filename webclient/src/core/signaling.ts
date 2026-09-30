import {
  ConnType,
  NatType,
  OnlineRequest,
  PunchHoleRequest,
  PunchHoleResponse_Failure,
  RendezvousMessage,
} from '../gen/rendezvous';

export function buildPunchHoleRequest(o: {
  peerId: string;
  licenceKey: string;
  version: string;
  connType?: ConnType;
  /** Console-signed ticket; lets the ID server admit this client in approved-only mode. */
  token?: string;
}): Uint8Array {
  return RendezvousMessage.encode({
    union: {
      $case: 'punch_hole_request',
      punch_hole_request: PunchHoleRequest.fromPartial({
        id: o.peerId,
        licence_key: o.licenceKey,
        conn_type: o.connType ?? ConnType.DEFAULT_CONN,
        nat_type: NatType.SYMMETRIC,
        force_relay: true,
        version: o.version,
        token: o.token ?? '',
      }),
    },
  }).finish();
}

export type RendezvousFailure = 'ID_NOT_EXIST' | 'OFFLINE' | 'LICENSE_MISMATCH' | 'LICENSE_OVERUSE' | 'OTHER';

export type RendezvousParsed =
  | { kind: 'relayResponse'; uuid: string; relayServer: string; pk?: Uint8Array }
  | { kind: 'punchHoleResponse'; failure?: RendezvousFailure; message?: string }
  | { kind: 'onlineResponse'; states: Uint8Array }
  | { kind: 'other'; $case: string };

function mapFailure(f: PunchHoleResponse_Failure): RendezvousFailure {
  switch (f) {
    case PunchHoleResponse_Failure.ID_NOT_EXIST:
      return 'ID_NOT_EXIST';
    case PunchHoleResponse_Failure.OFFLINE:
      return 'OFFLINE';
    case PunchHoleResponse_Failure.LICENSE_MISMATCH:
      return 'LICENSE_MISMATCH';
    case PunchHoleResponse_Failure.LICENSE_OVERUSE:
      return 'LICENSE_OVERUSE';
    default:
      return 'OTHER';
  }
}

export function parseRendezvous(bytes: Uint8Array): RendezvousParsed {
  const rmsg = RendezvousMessage.decode(bytes);
  switch (rmsg.union?.$case) {
    case 'relay_response': {
      const rr = rmsg.union.relay_response;
      return {
        kind: 'relayResponse',
        uuid: rr.uuid,
        relayServer: rr.relay_server,
        pk: rr.union?.$case === 'pk' ? rr.union.pk : undefined,
      };
    }
    case 'punch_hole_response': {
      const pr = rmsg.union.punch_hole_response;
      // failure enum defaults to ID_NOT_EXIST(0); a non-empty socket_addr is the
      // success signal (matches the official client's is_empty() check).
      if (pr.socket_addr.length > 0) return { kind: 'punchHoleResponse' };
      if (pr.other_failure !== '') return { kind: 'punchHoleResponse', failure: 'OTHER', message: pr.other_failure };
      return { kind: 'punchHoleResponse', failure: mapFailure(pr.failure) };
    }
    case 'online_response':
      return { kind: 'onlineResponse', states: rmsg.union.online_response.states };
    default:
      return { kind: 'other', $case: rmsg.union?.$case ?? 'none' };
  }
}

export function buildOnlineRequest(o: { myId: string; peerIds: string[] }): Uint8Array {
  return RendezvousMessage.encode({
    union: {
      $case: 'online_request',
      online_request: OnlineRequest.fromPartial({ id: o.myId, peers: o.peerIds }),
    },
  }).finish();
}

// hbbs packs presence MSB-first: peer i lives in byte i>>3, bit (7 - i%8).
export function isOnline(states: Uint8Array, index: number): boolean {
  const byte = states[index >> 3];
  if (byte === undefined) return false;
  return ((byte >> (7 - (index & 7))) & 1) === 1;
}
