import type { GestureEvent, Pt } from './gesture';
import type { Rect } from './view-transform';
import { LEFT, RIGHT, ScrollAccumulator, type GestureMapper, type TouchOutput } from './touch-direct';

/**
 * Trackpad (relative) mode: the screen is a laptop trackpad. A local cursor
 * moves by finger deltas (with acceleration); taps click where that cursor
 * is. For precise aiming on a small screen, where a fingertip covers a
 * dozen remote pixels.
 *
 * one-finger move = move the cursor; tap = left click; double tap = double
 * click; tap then drag = left drag (drag lock); two-finger tap or long
 * press = right click; two-finger drag = scroll; pinch = zoom (the view
 * follows the cursor); three-finger tap = keyboard.
 *
 * The cursor lives in content-relative coordinates (0..1 of the remote
 * image), so zooming or rotating the view does not move it on the host.
 * It is sent as absolute MOVEs, which every host handles.
 */

/** Pointer acceleration: slow strokes are precise, fast ones cover distance. */
export function trackpadGain(stepPx: number): number {
  const m = Math.abs(stepPx);
  return Math.min(2.5, 1 + Math.max(0, m - 2) * 0.12);
}

/** A tap waits this long for a second touch (double tap / drag lock) before clicking. */
export const TAP_HOLD_MS = 250;

export type TrackpadHooks = {
  /** Where the remote image is on screen now (client coordinates). */
  content(): Rect;
  /** The local cursor moved: draw it, and keep it in view when zoomed. */
  cursor(at: Pt): void;
};

export class TrackpadMapper implements GestureMapper {
  /** Cursor, as a fraction of the remote image. */
  private fx = 0.5;
  private fy = 0.5;
  private readonly scroll = new ScrollAccumulator();
  private pendingClick: ReturnType<typeof setTimeout> | undefined;
  /** A tap is waiting: the next touch decides between click, double click and drag. */
  private tapWaiting = false;
  private dragging = false;
  private dragButton = false;

  constructor(
    private readonly out: TouchOutput,
    private readonly hooks: TrackpadHooks,
  ) {}

  /** Cursor position on screen. */
  position(): Pt {
    const r = this.hooks.content();
    return { x: r.x + this.fx * r.width, y: r.y + this.fy * r.height };
  }

  /** Put the cursor somewhere (e.g. the remote cursor's position), as content fractions. */
  place(fx: number, fy: number): void {
    this.fx = clamp01(fx);
    this.fy = clamp01(fy);
    this.hooks.cursor(this.position());
  }

  handle(e: GestureEvent): void {
    const o = this.out;
    switch (e.g) {
      case 'down':
        if (!this.tapWaiting) return;
        // A second touch right after a tap: hold the click until we know
        // whether this is a double tap or a drag. Anything else: click now.
        if (e.afterTap) clearTimeout(this.pendingClick);
        else this.flushTap();
        return;
      case 'tap':
        if (e.fingers === 3) {
          this.flushTap();
          o.keyboard();
          return;
        }
        if (e.fingers === 2) {
          this.flushTap();
          this.click(RIGHT);
          return;
        }
        if (this.tapWaiting) {
          // Second tap: double click.
          this.tapWaiting = false;
          clearTimeout(this.pendingClick);
          this.click(LEFT);
          this.click(LEFT);
          return;
        }
        this.tapWaiting = true;
        this.pendingClick = setTimeout(() => this.flushTap(), TAP_HOLD_MS);
        return;
      case 'longPress':
        this.flushTap();
        o.haptic();
        this.click(RIGHT);
        return;
      case 'dragStart':
        this.dragging = true;
        if (this.tapWaiting && e.afterTap) {
          // Tap, then drag: hold the left button for the drag (no click).
          this.tapWaiting = false;
          clearTimeout(this.pendingClick);
          this.dragButton = true;
          const p = this.position();
          o.mouse('move', 0, p);
          o.mouse('down', LEFT, p);
        } else {
          this.flushTap();
        }
        this.moveBy(e.at.x - e.from.x, e.at.y - e.from.y);
        return;
      case 'dragMove':
        if (this.dragging) this.moveBy(e.delta.x, e.delta.y);
        return;
      case 'dragEnd':
        this.dragging = false;
        if (this.dragButton) {
          this.dragButton = false;
          o.mouse('up', LEFT, this.position());
        }
        return;
      case 'scroll': {
        this.flushTap();
        const s = this.scroll.add(e.dx, e.dy);
        if (s.x || s.y) o.wheel(s.x, s.y);
        return;
      }
      case 'pinch':
        this.flushTap();
        o.zoom(e.scale, e.center, e.pan);
        this.hooks.cursor(this.position());
        return;
      case 'idle':
        this.scroll.reset();
        return;
    }
  }

  /** Forget a waiting tap (mode switch, detach). */
  reset(): void {
    clearTimeout(this.pendingClick);
    this.tapWaiting = false;
    if (this.dragButton) this.out.mouse('up', LEFT, this.position());
    this.dragButton = this.dragging = false;
  }

  private flushTap(): void {
    if (!this.tapWaiting) return;
    this.tapWaiting = false;
    clearTimeout(this.pendingClick);
    this.click(LEFT);
  }

  private moveBy(dx: number, dy: number): void {
    const r = this.hooks.content();
    if (r.width <= 0 || r.height <= 0) return;
    const g = trackpadGain(Math.hypot(dx, dy));
    this.fx = clamp01(this.fx + (dx * g) / r.width);
    this.fy = clamp01(this.fy + (dy * g) / r.height);
    const p = this.position();
    this.out.mouse('move', 0, p);
    this.hooks.cursor(p);
  }

  private click(button: number): void {
    const p = this.position();
    this.out.mouse('move', 0, p);
    this.out.mouse('down', button, p);
    this.out.mouse('up', button, p);
  }
}

const clamp01 = (v: number): number => (v < 0 ? 0 : v > 1 ? 1 : v);
