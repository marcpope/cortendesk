import { escapeHtml, iconHtml } from './common';

/**
 * A short in-page QWERTY keyboard (about 40% of a landscape phone screen),
 * optional, for landscape where the phone's own keyboard leaves only a
 * sliver of the remote screen. Off by default: Dhivehi (Thaana) and other
 * scripts need the system keyboard and its IME. Its keys go through the
 * same path as the on-screen keyboard (text as `seq`, Backspace/Enter as
 * keys, sticky modifiers from the extra-keys bar).
 */

export type CompactAction =
  | { k: 'text'; text: string }
  | { k: 'key'; key: 'Backspace' | 'Enter' }
  | { k: 'hide' };

type Layer = 'lower' | 'upper' | 'sym' | 'sym2';

const SPECIAL = { shift: '⇧', bksp: '⌫', enter: '⏎', hide: '⌄', sym: '?123', sym2: '#+=', abc: 'ABC', back: '123', space: ' ' } as const;
type Special = keyof typeof SPECIAL;
export type CKey = { ch: string } | { sp: Special };

const row = (s: string): CKey[] => Array.from(s).map((ch) => ({ ch }));

export const LAYOUTS: Record<Layer, CKey[][]> = {
  lower: [
    row('qwertyuiop'),
    row('asdfghjkl'),
    [{ sp: 'shift' }, ...row('zxcvbnm'), { sp: 'bksp' }],
    [{ sp: 'sym' }, { ch: ',' }, { sp: 'space' }, { ch: '.' }, { sp: 'enter' }, { sp: 'hide' }],
  ],
  upper: [
    row('QWERTYUIOP'),
    row('ASDFGHJKL'),
    [{ sp: 'shift' }, ...row('ZXCVBNM'), { sp: 'bksp' }],
    [{ sp: 'sym' }, { ch: ',' }, { sp: 'space' }, { ch: '.' }, { sp: 'enter' }, { sp: 'hide' }],
  ],
  sym: [
    row('1234567890'),
    row('-/:;()$&@"'),
    [{ sp: 'sym2' }, ...row(".,?!'"), { sp: 'bksp' }],
    [{ sp: 'abc' }, { ch: '_' }, { sp: 'space' }, { ch: '\\' }, { sp: 'enter' }, { sp: 'hide' }],
  ],
  sym2: [
    row('[]{}#%^*+='),
    row('_\\|~<>€£¥•'),
    [{ sp: 'back' }, ...row(".,?!'"), { sp: 'bksp' }],
    [{ sp: 'abc' }, { ch: '`' }, { sp: 'space' }, { ch: '§' }, { sp: 'enter' }, { sp: 'hide' }],
  ],
};

/** Layer logic, without a DOM: press a key, get what to send. Shift is for one letter. */
export class CompactKeyboardModel {
  layer: Layer = 'lower';

  press(key: CKey): CompactAction | null {
    if ('ch' in key) {
      if (this.layer === 'upper') this.layer = 'lower';
      return { k: 'text', text: key.ch };
    }
    switch (key.sp) {
      case 'shift':
        this.layer = this.layer === 'upper' ? 'lower' : 'upper';
        return null;
      case 'sym':
      case 'back':
        this.layer = 'sym';
        return null;
      case 'sym2':
        this.layer = 'sym2';
        return null;
      case 'abc':
        this.layer = 'lower';
        return null;
      case 'space':
        return { k: 'text', text: ' ' };
      case 'bksp':
        return { k: 'key', key: 'Backspace' };
      case 'enter':
        return { k: 'key', key: 'Enter' };
      case 'hide':
        return { k: 'hide' };
    }
  }
}

const REPEAT_DELAY_MS = 400;
const REPEAT_MS = 70;

export class CompactKeyboard {
  readonly el: HTMLElement;
  private readonly model = new CompactKeyboardModel();

  constructor(private readonly emit: (a: CompactAction) => void, label: string, hideLabel: string) {
    const el = document.createElement('div');
    el.id = 'rd-ckbd';
    el.className = 'rd-stream-only';
    el.setAttribute('role', 'group');
    el.setAttribute('aria-label', label);
    el.hidden = true;
    el.dataset.hideLabel = hideLabel;
    this.el = el;
    // Never take focus; act on the key, repeat Backspace while held.
    let timer: ReturnType<typeof setTimeout> | undefined;
    let repeat: ReturnType<typeof setInterval> | undefined;
    const stop = (): void => {
      clearTimeout(timer);
      clearInterval(repeat);
      timer = repeat = undefined;
    };
    el.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      const b = (e.target as Element).closest<HTMLButtonElement>('button[data-ck]');
      if (!b) return;
      const key = this.keyOf(b);
      if (!key) return;
      this.fire(key);
      if ('sp' in key && key.sp === 'bksp') {
        timer = setTimeout(() => {
          repeat = setInterval(() => this.fire(key), REPEAT_MS);
        }, REPEAT_DELAY_MS);
      }
    });
    for (const t of ['pointerup', 'pointercancel', 'pointerleave'] as const) el.addEventListener(t, stop);
    el.addEventListener('contextmenu', (e) => e.preventDefault());
    this.render();
  }

  get shown(): boolean {
    return !this.el.hidden;
  }

  show(on: boolean): void {
    this.el.hidden = !on;
    if (!on && this.model.layer !== 'lower') {
      this.model.layer = 'lower';
      this.render();
    }
  }

  private fire(key: CKey): void {
    const before = this.model.layer;
    const a = this.model.press(key);
    if (this.model.layer !== before) this.render();
    if (a) this.emit(a);
  }

  private keyOf(b: HTMLButtonElement): CKey | undefined {
    const [r, c] = (b.dataset.ck ?? '').split(':').map(Number);
    return LAYOUTS[this.model.layer][r!]?.[c!];
  }

  private render(): void {
    const layer = this.model.layer;
    this.el.dataset.layer = layer;
    this.el.innerHTML = LAYOUTS[layer]
      .map((keys, r) =>
        '<div class="rd-ck-row">' +
        keys
          .map((k, c) => {
            const cls = 'ch' in k ? 'rd-ck' : `rd-ck rd-ck-${k.sp}`;
            const label = 'ch' in k
              ? escapeHtml(k.ch)
              : k.sp === 'hide'
                ? iconHtml('keyboardHide')
                : escapeHtml(SPECIAL[k.sp]);
            const aria = 'sp' in k && k.sp === 'hide' ? ` aria-label="${escapeHtml(this.el.dataset.hideLabel ?? '')}"` : '';
            const on = 'sp' in k && k.sp === 'shift' && layer === 'upper' ? ' data-on="1"' : '';
            return `<button type="button" class="${cls}" data-ck="${r}:${c}"${aria}${on}>${label}</button>`;
          })
          .join('') +
        '</div>',
      )
      .join('');
  }
}
