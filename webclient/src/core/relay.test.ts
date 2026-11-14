import { describe, it, expect } from 'vitest';
import { ConnType, RendezvousMessage } from '../gen/rendezvous';
import { buildRequestRelay } from './relay';

describe('buildRequestRelay', () => {
  it('encodes id/uuid/licence_key with DEFAULT_CONN by default', () => {
    const bytes = buildRequestRelay({ licenceKey: 'key-1', peerId: '987654321', uuid: 'uuid-xyz' });
    const rmsg = RendezvousMessage.decode(bytes);
    expect(rmsg.union?.$case).toBe('request_relay');
    if (rmsg.union?.$case !== 'request_relay') return;
    const rr = rmsg.union.request_relay;
    expect(rr.id).toBe('987654321');
    expect(rr.uuid).toBe('uuid-xyz');
    expect(rr.licence_key).toBe('key-1');
    expect(rr.conn_type).toBe(ConnType.DEFAULT_CONN);
    expect(rr.secure).toBe(false);
    expect(rr.socket_addr.length).toBe(0);
  });

  it('honors an explicit conn type', () => {
    const bytes = buildRequestRelay({
      licenceKey: '',
      peerId: 'p',
      uuid: 'u',
      connType: ConnType.FILE_TRANSFER,
    });
    const rmsg = RendezvousMessage.decode(bytes);
    if (rmsg.union?.$case !== 'request_relay') throw new Error('wrong case');
    expect(rmsg.union.request_relay.conn_type).toBe(ConnType.FILE_TRANSFER);
  });
});
