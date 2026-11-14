import { describe, it, expect, beforeAll } from 'vitest';
import sodium from 'libsodium-wrappers';
import nacl from 'tweetnacl';
import { IdPk, Message } from '../gen/message';
import { sodiumReady } from './crypto';
import { decodeIdPk, verifyServerRelayPk, verifyPeerSignedId, buildPublicKeyMessage } from './handshake';

let serverKp: sodium.KeyPair;
let peerKp: sodium.KeyPair;

function signIdPk(id: string, pk: Uint8Array, signSk: Uint8Array): Uint8Array {
  // Attached signature: sig[0..64) || IdPk-bytes.
  return sodium.crypto_sign(IdPk.encode(IdPk.fromPartial({ id, pk })).finish(), signSk);
}

beforeAll(async () => {
  await sodiumReady();
  serverKp = sodium.crypto_sign_keypair();
  peerKp = sodium.crypto_sign_keypair();
});

describe('decodeIdPk', () => {
  it('recovers id and pk from an attached-signed IdPk', () => {
    const pk = sodium.randombytes_buf(32);
    const signed = signIdPk('123456789', pk, serverKp.privateKey);
    expect(signed.length).toBe(64 + IdPk.encode(IdPk.fromPartial({ id: '123456789', pk })).finish().length);
    const out = decodeIdPk(signed, serverKp.publicKey);
    expect(out.id).toBe('123456789');
    expect(out.pk).toEqual(pk);
  });

  it('throws when verified with the wrong ed25519 key', () => {
    const signed = signIdPk('123456789', sodium.randombytes_buf(32), serverKp.privateKey);
    expect(() => decodeIdPk(signed, peerKp.publicKey)).toThrow();
  });
});

describe('verifyServerRelayPk', () => {
  it('returns the peer ed25519 pk when the server-signed id matches', () => {
    const peerEdPk = peerKp.publicKey;
    const signed = signIdPk('123456789', peerEdPk, serverKp.privateKey);
    expect(verifyServerRelayPk(signed, serverKp.publicKey, '123456789')).toEqual(peerEdPk);
  });

  it('throws on id mismatch', () => {
    const signed = signIdPk('123456789', peerKp.publicKey, serverKp.privateKey);
    expect(() => verifyServerRelayPk(signed, serverKp.publicKey, '999999999')).toThrow(/mismatch/);
  });

  it('throws on a tampered signature', () => {
    const signed = signIdPk('123456789', peerKp.publicKey, serverKp.privateKey);
    const tampered = signed.slice();
    tampered[3] ^= 0xff; // inside sig[0..64)
    expect(() => verifyServerRelayPk(tampered, serverKp.publicKey, '123456789')).toThrow();
  });

  it('throws on a tampered payload byte after the signature', () => {
    const signed = signIdPk('123456789', peerKp.publicKey, serverKp.privateKey);
    const tampered = signed.slice();
    tampered[tampered.length - 1] ^= 0x01;
    expect(() => verifyServerRelayPk(tampered, serverKp.publicKey, '123456789')).toThrow();
  });
});

describe('verifyPeerSignedId', () => {
  it('returns id and the peer curve25519 box pk', () => {
    const boxKp = sodium.crypto_box_keypair();
    const signed = signIdPk('123456789', boxKp.publicKey, peerKp.privateKey);
    const out = verifyPeerSignedId(signed, peerKp.publicKey);
    expect(out.id).toBe('123456789');
    expect(out.boxPk).toEqual(boxKp.publicKey);
  });

  it('rejects a SignedId not signed by the peer key', () => {
    const boxKp = sodium.crypto_box_keypair();
    const signed = signIdPk('123456789', boxKp.publicKey, serverKp.privateKey);
    expect(() => verifyPeerSignedId(signed, peerKp.publicKey)).toThrow();
  });
});

describe('buildPublicKeyMessage', () => {
  it('encodes public_key{asymmetric_value: ephemeral pk, symmetric_value: 48-byte sealed key}', () => {
    const boxKp = sodium.crypto_box_keypair();
    const { bytes, key } = buildPublicKeyMessage(boxKp.publicKey);
    expect(key.length).toBe(32);

    const msg = Message.decode(bytes);
    expect(msg.union?.$case).toBe('public_key');
    if (msg.union?.$case !== 'public_key') return;
    const pkMsg = msg.union.public_key;
    expect(pkMsg.asymmetric_value.length).toBe(32);
    expect(pkMsg.symmetric_value.length).toBe(48);

    const opened = sodium.crypto_box_open_easy(
      pkMsg.symmetric_value,
      new Uint8Array(24),
      pkMsg.asymmetric_value,
      boxKp.privateKey,
    );
    expect(opened).toEqual(key);
  });

  it('cross-checks the seal with tweetnacl and fails under a non-zero nonce', () => {
    const boxKp = sodium.crypto_box_keypair();
    const { bytes, key } = buildPublicKeyMessage(boxKp.publicKey);
    const msg = Message.decode(bytes);
    if (msg.union?.$case !== 'public_key') throw new Error('wrong case');
    const pkMsg = msg.union.public_key;

    const opened = nacl.box.open(pkMsg.symmetric_value, new Uint8Array(24), pkMsg.asymmetric_value, boxKp.privateKey);
    expect(opened).not.toBeNull();
    expect(new Uint8Array(opened!)).toEqual(key);

    const badNonce = new Uint8Array(24);
    badNonce[0] = 1;
    expect(nacl.box.open(pkMsg.symmetric_value, badNonce, pkMsg.asymmetric_value, boxKp.privateKey)).toBeNull();
  });

  it('generates a fresh key and ephemeral pk per call', () => {
    const boxKp = sodium.crypto_box_keypair();
    const a = buildPublicKeyMessage(boxKp.publicKey);
    const b = buildPublicKeyMessage(boxKp.publicKey);
    expect(a.key).not.toEqual(b.key);
    expect(a.bytes).not.toEqual(b.bytes);
  });
});
