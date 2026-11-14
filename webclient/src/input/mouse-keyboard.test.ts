import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';
import { ControlKey } from '../gen/message';
import type { UiCommand } from '../core/contracts';
import {
  MouseType,
  MouseButton,
  attachInput,
  buttonMask,
  mapCoords,
  domButtonToMask,
  wheelDelta,
  legacyKeyCommand,
} from './mouse-keyboard';

function kev(props: { key: string; location?: number }): KeyboardEvent {
  return { key: props.key, location: props.location ?? 0 } as unknown as KeyboardEvent;
}

describe('buttonMask', () => {
  it('packs buttons<<3 | type', () => {
    expect(buttonMask(MouseType.DOWN, MouseButton.LEFT)).toBe(0x09);
    expect(buttonMask(MouseType.UP, MouseButton.RIGHT)).toBe(0x12);
    expect(buttonMask(MouseType.DOWN, MouseButton.MIDDLE)).toBe(0x21);
    expect(buttonMask(MouseType.MOVE, 0)).toBe(0);
    expect(buttonMask(MouseType.WHEEL, 0)).toBe(3);
  });
});

describe('mapCoords', () => {
  const rect = { left: 10, top: 20, width: 800, height: 450 } as DOMRect;

  it('scales canvas-local pixels to display resolution with display origin offset', () => {
    const display = { x: 1920, y: 0, width: 1920, height: 1080 };
    expect(mapCoords(410, 245, rect, display)).toEqual({ x: 2880, y: 540 });
  });

  it('maps rect corners to display corners', () => {
    const display = { x: -1920, y: -100, width: 1920, height: 1080 };
    expect(mapCoords(10, 20, rect, display)).toEqual({ x: -1920, y: -100 });
    expect(mapCoords(810, 470, rect, display)).toEqual({ x: 0, y: 980 });
  });

  it('rounds to integers', () => {
    const display = { x: 0, y: 0, width: 1920, height: 1080 };
    const { x, y } = mapCoords(11, 21, rect, display); // 1*2.4=2.4, 1*2.4=2.4
    expect(x).toBe(2);
    expect(y).toBe(2);
    expect(Number.isInteger(x)).toBe(true);
    expect(Number.isInteger(y)).toBe(true);
  });

  it('accounts for object-fit letterboxing (pillarbox: element wider than display)', () => {
    // 2000x1000 element (aspect 2.0) showing a 1920x1080 desktop (aspect 1.778):
    // side bars of (2000 - 1000*1.778)/2 = 111.11px. A click at the video's left
    // edge maps to x=0, and the video's right edge to x=1920 — NOT compressed.
    const el = { left: 0, top: 0, width: 2000, height: 1000 } as DOMRect;
    const display = { x: 0, y: 0, width: 1920, height: 1080 };
    expect(mapCoords(1000, 500, el, display)).toEqual({ x: 960, y: 540 }); // center stays center
    expect(mapCoords(112, 500, el, display).x).toBe(1); // ~left edge of image
    expect(mapCoords(1888, 500, el, display).x).toBe(1919); // ~right edge of image
    // clicks in the bars clamp to the display bounds
    expect(mapCoords(10, 500, el, display).x).toBe(0);
    expect(mapCoords(1995, 500, el, display).x).toBe(1920);
  });

  it('accounts for object-fit letterboxing (letterbox: element taller than display)', () => {
    const el = { left: 0, top: 0, width: 1600, height: 1200 } as DOMRect; // aspect 1.333
    const display = { x: 0, y: 0, width: 1920, height: 1080 }; // aspect 1.778
    // bars top/bottom: (1200 - 1600/1.778)/2 = 150px
    expect(mapCoords(800, 600, el, display)).toEqual({ x: 960, y: 540 });
    expect(mapCoords(800, 151, el, display).y).toBe(1); // ~top edge of image
    expect(mapCoords(800, 10, el, display).y).toBe(0); // in the top bar -> clamped
  });
});

describe('domButtonToMask', () => {
  it('maps DOM button numbers to RustDesk button bits (middle is 0x04)', () => {
    expect(domButtonToMask(0)).toBe(MouseButton.LEFT);
    expect(domButtonToMask(1)).toBe(MouseButton.MIDDLE);
    expect(domButtonToMask(2)).toBe(MouseButton.RIGHT);
    expect(domButtonToMask(3)).toBe(MouseButton.BACK);
    expect(domButtonToMask(4)).toBe(MouseButton.FORWARD);
    expect(domButtonToMask(5)).toBe(0);
  });
});

describe('wheelDelta', () => {
  it('inverts DOM deltaY sign into unit steps', () => {
    expect(wheelDelta(100)).toBe(-1);
    expect(wheelDelta(1)).toBe(-1);
    expect(wheelDelta(-3)).toBe(1);
    expect(wheelDelta(-120)).toBe(1);
    expect(wheelDelta(0)).toBe(0);
  });
});

describe('legacyKeyCommand', () => {
  it('printable ASCII -> chr codepoint', () => {
    expect(legacyKeyCommand(kev({ key: 'a' }), true)).toEqual({ keyKind: 'chr', value: 97 });
    expect(legacyKeyCommand(kev({ key: 'W' }), true)).toEqual({ keyKind: 'chr', value: 87 });
    expect(legacyKeyCommand(kev({ key: '/' }), false)).toEqual({ keyKind: 'chr', value: 47 });
  });

  it('non-ASCII printable -> unicode codepoint', () => {
    expect(legacyKeyCommand(kev({ key: 'é' }), true)).toEqual({ keyKind: 'unicode', value: 0xe9 });
    expect(legacyKeyCommand(kev({ key: '€' }), true)).toEqual({ keyKind: 'unicode', value: 0x20ac });
  });

  it('named keys -> ControlKey values', () => {
    expect(legacyKeyCommand(kev({ key: 'Enter' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Return });
    expect(legacyKeyCommand(kev({ key: 'Backspace' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Backspace });
    expect(legacyKeyCommand(kev({ key: 'Delete' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Delete });
    expect(legacyKeyCommand(kev({ key: 'Escape' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Escape });
    expect(legacyKeyCommand(kev({ key: 'Tab' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Tab });
    expect(legacyKeyCommand(kev({ key: 'ArrowLeft' }), true)).toEqual({ keyKind: 'control', value: ControlKey.LeftArrow });
    expect(legacyKeyCommand(kev({ key: 'ArrowUp' }), true)).toEqual({ keyKind: 'control', value: ControlKey.UpArrow });
    expect(legacyKeyCommand(kev({ key: 'F5' }), true)).toEqual({ keyKind: 'control', value: ControlKey.F5 });
    expect(legacyKeyCommand(kev({ key: 'F12' }), true)).toEqual({ keyKind: 'control', value: ControlKey.F12 });
    expect(legacyKeyCommand(kev({ key: 'PageDown' }), true)).toEqual({ keyKind: 'control', value: ControlKey.PageDown });
  });

  it('space is the Space control key, not chr 32', () => {
    expect(legacyKeyCommand(kev({ key: ' ' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Space });
  });

  it('modifier keys map to left/right ControlKey variants by location', () => {
    expect(legacyKeyCommand(kev({ key: 'Shift' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Shift });
    expect(legacyKeyCommand(kev({ key: 'Shift', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RShift });
    expect(legacyKeyCommand(kev({ key: 'Control', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RControl });
    expect(legacyKeyCommand(kev({ key: 'Meta' }), true)).toEqual({ keyKind: 'control', value: ControlKey.Meta });
    expect(legacyKeyCommand(kev({ key: 'Meta', location: 2 }), true)).toEqual({ keyKind: 'control', value: ControlKey.RWin });
  });

  it('numpad location maps digits and operators to Numpad* keys', () => {
    expect(legacyKeyCommand(kev({ key: '7', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Numpad7 });
    expect(legacyKeyCommand(kev({ key: '0', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Numpad0 });
    expect(legacyKeyCommand(kev({ key: '+', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Add });
    expect(legacyKeyCommand(kev({ key: '.', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.Decimal });
    expect(legacyKeyCommand(kev({ key: 'Enter', location: 3 }), true)).toEqual({ keyKind: 'control', value: ControlKey.NumpadEnter });
    // same keys off the numpad stay chr
    expect(legacyKeyCommand(kev({ key: '7' }), true)).toEqual({ keyKind: 'chr', value: 55 });
  });

  it('ignores dead/IME/unmapped keys', () => {
    expect(legacyKeyCommand(kev({ key: 'Dead' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'Unidentified' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'Process' }), true)).toBeNull();
    expect(legacyKeyCommand(kev({ key: 'MediaPlayPause' }), true)).toBeNull();
  });
});

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
