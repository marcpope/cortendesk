import { describe, it, expect } from 'vitest';
import { createHash } from 'node:crypto';
import { Message, SupportedDecoding, SupportedDecoding_PreferCodec } from '../gen/message';
import { loginPasswordHash, buildLoginRequest, computeLoginH1, loginHashFromH1 } from './auth';

describe('loginPasswordHash', () => {
  it('matches the golden vector: SHA256(SHA256(pw||salt)_raw || challenge)', async () => {
    const got = await loginPasswordHash('hunter2', 'abcd1234', 'efgh5678');
    expect(Buffer.from(got).toString('hex')).toBe(
      '8d85b52497b61b3207c1594131731772a489f99a6135885b30ba82b33be2fc3c',
    );
  });

  it('saved-hash path (reuse h1) yields the same login value as the plaintext path', async () => {
    // "Save password" stores h1 = SHA256(pw||salt); a later login reuses it with a
    // fresh challenge, and must produce the identical LoginRequest.password bytes.
    const h1 = await computeLoginH1('hunter2', 'abcd1234');
    const fromSaved = await loginHashFromH1(h1, 'newchallenge');
    const fromPlain = await loginPasswordHash('hunter2', 'abcd1234', 'newchallenge');
    expect(Buffer.from(fromSaved)).toEqual(Buffer.from(fromPlain));
  });

  it('cross-checks WebCrypto against node:crypto', async () => {
    const pw = 'påss wörd 你好';
    const salt = 'sAlT-42';
    const challenge = 'chAll-99';
    const h1 = createHash('sha256').update(Buffer.from(pw + salt, 'utf8')).digest();
    const expected = createHash('sha256')
      .update(Buffer.concat([h1, Buffer.from(challenge, 'utf8')]))
      .digest();
    const got = await loginPasswordHash(pw, salt, challenge);
    expect(got).toEqual(new Uint8Array(expected));
  });

  it('differs from the hex-encoded-h1 mistake (h1 must stay raw)', async () => {
    const h1 = createHash('sha256').update(Buffer.from('hunter2abcd1234', 'utf8')).digest();
    const wrong = createHash('sha256')
      .update(Buffer.concat([Buffer.from(h1.toString('hex'), 'utf8'), Buffer.from('efgh5678', 'utf8')]))
      .digest();
    expect(wrong.toString('hex')).toBe(
      '93b2344fcfd10ccbe4a879228923a9c5e8e8a0770410a8639db4721b12a48cb4',
    );
    const got = await loginPasswordHash('hunter2', 'abcd1234', 'efgh5678');
    expect(got).not.toEqual(new Uint8Array(wrong));
  });

  it('returns empty bytes for an empty password', async () => {
    const got = await loginPasswordHash('', 'salt', 'challenge');
    expect(got.length).toBe(0);
  });
});

describe('buildLoginRequest', () => {
  const supportedDecoding = SupportedDecoding.fromPartial({
    ability_vp9: 1,
    ability_vp8: 1,
    ability_h264: 1,
    ability_av1: 1,
    prefer: SupportedDecoding_PreferCodec.Auto,
  });

  it('encodes a login_request Message with all required fields', () => {
    const passwordHash = new Uint8Array(32).fill(7);
    const bytes = buildLoginRequest({
      peerId: '123456789',
      passwordHash,
      myId: 'web-1',
      myName: 'CortenDesk Web',
      sessionId: 0x1122334455667788n,
      version: '1.3.8',
      supportedDecoding,
    });

    const msg = Message.decode(bytes);
    expect(msg.union?.$case).toBe('login_request');
    if (msg.union?.$case !== 'login_request') return;
    const lr = msg.union.login_request;
    expect(lr.username).toBe('123456789');
    expect(lr.password).toEqual(passwordHash);
    expect(lr.my_id).toBe('web-1');
    expect(lr.my_name).toBe('CortenDesk Web');
    expect(lr.session_id).toBe(0x1122334455667788n);
    expect(lr.version).toBe('1.3.8');
    expect(lr.video_ack_required).toBe(true);
    expect(lr.option?.supported_decoding).toEqual(supportedDecoding);
    expect(lr.union).toBeUndefined(); // no file_transfer/port_forward/terminal sub-case
  });

  it('round-trips an empty password hash (password-less login)', () => {
    const bytes = buildLoginRequest({
      peerId: '123456789',
      passwordHash: new Uint8Array(0),
      myId: 'web-1',
      myName: 'w',
      sessionId: 1n,
      version: '1.3.8',
      supportedDecoding,
    });
    const msg = Message.decode(bytes);
    if (msg.union?.$case !== 'login_request') throw new Error('wrong case');
    expect(msg.union.login_request.password.length).toBe(0);
    expect(msg.union.login_request.session_id).toBe(1n);
  });
});
