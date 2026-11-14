import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { SupportedDecoding_PreferCodec } from '../gen/message';
import {
  adaptFps,
  bestFitResolution,
  buildResolutionChoices,
  canUseRemoteCursor,
  canToggleVirtualDisplay,
  codecPreferenceValue,
  mapRemoteCursorToCanvas,
  mergeDisplayRefresh,
  mergePlatformAdditions,
  parseVirtualDisplayCapability,
} from './display-controls';

describe('canToggleVirtualDisplay', () => {
  it('only enables virtual-display controls for installed Windows peers with a supported IDD', () => {
    expect(
      canToggleVirtualDisplay('Windows', '{"is_installed":true,"idd_impl":"rustdesk_idd"}'),
    ).toBe(true);
    expect(
      canToggleVirtualDisplay('Windows', '{"is_installed":true,"idd_impl":"amyuni_idd"}'),
    ).toBe(true);
    expect(
      canToggleVirtualDisplay('Windows', '{"is_installed":false,"idd_impl":"rustdesk_idd"}'),
    ).toBe(false);
    expect(
      canToggleVirtualDisplay('Linux', '{"is_installed":true,"idd_impl":"rustdesk_idd"}'),
    ).toBe(false);
    expect(canToggleVirtualDisplay('Windows', '{not json')).toBe(false);
  });
});

describe('bestFitResolution', () => {
  it('uses an advertised physical mode but allows a browser-local mode for virtual displays', () => {
    const supported = [
      { width: 1280, height: 720 },
      { width: 1920, height: 1080 },
    ];

    expect(bestFitResolution(1920, 1080, supported, false)).toEqual({ width: 1920, height: 1080 });
    expect(bestFitResolution(1366, 768, supported, false)).toBeNull();
    expect(bestFitResolution(1366, 768, supported, true)).toEqual({ width: 1366, height: 768 });
  });
});

describe('virtual display metadata', () => {
  it('preserves stable installed capability while replacing transient display state', () => {
    const merged = mergePlatformAdditions(
      '{"is_installed":true,"support_view_camera":true,"idd_impl":"rustdesk_idd","rustdesk_virtual_displays":[1]}',
      '{"idd_impl":"rustdesk_idd"}',
    );
    expect(JSON.parse(merged)).toEqual({
      is_installed: true,
      support_view_camera: true,
      idd_impl: 'rustdesk_idd',
    });
  });

  it('preserves the stable IDD implementation across partial transient refreshes', () => {
    const merged = mergePlatformAdditions(
      '{"is_installed":true,"idd_impl":"rustdesk_idd","rustdesk_virtual_displays":[1]}',
      '{"rustdesk_virtual_displays":[2]}',
    );

    expect(parseVirtualDisplayCapability('Windows', merged)).toEqual({
      impl: 'rustdesk_idd', rustdeskIds: [2], amyuniCount: 0,
    });
  });

  it('parses RustDesk and Amyuni state without trusting malformed additions', () => {
    expect(parseVirtualDisplayCapability('Windows', '{"is_installed":true,"idd_impl":"rustdesk_idd","rustdesk_virtual_displays":[1,3]}')).toEqual({
      impl: 'rustdesk_idd', rustdeskIds: [1, 3], amyuniCount: 0,
    });
    expect(parseVirtualDisplayCapability('Windows', '{"is_installed":true,"idd_impl":"amyuni_idd","amyuni_virtual_displays":2}')).toEqual({
      impl: 'amyuni_idd', rustdeskIds: [], amyuniCount: 2,
    });
    expect(parseVirtualDisplayCapability('Linux', '{}')).toBeNull();
  });
});

describe('display refresh merging', () => {
  it('drops stale active geometry when the active display disappears', () => {
    const existing = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 1, x: 1920, y: 0, width: 1600, height: 900, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
    ];
    const incoming = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
    ];

    const merged = mergeDisplayRefresh(existing, incoming, 1);

    expect(merged[1]).toBeUndefined();
  });
});

describe('adaptive fps', () => {
  it('backs off on dropped frames and cautiously recovers after a stable window', () => {
    expect(adaptFps({ target: 60, droppedDelta: 2, stableSamples: 0, cap: 60 })).toEqual({ target: 50, stableSamples: 0 });
    expect(adaptFps({ target: 30, droppedDelta: 0, stableSamples: 4, cap: 60 })).toEqual({ target: 35, stableSamples: 0 });
    expect(adaptFps({ target: 5, droppedDelta: 9, stableSamples: 0, cap: 60 }).target).toBe(5);
  });
});

describe('remote cursor mapping', () => {
  it('fails closed for embedded and mobile remote cursors', () => {
    expect(canUseRemoteCursor('Windows', { cursorEmbedded: false })).toBe(true);
    expect(canUseRemoteCursor('Windows', { cursorEmbedded: true })).toBe(false);
    expect(canUseRemoteCursor('Android', { cursorEmbedded: false })).toBe(false);
    expect(canUseRemoteCursor('iOS', { cursorEmbedded: false })).toBe(false);
    expect(canUseRemoteCursor('Windows', undefined)).toBe(false);
  });

  it('applies the remote display scale before mapping HiDPI cursor coordinates', () => {
    expect(mapRemoteCursorToCanvas(
      { x: 640, y: 360 },
      { x: 0, y: 0, width: 2560, height: 1440, scale: 2 },
      { left: 10, top: 20, width: 1280, height: 720 },
    )).toEqual({ x: 650, y: 380 });
  });

  it('maps absolute remote coordinates through the authoritative display and canvas rectangles', () => {
    expect(mapRemoteCursorToCanvas(
      { x: 2020, y: 100 },
      { x: 1920, y: 0, width: 1600, height: 900 },
      { left: 20, top: 40, width: 800, height: 450 },
    )).toEqual({ x: 70, y: 90 });
    expect(mapRemoteCursorToCanvas(
      { x: 100, y: 100 },
      { x: 1920, y: 0, width: 1600, height: 900 },
      { left: 20, top: 40, width: 800, height: 450 },
    )).toBeNull();
  });
});

describe('display menu models', () => {
  it('deduplicates advertised, original, and fit resolutions while keeping safe labels', () => {
    expect(buildResolutionChoices({
      supported: [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }],
      original: { width: 1920, height: 1080 },
      fit: { width: 1280, height: 720 },
      isVirtual: false,
    })).toEqual([
      { label: '1280×720', width: 1280, height: 720 },
      { label: '1920×1080 (original)', width: 1920, height: 1080 },
    ]);
  });

  it('maps known codec names with the generated RustDesk enum', () => {
    expect(codecPreferenceValue('auto')).toBe(SupportedDecoding_PreferCodec.Auto);
    expect(codecPreferenceValue('h264')).toBe(SupportedDecoding_PreferCodec.H264);
    expect(codecPreferenceValue('bogus')).toBeNull();
    const source = readFileSync(new URL('./display-controls.ts', import.meta.url), 'utf8');
    const contracts = readFileSync(new URL('../core/contracts.ts', import.meta.url), 'utf8');
    const worker = readFileSync(new URL('../worker/session.worker.ts', import.meta.url), 'utf8');
    expect(source).toContain('SupportedDecoding_PreferCodec.H264');
    expect(source).not.toContain('{ auto: 0, vp9: 1');
    expect(contracts).toContain("prefer:SupportedDecoding_PreferCodec");
    expect(worker).not.toContain('cmd.prefer as SupportedDecoding_PreferCodec');
  });
});
