import { describe, it, expect } from 'vitest';
import { sha256 } from './sha256';
import { createHash } from 'node:crypto';

const hex = (b: Uint8Array): string =>
  [...b].map((x) => x.toString(16).padStart(2, '0')).join('');

describe('sha256', () => {
  // FIPS 180-4 / NIST published vectors.
  it('matches the NIST vectors', () => {
    expect(hex(sha256(new Uint8Array(0)))).toBe(
      'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    );
    expect(hex(sha256(new TextEncoder().encode('abc')))).toBe(
      'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    );
    expect(
      hex(sha256(new TextEncoder().encode('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'))),
    ).toBe('248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1');
  });

  // Block-boundary handling is where hand-written SHA-256 usually breaks: the
  // padding either fits, exactly fills, or spills into another block.
  it('agrees with node:crypto across every length around the block boundaries', () => {
    for (const len of [1, 54, 55, 56, 57, 63, 64, 65, 119, 120, 127, 128, 129, 1000]) {
      const data = new Uint8Array(len);
      for (let i = 0; i < len; i++) data[i] = (i * 31 + 7) & 0xff;
      const expected = createHash('sha256').update(data).digest('hex');
      expect(hex(sha256(data)), `length ${len}`).toBe(expected);
    }
  });

  it('handles high bytes and multi-byte utf8 without sign errors', () => {
    const data = new TextEncoder().encode('pässwörd·🙂·salt');
    expect(hex(sha256(data))).toBe(createHash('sha256').update(data).digest('hex'));
  });
});
