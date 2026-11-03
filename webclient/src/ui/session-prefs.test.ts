import { describe, expect, it } from 'vitest';
import { DEFAULT_PREFS, resolveInputMode, validatePrefs } from './session-prefs';

describe('input mode preference', () => {
  it('defaults to auto: touch on a phone, pointer on a desktop', () => {
    expect(DEFAULT_PREFS.inputMode).toBe('auto');
    expect(resolveInputMode('auto', true)).toBe('touch');
    expect(resolveInputMode('auto', false)).toBe('pointer');
  });

  it('keeps an explicit choice on any device', () => {
    expect(resolveInputMode('pointer', true)).toBe('pointer');
    expect(resolveInputMode('touch', false)).toBe('touch');
  });

  it('drops unknown values', () => {
    expect(validatePrefs({ inputMode: 'joystick' }).inputMode).toBe('auto');
    expect(validatePrefs({ inputMode: 'touch' }).inputMode).toBe('touch');
  });
});
