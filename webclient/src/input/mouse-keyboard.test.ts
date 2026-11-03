import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { UiCommand } from '../core/contracts';
import { MouseButton, MouseType, attachInput, buttonMask } from './mouse-keyboard';

/** A 1000x500 element at (0,0) mapped onto a 2000x1000 display: client px * 2. */
class FakeSurface extends EventTarget {
  tabIndex = -1;
  focused = 0;
  focus(): void {
    this.focused++;
  }
  setPointerCapture(): void {}
  getBoundingClientRect(): DOMRect {
    return { left: 0, top: 0, width: 1000, height: 500, right: 1000, bottom: 500, x: 0, y: 0 } as DOMRect;
  }
}

function pointer(type: string, init: Partial<PointerEvent>): Event {
  const e = new Event(type, { cancelable: true });
  Object.assign(e, { button: 0, ctrlKey: false, shiftKey: false, altKey: false, metaKey: false, ...init });
  return e;
}

const mouse = (c: UiCommand) => (c.c === 'mouse' ? [c.mask & 7, c.mask >> 3, c.x, c.y] : null);

describe('attachInput pointers', () => {
  let el: FakeSurface;
  let sent: UiCommand[];
  let mode: 'pointer' | 'touch';
  let detach: () => void;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('document', new EventTarget());
    el = new FakeSurface();
    sent = [];
    mode = 'pointer';
    detach = attachInput(el as unknown as HTMLElement, (c) => sent.push(c), () => ({ x: 0, y: 0, width: 2000, height: 1000 }), {
      isTouchMode: () => mode === 'touch',
    });
  });
  afterEach(() => {
    detach();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  it('moves the host cursor to a finger before pressing (pointer mode)', () => {
    el.dispatchEvent(pointer('pointerdown', { pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 50 }));
    el.dispatchEvent(pointer('pointerup', { pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 50 }));
    expect(sent.map(mouse)).toEqual([
      [MouseType.MOVE, 0, 200, 100],
      [MouseType.DOWN, MouseButton.LEFT, 200, 100],
      [MouseType.UP, MouseButton.LEFT, 200, 100],
    ]);
  });

  it('leaves the mouse as it was: no extra MOVE before a click', () => {
    el.dispatchEvent(pointer('pointerdown', { pointerId: 1, pointerType: 'mouse', clientX: 10, clientY: 10 }));
    el.dispatchEvent(pointer('pointerup', { pointerId: 1, pointerType: 'mouse', clientX: 10, clientY: 10 }));
    el.dispatchEvent(pointer('lostpointercapture', { pointerId: 1, pointerType: 'mouse' }));
    expect(sent.map(mouse)).toEqual([
      [MouseType.DOWN, MouseButton.LEFT, 20, 20],
      [MouseType.UP, MouseButton.LEFT, 20, 20],
    ]);
  });

  it('releases a held button when the browser cancels the pointer', () => {
    el.dispatchEvent(pointer('pointerdown', { pointerId: 7, pointerType: 'touch', clientX: 100, clientY: 100 }));
    el.dispatchEvent(pointer('pointermove', { pointerId: 7, pointerType: 'touch', clientX: 300, clientY: 200 }));
    sent = [];
    el.dispatchEvent(pointer('pointercancel', { pointerId: 7, pointerType: 'touch' }));
    expect(sent.map(mouse)).toEqual([[MouseType.UP, MouseButton.LEFT, 600, 400]]);
    // Nothing is held any more: a late capture loss sends nothing.
    sent = [];
    el.dispatchEvent(pointer('lostpointercapture', { pointerId: 7, pointerType: 'touch' }));
    expect(sent).toEqual([]);
  });

  it('touch mode: a cancelled touch does not swallow every later touch', () => {
    mode = 'touch';
    el.dispatchEvent(pointer('pointerdown', { pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 100 }));
    el.dispatchEvent(pointer('pointercancel', { pointerId: 1, pointerType: 'touch' }));
    vi.advanceTimersByTime(2000); // no stray long-press right-click either
    expect(sent).toEqual([]);
    el.dispatchEvent(pointer('pointerdown', { pointerId: 2, pointerType: 'touch', clientX: 50, clientY: 25 }));
    el.dispatchEvent(pointer('pointerup', { pointerId: 2, pointerType: 'touch', clientX: 50, clientY: 25 }));
    expect(sent.map(mouse)).toEqual([
      [MouseType.MOVE, 0, 100, 50],
      [MouseType.DOWN, MouseButton.LEFT, 100, 50],
      [MouseType.UP, MouseButton.LEFT, 100, 50],
    ]);
  });

  it('detach lets go of anything still held', () => {
    el.dispatchEvent(pointer('pointerdown', { pointerId: 3, pointerType: 'pen', button: 2, clientX: 10, clientY: 20 }));
    sent = [];
    detach();
    detach = () => {};
    expect(sent.map(mouse)).toEqual([[MouseType.UP, MouseButton.RIGHT, 20, 40]]);
  });

  it('builds the mask as type | buttons << 3', () => {
    expect(buttonMask(MouseType.DOWN, MouseButton.RIGHT)).toBe(17);
  });
});
