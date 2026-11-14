import { describe, expect, it } from 'vitest';
import { ControlKey } from '../gen/message';
import type { UiCommand } from '../core/contracts';
import {
  ModifierLatches,
  XKEY_PAGES,
  charToKey,
  keyComboCommands,
  modControlKeys,
  stickyTextCommands,
  xkeyCommands,
  type XKey,
} from './extra-keys';

const find = (id: string): XKey => XKEY_PAGES.flatMap((p) => p.keys).find((k) => k.id === id)!;
const keys = (cmds: UiCommand[]) =>
  cmds.map((c) => (c.c === 'key' ? [c.keyKind, c.value, c.down ? 'down' : 'up', c.modifiers] : c));

describe('extra keys: Win+R', () => {
  it('Map mode (Windows host): true down/up scancodes, Win wrapped around R', () => {
    expect(keys(xkeyCommands(find('win-r'), [], 'windows'))).toEqual([
      ['map', 0xe05b, 'down', []],
      ['map', 0x13, 'down', []],
      ['map', 0x13, 'up', []],
      ['map', 0xe05b, 'up', []],
    ]);
  });

  it('Map mode (Linux host): evdev keycodes', () => {
    expect(keys(xkeyCommands(find('win-r'), [], 'linux'))).toEqual([
      ['map', 0x85, 'down', []],
      ['map', 0x1b, 'down', []],
      ['map', 0x1b, 'up', []],
      ['map', 0x85, 'up', []],
    ]);
  });

  it('legacy mode: Meta key, then r carrying the Meta modifier', () => {
    expect(keys(xkeyCommands(find('win-r'), [], null))).toEqual([
      ['control', ControlKey.Meta, 'down', [ControlKey.Meta]],
      ['chr', 'r'.charCodeAt(0), 'down', [ControlKey.Meta]],
      ['chr', 'r'.charCodeAt(0), 'up', [ControlKey.Meta]],
      ['control', ControlKey.Meta, 'up', []],
    ]);
  });
});

describe('extra keys: specials', () => {
  it('Ctrl+Alt+Del is the dedicated control key, never synthesised', () => {
    expect(xkeyCommands(find('cad'), [], 'windows')).toEqual([{ c: 'ctrlAltDel' }]);
    expect(xkeyCommands(find('cad'), ['shift'], null)).toEqual([{ c: 'ctrlAltDel' }]);
  });

  it('Win+L is the lock-screen key (Windows ignores an injected Win+L)', () => {
    expect(xkeyCommands(find('win-l'), [], 'windows')).toEqual([
      { c: 'key', down: false, press: true, keyKind: 'control', value: ControlKey.LockScreen, modifiers: [] },
    ]);
  });

  it('Ctrl+Shift+Esc keeps modifier order and releases in reverse', () => {
    expect(keys(xkeyCommands(find('taskmgr'), [], 'windows'))).toEqual([
      ['map', 0x1d, 'down', []],
      ['map', 0x2a, 'down', []],
      ['map', 0x01, 'down', []],
      ['map', 0x01, 'up', []],
      ['map', 0x2a, 'up', []],
      ['map', 0x1d, 'up', []],
    ]);
  });

  it('sticky modifiers join a plain key: Alt (sticky) + F4', () => {
    expect(keys(xkeyCommands(find('f4'), ['alt'], 'windows'))).toEqual([
      ['map', 0x38, 'down', []],
      ['map', 0x3e, 'down', []],
      ['map', 0x3e, 'up', []],
      ['map', 0x38, 'up', []],
    ]);
  });

  it('a modifier key itself sends nothing', () => {
    expect(xkeyCommands(find('ctrl'), [], 'windows')).toEqual([]);
  });

  it('every bar key produces commands in both modes', () => {
    for (const x of XKEY_PAGES.flatMap((p) => p.keys)) {
      if ('mod' in x) continue;
      expect(xkeyCommands(x, [], 'windows').length, x.id).toBeGreaterThan(0);
      expect(xkeyCommands(x, [], null).length, x.id).toBeGreaterThan(0);
    }
  });
});

describe('sticky text', () => {
  it('Ctrl + a typed "c" is Ctrl+C; Thaana stays text', () => {
    const cmds = stickyTextCommands('cދ', ['ctrl'], 'windows');
    expect(keys(cmds)).toEqual([
      ['map', 0x1d, 'down', []],
      ['map', 0x2e, 'down', []],
      ['map', 0x2e, 'up', []],
      ['map', 0x1d, 'up', []],
      { c: 'text', text: 'ދ' },
    ]);
  });

  it('Shift alone leaves text to the keyboard', () => {
    expect(stickyTextCommands('Hi', ['shift'], 'windows')).toEqual([{ c: 'text', text: 'Hi' }]);
  });

  it('charToKey maps US letters, digits and shifted punctuation', () => {
    expect(charToKey('a')).toEqual({ key: { code: 'KeyA', key: 'a' }, shift: false });
    expect(charToKey('A')).toEqual({ key: { code: 'KeyA', key: 'a' }, shift: true });
    expect(charToKey('!')).toEqual({ key: { code: 'Digit1', key: '1' }, shift: true });
    expect(charToKey('?')).toEqual({ key: { code: 'Slash', key: '/' }, shift: true });
    expect(charToKey('ދ')).toBeNull();
  });

  it('keyComboCommands leaves nothing held', () => {
    const cmds = keyComboCommands({ code: 'KeyV', key: 'v' }, ['ctrl'], 'windows');
    const downs = cmds.filter((c) => c.c === 'key' && c.down).length;
    const ups = cmds.filter((c) => c.c === 'key' && !c.down).length;
    expect(downs).toBe(ups);
  });
});

describe('ModifierLatches', () => {
  it('tap = once, spent by the next key', () => {
    const l = new ModifierLatches();
    expect(l.tap('ctrl', 0)).toBe('once');
    expect(l.active()).toEqual(['ctrl']);
    expect(l.consume()).toBe(true);
    expect(l.active()).toEqual([]);
  });

  it('double tap = locked, survives keys, a third tap releases', () => {
    const l = new ModifierLatches();
    l.tap('alt', 0);
    expect(l.tap('alt', 200)).toBe('locked');
    l.consume();
    expect(l.active()).toEqual(['alt']);
    expect(l.tap('alt', 5000)).toBe('off');
  });

  it('a slow second tap turns a one-shot off instead of locking', () => {
    const l = new ModifierLatches();
    l.tap('shift', 0);
    expect(l.tap('shift', 1000)).toBe('off');
  });

  it('long press locks; clear drops everything', () => {
    const l = new ModifierLatches();
    l.lock('meta');
    l.tap('ctrl', 0);
    expect(l.active()).toEqual(['ctrl', 'meta']);
    l.clear();
    expect(l.active()).toEqual([]);
  });

  it('mouse modifiers for sticky keys', () => {
    expect(modControlKeys(['ctrl', 'shift'])).toEqual([ControlKey.Control, ControlKey.Shift]);
  });
});
