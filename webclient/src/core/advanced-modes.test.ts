import { describe, expect, it } from 'vitest';
import {
  Message,
  OptionMessage_BoolOption,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
} from '../gen/message';
import { buildLoginRequest } from './auth';

const decoding = SupportedDecoding.fromPartial({
  ability_vp9: 1,
  ability_vp8: 1,
  prefer: SupportedDecoding_PreferCodec.Auto,
});

function decodeLogin(bytes: Uint8Array) {
  const message = Message.decode(bytes);
  expect(message.union?.$case).toBe('login_request');
  if (message.union?.$case !== 'login_request') throw new Error('not login request');
  return message.union.login_request;
}

const base = {
  peerId: '123456789',
  passwordHash: new Uint8Array([1, 2, 3]),
  myId: 'browser',
  myName: 'Browser',
  sessionId: 10n,
  version: '1.4.0',
  supportedDecoding: decoding,
};

describe('advanced connection login messages', () => {
  it('builds a view-camera login while retaining video decoding options', () => {
    const login = decodeLogin(buildLoginRequest({ ...base, viewCamera: true }));
    expect(login.union).toEqual({ $case: 'view_camera', view_camera: {} });
    expect(login.option?.supported_decoding?.ability_vp9).toBe(1);
    expect(login.video_ack_required).toBe(true);
  });

  it('builds a persistent terminal login with a service id and no video options', () => {
    const login = decodeLogin(buildLoginRequest({
      ...base,
      terminal: { serviceId: 'terminal-service-42', persistent: true },
    }));
    expect(login.union).toEqual({
      $case: 'terminal',
      terminal: { service_id: 'terminal-service-42' },
    });
    expect(login.option?.terminal_persistent).toBe(OptionMessage_BoolOption.Yes);
    expect(login.option?.supported_decoding).toBeUndefined();
    expect(login.video_ack_required).toBe(false);
  });
});
