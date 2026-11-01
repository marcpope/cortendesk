import sodium from 'libsodium-wrappers';
import type { Encryptor } from './contracts';

export async function sodiumReady(): Promise<void> {
  await sodium.ready;
}

export function decodeB64(s: string): Uint8Array {
  return sodium.from_base64(s, sodium.base64_variants.ORIGINAL);
}

// Attached signature: signed = sig[0..64) || message. Throws on bad signature.
export function signOpen(signed: Uint8Array, ed25519Pk: Uint8Array): Uint8Array {
  return sodium.crypto_sign_open(signed, ed25519Pk);
}

const ZERO_NONCE_24 = new Uint8Array(24);

// key = 32 random bytes, sealed with a FRESH ephemeral box keypair under an
// all-zero 24-byte nonce -> 48 bytes (16 MAC + 32 ct). ourBoxPk = ephemeral pk.
export function sealSymmetricKey(theirBoxPk: Uint8Array): { ourBoxPk: Uint8Array; sealed: Uint8Array; key: Uint8Array } {
  const key = sodium.randombytes_buf(32);
  const eph = sodium.crypto_box_keypair();
  const sealed = sodium.crypto_box_easy(key, ZERO_NONCE_24, theirBoxPk, eph.privateKey);
  return { ourBoxPk: eph.publicKey, sealed, key };
}

// bytes[0..8) = seq as LITTLE-ENDIAN u64, bytes[8..24) = 0.
export function buildNonce(seq: bigint): Uint8Array {
  const n = new Uint8Array(24);
  new DataView(n.buffer).setBigUint64(0, seq, true);
  return n;
}

// RustDesk 1.5 WebRTC: DTLS encrypts the data channel and the protocol adds
// no secretbox layer on top, so messages pass through as they are.
export class PlainChannel implements Encryptor {
  seal(pt: Uint8Array): Uint8Array {
    return pt;
  }

  open(ct: Uint8Array): Uint8Array {
    return ct;
  }
}

export class StreamCipher implements Encryptor {
  private sendSeq = 0n;
  private recvSeq = 0n;
  private readonly key: Uint8Array;

  constructor(key: Uint8Array) {
    this.key = key;
  }

  seal(pt: Uint8Array): Uint8Array {
    this.sendSeq++; // pre-increment: first frame uses seq=1
    return sodium.crypto_secretbox_easy(pt, buildNonce(this.sendSeq), this.key);
  }

  open(ct: Uint8Array): Uint8Array {
    if (ct.length <= 1) return ct; // bypass: must not consume recvSeq
    this.recvSeq++;
    return sodium.crypto_secretbox_open_easy(ct, buildNonce(this.recvSeq), this.key);
  }
}
