import { describe, expect, it } from 'vitest';
import { shouldOfferHomeScreen } from './app';

const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const android = 'Mozilla/5.0 (Linux; Android 15; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36';

describe('Add to Home Screen hint', () => {
  it('only in an iPhone browser tab', () => {
    expect(shouldOfferHomeScreen({ userAgent: iphone }, false)).toBe(true);
    expect(shouldOfferHomeScreen({ userAgent: iphone, standalone: true }, false)).toBe(false);
    expect(shouldOfferHomeScreen({ userAgent: android }, true)).toBe(false);
    expect(shouldOfferHomeScreen({ userAgent: iphone }, true)).toBe(false);
  });
});
