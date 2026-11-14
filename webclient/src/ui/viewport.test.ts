import { describe, expect, it } from 'vitest';
import { KeyboardDetector } from './viewport';

describe('KeyboardDetector', () => {
  it('portrait phone: keyboard open when the visual viewport loses >= 120px', () => {
    const d = new KeyboardDetector();
    expect(d.update(390, 750)).toBe(false);
    expect(d.update(390, 720)).toBe(false); // URL bar
    expect(d.update(390, 420)).toBe(true);
    expect(d.update(390, 750)).toBe(false);
  });

  it('rotating starts over instead of reading landscape as a keyboard', () => {
    const d = new KeyboardDetector();
    d.update(390, 750);
    expect(d.update(844, 360)).toBe(false);
    expect(d.update(844, 170)).toBe(true);
    expect(d.update(390, 750)).toBe(false);
  });
});
