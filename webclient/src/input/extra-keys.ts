import { ControlKey } from '../gen/message';
import type { UiCommand } from '../core/contracts';
import { DOM_CODE_KEYCODES } from './keycodes';
import { LegacyKeyTranslator, MapKeyTranslator, type KeyTarget } from './mouse-keyboard';

/**
 * The extra-keys bar: keys a phone keyboard does not have (Esc, Tab, Ctrl,
 * Alt, Win, arrows, F-keys) and the shortcuts an operator needs most.
 *
 * Every key is synthesised as the browser events a physical keyboard would
 * produce and run through the same translators as real keys, so the host
 * gets true key-down / key-up pairs: Map mode keycodes on Windows/Linux
 * hosts, legacy keys elsewhere. Win+R reaches the host exactly as if typed.
 */

export type Mod = 'ctrl' | 'alt' | 'shift' | 'meta';
export const MODS: readonly Mod[] = ['ctrl', 'alt', 'shift', 'meta'];

/** A physical key: DOM `code` (Map mode) and `key` (legacy mode). */
export type PhysKey = { code: string; key: string };

export type XKey =
  | { id: string; label: string; mod: Mod }
  | { id: string; label: string; key: PhysKey; mods?: Mod[] }
  | { id: string; label: string; special: 'ctrlAltDel' | 'lockScreen' };

const k = (code: string, key = code): PhysKey => ({ code, key });

const MOD_KEY: Record<Mod, PhysKey & { flag: 'ctrlKey' | 'altKey' | 'shiftKey' | 'metaKey' }> = {
  ctrl: { code: 'ControlLeft', key: 'Control', flag: 'ctrlKey' },
  alt: { code: 'AltLeft', key: 'Alt', flag: 'altKey' },
  shift: { code: 'ShiftLeft', key: 'Shift', flag: 'shiftKey' },
  meta: { code: 'MetaLeft', key: 'Meta', flag: 'metaKey' },
};

export type XKeyPage = { id: 'keys' | 'fn' | 'combos'; label: string; keys: XKey[] };

/** Labels here are key caps, the same in every language. */
export const XKEY_PAGES: XKeyPage[] = [
  {
    id: 'keys',
    label: 'Esc Ctrl',
    keys: [
      { id: 'esc', label: 'Esc', key: k('Escape') },
      { id: 'tab', label: 'Tab', key: k('Tab') },
      { id: 'ctrl', label: 'Ctrl', mod: 'ctrl' },
      { id: 'alt', label: 'Alt', mod: 'alt' },
      { id: 'shift', label: 'Shift', mod: 'shift' },
      { id: 'meta', label: 'Win', mod: 'meta' },
      { id: 'left', label: '←', key: k('ArrowLeft') },
      { id: 'up', label: '↑', key: k('ArrowUp') },
      { id: 'down', label: '↓', key: k('ArrowDown') },
      { id: 'right', label: '→', key: k('ArrowRight') },
      { id: 'home', label: 'Home', key: k('Home') },
      { id: 'end', label: 'End', key: k('End') },
      { id: 'pgup', label: 'PgUp', key: k('PageUp') },
      { id: 'pgdn', label: 'PgDn', key: k('PageDown') },
      { id: 'ins', label: 'Ins', key: k('Insert') },
      { id: 'del', label: 'Del', key: k('Delete') },
      { id: 'bksp', label: '⌫', key: k('Backspace') },
      { id: 'enter', label: '⏎', key: k('Enter') },
    ],
  },
  {
    id: 'fn',
    label: 'F1–F12',
    keys: Array.from({ length: 12 }, (_, i) => ({ id: `f${i + 1}`, label: `F${i + 1}`, key: k(`F${i + 1}`) })),
  },
  {
    id: 'combos',
    label: 'Ctrl+Alt+Del',
    keys: [
      { id: 'cad', label: 'Ctrl+Alt+Del', special: 'ctrlAltDel' },
      { id: 'win', label: 'Win', key: k('MetaLeft', 'Meta') },
      { id: 'win-r', label: 'Win+R', key: k('KeyR', 'r'), mods: ['meta'] },
      { id: 'win-d', label: 'Win+D', key: k('KeyD', 'd'), mods: ['meta'] },
      { id: 'win-e', label: 'Win+E', key: k('KeyE', 'e'), mods: ['meta'] },
      { id: 'win-l', label: 'Win+L', special: 'lockScreen' },
      { id: 'alt-tab', label: 'Alt+Tab', key: k('Tab'), mods: ['alt'] },
      { id: 'alt-f4', label: 'Alt+F4', key: k('F4'), mods: ['alt'] },
      { id: 'taskmgr', label: 'Ctrl+Shift+Esc', key: k('Escape'), mods: ['ctrl', 'shift'] },
      { id: 'prtsc', label: 'PrtSc', key: k('PrintScreen') },
      { id: 'copy', label: 'Ctrl+C', key: k('KeyC', 'c'), mods: ['ctrl'] },
      { id: 'paste', label: 'Ctrl+V', key: k('KeyV', 'v'), mods: ['ctrl'] },
      { id: 'cut', label: 'Ctrl+X', key: k('KeyX', 'x'), mods: ['ctrl'] },
      { id: 'undo', label: 'Ctrl+Z', key: k('KeyZ', 'z'), mods: ['ctrl'] },
      { id: 'all', label: 'Ctrl+A', key: k('KeyA', 'a'), mods: ['ctrl'] },
    ],
  },
];

const SHIFTED_US: Record<string, string> = {
  '!': '1', '@': '2', '#': '3', $: '4', '%': '5', '^': '6', '&': '7', '*': '8', '(': '9', ')': '0',
  _: '-', '+': '=', '{': '[', '}': ']', '|': '\\', ':': ';', '"': "'", '<': ',', '>': '.', '?': '/', '~': '`',
};
const PUNCT_CODE: Record<string, string> = {
  '-': 'Minus', '=': 'Equal', '[': 'BracketLeft', ']': 'BracketRight', '\\': 'Backslash', ';': 'Semicolon',
  "'": 'Quote', ',': 'Comma', '.': 'Period', '/': 'Slash', '`': 'Backquote', ' ': 'Space',
};

/**
 * The physical (US) key for a typed character, so Ctrl + a letter typed on
 * the on-screen keyboard becomes the shortcut. Null for anything else.
 */
export function charToKey(ch: string): { key: PhysKey; shift: boolean } | null {
  if (/^[a-z]$/.test(ch)) return { key: k(`Key${ch.toUpperCase()}`, ch), shift: false };
  if (/^[A-Z]$/.test(ch)) return { key: k(`Key${ch}`, ch.toLowerCase()), shift: true };
  if (/^[0-9]$/.test(ch)) return { key: k(`Digit${ch}`, ch), shift: false };
  if (PUNCT_CODE[ch]) return { key: k(PUNCT_CODE[ch]!, ch), shift: false };
  const base = SHIFTED_US[ch];
  if (base) return { key: k(base >= '0' && base <= '9' ? `Digit${base}` : PUNCT_CODE[base]!, base), shift: true };
  return null;
}

type FakeKey = {
  code: string; key: string; location: number; repeat: boolean; isComposing: boolean; timeStamp: number;
  ctrlKey: boolean; altKey: boolean; shiftKey: boolean; metaKey: boolean;
  getModifierState(k: string): boolean;
};

/**
 * Commands for pressing `key` with `mods` held: modifiers down in order, the
 * key down and up, modifiers up in reverse — through the Map translator for
 * a Windows/Linux host, the legacy one otherwise.
 */
export function keyComboCommands(key: PhysKey, mods: readonly Mod[], target: KeyTarget | null): UiCommand[] {
  const out: UiCommand[] = [];
  const order = MODS.filter((m) => mods.includes(m));
  const held = { ctrlKey: false, altKey: false, shiftKey: false, metaKey: false };
  let t = 0;
  const ev = (p: PhysKey, isMod: boolean): FakeKey => ({
    code: p.code, key: p.key, location: isMod ? 1 : 0, repeat: false, isComposing: false,
    timeStamp: (t += 100), ...held, getModifierState: () => false,
  });

  const mappable = (p: PhysKey): boolean =>
    !!target && !!DOM_CODE_KEYCODES[p.code]?.[target === 'windows' ? 0 : 1] && !(target === 'windows' && p.code === 'Pause');
  if (target && mappable(key)) {
    const map = new MapKeyTranslator(target, (m) =>
      out.push({ c: 'key', down: m.down, press: false, keyKind: 'map', value: m.keyCode, modifiers: m.modifiers }),
    );
    const send = (p: PhysKey, down: boolean, isMod: boolean): void => {
      map.handle(ev(p, isMod) as unknown as KeyboardEvent, down);
    };
    for (const m of order) {
      held[MOD_KEY[m].flag] = true;
      send(MOD_KEY[m], true, true);
    }
    send(key, true, false);
    send(key, false, false);
    for (const m of [...order].reverse()) {
      held[MOD_KEY[m].flag] = false;
      send(MOD_KEY[m], false, true);
    }
    map.reset(); // nothing stays held, and no AltGr timer is left behind
    return out;
  }

  const legacy = new LegacyKeyTranslator();
  const send = (p: PhysKey, down: boolean, isMod: boolean): void => {
    for (const m of legacy.translate(ev(p, isMod), down)) {
      out.push({ c: 'key', down: m.down, press: false, keyKind: m.keyKind, value: m.value, modifiers: m.modifiers });
    }
  };
  for (const m of order) {
    held[MOD_KEY[m].flag] = true;
    send(MOD_KEY[m], true, true);
  }
  send(key, true, false);
  send(key, false, false);
  for (const m of [...order].reverse()) {
    held[MOD_KEY[m].flag] = false;
    send(MOD_KEY[m], false, true);
  }
  return out;
}

/** Commands for one tap on a bar key (not a modifier) with the sticky `mods`. */
export function xkeyCommands(x: XKey, mods: readonly Mod[], target: KeyTarget | null): UiCommand[] {
  if ('mod' in x) return [];
  if ('special' in x) {
    if (x.special === 'ctrlAltDel') return [{ c: 'ctrlAltDel' }];
    return [{ c: 'key', down: false, press: true, keyKind: 'control', value: ControlKey.LockScreen, modifiers: [] }];
  }
  const all = MODS.filter((m) => mods.includes(m) || x.mods?.includes(m));
  return keyComboCommands(x.key, all, target);
}

/**
 * Text typed on the on-screen keyboard while sticky modifiers are on. With
 * Ctrl, Alt or Win it is a shortcut: each character becomes its key with the
 * modifiers. Characters with no key (Thaana, emoji) are sent as text. With
 * Shift alone the keyboard has already applied it, so text stays text.
 */
export function stickyTextCommands(text: string, mods: readonly Mod[], target: KeyTarget | null): UiCommand[] {
  if (!mods.some((m) => m !== 'shift')) return [{ c: 'text', text }];
  const out: UiCommand[] = [];
  for (const ch of text) {
    const hit = charToKey(ch);
    if (!hit) {
      out.push({ c: 'text', text: ch });
      continue;
    }
    const m = hit.shift && !mods.includes('shift') ? [...mods, 'shift' as const] : mods;
    out.push(...keyComboCommands(hit.key, m, target));
  }
  return out;
}

/**
 * Text as real key presses (Map-mode scancodes, US layout), for screens
 * where Windows may ignore Unicode input: the sign-in / lock screen and UAC
 * prompts on the secure desktop. `seq` is typed with KEYEVENTF_UNICODE
 * (VK_PACKET); a scancode is what a physical keyboard sends. Characters
 * with no US key (Thaana, emoji, accents) cannot be typed this way and are
 * skipped and counted.
 */
export function textAsKeyPresses(text: string, target: KeyTarget): { cmds: UiCommand[]; skipped: number } {
  const cmds: UiCommand[] = [];
  let skipped = 0;
  for (const ch of text.replace(/\r\n?/g, '\n')) {
    if (ch === '\n' || ch === '\t') {
      cmds.push(...keyComboCommands(ch === '\n' ? { code: 'Enter', key: 'Enter' } : { code: 'Tab', key: 'Tab' }, [], target));
      continue;
    }
    const hit = charToKey(ch);
    if (!hit) {
      skipped++;
      continue;
    }
    cmds.push(...keyComboCommands(hit.key, hit.shift ? ['shift'] : [], target));
  }
  return { cmds, skipped };
}

export type LatchState = 'off' | 'once' | 'locked';

/**
 * Sticky modifiers: a tap arms one for the next key or click (once), a
 * double tap or a long press locks it until tapped again.
 */
export class ModifierLatches {
  private state: Record<Mod, LatchState> = { ctrl: 'off', alt: 'off', shift: 'off', meta: 'off' };
  private lastTap: { mod: Mod; at: number } | null = null;

  constructor(private readonly doubleTapMs = 350) {}

  get(m: Mod): LatchState {
    return this.state[m];
  }

  active(): Mod[] {
    return MODS.filter((m) => this.state[m] !== 'off');
  }

  tap(m: Mod, now: number): LatchState {
    const double = this.lastTap?.mod === m && now - this.lastTap.at <= this.doubleTapMs;
    this.lastTap = { mod: m, at: now };
    const s = this.state[m];
    this.state[m] = s === 'off' ? 'once' : s === 'once' && double ? 'locked' : 'off';
    return this.state[m];
  }

  lock(m: Mod): void {
    this.state[m] = 'locked';
    this.lastTap = null;
  }

  /** A key or click used the modifiers: one-shot ones are spent. True if any changed. */
  consume(): boolean {
    let changed = false;
    for (const m of MODS) {
      if (this.state[m] === 'once') {
        this.state[m] = 'off';
        changed = true;
      }
    }
    return changed;
  }

  clear(): void {
    for (const m of MODS) this.state[m] = 'off';
    this.lastTap = null;
  }
}

/** ControlKey modifiers for mouse events while sticky modifiers are on. */
export function modControlKeys(mods: readonly Mod[]): number[] {
  const map: Record<Mod, number> = {
    ctrl: ControlKey.Control, alt: ControlKey.Alt, shift: ControlKey.Shift, meta: ControlKey.Meta,
  };
  return mods.map((m) => map[m]);
}
