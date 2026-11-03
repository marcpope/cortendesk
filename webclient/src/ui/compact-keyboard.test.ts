import { describe, expect, it } from 'vitest';
import { CompactKeyboardModel, LAYOUTS } from './compact-keyboard';

describe('compact keyboard', () => {
  it('letters as text; shift is for one letter', () => {
    const m = new CompactKeyboardModel();
    expect(m.press({ ch: 'a' })).toEqual({ k: 'text', text: 'a' });
    expect(m.press({ sp: 'shift' })).toBeNull();
    expect(m.layer).toBe('upper');
    expect(m.press({ ch: 'H' })).toEqual({ k: 'text', text: 'H' });
    expect(m.layer).toBe('lower');
  });

  it('symbols pages and back', () => {
    const m = new CompactKeyboardModel();
    m.press({ sp: 'sym' });
    expect(m.layer).toBe('sym');
    m.press({ sp: 'sym2' });
    expect(m.layer).toBe('sym2');
    expect(m.press({ ch: '{' })).toEqual({ k: 'text', text: '{' });
    expect(m.layer).toBe('sym2'); // symbols stay until ABC
    m.press({ sp: 'abc' });
    expect(m.layer).toBe('lower');
  });

  it('space, Backspace, Enter, hide', () => {
    const m = new CompactKeyboardModel();
    expect(m.press({ sp: 'space' })).toEqual({ k: 'text', text: ' ' });
    expect(m.press({ sp: 'bksp' })).toEqual({ k: 'key', key: 'Backspace' });
    expect(m.press({ sp: 'enter' })).toEqual({ k: 'key', key: 'Enter' });
    expect(m.press({ sp: 'hide' })).toEqual({ k: 'hide' });
  });

  it('four short rows on every layer, all with Backspace, Enter and hide', () => {
    for (const rows of Object.values(LAYOUTS)) {
      expect(rows).toHaveLength(4);
      const sp = rows.flat().flatMap((k) => ('sp' in k ? [k.sp] : []));
      expect(sp).toEqual(expect.arrayContaining(['bksp', 'enter', 'hide', 'space']));
      for (const r of rows) expect(r.length).toBeLessThanOrEqual(10);
    }
  });
});
