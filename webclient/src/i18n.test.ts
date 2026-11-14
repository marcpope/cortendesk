import { describe, it, expect, afterEach } from 'vitest';
import { hasText, htl, pluralIndex, setCatalog, tl, tn } from './i18n';

afterEach(() => setCatalog(undefined));

describe('pluralIndex', () => {
  it('one/other for English-like languages', () => {
    expect([0, 1, 2, 5].map((n) => pluralIndex('en', n))).toEqual([1, 0, 1, 1]);
    expect(pluralIndex('pt-BR', 1)).toBe(0);
  });

  it('French counts 0 and 1 as one', () => {
    expect([0, 1, 2].map((n) => pluralIndex('fr', n))).toEqual([0, 0, 1]);
  });

  it('Russian picks one, few and many', () => {
    expect([1, 2, 4, 5, 11, 12, 21, 22, 25, 111].map((n) => pluralIndex('ru', n)))
      .toEqual([0, 1, 1, 2, 2, 2, 0, 1, 2, 2]);
  });

  it('Polish picks one, few and many', () => {
    expect([1, 2, 4, 5, 12, 21, 22, 25].map((n) => pluralIndex('pl', n)))
      .toEqual([0, 1, 1, 2, 2, 2, 1, 2]);
  });

  it('Chinese has a single form', () => {
    expect([0, 1, 7].map((n) => pluralIndex('zh-CN', n))).toEqual([0, 0, 0]);
  });
});

describe('lookup', () => {
  it('falls back to English when the page sent no messages', () => {
    expect(tl('view_only')).toBe('View only');
    expect(hasText('view_only')).toBe(true);
    expect(tl('no_such_key')).toBe('no_such_key');
    expect(hasText('no_such_key')).toBe(false);
  });

  it('prefers the loaded catalog and fills placeholders', () => {
    setCatalog({ view_only: 'Nur ansehen', greet: 'Hallo :name' });
    expect(tl('view_only')).toBe('Nur ansehen');
    expect(tl('greet', { name: 'Ada' })).toBe('Hallo Ada');
    expect(tl('input_enabled')).toBe('Input enabled'); // gap filled from English
  });

  it('escapes text meant for HTML', () => {
    setCatalog({ x: 'a < b & "c"' });
    expect(htl('x')).toBe('a &lt; b &amp; &quot;c&quot;');
    expect(tl('x')).toBe('a < b & "c"');
  });

  it('picks the plural form by count', () => {
    setCatalog({ n: ':count file|:count files' });
    expect(tn('n', 1)).toBe('1 file');
    expect(tn('n', 3)).toBe('3 files');
  });
});
