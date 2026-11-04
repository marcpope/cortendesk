import { describe, expect, it } from 'vitest';
import { barSide, currentOrientation, fullscreenButtonMode, swappedPrefs } from './mobile-bar';
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

describe('bar Fullscreen button', () => {
  it('toggles where the browser has fullscreen, explains Add to Home Screen on iPhone, hides in the Home Screen app', () => {
    expect(fullscreenButtonMode(true, false)).toBe('toggle');
    expect(fullscreenButtonMode(true, true)).toBe('toggle');
    expect(fullscreenButtonMode(false, false)).toBe('hint');
    expect(fullscreenButtonMode(false, true)).toBe('hidden');
  });
});
