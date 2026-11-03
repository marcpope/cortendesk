/**
 * Touch gesture recogniser: raw pointer down/move/up/cancel for touch
 * pointers in, gesture intents out. Pure (no DOM), so every timing and
 * threshold is unit-tested with fake timers; touch-direct.ts and
 * touch-trackpad.ts turn the intents into mouse input.
 *
 * One finger: tap (with double-tap detection), long press, drag.
 * Two or three fingers: tap (right-click / keyboard), two-finger scroll,
 * pinch (zoom + pan). Extra fingers during a one-finger drag are ignored.
 *
 * A cancelled pointer (system gesture, notification shade, alert) ends the
 * gesture: an active drag reports dragEnd {cancelled} so the mapper can
 * release the button, and the recogniser is ready for the next touch.
 */

export type Pt = { x: number; y: number };

export type GestureEvent =
  /** First finger down (afterTap: right after a tap, nearby — a double tap or tap-and-drag may follow). */
  | { g: 'down'; at: Pt; afterTap: boolean }
  /** `at`: where to click. A double tap reports the FIRST tap's point (count 2). */
  | { g: 'tap'; fingers: 1 | 2 | 3; at: Pt; count: 1 | 2 }
  | { g: 'longPress'; at: Pt }
  /** One finger left the slop circle. afterTap: it came down right after a tap (tap-and-drag). */
  | { g: 'dragStart'; from: Pt; at: Pt; afterTap: boolean }
  | { g: 'dragMove'; at: Pt; delta: Pt }
  | { g: 'dragEnd'; at: Pt; cancelled: boolean }
  /** One-finger movement before it became a drag is not reported; this is the two-finger centroid. */
  | { g: 'scroll'; dx: number; dy: number }
  /** Incremental: multiply zoom by `scale` about `center`, then pan by `pan`. */
  | { g: 'pinch'; scale: number; center: Pt; pan: Pt }
  | { g: 'idle' };

export type GestureOptions = {
  slopPx: number;
  longPressMs: number;
  doubleTapMs: number;
  doubleTapSlopPx: number;
  /** Longest a multi-finger touch may last and still be a tap. */
  multiTapMs: number;
  /** Change in finger distance that makes a two-finger gesture a pinch. */
  pinchPx: number;
};

export const GESTURE_DEFAULTS: GestureOptions = {
  slopPx: 10,
  longPressMs: 500,
  doubleTapMs: 300,
  doubleTapSlopPx: 40,
  multiTapMs: 350,
  pinchPx: 24,
};

type Finger = { start: Pt; last: Pt };

type State =
  | { s: 'idle' }
  | { s: 'one'; id: number; start: Pt; last: Pt; t0: number; afterTap: boolean }
  | { s: 'pressed'; id: number } // long press fired; wait for the finger to lift
  | { s: 'drag'; id: number; last: Pt }
  | {
      s: 'multi';
      mode: 'undecided' | 'scroll' | 'pinch' | 'done';
      t0: number;
      maxFingers: number;
      first: Pt;
      centroid: Pt;
      dist: number;
    };

const dist2 = (a: Pt, b: Pt): number => (a.x - b.x) ** 2 + (a.y - b.y) ** 2;

export class GestureRecognizer {
  private readonly o: GestureOptions;
  private state: State = { s: 'idle' };
  private readonly fingers = new Map<number, Finger>();
  private timer: ReturnType<typeof setTimeout> | undefined;
  private lastTap: { at: Pt; t: number } | null = null;

  constructor(
    private readonly emit: (e: GestureEvent) => void,
    opts: Partial<GestureOptions> = {},
  ) {
    this.o = { ...GESTURE_DEFAULTS, ...opts };
  }

  get busy(): boolean {
    return this.state.s !== 'idle';
  }

  down(id: number, x: number, y: number, t: number): void {
    const p = { x, y };
    this.fingers.set(id, { start: p, last: p });
    const st = this.state;
    if (st.s === 'idle') {
      const afterTap = !!this.lastTap && t - this.lastTap.t <= this.o.doubleTapMs &&
        dist2(this.lastTap.at, p) <= this.o.doubleTapSlopPx ** 2;
      this.state = { s: 'one', id, start: p, last: p, t0: t, afterTap };
      this.timer = setTimeout(() => this.onLongPress(), this.o.longPressMs);
      this.emit({ g: 'down', at: p, afterTap });
      return;
    }
    if (st.s === 'one') {
      // A second finger: a two-finger gesture, not a one-finger one.
      this.clearTimer();
      this.lastTap = null;
      this.state = {
        s: 'multi', mode: 'undecided', t0: st.t0, maxFingers: this.fingers.size,
        first: st.start, centroid: this.centroid(), dist: this.spread(),
      };
      return;
    }
    if (st.s === 'multi') {
      st.maxFingers = Math.max(st.maxFingers, this.fingers.size);
      // Re-baseline, or the new finger reads as a jump.
      st.centroid = this.centroid();
      st.dist = this.spread();
    }
    // 'drag' / 'pressed': extra fingers are ignored.
  }

  move(id: number, x: number, y: number, _t: number): void {
    const f = this.fingers.get(id);
    if (!f) return;
    const p = { x, y };
    const prev = f.last;
    f.last = p;
    const st = this.state;
    switch (st.s) {
      case 'one':
        if (id !== st.id) return;
        st.last = p;
        if (dist2(st.start, p) > this.o.slopPx ** 2) {
          this.clearTimer();
          this.state = { s: 'drag', id, last: p };
          this.emit({ g: 'dragStart', from: st.start, at: p, afterTap: st.afterTap });
        }
        return;
      case 'drag':
        if (id !== st.id) return;
        this.emit({ g: 'dragMove', at: p, delta: { x: p.x - prev.x, y: p.y - prev.y } });
        st.last = p;
        return;
      case 'multi': {
        if (this.fingers.size < 2 || st.mode === 'done') return;
        const c = this.centroid();
        const d = this.spread();
        if (st.mode === 'undecided') {
          if (st.maxFingers === 2 && Math.abs(d - st.dist) > this.o.pinchPx) {
            st.mode = 'pinch';
          } else if (dist2(c, st.centroid) > this.o.slopPx ** 2) {
            st.mode = st.maxFingers === 2 ? 'scroll' : 'done';
          } else {
            return;
          }
          if (st.mode === 'done') return;
          // Start from here: the decision threshold itself is not movement.
          if (st.mode === 'scroll') {
            this.emit({ g: 'scroll', dx: c.x - st.centroid.x, dy: c.y - st.centroid.y });
          }
          st.centroid = c;
          st.dist = d;
          return;
        }
        if (st.mode === 'scroll') {
          this.emit({ g: 'scroll', dx: c.x - st.centroid.x, dy: c.y - st.centroid.y });
        } else if (st.mode === 'pinch' && st.dist > 0 && d > 0) {
          this.emit({ g: 'pinch', scale: d / st.dist, center: c, pan: { x: c.x - st.centroid.x, y: c.y - st.centroid.y } });
        }
        st.centroid = c;
        st.dist = d;
        return;
      }
      default:
        return;
    }
  }

  up(id: number, x: number, y: number, t: number): void {
    const f = this.fingers.get(id);
    if (!f) return;
    f.last = { x, y };
    const st = this.state;
    switch (st.s) {
      case 'one': {
        this.fingers.delete(id);
        if (id !== st.id) return;
        this.clearTimer();
        const prev = this.lastTap;
        const isDouble = st.afterTap && !!prev;
        const at = isDouble ? prev!.at : st.start;
        this.lastTap = isDouble ? null : { at: st.start, t };
        this.emit({ g: 'tap', fingers: 1, at, count: isDouble ? 2 : 1 });
        this.toIdle();
        return;
      }
      case 'drag':
        this.fingers.delete(id);
        if (id !== st.id) return;
        this.lastTap = null;
        this.emit({ g: 'dragEnd', at: { x, y }, cancelled: false });
        this.toIdle();
        return;
      case 'pressed':
        this.fingers.delete(id);
        if (id === st.id) this.toIdle();
        return;
      case 'multi':
        this.fingers.delete(id);
        if (this.fingers.size > 0) {
          // Fewer fingers: keep going from the new baseline.
          if (this.fingers.size >= 2) {
            st.centroid = this.centroid();
            st.dist = this.spread();
          }
          return;
        }
        if (st.mode === 'undecided' && t - st.t0 <= this.o.multiTapMs) {
          const n = st.maxFingers >= 3 ? 3 : 2;
          this.emit({ g: 'tap', fingers: n, at: st.first, count: 1 });
        }
        this.toIdle();
        return;
      default:
        this.fingers.delete(id);
        if (this.fingers.size === 0) this.toIdle();
    }
  }

  /**
   * The browser took a pointer away (pointercancel / lost capture). The whole
   * gesture is over; an active drag is reported cancelled so its button is
   * released. Unknown ids are ignored.
   */
  cancel(id: number): void {
    if (!this.fingers.has(id)) return;
    const st = this.state;
    if (st.s === 'drag') this.emit({ g: 'dragEnd', at: st.last, cancelled: true });
    this.lastTap = null;
    this.toIdle();
  }

  /** Forget everything (detach, mode switch). */
  reset(): void {
    const st = this.state;
    if (st.s === 'drag') this.emit({ g: 'dragEnd', at: st.last, cancelled: true });
    this.lastTap = null;
    this.toIdle();
  }

  private onLongPress(): void {
    this.timer = undefined;
    const st = this.state;
    if (st.s !== 'one') return;
    this.lastTap = null;
    this.state = { s: 'pressed', id: st.id };
    this.emit({ g: 'longPress', at: st.last });
  }

  private toIdle(): void {
    this.clearTimer();
    this.fingers.clear();
    this.state = { s: 'idle' };
    this.emit({ g: 'idle' });
  }

  private clearTimer(): void {
    if (this.timer !== undefined) clearTimeout(this.timer);
    this.timer = undefined;
  }

  private centroid(): Pt {
    let x = 0;
    let y = 0;
    for (const f of this.fingers.values()) {
      x += f.last.x;
      y += f.last.y;
    }
    const n = this.fingers.size || 1;
    return { x: x / n, y: y / n };
  }

  /** Distance between the first two fingers. */
  private spread(): number {
    const [a, b] = [...this.fingers.values()];
    if (!a || !b) return 0;
    return Math.sqrt(dist2(a.last, b.last));
  }
}
