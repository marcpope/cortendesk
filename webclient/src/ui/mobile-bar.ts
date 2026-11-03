import { escapeHtml, iconHtml, type IconName } from './common';

/**
 * Session controls for phones and tablets, docked to an edge of the screen:
 * a strip along the top or bottom in portrait (thumb reach), a column down
 * the left or right side in landscape, where height is what is scarce. It
 * replaces the top bar and the dock while a session streams, every control
 * one tap away, and the remote screen fits the rest (the bar never covers
 * it). The swap button moves it to the opposite edge; the edge is
 * remembered per orientation.
 *
 * Buttons never take focus, so the on-screen keyboard stays open.
 */

export type Orientation = 'portrait' | 'landscape';
export type BarSide = 'top' | 'bottom' | 'left' | 'right';
export type BarPrefs = { barPortrait: 'top' | 'bottom'; barLandscape: 'left' | 'right' };

export type BarItem = {
  id: string;
  icon: IconName;
  label: string;
  /** Extra attributes for the button (data-kbd-toggle, aria-pressed...). */
  attrs?: string;
  /** Kept at the end of the bar, never scrolled away (settings, disconnect). */
  pinned?: boolean;
  onClick(button: HTMLButtonElement): void;
};

/** Phone held sideways? The screen's orientation, not the window's (a keyboard shrinks the window). */
export function currentOrientation(
  screenType: string | undefined,
  legacyAngle: number | undefined,
  width: number,
  height: number,
): Orientation {
  if (screenType) return screenType.startsWith('landscape') ? 'landscape' : 'portrait';
  if (legacyAngle !== undefined) return Math.abs(legacyAngle) === 90 ? 'landscape' : 'portrait';
  return width > height ? 'landscape' : 'portrait';
}

export function barSide(o: Orientation, p: BarPrefs): BarSide {
  return o === 'landscape' ? p.barLandscape : p.barPortrait;
}

/** The swap button: the opposite edge, as the pref change to remember. */
export function swappedPrefs(o: Orientation, p: BarPrefs): Partial<BarPrefs> {
  return o === 'landscape'
    ? { barLandscape: p.barLandscape === 'left' ? 'right' : 'left' }
    : { barPortrait: p.barPortrait === 'top' ? 'bottom' : 'top' };
}

export class MobileBar {
  readonly el: HTMLElement;

  constructor(items: BarItem[], label: string) {
    const el = document.createElement('div');
    el.id = 'rd-mbar';
    el.className = 'rd-stream-only';
    el.setAttribute('role', 'toolbar');
    el.setAttribute('aria-label', label);
    const html = (list: BarItem[]): string =>
      list
        .map((it) =>
          `<button type="button" class="rd-mbar-btn" data-mbar="${escapeHtml(it.id)}" title="${escapeHtml(it.label)}" aria-label="${escapeHtml(it.label)}"${it.attrs ? ' ' + it.attrs : ''}>${iconHtml(it.icon)}</button>`,
        )
        .join('');
    // The main group scrolls when it does not fit; the pinned ones stay put.
    el.innerHTML =
      `<div class="rd-mbar-main">${html(items.filter((it) => !it.pinned))}</div>` +
      `<div class="rd-mbar-end">${html(items.filter((it) => it.pinned))}</div>`;
    this.el = el;
    for (const it of items) {
      const b = el.querySelector<HTMLButtonElement>(`[data-mbar="${it.id}"]`)!;
      b.addEventListener('click', () => it.onClick(b));
    }
    // Keep focus (and the on-screen keyboard) where it is.
    el.addEventListener('pointerdown', (e) => e.preventDefault());
    el.addEventListener('contextmenu', (e) => e.preventDefault());
  }

  button(id: string): HTMLButtonElement | null {
    return this.el.querySelector<HTMLButtonElement>(`[data-mbar="${id}"]`);
  }

  setIcon(id: string, icon: IconName): void {
    const b = this.button(id);
    if (b) b.innerHTML = iconHtml(icon);
  }

  setOn(id: string, on: boolean): void {
    const b = this.button(id);
    b?.classList.toggle('rd-on', on);
    b?.setAttribute('aria-pressed', String(on));
  }
}
