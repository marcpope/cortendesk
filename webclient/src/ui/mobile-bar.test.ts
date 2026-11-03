import { describe, expect, it } from 'vitest';
import { barSide, currentOrientation, swappedPrefs } from './mobile-bar';
import { validatePrefs } from './session-prefs';

describe('docked controls', () => {
  it('follows the screen orientation, not the window (a keyboard shrinks the window)', () => {
    expect(currentOrientation('portrait-primary', undefined, 400, 300)).toBe('portrait');
    expect(currentOrientation('landscape-secondary', undefined, 300, 400)).toBe('landscape');
    expect(currentOrientation(undefined, 90, 300, 400)).toBe('landscape');
    expect(currentOrientation(undefined, 0, 800, 400)).toBe('portrait');
    expect(currentOrientation(undefined, undefined, 800, 400)).toBe('landscape');
  });

  it('bottom strip in portrait, right column in landscape by default', () => {
    const p = validatePrefs({});
    expect(barSide('portrait', p)).toBe('bottom');
    expect(barSide('landscape', p)).toBe('right');
  });

  it('swap moves to the opposite edge of the current orientation only', () => {
    const p = validatePrefs({});
    expect(swappedPrefs('portrait', p)).toEqual({ barPortrait: 'top' });
    expect(swappedPrefs('landscape', p)).toEqual({ barLandscape: 'left' });
    expect(swappedPrefs('landscape', { ...p, barLandscape: 'left' })).toEqual({ barLandscape: 'right' });
  });

  it('prefs: invalid edges fall back to the defaults', () => {
    expect(validatePrefs({ barPortrait: 'left', barLandscape: 'top' })).toMatchObject({ barPortrait: 'bottom', barLandscape: 'right' });
  });
});
