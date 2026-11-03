import { describe, expect, it } from 'vitest';
import { diffText } from './text-diff';

/** Replay successive field values; collect Backspaces ('⌫') and inserts. */
function replay(values: string[]): string[] {
  const out: string[] = [];
  for (let i = 1; i < values.length; i++) {
    const { deleted, inserted } = diffText(values[i - 1]!, values[i]!);
    for (let n = 0; n < deleted; n++) out.push('⌫');
    if (inserted) out.push(inserted);
  }
  return out;
}

/** Apply the same keystrokes to a remote text that started as `start`. */
function remote(start: string, ops: string[]): string {
  let text = Array.from(start);
  for (const op of ops) {
    if (op === '⌫') text = text.slice(0, -1);
    else text = text.concat(Array.from(op));
  }
  return text.join('');
}

describe('diffText', () => {
  it('plain typing is pure inserts', () => {
    expect(replay(['  ', '  h', '  hi'])).toEqual(['h', 'i']);
  });

  it('Backspace is one deletion', () => {
    expect(diffText('  hi', '  h')).toEqual({ deleted: 1, inserted: '' });
  });

  it('autocorrect replaces the last word', () => {
    // "teh" + space, the keyboard commits "the ".
    const ops = replay(['  teh', '  the ']);
    expect(ops).toEqual(['⌫', '⌫', 'he ']);
    expect(remote('teh', ops)).toBe('the ');
  });

  it('Gboard composition: the composing word is rewritten on every key', () => {
    // compositionupdate values as Gboard sends them, then the commit with a space.
    const values = ['  ', '  w', '  wo', '  wor', '  worl', '  world', '  world '];
    const ops = replay(values);
    expect(ops).toEqual(['w', 'o', 'r', 'l', 'd', ' ']);
    expect(remote('', ops)).toBe('world ');
  });

  it('Gboard composition with a suggestion picked mid-word', () => {
    const ops = replay(['  ', '  hel', '  help', '  helpful ']);
    expect(remote('', ops)).toBe('helpful ');
  });

  it('Thaana with fili: each fili is its own character', () => {
    // ބ (BAA) + ަ (ABAFILI) + ނ (NOONU) + ޑ (DAVIYANI) + ު (UBUFILI) = ބަނޑު
    const steps = ['  ', '  ބ', '  ބަ', '  ބަނ', '  ބަނޑ', '  ބަނޑު'];
    const ops = replay(steps);
    expect(ops).toEqual(['ބ', 'ަ', 'ނ', 'ޑ', 'ު']);
    expect(remote('', ops)).toBe('ބަނޑު');
    // Backspace removes just the fili.
    expect(diffText('  ބަނޑު', '  ބަނޑ')).toEqual({ deleted: 1, inserted: '' });
  });

  it('Thaana word replaced by a suggestion', () => {
    const ops = replay(['  ދިވެ', '  ދިވެހި ']);
    expect(ops).toEqual(['ހި ']);
    expect(remote('ދިވެ', ops)).toBe('ދިވެހި ');
  });

  it('emoji are whole code points, never half a surrogate pair', () => {
    expect(diffText('  hi', '  hi😀')).toEqual({ deleted: 0, inserted: '😀' });
    expect(diffText('  hi😀', '  hi')).toEqual({ deleted: 1, inserted: '' });
    // Two emoji sharing a high surrogate: the diff must not split them.
    expect(diffText('  😀', '  😁')).toEqual({ deleted: 1, inserted: '😁' });
  });

  it('a caret moved mid-text still ends with the same remote text', () => {
    const ops = replay(['  abc', '  axbc']);
    expect(remote('abc', ops)).toBe('axbc');
  });
});
