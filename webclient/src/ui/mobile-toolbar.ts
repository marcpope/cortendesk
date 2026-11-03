import { escapeHtml, iconHtml, type IconName } from './common';

/**
 * Floating session controls for phones and tablets: a pill on the edge of
 * the remote screen that replaces the top bar and the dock (which take a
 * third of a landscape phone). Drag the handle to move it; it snaps to the
 * nearer side and the place is remembered. Tap the handle to fold it.
 *
 * Buttons never take focus, so the on-screen keyboard stays open.
 */

export type PillSide = 'left' | 'right';
export type PillPos = { side: PillSide; y: number };

export type PillItem = {
  id: string;
  icon: IconName;
  label: string;
  /** Extra attributes for the button (data-kbd-toggle, aria-pressed...). */
  attrs?: string;
  onClick(button: HTMLButtonElement): void;
};

const MARGIN = 8;
const DRAG_SLOP = 8;

/** Where a pill dropped at (centerX, top) settles: nearer side, top as a fraction of the free height. */
export function snapPill(centerX: number, top: number, pillH: number, vpW: number, vpH: number): PillPos {
  const free = Math.max(1, vpH - pillH - 2 * MARGIN);
  const y = Math.min(1, Math.max(0, (top - MARGIN) / free));
  return { side: centerX < vpW / 2 ? 'left' : 'right', y: Math.round(y * 1000) / 1000 };
}

/** Top in px for a saved position, kept inside the viewport whatever its height now. */
export function pillTop(pos: PillPos, pillH: number, vpH: number): number {
  const free = Math.max(0, vpH - pillH - 2 * MARGIN);
  return Math.round(MARGIN + Math.min(1, Math.max(0, pos.y)) * free);
}

export class MobileToolbar {
  readonly el: HTMLElement;
  private pos: PillPos;
  private open: boolean;
  private readonly handle: HTMLButtonElement;

  constructor(
    items: PillItem[],
    opts: {
      label: string;
      handleLabel: string;
      pos: PillPos;
      open: boolean;
      onChange(pos: PillPos, open: boolean): void;
    },
  ) {
    this.pos = opts.pos;
    this.open = opts.open;
    const el = document.createElement('div');
    el.id = 'rd-pill';
    el.className = 'rd-stream-only';
    el.setAttribute('role', 'toolbar');
    el.setAttribute('aria-label', opts.label);
    el.setAttribute('aria-orientation', 'vertical');
    el.innerHTML =
      `<button type="button" class="rd-pill-btn rd-pill-handle" title="${escapeHtml(opts.handleLabel)}" aria-label="${escapeHtml(opts.handleLabel)}">${iconHtml('grip')}</button>` +
      `<div class="rd-pill-items">` +
      items
        .map((it) =>
          `<button type="button" class="rd-pill-btn" data-pill="${escapeHtml(it.id)}" title="${escapeHtml(it.label)}" aria-label="${escapeHtml(it.label)}"${it.attrs ? ' ' + it.attrs : ''}>${iconHtml(it.icon)}</button>`,
        )
        .join('') +
      `</div>`;
    this.el = el;
    this.handle = el.querySelector<HTMLButtonElement>('.rd-pill-handle')!;
    for (const it of items) {
      const b = el.querySelector<HTMLButtonElement>(`[data-pill="${it.id}"]`)!;
      b.addEventListener('click', () => it.onClick(b));
    }
    // Keep focus (and the on-screen keyboard) where it is; the remote screen
    // underneath never sees these touches.
    el.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      e.stopPropagation();
    });
    el.addEventListener('contextmenu', (e) => e.preventDefault());
    this.wireDrag(opts.onChange);
    this.sync();
  }

  button(id: string): HTMLButtonElement | null {
    return this.el.querySelector<HTMLButtonElement>(`[data-pill="${id}"]`);
  }

  setIcon(id: string, icon: IconName): void {
    const b = this.button(id);
    if (b) b.innerHTML = iconHtml(icon);
  }

  /** Re-place after the viewport changed size (rotation, keyboard). */
  layout(): void {
    const parent = this.el.parentElement;
    if (!parent) return;
    this.el.style.top = `${pillTop(this.pos, this.el.offsetHeight, parent.clientHeight)}px`;
  }

  private sync(): void {
    this.el.dataset.side = this.pos.side;
    this.el.dataset.open = this.open ? '1' : '0';
    this.handle.setAttribute('aria-expanded', String(this.open));
    this.el.style.left = this.el.style.right = '';
    this.layout();
  }

  private wireDrag(onChange: (pos: PillPos, open: boolean) => void): void {
    let drag: { id: number; x0: number; y0: number; top0: number; moved: boolean } | null = null;
    const h = this.handle;
    h.addEventListener('pointerdown', (e) => {
      drag = { id: e.pointerId, x0: e.clientX, y0: e.clientY, top0: this.el.offsetTop, moved: false };
      try {
        h.setPointerCapture(e.pointerId);
      } catch {
        /* best effort */
      }
    });
    h.addEventListener('pointermove', (e) => {
      if (!drag || e.pointerId !== drag.id) return;
      const dx = e.clientX - drag.x0;
      const dy = e.clientY - drag.y0;
      if (!drag.moved && dx * dx + dy * dy < DRAG_SLOP * DRAG_SLOP) return;
      drag.moved = true;
      const parent = this.el.parentElement!.getBoundingClientRect();
      this.el.dataset.dragging = '1';
      this.el.style.top = `${drag.top0 + dy}px`;
      const left = e.clientX - parent.left - this.el.offsetWidth / 2;
      this.el.style.left = `${Math.max(0, Math.min(parent.width - this.el.offsetWidth, left))}px`;
      this.el.style.right = 'auto';
    });
    const end = (e: PointerEvent, cancelled: boolean): void => {
      if (!drag || e.pointerId !== drag.id) return;
      const moved = drag.moved;
      drag = null;
      delete this.el.dataset.dragging;
      if (moved) {
        const parent = this.el.parentElement!;
        const r = this.el.getBoundingClientRect();
        const p = parent.getBoundingClientRect();
        this.pos = snapPill(r.left + r.width / 2 - p.left, r.top - p.top, r.height, p.width, p.height);
      } else if (!cancelled) {
        this.open = !this.open;
      }
      this.sync();
      onChange(this.pos, this.open);
    };
    h.addEventListener('pointerup', (e) => end(e, false));
    h.addEventListener('pointercancel', (e) => end(e, true));
    // The handle acts on pointerup (it is also a drag handle); a keyboard
    // user still gets Enter/Space through click (detail 0 = not a pointer).
    h.addEventListener('click', (e) => {
      if (e.detail !== 0) return;
      this.open = !this.open;
      this.sync();
      onChange(this.pos, this.open);
    });
  }
}
