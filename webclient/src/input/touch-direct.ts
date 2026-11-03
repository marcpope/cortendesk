import type { GestureEvent, Pt } from './gesture';

/** Mouse output shared by the touch mappers: client coordinates in, host input out. */
export type TouchOutput = {
  /** MOVE / DOWN / UP at a client point (mask = buttonMask(type, buttons)). */
  mouse(type: 'move' | 'down' | 'up', button: number, at: Pt): void;
  /** Wheel steps (RustDesk sign convention: positive = up / left). */
  wheel(x: number, y: number): void;
  haptic(): void;
  /** Three-finger tap. */
  keyboard(): void;
  /** Local zoom: multiply by scale about center, then pan. */
  zoom(scale: number, center: Pt, pan: Pt): void;
};

/** Turns gesture intents into input; one per touch mode. */
export type GestureMapper = { handle(e: GestureEvent): void };

export const LEFT = 0x01;
export const RIGHT = 0x02;

/** Finger travel per wheel step for two-finger scrolling. */
export const SCROLL_STEP_PX = 40;

/**
 * Two-finger scroll -> wheel steps. Natural scrolling: fingers moving down
 * reveal what is above, which is a wheel step UP (+1 in RustDesk's
 * convention). The remainder carries over so slow drags still scroll.
 */
export class ScrollAccumulator {
  private x = 0;
  private y = 0;
  constructor(private readonly stepPx = SCROLL_STEP_PX) {}

  add(dx: number, dy: number): { x: number; y: number } {
    this.x += dx;
    this.y += dy;
    const sx = Math.trunc(this.x / this.stepPx);
    const sy = Math.trunc(this.y / this.stepPx);
    this.x -= sx * this.stepPx;
    this.y -= sy * this.stepPx;
    return { x: sx, y: sy };
  }

  reset(): void {
    this.x = this.y = 0;
  }
}

/**
 * Touch (direct) mode: the finger is the mouse, where it touches.
 * tap = click there (the host cursor is moved first: it only moves on MOVE),
 * double tap = double click at the first tap's point, long press or
 * two-finger tap = right click, one-finger drag = left drag, two-finger drag
 * = scroll, pinch = local zoom, three-finger tap = keyboard.
 */
export class DirectTouchMapper {
  private readonly scroll = new ScrollAccumulator();
  private dragging = false;

  constructor(private readonly out: TouchOutput) {}

  handle(e: GestureEvent): void {
    const o = this.out;
    switch (e.g) {
      case 'tap':
        if (e.fingers === 3) {
          o.keyboard();
          return;
        }
        this.click(e.fingers === 2 ? RIGHT : LEFT, e.at);
        return;
      case 'longPress':
        o.haptic();
        this.click(RIGHT, e.at);
        return;
      case 'dragStart':
        o.mouse('move', 0, e.from);
        o.mouse('down', LEFT, e.from);
        o.mouse('move', 0, e.at);
        this.dragging = true;
        return;
      case 'dragMove':
        if (this.dragging) o.mouse('move', 0, e.at);
        return;
      case 'dragEnd':
        if (!this.dragging) return;
        this.dragging = false;
        if (!e.cancelled) o.mouse('move', 0, e.at);
        o.mouse('up', LEFT, e.at);
        return;
      case 'scroll': {
        const s = this.scroll.add(e.dx, e.dy);
        if (s.x || s.y) o.wheel(s.x, s.y);
        return;
      }
      case 'pinch':
        o.zoom(e.scale, e.center, e.pan);
        return;
      case 'idle':
        this.scroll.reset();
        return;
      case 'down':
        return;
    }
  }

  private click(button: number, at: Pt): void {
    this.out.mouse('move', 0, at);
    this.out.mouse('down', button, at);
    this.out.mouse('up', button, at);
  }
}
