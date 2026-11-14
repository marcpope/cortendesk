import { describe, expect, it, vi } from 'vitest';
import { decodeTerminalOutput } from './terminal-output';

describe('terminal output resource bounds', () => {
  it('rejects oversized raw and compressed frames before forwarding', () => {
    const oversized = new Uint8Array(17);
    expect(() => decodeTerminalOutput(oversized, false, vi.fn(), 16)).toThrow(/exceeds 16/);
    expect(() => decodeTerminalOutput(oversized, true, vi.fn(), 16)).toThrow(/exceeds 16/);
  });

  it('passes a fixed output capacity to zstd and rejects output beyond it', () => {
    const decode = vi.fn((_data: Uint8Array, limit: number) => new Uint8Array(limit + 1));
    expect(() => decodeTerminalOutput(new Uint8Array([1]), true, decode, 16)).toThrow(/exceeds 16/);
    expect(decode).toHaveBeenCalledWith(new Uint8Array([1]), 16);
  });

  it('forwards bounded output unchanged', () => {
    const expected = new Uint8Array([65, 66]);
    expect(decodeTerminalOutput(expected, false, vi.fn(), 16)).toBe(expected);
    expect(decodeTerminalOutput(new Uint8Array([1]), true, () => expected, 16)).toBe(expected);
  });
});
