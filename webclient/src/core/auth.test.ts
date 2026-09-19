import { describe, expect, it } from 'vitest';
import {
  Message,
  OptionMessage_BoolOption,
  SupportedDecoding,
} from '../gen/message';
import { buildLoginRequest } from './auth';

function decodeLogin(bytes: Uint8Array) {
  const message = Message.decode(bytes);
  if (message.union?.$case !== 'login_request') throw new Error('expected login request');
  return message.union.login_request;
}

describe('LoginRequest security options', () => {
  it('preserves an explicit manual lock request without placing an OS password in LoginRequest', () => {
    const secret = 'remote-os-secret';
    const request = decodeLogin(buildLoginRequest({
      peerId: '123456',
      passwordHash: new Uint8Array([1, 2, 3]),
      myId: 'controller',
      myName: 'Controller',
      sessionId: 42n,
      version: '1.4.0',
      supportedDecoding: SupportedDecoding.fromPartial({}),
      lockAfterSessionEnd: true,
    }));

    expect(request.option?.lock_after_session_end).toBe(OptionMessage_BoolOption.Yes);
    expect(request.os_login?.password ?? '').not.toBe(secret);
    expect(new TextDecoder().decode(Message.encode({
      union: { $case: 'login_request', login_request: request },
    }).finish())).not.toContain(secret);
  });
});
