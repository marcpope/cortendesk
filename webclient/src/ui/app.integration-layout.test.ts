import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import * as common from './common';

describe('integrated popover layout', () => {
  it('keeps the combined controls menu bounded and scrollable inside the viewport', () => {
    const css = readFileSync(new URL('./app.css', import.meta.url), 'utf8');
    const popovers = [...css.matchAll(/(?:^|\n)\.rd-pop\s*\{([^}]*)\}/gs)].map((match) => match[1] ?? '');
    const popover = popovers.find((body) => body.includes('position: fixed')) ?? '';

    expect(popover).toMatch(/max-height:\s*calc\(100vh\s*-\s*20px\)/);
    expect(popover).toMatch(/box-sizing:\s*border-box/);
    expect(popover).toMatch(/overflow-y:\s*auto/);
    expect(popover).toMatch(/overscroll-behavior:\s*contain/);
  });

  it('limits a top-toolbar popover to the height remaining below its anchor', () => {
    const placePopover = (common as unknown as {
      placePopover?: (
        anchor: { left: number; top: number; bottom: number; width: number },
        popover: { width: number; height: number },
        viewport: { width: number; height: number },
        preferAbove?: boolean,
      ) => { top: number; maxHeight: number };
    }).placePopover;

    expect(placePopover).toBeTypeOf('function');
    const placement = placePopover!(
      { left: 500, top: 8, bottom: 44, width: 32 },
      { width: 320, height: 508 },
      { width: 756, height: 419 },
    );

    expect(placement).toMatchObject({ top: 54, maxHeight: 357 });
    expect(placement.top + Math.min(508, placement.maxHeight)).toBeLessThanOrEqual(411);
  });
});
