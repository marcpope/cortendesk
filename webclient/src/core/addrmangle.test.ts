import { describe, it, expect } from 'vitest';
import { decodeAddr, encodeAddr } from './addrmangle';

describe('addrmangle v4', () => {
  it('decodes known-good vectors', () => {
    // 192.168.1.100:21118 mangled with tm=0x12345678
    expect(decodeAddr(new Uint8Array([246, 168, 240, 172, 104, 36, 112, 254, 107, 236]))).toEqual({
      ip: '192.168.1.100',
      port: 21118,
    });
    // 10.0.0.1:8080 with tm=0xFFFFFFFF (carry into every field)
    expect(decodeAddr(new Uint8Array([143, 31, 255, 255, 255, 255, 19, 0, 0, 2, 2]))).toEqual({
      ip: '10.0.0.1',
      port: 8080,
    });
    // 127.0.0.1:21117 with tm=0 (degenerate, no obfuscation)
    expect(decodeAddr(new Uint8Array([125, 82, 0, 0, 0, 0, 254, 0, 0, 2]))).toEqual({
      ip: '127.0.0.1',
      port: 21117,
    });
  });

  it('encodes known vectors deterministically for a fixed tm', () => {
    expect(encodeAddr({ ip: '192.168.1.100', port: 21118 }, 0x12345678n)).toEqual(
      new Uint8Array([246, 168, 240, 172, 104, 36, 112, 254, 107, 236]),
    );
    expect(encodeAddr({ ip: '10.0.0.1', port: 8080 }, 0xffffffffn)).toEqual(
      new Uint8Array([143, 31, 255, 255, 255, 255, 19, 0, 0, 2, 2]),
    );
  });

  it('truncates tm to u32 like the Rust cast', () => {
    expect(encodeAddr({ ip: '10.0.0.1', port: 8080 }, 0x1_ffff_ffffn)).toEqual(
      encodeAddr({ ip: '10.0.0.1', port: 8080 }, 0xffffffffn),
    );
  });

  it('round-trips with the current clock', () => {
    for (const addr of [
      { ip: '0.0.0.0', port: 0 },
      { ip: '255.255.255.255', port: 65535 },
      { ip: '203.0.113.7', port: 21116 },
    ]) {
      expect(decodeAddr(encodeAddr(addr))).toEqual(addr);
    }
  });

  it('rejects malformed input', () => {
    expect(() => encodeAddr({ ip: '1.2.3', port: 80 })).toThrow();
    expect(() => encodeAddr({ ip: '1.2.3.256', port: 80 })).toThrow();
    expect(() => encodeAddr({ ip: '1.2.3.4', port: 65536 })).toThrow();
  });
});

describe('addrmangle v6', () => {
  it('encodes as 16 address bytes plus LE port', () => {
    const bytes = encodeAddr({ ip: '2001:db8::1', port: 21118 });
    expect(bytes).toEqual(
      new Uint8Array([0x20, 0x01, 0x0d, 0xb8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0x7e, 0x52]),
    );
  });

  it('decodes to uncompressed group notation', () => {
    const bytes = new Uint8Array([0x20, 0x01, 0x0d, 0xb8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0x7e, 0x52]);
    expect(decodeAddr(bytes)).toEqual({ ip: '2001:db8:0:0:0:0:0:1', port: 21118 });
  });

  it('round-trips full and compressed forms', () => {
    for (const addr of [
      { ip: 'fe80:0:0:0:0:0:0:1', port: 1 },
      { ip: 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', port: 65535 },
    ]) {
      expect(decodeAddr(encodeAddr(addr))).toEqual(addr);
    }
    expect(decodeAddr(encodeAddr({ ip: '::1', port: 9 }))).toEqual({ ip: '0:0:0:0:0:0:0:1', port: 9 });
  });

  it('rejects malformed input', () => {
    expect(() => encodeAddr({ ip: '1::2::3', port: 1 })).toThrow();
    expect(() => encodeAddr({ ip: '1:2:3:4:5:6:7:8:9', port: 1 })).toThrow();
    expect(() => decodeAddr(new Uint8Array(17))).toThrow();
  });
});
