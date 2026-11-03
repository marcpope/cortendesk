import type { UiCommand } from '../core/contracts';
import {
  ModifierLatches,
  XKEY_PAGES,
  xkeyCommands,
  type Mod,
  type XKey,
} from '../input/extra-keys';
import type { KeyTarget } from '../input/mouse-keyboard';
import { escapeHtml } from './common';
import { haptic } from './haptics';
import { htl, tl } from '../i18n';

const LONG_PRESS_MS = 500;
const REPEAT_DELAY_MS = 400;
const REPEAT_MS = 70;
/** Keys that repeat while held, like on a real keyboard. */
const REPEATING = new Set(['left', 'right', 'up', 'down', 'bksp', 'del', 'pgup', 'pgdn']);

export type ExtraKeysDeps = {
  send(cmds: UiCommand[]): void;
  target(): KeyTarget | null;
  /** Sticky modifier state changed (the app re-reads latches.active()). */
  onLatchChange?(): void;
};

/**
 * The extra-keys bar under the remote screen (phones, tablets): pages of
 * keys a phone keyboard lacks. Buttons never take focus, so the on-screen
 * keyboard stays open while they are used.
 */
export class ExtraKeysBar {
  readonly el: HTMLElement;
  readonly latches = new ModifierLatches();
  private page = 0;
  private enabled = true;
  private row!: HTMLElement;

  constructor(private readonly deps: ExtraKeysDeps) {
    const el = document.createElement('div');
    el.id = 'rd-xkeys';
    el.className = 'rd-stream-only';
    el.setAttribute('role', 'toolbar');
    el.setAttribute('aria-label', tl('extra_keys'));
    el.hidden = true;
    this.el = el;
    // Keep focus (and the on-screen keyboard) where it is.
    el.addEventListener('pointerdown', (e) => e.preventDefault());
    el.addEventListener('contextmenu', (e) => e.preventDefault());
    this.render();
  }

  get shown(): boolean {
    return !this.el.hidden;
  }

  show(on: boolean): void {
    this.el.hidden = !on;
  }

  setEnabled(on: boolean): void {
    this.enabled = on;
    if (!on) this.clearLatches();
    for (const b of this.el.querySelectorAll<HTMLButtonElement>('button[data-xk]')) b.disabled = !on;
  }

  /** Sticky modifiers in force for the next key or click. */
  activeMods(): Mod[] {
    return this.enabled ? this.latches.active() : [];
  }

  /** A key or click used the sticky modifiers. */
  consume(): void {
    if (this.latches.consume()) this.sync();
  }

  clearLatches(): void {
    this.latches.clear();
    this.sync();
  }

  private render(): void {
    const pages = XKEY_PAGES;
    const next = pages[(this.page + 1) % pages.length]!;
    this.el.innerHTML =
      `<button type="button" class="rd-xk rd-xk-page" aria-label="${htl('more_keys')}">${escapeHtml(next.label)}</button>` +
      `<div class="rd-xk-row">` +
      pages[this.page]!.keys
        .map((x) => `<button type="button" class="rd-xk" data-xk="${x.id}"${this.enabled ? '' : ' disabled'}>${escapeHtml(x.label)}</button>`)
        .join('') +
      `</div>`;
    this.row = this.el.querySelector('.rd-xk-row')!;
    this.el.querySelector<HTMLButtonElement>('.rd-xk-page')!.addEventListener('click', () => {
      this.page = (this.page + 1) % pages.length;
      this.render();
    });
    for (const b of this.row.querySelectorAll<HTMLButtonElement>('button[data-xk]')) {
      const x = pages[this.page]!.keys.find((k) => k.id === b.dataset.xk)!;
      this.wire(b, x);
    }
    this.sync();
  }

  private wire(b: HTMLButtonElement, x: XKey): void {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let repeat: ReturnType<typeof setInterval> | undefined;
    let longPressed = false;
    const stop = (): void => {
      clearTimeout(timer);
      clearInterval(repeat);
      timer = repeat = undefined;
    };
    b.addEventListener('pointerdown', () => {
      if (b.disabled) return;
      longPressed = false;
      stop();
      if ('mod' in x) {
        timer = setTimeout(() => {
          longPressed = true;
          this.latches.lock(x.mod);
          haptic();
          this.sync();
        }, LONG_PRESS_MS);
      } else if (REPEATING.has(x.id)) {
        timer = setTimeout(() => {
          longPressed = true;
          this.press(x);
          repeat = setInterval(() => this.press(x), REPEAT_MS);
        }, REPEAT_DELAY_MS);
      }
    });
    for (const type of ['pointerup', 'pointercancel', 'pointerleave'] as const) b.addEventListener(type, stop);
    b.addEventListener('click', () => {
      stop();
      if (longPressed) {
        longPressed = false;
        return;
      }
      if ('mod' in x) {
        if (this.latches.tap(x.mod, Date.now()) === 'locked') haptic();
        this.sync();
        return;
      }
      this.press(x);
    });
  }

  private press(x: XKey): void {
    if (!this.enabled) return;
    this.deps.send(xkeyCommands(x, this.latches.active(), this.deps.target()));
    this.consume();
  }

  private sync(): void {
    for (const b of this.el.querySelectorAll<HTMLButtonElement>('button[data-xk]')) {
      const x = XKEY_PAGES[this.page]!.keys.find((k) => k.id === b.dataset.xk);
      if (!x || !('mod' in x)) continue;
      const state = this.latches.get(x.mod);
      b.dataset.latch = state;
      b.setAttribute('aria-pressed', String(state !== 'off'));
    }
    this.deps.onLatchChange?.();
  }
}
