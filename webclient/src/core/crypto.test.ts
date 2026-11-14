import { describe, it, expect, beforeAll } from 'vitest';
import nacl from 'tweetnacl';
import sodium from 'libsodium-wrappers';
import { sodiumReady, decodeB64, signOpen, sealSymmetricKey, buildNonce, StreamCipher } from './crypto';

const ZERO24 = new Uint8Array(24);
const KEY = new Uint8Array(32).map((_, i) => i * 7 + 1);
const MSG = new TextEncoder().encode('cortendesk stream frame');

beforeAll(async () => {
  await sodiumReady();
});

describe('decodeB64', () => {
  it('decodes standard padded base64', () => {
    expect(Array.from(decodeB64('AQID'))).toEqual([1, 2, 3]);
    expect(Array.from(decodeB64('AQI='))).toEqual([1, 2]);
    const bytes = new Uint8Array(32).map((_, i) => 255 - i);
    expect(decodeB64(Buffer.from(bytes).toString('base64'))).toEqual(bytes);
  });
});

describe('buildNonce', () => {
  it('golden bytes: seq=1 little-endian, zero padding to 24', () => {
    const n = buildNonce(1n);
    expect(n.length).toBe(24);
    expect(Array.from(n)).toEqual([1, ...new Array(23).fill(0)]);
  });

  it('golden bytes: seq=258 (0x0102) little-endian', () => {
    const n = buildNonce(258n);
    expect(Array.from(n)).toEqual([0x02, 0x01, ...new Array(22).fill(0)]);
  });
});

describe('StreamCipher', () => {
  it('round-trips with roles swapped: A.send/B.recv and B.send/A.recv, first seq=1 each direction', () => {
    const a = new StreamCipher(KEY);
    const b = new StreamCipher(KEY);
    expect(Array.from(b.open(a.seal(MSG)))).toEqual(Array.from(MSG));
    expect(Array.from(a.open(b.seal(MSG)))).toEqual(Array.from(MSG));
    // second frame each direction interoperates too (both counters at 2)
    const m2 = new TextEncoder().encode('frame two');
    expect(Array.from(b.open(a.seal(m2)))).toEqual(Array.from(m2));
    expect(Array.from(a.open(b.seal(m2)))).toEqual(Array.from(m2));
  });

  it('seal output is tweetnacl secretbox with LE nonce seq=1 (cross-check)', () => {
    const a = new StreamCipher(KEY);
    const ct = a.seal(MSG);
    expect(ct.length).toBe(MSG.length + 16); // combined MAC||ct
    const pt = nacl.secretbox.open(ct, buildNonce(1n), KEY);
    expect(pt).not.toBeNull();
    expect(Array.from(pt!)).toEqual(Array.from(MSG));
    const ct2 = a.seal(MSG);
    expect(Array.from(nacl.secretbox.open(ct2, buildNonce(2n), KEY)!)).toEqual(Array.from(MSG));
  });

  it('len<=1 bypass returns input as-is and leaves recv counter untouched', () => {
    const rx = new StreamCipher(KEY);
    const one = new Uint8Array([0x2a]);
    expect(rx.open(one)).toBe(one);
    expect(rx.open(new Uint8Array(0)).length).toBe(0);
    // next real frame must still be seq=1
    const frame1 = nacl.secretbox(MSG, buildNonce(1n), KEY);
    expect(Array.from(rx.open(frame1))).toEqual(Array.from(MSG));
    expect(rx.open(one)).toBe(one);
    const frame2 = nacl.secretbox(MSG, buildNonce(2n), KEY);
    expect(Array.from(rx.open(frame2))).toEqual(Array.from(MSG));
  });

  it('NEGATIVE: big-endian nonce fails', () => {
    const be = new Uint8Array(24);
    new DataView(be.buffer).setBigUint64(0, 1n, false);
    const ct = nacl.secretbox(MSG, be, KEY);
    expect(() => new StreamCipher(KEY).open(ct)).toThrow();
  });

  it('NEGATIVE: non-pre-incremented counter (first seq=0) fails', () => {
    const ct = nacl.secretbox(MSG, buildNonce(0n), KEY);
    expect(() => new StreamCipher(KEY).open(ct)).toThrow();
  });

  it('NEGATIVE: nonzero padding in nonce bytes [8..24) fails', () => {
    const bad = buildNonce(1n);
    bad[23] = 1;
    const ct = nacl.secretbox(MSG, bad, KEY);
    expect(() => new StreamCipher(KEY).open(ct)).toThrow();
  });

  it('NEGATIVE: wrong key fails', () => {
    const ct = new StreamCipher(KEY).seal(MSG);
    const other = new Uint8Array(32).fill(9);
    expect(() => new StreamCipher(other).open(ct)).toThrow();
  });
});

describe('sealSymmetricKey', () => {
  it('produces 32-byte key, 32-byte ephemeral pk, 48-byte sealed blob', () => {
    const recipient = nacl.box.keyPair();
    const { ourBoxPk, sealed, key } = sealSymmetricKey(recipient.publicKey);
    expect(key.length).toBe(32);
    expect(ourBoxPk.length).toBe(32);
    expect(sealed.length).toBe(48);
  });

  it('recipient recovers key via crypto_box_open_easy with zero nonce (sodium + tweetnacl)', () => {
    const recipient = nacl.box.keyPair();
    const { ourBoxPk, sealed, key } = sealSymmetricKey(recipient.publicKey);
    const viaSodium = sodium.crypto_box_open_easy(sealed, ZERO24, ourBoxPk, recipient.secretKey);
    expect(Array.from(viaSodium)).toEqual(Array.from(key));
    const viaNacl = nacl.box.open(sealed, ZERO24, ourBoxPk, recipient.secretKey);
    expect(viaNacl).not.toBeNull();
    expect(Array.from(viaNacl!)).toEqual(Array.from(key));
  });

  it('uses a fresh ephemeral keypair and key each call', () => {
    const recipient = nacl.box.keyPair();
    const s1 = sealSymmetricKey(recipient.publicKey);
    const s2 = sealSymmetricKey(recipient.publicKey);
    expect(Array.from(s1.ourBoxPk)).not.toEqual(Array.from(s2.ourBoxPk));
    expect(Array.from(s1.key)).not.toEqual(Array.from(s2.key));
  });

  it('NEGATIVE: tampered sealed blob does not open', () => {
    const recipient = nacl.box.keyPair();
    const { ourBoxPk, sealed } = sealSymmetricKey(recipient.publicKey);
    sealed[0] ^= 0xff;
    expect(nacl.box.open(sealed, ZERO24, ourBoxPk, recipient.secretKey)).toBeNull();
  });
});

describe('signOpen', () => {
  it('opens an ATTACHED tweetnacl signature (sig[0..64) || message)', () => {
    const kp = nacl.sign.keyPair();
    const signed = nacl.sign(MSG, kp.secretKey);
    expect(signed.length).toBe(MSG.length + 64);
    expect(Array.from(signed.subarray(64))).toEqual(Array.from(MSG)); // attached layout
    expect(Array.from(signOpen(signed, kp.publicKey))).toEqual(Array.from(MSG));
  });

  it('NEGATIVE: throws on tampered signature and wrong public key', () => {
    const kp = nacl.sign.keyPair();
    const signed = nacl.sign(MSG, kp.secretKey);
    const tampered = signed.slice();
    tampered[10] ^= 1;
    expect(() => signOpen(tampered, kp.publicKey)).toThrow();
    expect(() => signOpen(signed, nacl.sign.keyPair().publicKey)).toThrow();
  });
});
