import { describe, expect, it } from 'vitest';
import { pillTop, snapPill } from './mobile-toolbar';
import { validatePrefs } from './session-prefs';

describe('floating controls placement', () => {
  it('snaps to the nearer side and keeps the height as a fraction', () => {
    expect(snapPill(50, 8, 300, 400, 800)).toEqual({ side: 'left', y: 0 });
    expect(snapPill(390, 492, 300, 400, 800)).toEqual({ side: 'right', y: 1 });
    expect(snapPill(300, 250, 300, 400, 800).y).toBeCloseTo(0.5, 2);
  });

  it('clamps a drop outside the viewport', () => {
    expect(snapPill(-100, -500, 300, 400, 800).y).toBe(0);
    expect(snapPill(900, 5000, 300, 400, 800)).toEqual({ side: 'right', y: 1 });
  });

  it('keeps a saved position on screen after rotation or with the keyboard open', () => {
    expect(pillTop({ side: 'right', y: 1 }, 300, 800)).toBe(492);
    expect(pillTop({ side: 'right', y: 1 }, 300, 360)).toBe(52);
    expect(pillTop({ side: 'left', y: 0.5 }, 300, 200)).toBe(8); // taller than the space: top
  });

  it('prefs: invalid positions fall back to the default', () => {
    expect(validatePrefs({ pillSide: 'top', pillY: 3 })).toMatchObject({ pillSide: 'right', pillY: 0.25, pillOpen: true });
    expect(validatePrefs({ pillSide: 'left', pillY: 0.7, pillOpen: false })).toMatchObject({ pillSide: 'left', pillY: 0.7, pillOpen: false });
  });
});
