import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GestureRecognizer, type GestureEvent } from './gesture';
import { DirectTouchMapper, ScrollAccumulator, type TouchOutput } from './touch-direct';

/** Gesture events, without the 'idle' markers. */
function rig() {
  const ev: GestureEvent[] = [];
  const g = new GestureRecognizer((e) => {
    if (e.g !== 'idle' && e.g !== 'down') ev.push(e);
  });
  return { g, ev };
}

describe('GestureRecognizer', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.move(1, 103, 102, 50); // inside the slop
    g.up(1, 103, 102, 120);
    expect(ev).toEqual([{ g: 'tap', fingers: 1, at: { x: 100, y: 100 }, count: 1 }]);
  });

  it('double tap lands on the first tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 80);
    g.down(2, 120, 115, 200);
    g.up(2, 120, 115, 260);
    expect(ev).toEqual([
      { g: 'tap', fingers: 1, at: { x: 100, y: 100 }, count: 1 },
      { g: 'tap', fingers: 1, at: { x: 100, y: 100 }, count: 2 },
    ]);
  });

  it('a second tap too late or too far is a new single tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 50);
    g.down(2, 100, 100, 1000);
    g.up(2, 100, 100, 1050);
    g.down(3, 300, 300, 1100);
    g.up(3, 300, 300, 1150);
    expect(ev.map((e) => (e.g === 'tap' ? e.count : 0))).toEqual([1, 1, 1]);
  });

  it('long press fires while the finger is still down, once', () => {
    const { g, ev } = rig();
    g.down(1, 50, 60, 0);
    vi.advanceTimersByTime(499);
    expect(ev).toEqual([]);
    vi.advanceTimersByTime(1);
    expect(ev).toEqual([{ g: 'longPress', at: { x: 50, y: 60 } }]);
    g.move(1, 200, 200, 700); // no drag after a long press
    g.up(1, 200, 200, 900);
    expect(ev).toHaveLength(1);
  });

  it('drag: start from the touch point, moves, end', () => {
    const { g, ev } = rig();
    g.down(1, 10, 10, 0);
    g.move(1, 30, 10, 20);
    g.move(1, 50, 20, 40);
    g.up(1, 50, 20, 60);
    vi.advanceTimersByTime(1000); // no late long press
    expect(ev).toEqual([
      { g: 'dragStart', from: { x: 10, y: 10 }, at: { x: 30, y: 10 }, afterTap: false },
      { g: 'dragMove', at: { x: 50, y: 20 }, delta: { x: 20, y: 10 } },
      { g: 'dragEnd', at: { x: 50, y: 20 }, cancelled: false },
    ]);
  });

  it('tap then drag = tap-and-drag', () => {
    const { g, ev } = rig();
    g.down(1, 10, 10, 0);
    g.up(1, 10, 10, 50);
    g.down(2, 12, 12, 150);
    g.move(2, 60, 12, 200);
    expect(ev[1]).toMatchObject({ g: 'dragStart', afterTap: true });
  });

  it('pointercancel mid-drag reports the drag cancelled, and the next touch works', () => {
    const { g, ev } = rig();
    g.down(1, 10, 10, 0);
    g.move(1, 80, 10, 20);
    g.cancel(1);
    expect(ev.at(-1)).toEqual({ g: 'dragEnd', at: { x: 80, y: 10 }, cancelled: true });
    expect(g.busy).toBe(false);
    g.down(2, 5, 5, 100);
    g.up(2, 5, 5, 150);
    expect(ev.at(-1)).toEqual({ g: 'tap', fingers: 1, at: { x: 5, y: 5 }, count: 1 });
  });

  it('pointercancel before a long press: no stray right-click later', () => {
    const { g, ev } = rig();
    g.down(1, 10, 10, 0);
    g.cancel(1);
    vi.advanceTimersByTime(2000);
    expect(ev).toEqual([]);
    expect(g.busy).toBe(false);
  });

  it('cancel / up for a pointer it never saw is ignored', () => {
    const { g, ev } = rig();
    g.cancel(42);
    g.up(42, 0, 0, 0);
    g.move(42, 0, 0, 0);
    expect(ev).toEqual([]);
  });

  it('two-finger tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.down(2, 160, 100, 30);
    g.up(2, 160, 100, 120);
    g.up(1, 100, 100, 130);
    vi.advanceTimersByTime(1000);
    expect(ev).toEqual([{ g: 'tap', fingers: 2, at: { x: 100, y: 100 }, count: 1 }]);
  });

  it('three-finger tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.down(2, 150, 100, 10);
    g.down(3, 200, 100, 20);
    g.up(1, 100, 100, 100);
    g.up(2, 150, 100, 110);
    g.up(3, 200, 100, 120);
    expect(ev).toEqual([{ g: 'tap', fingers: 3, at: { x: 100, y: 100 }, count: 1 }]);
  });

  it('a slow two-finger touch is not a tap', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.down(2, 160, 100, 10);
    g.up(1, 100, 100, 900);
    g.up(2, 160, 100, 910);
    expect(ev).toEqual([]);
  });

  it('two fingers moving together scroll', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.down(2, 200, 100, 0);
    g.move(1, 100, 130, 10);
    g.move(2, 200, 130, 10);
    g.move(1, 100, 170, 20);
    g.move(2, 200, 170, 20);
    const dy = ev.filter((e) => e.g === 'scroll').reduce((n, e) => n + (e.g === 'scroll' ? e.dy : 0), 0);
    expect(ev.every((e) => e.g === 'scroll')).toBe(true);
    expect(dy).toBe(70);
  });

  it('fingers spreading apart pinch', () => {
    const { g, ev } = rig();
    g.down(1, 100, 100, 0);
    g.down(2, 200, 100, 0);
    g.move(2, 240, 100, 10); // spread 140: decides pinch
    g.move(2, 380, 100, 20); // spread 280: x2
    const pinches = ev.filter((e) => e.g === 'pinch');
    expect(pinches).toHaveLength(1);
    expect(pinches[0]).toMatchObject({ scale: 2, center: { x: 240, y: 100 }, pan: { x: 70, y: 0 } });
  });

  it('a second finger during a drag is ignored', () => {
    const { g, ev } = rig();
    g.down(1, 10, 10, 0);
    g.move(1, 50, 10, 10);
    g.down(2, 300, 300, 20);
    g.move(2, 320, 300, 30);
    g.up(2, 320, 300, 40);
    g.move(1, 60, 10, 50);
    g.up(1, 60, 10, 60);
    expect(ev.map((e) => e.g)).toEqual(['dragStart', 'dragMove', 'dragEnd']);
  });
});

describe('DirectTouchMapper', () => {
  function out() {
    const calls: string[] = [];
    const o: TouchOutput = {
      mouse: (t, b, at) => calls.push(`${t}${b ? ':' + b : ''}@${at.x},${at.y}`),
      wheel: (x, y) => calls.push(`wheel ${x},${y}`),
      haptic: () => calls.push('haptic'),
      keyboard: () => calls.push('keyboard'),
      zoom: (s) => calls.push(`zoom ${s}`),
    };
    return { o, calls };
  }

  it('tap = MOVE then DOWN/UP left at the finger', () => {
    const { o, calls } = out();
    new DirectTouchMapper(o).handle({ g: 'tap', fingers: 1, at: { x: 5, y: 6 }, count: 1 });
    expect(calls).toEqual(['move@5,6', 'down:1@5,6', 'up:1@5,6']);
  });

  it('long press and two-finger tap right-click', () => {
    const { o, calls } = out();
    const m = new DirectTouchMapper(o);
    m.handle({ g: 'longPress', at: { x: 1, y: 2 } });
    m.handle({ g: 'tap', fingers: 2, at: { x: 3, y: 4 }, count: 1 });
    expect(calls).toEqual(['haptic', 'move@1,2', 'down:2@1,2', 'up:2@1,2', 'move@3,4', 'down:2@3,4', 'up:2@3,4']);
  });

  it('drag presses at the touch point and releases at the end', () => {
    const { o, calls } = out();
    const m = new DirectTouchMapper(o);
    m.handle({ g: 'dragStart', from: { x: 0, y: 0 }, at: { x: 20, y: 0 }, afterTap: false });
    m.handle({ g: 'dragMove', at: { x: 40, y: 0 }, delta: { x: 20, y: 0 } });
    m.handle({ g: 'dragEnd', at: { x: 40, y: 0 }, cancelled: false });
    expect(calls).toEqual(['move@0,0', 'down:1@0,0', 'move@20,0', 'move@40,0', 'move@40,0', 'up:1@40,0']);
  });

  it('a cancelled drag still releases the button', () => {
    const { o, calls } = out();
    const m = new DirectTouchMapper(o);
    m.handle({ g: 'dragStart', from: { x: 0, y: 0 }, at: { x: 20, y: 0 }, afterTap: false });
    m.handle({ g: 'dragEnd', at: { x: 20, y: 0 }, cancelled: true });
    expect(calls.at(-1)).toBe('up:1@20,0');
  });

  it('three-finger tap opens the keyboard; pinch zooms', () => {
    const { o, calls } = out();
    const m = new DirectTouchMapper(o);
    m.handle({ g: 'tap', fingers: 3, at: { x: 0, y: 0 }, count: 1 });
    m.handle({ g: 'pinch', scale: 1.5, center: { x: 0, y: 0 }, pan: { x: 0, y: 0 } });
    expect(calls).toEqual(['keyboard', 'zoom 1.5']);
  });

  it('two-finger scroll: one wheel step per 40px, natural direction', () => {
    const { o, calls } = out();
    const m = new DirectTouchMapper(o);
    m.handle({ g: 'scroll', dx: 0, dy: 30 });
    m.handle({ g: 'scroll', dx: 0, dy: 30 }); // 60: one step up
    m.handle({ g: 'scroll', dx: 0, dy: -100 }); // -40 total: one step down... from remainder 20 -> -80: two down
    expect(calls).toEqual(['wheel 0,1', 'wheel 0,-2']);
  });
});

describe('ScrollAccumulator', () => {
  it('keeps the remainder and resets', () => {
    const s = new ScrollAccumulator(40);
    expect(s.add(0, 39)).toEqual({ x: 0, y: 0 });
    expect(s.add(0, 1)).toEqual({ x: 0, y: 1 });
    expect(s.add(85, 0)).toEqual({ x: 2, y: 0 });
    s.reset();
    expect(s.add(0, 39)).toEqual({ x: 0, y: 0 });
  });
});
