import { describe, it, expect, vi, afterEach } from 'vitest';
import { ControlKey } from '../gen/message';
import {
  LegacyKeyTranslator,
  MapKeyTranslator,
  mapKeyboardTarget,
  type MapKeyMessage,
} from './mouse-keyboard';
import { DOM_CODE_KEYCODES } from './keycodes';

type K = {
  key: string; code?: string; location?: number; repeat?: boolean; timeStamp?: number;
  ctrlKey?: boolean; shiftKey?: boolean; altKey?: boolean; metaKey?: boolean;
  isComposing?: boolean; caps?: boolean; num?: boolean;
};

function kev(p: K) {
  return {
    key: p.key, code: p.code ?? '', location: p.location ?? 0, repeat: p.repeat ?? false,
    timeStamp: p.timeStamp ?? 0, ctrlKey: !!p.ctrlKey, shiftKey: !!p.shiftKey,
    altKey: !!p.altKey, metaKey: !!p.metaKey, isComposing: !!p.isComposing,
    getModifierState: (m: string) => (m === 'CapsLock' ? !!p.caps : m === 'NumLock' ? !!p.num : false),
  } as unknown as KeyboardEvent;
}

describe('LegacyKeyTranslator', () => {
  it('sends non-ASCII on key-down only (the host types unicode on every event)', () => {
    const t = new LegacyKeyTranslator();
    expect(t.translate(kev({ key: 'æ' }), true)).toEqual([
      { down: true, keyKind: 'unicode', value: 'æ'.codePointAt(0), modifiers: [] },
    ]);
    expect(t.translate(kev({ key: 'æ' }), false)).toEqual([]);
  });

  it('keeps ASCII down and up', () => {
    const t = new LegacyKeyTranslator();
    expect(t.translate(kev({ key: 'a' }), true)).toHaveLength(1);
    expect(t.translate(kev({ key: 'a' }), false)).toHaveLength(1);
  });

  it('Windows AltGr: releases the fake left Control and drops Ctrl/Alt from AltGr characters', () => {
    const t = new LegacyKeyTranslator();
    t.translate(kev({ key: 'Control', location: 1, timeStamp: 100, ctrlKey: true }), true);
    const altgr = t.translate(kev({ key: 'AltGraph', timeStamp: 101, ctrlKey: true, altKey: true }), true);
    expect(altgr[0]).toEqual({ down: false, keyKind: 'control', value: ControlKey.Control, modifiers: [] });
    const pipe = t.translate(kev({ key: '|', timeStamp: 300, ctrlKey: true, altKey: true }), true);
    expect(pipe).toEqual([{ down: true, keyKind: 'chr', value: '|'.codePointAt(0), modifiers: [] }]);
  });

  it('plain Ctrl+C keeps Ctrl', () => {
    const t = new LegacyKeyTranslator();
    t.translate(kev({ key: 'Control', location: 1, timeStamp: 100, ctrlKey: true }), true);
    const c = t.translate(kev({ key: 'c', timeStamp: 400, ctrlKey: true }), true);
    expect(c).toEqual([{ down: true, keyKind: 'chr', value: 99, modifiers: [ControlKey.Control] }]);
  });
});

describe('mapKeyboardTarget', () => {
  it('Windows and Linux hosts on 1.2.0 or later only', () => {
    expect(mapKeyboardTarget('Windows', '1.4.2')).toBe('windows');
    expect(mapKeyboardTarget('Linux', '1.2.0')).toBe('linux');
    expect(mapKeyboardTarget('Linux', '2.0.0')).toBe('linux');
    expect(mapKeyboardTarget('Linux', '1.1.9')).toBeNull();
    expect(mapKeyboardTarget('Mac OS', '1.4.2')).toBeNull();
    expect(mapKeyboardTarget('Android', '1.4.2')).toBeNull();
    expect(mapKeyboardTarget('Windows', '')).toBeNull();
  });
});

describe('MapKeyTranslator', () => {
  afterEach(() => vi.useRealTimers());
  const sent = (target: 'windows' | 'linux') => {
    const out: MapKeyMessage[] = [];
    return { out, t: new MapKeyTranslator(target, (m) => out.push(m)) };
  };

  it('sends the host keycode down and up', () => {
    const { out, t } = sent('linux');
    expect(t.handle(kev({ key: 'æ', code: 'Semicolon' }), true)).toBe(true);
    expect(t.handle(kev({ key: 'æ', code: 'Semicolon' }), false)).toBe(true);
    const lin = DOM_CODE_KEYCODES.Semicolon[1];
    expect(out).toEqual([
      { down: true, keyCode: lin, modifiers: [] },
      { down: false, keyCode: lin, modifiers: [] },
    ]);
  });

  it('uses the Windows scancode column for Windows hosts', () => {
    const { out, t } = sent('windows');
    t.handle(kev({ key: 'a', code: 'KeyA' }), true);
    expect(out[0].keyCode).toBe(0x1e);
  });

  it('forwards repeats as key-downs', () => {
    const { out, t } = sent('linux');
    t.handle(kev({ key: 'a', code: 'KeyA' }), true);
    t.handle(kev({ key: 'a', code: 'KeyA', repeat: true }), true);
    expect(out.filter((m) => m.down)).toHaveLength(2);
  });

  it('carries lock state and never presses lock keys', () => {
    const { out, t } = sent('linux');
    expect(t.handle(kev({ key: 'CapsLock', code: 'CapsLock', caps: true }), true)).toBe(true);
    t.handle(kev({ key: 'A', code: 'KeyA', caps: true, num: true }), true);
    expect(out).toEqual([
      { down: true, keyCode: DOM_CODE_KEYCODES.KeyA[1], modifiers: [ControlKey.CapsLock, ControlKey.NumLock] },
    ]);
  });

  it('drops the synthetic left Control when AltGr follows', () => {
    vi.useFakeTimers();
    const { out, t } = sent('linux');
    t.handle(kev({ key: 'Control', code: 'ControlLeft' }), true);
    t.handle(kev({ key: 'AltGraph', code: 'AltRight' }), true);
    vi.advanceTimersByTime(100);
    t.handle(kev({ key: 'Control', code: 'ControlLeft' }), false);
    expect(out).toEqual([{ down: true, keyCode: DOM_CODE_KEYCODES.AltRight[1], modifiers: [] }]);
  });

  it('a real left Control is sent once the hold-back expires or another key arrives', () => {
    vi.useFakeTimers();
    const { out, t } = sent('linux');
    t.handle(kev({ key: 'Control', code: 'ControlLeft' }), true);
    vi.advanceTimersByTime(60);
    expect(out[0]).toEqual({ down: true, keyCode: DOM_CODE_KEYCODES.ControlLeft[1], modifiers: [] });

    const b = sent('linux');
    b.t.handle(kev({ key: 'Control', code: 'ControlLeft' }), true);
    b.t.handle(kev({ key: 'c', code: 'KeyC', ctrlKey: true }), true);
    expect(b.out.map((m) => m.keyCode)).toEqual([DOM_CODE_KEYCODES.ControlLeft[1], DOM_CODE_KEYCODES.KeyC[1]]);
  });

  it('falls back to legacy for IME, unknown codes and Pause on Windows', () => {
    const { out, t } = sent('windows');
    expect(t.handle(kev({ key: 'Process', code: 'KeyA' }), true)).toBe(false);
    expect(t.handle(kev({ key: 'a', code: 'KeyA', isComposing: true }), true)).toBe(false);
    expect(t.handle(kev({ key: 'x', code: '' }), true)).toBe(false);
    expect(t.handle(kev({ key: 'Pause', code: 'Pause' }), true)).toBe(false);
    expect(out).toEqual([]);
  });

  it('reset releases every key the host still holds', () => {
    const { out, t } = sent('linux');
    t.handle(kev({ key: 'a', code: 'KeyA' }), true);
    t.handle(kev({ key: 'Shift', code: 'ShiftLeft' }), true);
    out.length = 0;
    t.reset();
    expect(out.every((m) => !m.down)).toBe(true);
    expect(out.map((m) => m.keyCode).sort()).toEqual(
      [DOM_CODE_KEYCODES.KeyA[1], DOM_CODE_KEYCODES.ShiftLeft[1]].sort(),
    );
    t.reset();
    expect(out).toHaveLength(2);
  });
});
