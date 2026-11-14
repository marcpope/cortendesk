import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GestureRecognizer } from './gesture';
import type { TouchOutput } from './touch-direct';
import { TAP_HOLD_MS, TrackpadMapper, trackpadGain } from './touch-trackpad';

function rig() {
  const calls: string[] = [];
  const out: TouchOutput = {
    mouse: (t, b, at) => calls.push(`${t}${b ? ':' + b : ''}@${Math.round(at.x)},${Math.round(at.y)}`),
    wheel: (x, y) => calls.push(`wheel ${x},${y}`),
    haptic: () => calls.push('haptic'),
    keyboard: () => calls.push('keyboard'),
    zoom: () => calls.push('zoom'),
  };
  const cursor: { x: number; y: number }[] = [];
  const follow: boolean[] = [];
  const view = { clamp: undefined as undefined | ((p: { x: number; y: number }) => { x: number; y: number }) };
  // Remote image fills a 1000 x 500 area at (0, 0).
  const tp = new TrackpadMapper(out, {
    content: () => ({ x: 0, y: 0, width: 1000, height: 500 }),
    cursor: (p, byCursor) => {
      cursor.push(p);
      follow.push(byCursor);
    },
    clampToView: (p) => (view.clamp ? view.clamp(p) : p),
  });
  const g = new GestureRecognizer((e) => tp.handle(e));
  return { calls, cursor, follow, view, tp, g };
}

describe('trackpadGain', () => {
  it('slow = 1:1, fast = accelerated, capped', () => {
    expect(trackpadGain(1)).toBe(1);
    expect(trackpadGain(2)).toBe(1);
    expect(trackpadGain(12)).toBeCloseTo(2.2, 6);
    expect(trackpadGain(500)).toBe(2.5);
    expect(trackpadGain(-12)).toBeCloseTo(2.2, 6);
  });
});

describe('TrackpadMapper', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('starts in the middle; a slide moves the cursor relatively, not to the finger', () => {
    const { calls, g } = rig();
    g.down(1, 900, 450, 0); // far corner: the cursor does not jump there
    g.move(1, 902, 450, 10);
    g.move(1, 920, 450, 20); // crosses the slop: +20 px total
    g.up(1, 920, 450, 30);
    expect(calls[0]).toBe('move@550,250'); // 20 px x gain 2.5 (capped)
  });

  it('clamps the cursor to the remote image', () => {
    const { calls, g, tp } = rig();
    g.down(1, 0, 0, 0);
    g.move(1, -2000, -2000, 10);
    g.up(1, -2000, -2000, 20);
    expect(tp.position()).toEqual({ x: 0, y: 0 });
    expect(calls.at(-1)).toBe('move@0,0');
  });

  it('tap clicks at the cursor after the double-tap window', () => {
    const { calls, g } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 50);
    expect(calls).toEqual([]);
    vi.advanceTimersByTime(TAP_HOLD_MS);
    expect(calls).toEqual(['move@500,250', 'down:1@500,250', 'up:1@500,250']);
  });

  it('double tap = two clicks, immediately', () => {
    const { calls, g } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 50);
    g.down(2, 105, 100, 150);
    g.up(2, 105, 100, 200);
    expect(calls.filter((c) => c.startsWith('down')).length).toBe(2);
    vi.advanceTimersByTime(1000);
    expect(calls.filter((c) => c.startsWith('down')).length).toBe(2);
  });

  it('tap then drag = drag lock: button down, moves, up — no click first', () => {
    const { calls, g } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 50);
    g.down(2, 100, 100, 150);
    g.move(2, 140, 100, 200);
    g.up(2, 140, 100, 250);
    vi.advanceTimersByTime(1000);
    expect(calls[0]).toBe('move@500,250');
    expect(calls[1]).toBe('down:1@500,250');
    expect(calls.filter((c) => c.startsWith('down')).length).toBe(1);
    expect(calls.at(-1)).toMatch(/^up:1@/);
  });

  it('two-finger tap right-clicks at the cursor; long press too', () => {
    const { calls, g } = rig();
    g.down(1, 10, 10, 0);
    g.down(2, 60, 10, 10);
    g.up(1, 10, 10, 100);
    g.up(2, 60, 10, 110);
    expect(calls).toEqual(['move@500,250', 'down:2@500,250', 'up:2@500,250']);
    g.down(3, 10, 10, 1000);
    vi.advanceTimersByTime(500);
    expect(calls.slice(3)).toEqual(['haptic', 'move@500,250', 'down:2@500,250', 'up:2@500,250']);
  });

  it('a tap followed by an unrelated touch elsewhere still clicks once', () => {
    const { calls, g } = rig();
    g.down(1, 100, 100, 0);
    g.up(1, 100, 100, 50);
    g.down(2, 800, 400, 120); // far away: not a double tap
    expect(calls.filter((c) => c.startsWith('down')).length).toBe(1);
  });

  it('only cursor moves may scroll the view; a pinch / pan never does', () => {
    const { g, follow } = rig();
    g.down(1, 100, 100, 0);
    g.move(1, 140, 100, 10);
    g.up(1, 140, 100, 20);
    expect(follow.every(Boolean)).toBe(true);
    follow.length = 0;
    g.down(2, 100, 100, 100);
    g.down(3, 200, 100, 100);
    g.move(3, 260, 100, 110);
    g.move(3, 320, 100, 120);
    expect(follow.length).toBeGreaterThan(0);
    expect(follow.some(Boolean)).toBe(false);
  });

  it('a cursor left off-screen by a pan comes to the edge of the view before it moves', () => {
    const { g, calls, view } = rig();
    // the user panned: only x 600..1000 is visible now; the cursor (500) is off-screen
    view.clamp = (p) => ({ x: Math.max(612, p.x), y: p.y });
    g.down(1, 100, 100, 0);
    g.move(1, 112, 100, 10); // +12 px at gain ~2.2
    g.up(1, 112, 100, 20);
    const move = calls.find((c) => c.startsWith('move@'))!;
    const x = Number(/move@(\d+)/.exec(move)![1]);
    expect(x).toBeGreaterThan(612); // moved on from the edge of the view, not from 500
  });
});
