import { describe, it, expect } from 'vitest';
import { ClipboardFormat } from '../gen/message';
import type { Clipboard, CursorData } from '../gen/message';
import { cursorToDataUrl, decodeClipboardText, initZstd, readLocalClipboardText } from './clipboard-cursor';

function clip(over: Partial<Clipboard>): Clipboard {
  return {
    compress: false,
    content: new Uint8Array(0),
    width: 0,
    height: 0,
    format: ClipboardFormat.Text,
    special_name: '',
    ...over,
  };
}

// `printf 'hello cortendesk' > f && zstd --no-check f` — frame header carries
// the content size (0x10), matching RustDesk's bulk-compressor output.
const ZSTD_HELLO = Uint8Array.from([
  0x28, 0xb5, 0x2f, 0xfd, 0x20, 0x10, 0x81, 0x00, 0x00,
  0x68, 0x65, 0x6c, 0x6c, 0x6f, 0x20, 0x63, 0x6f, 0x72, 0x74, 0x65, 0x6e, 0x64, 0x65, 0x73, 0x6b,
]);

describe('decodeClipboardText', () => {
  it('decodes uncompressed Text content as UTF-8', () => {
    const c = clip({ content: new TextEncoder().encode('hello') });
    expect(decodeClipboardText(c)).toBe('hello');
  });

  it('decodes uncompressed multibyte UTF-8', () => {
    const c = clip({ content: new TextEncoder().encode('héllo — ☃') });
    expect(decodeClipboardText(c)).toBe('héllo — ☃');
  });

  it('returns null for non-Text formats', () => {
    expect(decodeClipboardText(clip({ format: ClipboardFormat.Html, content: new TextEncoder().encode('<b>x</b>') }))).toBeNull();
    expect(decodeClipboardText(clip({ format: ClipboardFormat.ImagePng }))).toBeNull();
  });

  it('throws on compressed content before initZstd', () => {
    const c = clip({ compress: true, content: ZSTD_HELLO });
    expect(() => decodeClipboardText(c)).toThrowError(/initZstd/);
  });

  it('zstd-decompresses compressed Text after initZstd', async () => {
    await initZstd();
    const c = clip({ compress: true, content: ZSTD_HELLO });
    expect(decodeClipboardText(c)).toBe('hello cortendesk');
  });
});

describe('cursorToDataUrl', () => {
  it('throws a clear error where OffscreenCanvas is absent', async () => {
    const cursor: CursorData = { id: 1n, hotx: 0, hoty: 0, width: 2, height: 2, colors: new Uint8Array(0) };
    if (typeof OffscreenCanvas !== 'undefined') return; // browser-only path exercised elsewhere
    await expect(cursorToDataUrl(cursor)).rejects.toThrowError(/OffscreenCanvas/);
  });
});

describe('readLocalClipboardText', () => {
  it('resolves null when navigator.clipboard is unavailable', async () => {
    if (typeof navigator !== 'undefined' && typeof navigator.clipboard?.readText === 'function') return;
    await expect(readLocalClipboardText()).resolves.toBeNull();
  });
});
