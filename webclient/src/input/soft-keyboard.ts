import { diffText } from './text-diff';

/**
 * Phone and tablet keyboards, through a hidden text field.
 *
 * A canvas never gets an on-screen keyboard, and Android keyboards do not
 * send keys anyway: every key-down says `Unidentified` / keyCode 229 and the
 * text only shows up as edits to a focused field (`beforeinput` / `input`,
 * composition). So the client focuses a hidden <textarea> (the sink), lets
 * the keyboard edit it, and turns each edit into remote input:
 *
 * - typed, autocorrected, swiped, dictated text: diff the field against the
 *   last value and send N Backspaces plus the inserted text (as one RustDesk
 *   `seq`, which the host types as Unicode whatever its keyboard layout is,
 *   so Thaana with fili, emoji and CJK come out right);
 * - Enter, forward Delete, delete-word: from `beforeinput`, as keys;
 * - a real keyboard (iPad Magic Keyboard, Bluetooth): key-downs carry a
 *   physical `code`, so they go to the normal key translators and keep every
 *   shortcut.
 *
 * The field starts with a sentinel so Backspace on an "empty" field still
 * produces an edit, and is reset to it only outside a composition (resetting
 * mid-word confuses the keyboard's own state).
 */

export type SoftKey = 'Backspace' | 'Enter' | 'Delete' | 'Tab';

/**
 * Attributes for the hidden field. With suggestions hidden (the default)
 * the keyboard is asked for no autocorrect, no capitalisation, no spell
 * check, no word suggestions and no inline predictions, which is what saves
 * height where the platform allows it:
 *
 * - Android Chrome passes these to the keyboard as input-type flags (no
 *   suggestions, no auto-correct). Whether the suggestion strip actually
 *   goes away is the keyboard's choice: Gboard keeps a toolbar row of the
 *   same height; others drop the row.
 * - iOS / iPadOS WebKit maps them to UIKit's autocorrection / spell
 *   checking / inline-prediction traits; with autocorrection off the
 *   QuickType bar shows no word predictions.
 *
 * None of this changes which keyboard or language is used, so Dhivehi and
 * other IMEs keep working. `type="password"` would hide more, but it forces
 * Latin-only keyboards and calls up password managers, so it is not used.
 * Showing suggestions turns autocorrect and predictions back on (handy for
 * long text); the diff engine handles the corrections either way.
 */
export function sinkAttributes(hideSuggestions: boolean): Record<string, string> {
  const common = { autocomplete: 'off', autocapitalize: 'off', inputmode: 'text', enterkeyhint: 'enter' };
  return hideSuggestions
    ? { ...common, autocorrect: 'off', spellcheck: 'false', writingsuggestions: 'false' }
    : { ...common, autocorrect: 'on', spellcheck: 'true', writingsuggestions: 'true' };
}

export type SoftKeyAction =
  | { k: 'text'; text: string }
  | { k: 'key'; key: SoftKey; ctrl?: boolean }
  | { k: 'hardware'; event: KeyboardEvent; down: boolean };

export type SinkLike = { value: string; setSelectionRange(start: number, end: number): void };

type KeyLike = Pick<KeyboardEvent, 'key' | 'code' | 'keyCode' | 'isComposing' | 'preventDefault'>;
type BeforeInputLike = Pick<InputEvent, 'inputType' | 'cancelable' | 'preventDefault'>;

export const SENTINEL = '  ';
/** Reset the field past this length, at a word boundary. */
const MAX_SINK = 256;
/**
 * A routed key-down whose default action the keyboard performs anyway (some
 * Android keyboards edit the field regardless of preventDefault) must not
 * be sent twice: its echo in the field is ignored for this long.
 */
const ECHO_MS = 120;

/** A key-down from a physical keyboard, which the key translators can send as a key. */
export function isHardwareKey(e: Pick<KeyboardEvent, 'key' | 'code' | 'keyCode' | 'isComposing'>): boolean {
  if (e.isComposing || e.keyCode === 229) return false;
  if (!e.code || e.code === 'Unidentified') return false;
  return e.key !== 'Unidentified' && e.key !== 'Process' && e.key !== 'Dead';
}

export class SoftKeyboardInput {
  private last = '';
  private composing = false;
  /** Physical keys whose key-down went to the translators, so their key-up follows. */
  private readonly routed = new Set<string>();
  private echo: { key: SoftKey; until: number } | null = null;

  constructor(
    private readonly sink: SinkLike,
    private readonly emit: (a: SoftKeyAction) => void,
    private readonly now: () => number = () => Date.now(),
  ) {
    this.reseed();
  }

  get isComposing(): boolean {
    return this.composing;
  }

  /** Put the sentinel back with the caret after it. */
  reseed(): void {
    this.sink.value = SENTINEL;
    try {
      this.sink.setSelectionRange(SENTINEL.length, SENTINEL.length);
    } catch {
      /* not focusable yet */
    }
    this.last = SENTINEL;
  }

  keydown(e: KeyLike): void {
    if (!isHardwareKey(e)) return;
    e.preventDefault();
    this.routed.add(e.code);
    if (e.key === 'Backspace' || e.key === 'Enter' || e.key === 'Delete') {
      this.echo = { key: e.key, until: this.now() + ECHO_MS };
    }
    this.emit({ k: 'hardware', event: e as KeyboardEvent, down: true });
  }

  keyup(e: KeyLike): void {
    if (!this.routed.delete(e.code)) return;
    e.preventDefault();
    this.emit({ k: 'hardware', event: e as KeyboardEvent, down: false });
  }

  beforeinput(e: BeforeInputLike): void {
    if (this.composing || !e.cancelable) return; // the diff in input() covers it
    let key: SoftKey | null = null;
    let ctrl = false;
    switch (e.inputType) {
      case 'insertLineBreak':
      case 'insertParagraph':
        key = 'Enter';
        break;
      case 'deleteContentForward':
        key = 'Delete';
        break;
      case 'deleteWordBackward':
        key = 'Backspace';
        ctrl = true;
        break;
      default:
        return;
    }
    e.preventDefault();
    if (!this.takeEcho(key)) this.emit(ctrl ? { k: 'key', key, ctrl } : { k: 'key', key });
    if (key === 'Enter') this.reseed(); // a new line: fresh autocorrect context
  }

  input(): void {
    const value = this.sink.value;
    const { deleted, inserted } = diffText(this.last, value);
    this.last = value;
    const echoed =
      (deleted === 1 && inserted === '' && this.takeEcho('Backspace')) ||
      (deleted === 0 && inserted === '\n' && this.takeEcho('Enter'));
    if (!echoed) {
      for (let i = 0; i < deleted; i++) this.emit({ k: 'key', key: 'Backspace' });
      this.emitText(inserted);
    }
    if (!this.composing) this.maybeReseed(value);
  }

  compositionstart(): void {
    this.composing = true;
  }

  compositionend(): void {
    this.composing = false;
    this.input(); // Safari ends the composition before the last input event
  }

  /** Focus left the field: no key-up will come for routed keys. */
  reset(): void {
    this.routed.clear();
    this.composing = false;
    this.echo = null;
    this.reseed();
  }

  private emitText(text: string): void {
    // Line breaks and tabs are keys on the remote side, not characters.
    let run = '';
    const flush = (): void => {
      if (run) this.emit({ k: 'text', text: run });
      run = '';
    };
    for (const ch of text.replace(/\r\n?/g, '\n')) {
      if (ch === '\n' || ch === '\t') {
        flush();
        this.emit({ k: 'key', key: ch === '\n' ? 'Enter' : 'Tab' });
      } else {
        run += ch;
      }
    }
    flush();
  }

  private takeEcho(key: SoftKey): boolean {
    const echo = this.echo;
    this.echo = null;
    return !!echo && echo.key === key && this.now() <= echo.until;
  }

  private maybeReseed(value: string): void {
    if (
      !value.startsWith(SENTINEL) ||
      value.includes('\n') ||
      (value.length > MAX_SINK && value.endsWith(' '))
    ) {
      this.reseed();
    }
  }
}
