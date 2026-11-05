import { ControlKey } from '../gen/message';
import type { UiCommand } from '../core/contracts';
import { DOM_CODE_KEYCODES } from './keycodes';
import { GestureRecognizer, type Pt } from './gesture';
import { DirectTouchMapper, type GestureMapper, type TouchOutput } from './touch-direct';

export const MouseType = { MOVE: 0, DOWN: 1, UP: 2, WHEEL: 3, TRACKPAD: 4, MOVE_RELATIVE: 5 } as const;
export const MouseButton = { LEFT: 0x01, RIGHT: 0x02, MIDDLE: 0x04, BACK: 0x08, FORWARD: 0x10 } as const;

export type DisplayRect = { x: number; y: number; width: number; height: number };

// RustDesk MouseEvent.mask layout: low 3 bits = event type, buttons shifted left by 3.
export function buttonMask(type: number, buttons: number): number {
  return (buttons << 3) | type;
}

const clamp01 = (v: number): number => (v < 0 ? 0 : v > 1 ? 1 : v);

// Canvas-local pixel -> absolute virtual-desktop coordinate on the current display.
// The canvas renders with object-fit: contain, so the video image is letterboxed
// (centered, with bars) whenever the element aspect differs from the display
// aspect. Map the click against the ACTUAL rendered image rect — not the element
// box — otherwise the coordinate drifts toward center (clicks land left/high).
export function mapCoords(
  clientX: number,
  clientY: number,
  canvasRect: DOMRect,
  display: DisplayRect,
): { x: number; y: number } {
  const { width: rw, height: rh, left, top } = canvasRect;
  if (display.width <= 0 || display.height <= 0 || rw <= 0 || rh <= 0) {
    return { x: Math.round(display.x), y: Math.round(display.y) };
  }
  const dispAspect = display.width / display.height;
  const elAspect = rw / rh;
  let contentW = rw;
  let contentH = rh;
  if (elAspect > dispAspect) {
    contentW = rh * dispAspect; // pillarbox: bars left/right
  } else {
    contentH = rw / dispAspect; // letterbox: bars top/bottom
  }
  const offX = (rw - contentW) / 2;
  const offY = (rh - contentH) / 2;
  const fx = clamp01((clientX - left - offX) / contentW);
  const fy = clamp01((clientY - top - offY) / contentH);
  return {
    x: Math.round(display.x + fx * display.width),
    y: Math.round(display.y + fy * display.height),
  };
}

export function domButtonToMask(domButton: number): number {
  switch (domButton) {
    case 0: return MouseButton.LEFT;
    case 1: return MouseButton.MIDDLE;
    case 2: return MouseButton.RIGHT;
    case 3: return MouseButton.BACK;
    case 4: return MouseButton.FORWARD;
    default: return 0;
  }
}

// RustDesk wheel convention is inverted relative to DOM deltaY.
export function wheelDelta(deltaY: number): number {
  return deltaY > 0 ? -1 : deltaY < 0 ? 1 : 0;
}

const NAMED_KEYS: Record<string, ControlKey> = {
  Enter: ControlKey.Return,
  Backspace: ControlKey.Backspace,
  Tab: ControlKey.Tab,
  Escape: ControlKey.Escape,
  Delete: ControlKey.Delete,
  Insert: ControlKey.Insert,
  Home: ControlKey.Home,
  End: ControlKey.End,
  PageUp: ControlKey.PageUp,
  PageDown: ControlKey.PageDown,
  ArrowUp: ControlKey.UpArrow,
  ArrowDown: ControlKey.DownArrow,
  ArrowLeft: ControlKey.LeftArrow,
  ArrowRight: ControlKey.RightArrow,
  ' ': ControlKey.Space,
  Shift: ControlKey.Shift,
  Control: ControlKey.Control,
  Alt: ControlKey.Alt,
  Meta: ControlKey.Meta,
  AltGraph: ControlKey.RAlt,
  CapsLock: ControlKey.CapsLock,
  NumLock: ControlKey.NumLock,
  ScrollLock: ControlKey.Scroll,
  Pause: ControlKey.Pause,
  PrintScreen: ControlKey.Snapshot,
  ContextMenu: ControlKey.Apps,
  Help: ControlKey.Help,
  Clear: ControlKey.Clear,
  Select: ControlKey.Select,
  Execute: ControlKey.Execute,
  Print: ControlKey.Print,
  Cancel: ControlKey.Cancel,
  Convert: ControlKey.Convert,
  F1: ControlKey.F1,
  F2: ControlKey.F2,
  F3: ControlKey.F3,
  F4: ControlKey.F4,
  F5: ControlKey.F5,
  F6: ControlKey.F6,
  F7: ControlKey.F7,
  F8: ControlKey.F8,
  F9: ControlKey.F9,
  F10: ControlKey.F10,
  F11: ControlKey.F11,
  F12: ControlKey.F12,
};

const RIGHT_VARIANTS: Record<string, ControlKey> = {
  Shift: ControlKey.RShift,
  Control: ControlKey.RControl,
  Alt: ControlKey.RAlt,
  Meta: ControlKey.RWin,
};

const NUMPAD_KEYS: Record<string, ControlKey> = {
  '0': ControlKey.Numpad0,
  '1': ControlKey.Numpad1,
  '2': ControlKey.Numpad2,
  '3': ControlKey.Numpad3,
  '4': ControlKey.Numpad4,
  '5': ControlKey.Numpad5,
  '6': ControlKey.Numpad6,
  '7': ControlKey.Numpad7,
  '8': ControlKey.Numpad8,
  '9': ControlKey.Numpad9,
  '+': ControlKey.Add,
  '-': ControlKey.Subtract,
  '*': ControlKey.Multiply,
  '/': ControlKey.Divide,
  '.': ControlKey.Decimal,
  '=': ControlKey.Equals,
  Enter: ControlKey.NumpadEnter,
};

const LOCATION_RIGHT = 2; // KeyboardEvent.DOM_KEY_LOCATION_RIGHT
const LOCATION_NUMPAD = 3; // KeyboardEvent.DOM_KEY_LOCATION_NUMPAD

// Legacy keyboard mode: printable char -> chr codepoint (ASCII) or unicode
// codepoint (non-ASCII), named key -> ControlKey. null = ignore (Dead, IME, ...).
export function legacyKeyCommand(
  e: KeyboardEvent,
  _down: boolean,
): { keyKind: 'chr' | 'control' | 'unicode'; value: number } | null {
  const key = e.key;
  if (e.location === LOCATION_NUMPAD) {
    const np = NUMPAD_KEYS[key];
    if (np !== undefined) return { keyKind: 'control', value: np };
  }
  if (e.location === LOCATION_RIGHT) {
    const rk = RIGHT_VARIANTS[key];
    if (rk !== undefined) return { keyKind: 'control', value: rk };
  }
  const named = NAMED_KEYS[key];
  if (named !== undefined) return { keyKind: 'control', value: named };
  const cps = Array.from(key);
  if (cps.length !== 1) return null; // Dead, Unidentified, Process, unmapped named keys
  const cp = cps[0].codePointAt(0)!;
  return cp <= 0x7f ? { keyKind: 'chr', value: cp } : { keyKind: 'unicode', value: cp };
}

type ModifierState = { ctrlKey: boolean; shiftKey: boolean; altKey: boolean; metaKey: boolean };

function modifierList(e: ModifierState): number[] {
  const m: number[] = [];
  if (e.ctrlKey) m.push(ControlKey.Control);
  if (e.shiftKey) m.push(ControlKey.Shift);
  if (e.altKey) m.push(ControlKey.Alt);
  if (e.metaKey) m.push(ControlKey.Meta);
  return m;
}

export type LegacyKeyMessage = {
  down: boolean;
  keyKind: 'chr' | 'control' | 'unicode';
  value: number;
  modifiers: number[];
};

const LOCATION_LEFT = 1; // KeyboardEvent.DOM_KEY_LOCATION_LEFT
// Windows emits the synthetic Control and the AltGraph key-down for one
// physical AltGr press back to back, with the same OS event time.
const ALTGR_FAKE_CTRL_MS = 50;

type KeyEventLike = ModifierState &
  Pick<KeyboardEvent, 'key' | 'location' | 'repeat' | 'timeStamp'>;

/**
 * Turns browser key events into the legacy KeyEvents the host expects.
 *
 * The host types a `unicode` key on every event it receives, ignoring down/up
 * (input_service.rs process_unicode), so æøå are sent on key-down only —
 * otherwise each is typed again on release.
 *
 * AltGr: Windows browsers report it as a synthetic left Control followed by
 * AltGraph, and keep ctrlKey/altKey set for every key pressed under it. The
 * host then sees `|` as the hotkey Ctrl+Alt+| instead of text. Like the native
 * client (keyboard.rs legacy_keyboard_mode), drop the fake Control and send
 * characters typed under AltGr without Ctrl/Alt.
 */
export class LegacyKeyTranslator {
  private altGrHeld = false;
  private ctrlDownAt: number | null = null;

  translate(e: KeyEventLike, down: boolean): LegacyKeyMessage[] {
    const k = legacyKeyCommand(e as KeyboardEvent, down);
    if (!k) return [];
    const out: LegacyKeyMessage[] = [];
    const isAltGraph = e.key === 'AltGraph';
    const fakeCtrlAt = this.ctrlDownAt;
    this.ctrlDownAt = down && !e.repeat && e.key === 'Control' && e.location === LOCATION_LEFT
      ? e.timeStamp
      : null;

    if (isAltGraph) {
      if (down && !e.repeat && fakeCtrlAt !== null && e.timeStamp - fakeCtrlAt < ALTGR_FAKE_CTRL_MS) {
        out.push({ down: false, keyKind: 'control', value: ControlKey.Control, modifiers: [] });
      }
      this.altGrHeld = down;
    }
    if (k.keyKind === 'unicode' && !down) return out;

    let modifiers = modifierList(e);
    if (this.altGrHeld && (isAltGraph || k.keyKind !== 'control')) {
      modifiers = modifiers.filter((m) => m !== ControlKey.Control && m !== ControlKey.Alt);
    }
    out.push({ down, keyKind: k.keyKind, value: k.value, modifiers });
    return out;
  }

  /** Focus left the canvas: the matching key-ups will never arrive. */
  reset(): void {
    this.altGrHeld = false;
    this.ctrlDownAt = null;
  }
}

/** Host platforms that take Map-mode keycodes (DOM_CODE_KEYCODES columns). */
export type KeyTarget = 'windows' | 'linux';

/**
 * Map mode needs a Windows or Linux host on RustDesk 1.2.0 or later; anything
 * else (macOS, Android, unknown) stays on legacy character input.
 */
export function mapKeyboardTarget(platform: string, version: string): KeyTarget | null {
  const target = platform === 'Windows' ? 'windows' : platform === 'Linux' ? 'linux' : null;
  if (!target) return null;
  const m = /^(\d+)\.(\d+)/.exec(version);
  if (!m) return null;
  const major = Number(m[1]);
  return major > 1 || (major === 1 && Number(m[2]) >= 2) ? target : null;
}

export type MapKeyMessage = { down: boolean; keyCode: number; modifiers: number[] };

type MapKeyEventLike = Pick<
  KeyboardEvent,
  'code' | 'key' | 'repeat' | 'isComposing' | 'getModifierState'
>;

// Lock keys are not pressed on the host: each key carries the browser's lock
// state in `modifiers` and the host syncs to it (input_service.rs
// LockModesHandler). Pressing the key as well would toggle it twice.
const LOCK_CODES = new Set(['CapsLock', 'NumLock', 'ScrollLock']);

/**
 * Map keyboard mode: send the physical key (KeyboardEvent.code) as the host's
 * own keycode, and let the host's layout produce the character. With the same
 * layout on both sides every key works as it does locally — punctuation,
 * AltGr, dead keys, shortcuts. Legacy mode can't do that on a Wayland host,
 * which types ASCII through a built-in US key table (uinput.rs
 * KEY_MAP_LAYOUT), so `|` on a Danish layout came out as `*`.
 */
export class MapKeyTranslator {
  private held = new Map<string, number>(); // code -> keycode the host has down
  private pendingCtrl: { timer: ReturnType<typeof setTimeout>; modifiers: number[] } | null = null;

  constructor(
    readonly target: KeyTarget,
    private readonly emit: (m: MapKeyMessage) => void,
  ) {}

  /** False when the key has no keycode here; the caller sends it as legacy input. */
  handle(e: MapKeyEventLike, down: boolean): boolean {
    if (e.isComposing || e.key === 'Process') return false; // IME owns the key
    const keyCode = DOM_CODE_KEYCODES[e.code]?.[this.target === 'windows' ? 0 : 1] ?? 0;
    // Windows has no scancode for Pause; the native client sends it as a
    // legacy control key too.
    if (keyCode === 0 || (this.target === 'windows' && e.code === 'Pause')) {
      this.flushCtrl();
      return false;
    }
    if (LOCK_CODES.has(e.code)) return true;
    const modifiers: number[] = [];
    if (e.getModifierState('CapsLock')) modifiers.push(ControlKey.CapsLock);
    if (e.getModifierState('NumLock')) modifiers.push(ControlKey.NumLock);

    // Windows browsers precede AltGr with a synthetic left Control. Hold a
    // left Control press back briefly and drop it if AltGr follows, as the
    // native client does — otherwise a Linux host sees Ctrl+AltGr.
    if (down && e.code === 'AltRight' && this.pendingCtrl) {
      clearTimeout(this.pendingCtrl.timer);
      this.pendingCtrl = null;
    } else {
      this.flushCtrl();
    }
    if (down && !e.repeat && e.code === 'ControlLeft') {
      this.pendingCtrl = { timer: setTimeout(() => this.flushCtrl(), ALTGR_FAKE_CTRL_MS), modifiers };
      return true;
    }

    if (down) {
      // Pass the browser's repeats on: neither host auto-repeats our keys.
      // Windows doesn't repeat injected keys, and a Linux host turns every
      // non-modifier key-down into a click (connection.rs, rustdesk#6793).
      this.send(e.code, keyCode, true, modifiers);
    } else if (this.held.has(e.code)) {
      this.send(e.code, keyCode, false, modifiers);
    }
    return true;
  }

  /** Focus left the canvas: release everything the host still holds. */
  reset(): void {
    if (this.pendingCtrl) clearTimeout(this.pendingCtrl.timer);
    this.pendingCtrl = null;
    for (const [code, keyCode] of [...this.held]) this.send(code, keyCode, false, []);
  }

  private flushCtrl(): void {
    if (!this.pendingCtrl) return;
    clearTimeout(this.pendingCtrl.timer);
    const { modifiers } = this.pendingCtrl;
    this.pendingCtrl = null;
    this.send('ControlLeft', DOM_CODE_KEYCODES.ControlLeft[this.target === 'windows' ? 0 : 1], true, modifiers);
  }

  private send(code: string, keyCode: number, down: boolean, modifiers: number[]): void {
    if (down) this.held.set(code, keyCode);
    else this.held.delete(code);
    this.emit({ down, keyCode, modifiers });
  }
}

type KeyboardLock = { lock?: (keyCodes?: string[]) => Promise<void>; unlock?: () => void };

export type InputOptions = {
  /**
   * When true, touch pointers go through the gesture recogniser (Touch mode,
   * input/touch-direct.ts): tap clicks where the finger is, drag drags, long
   * press or two-finger tap right-clicks, two fingers scroll, pinch zooms.
   *
   * Without it (pointer mode), a finger behaves like a pressed left button —
   * touching immediately mouses-down and dragging drags. That is right for a
   * stylus or a touch-screen laptop. Mouse/pen pointers are identical in
   * both modes.
   */
  isTouchMode?: () => boolean;
  /**
   * Host platform to send Map-mode keycodes for, or null for legacy
   * character input. Read on every key, so it can change mid-session (peer
   * info arriving, the keyboard setting).
   */
  keyboardTarget?: () => KeyTarget | null;
  /**
   * Element to focus when the remote screen is touched, instead of the
   * screen itself (the on-screen keyboard's field while it is open).
   */
  focusTarget?: () => HTMLElement | null;
  /** Touch mode hooks. */
  touch?: {
    haptic?(): void;
    /** Three-finger tap. */
    keyboard?(): void;
    /** Pinch: local zoom by `scale` about `center`, then pan. */
    zoom?(scale: number, center: Pt, pan: Pt): void;
    /** A mapper other than direct touch (Trackpad mode), or null for direct. */
    mapper?(out: TouchOutput): GestureMapper | null;
  };
};

export type KeyHandler = {
  /** Send one key event; true when it was sent (and its default prevented). */
  onKey(e: KeyboardEvent, down: boolean): boolean;
  /** Focus left: release what the host holds, forget AltGr state. */
  reset(): void;
};

/**
 * Browser key events -> KeyEvents, in Map mode when the host takes keycodes
 * and in legacy mode otherwise. Shared by the remote screen and the
 * on-screen keyboard's field.
 */
export function createKeyHandler(
  post: (cmd: UiCommand) => void,
  keyboardTarget?: () => KeyTarget | null,
): KeyHandler {
  const keys = new LegacyKeyTranslator();
  const postMapKey = (k: MapKeyMessage): void =>
    post({ c: 'key', down: k.down, press: false, keyKind: 'map', value: k.keyCode, modifiers: k.modifiers });
  let mapKeys: MapKeyTranslator | null = null;
  return {
    onKey(e, down) {
      const target = keyboardTarget?.() ?? null;
      if (target !== (mapKeys?.target ?? null)) {
        mapKeys?.reset();
        mapKeys = target ? new MapKeyTranslator(target, postMapKey) : null;
      }
      if (mapKeys?.handle(e, down)) {
        e.preventDefault(); // keeps Ctrl+W / F5 / Tab from hitting the browser while focused
        return true;
      }
      if (!legacyKeyCommand(e, down)) return false;
      e.preventDefault();
      for (const k of keys.translate(e, down)) {
        post({ c: 'key', down: k.down, press: false, keyKind: k.keyKind, value: k.value, modifiers: k.modifiers });
      }
      return true;
    },
    reset() {
      keys.reset();
      mapKeys?.reset();
    },
  };
}

export function attachInput(
  el: HTMLElement,
  post: (cmd: UiCommand) => void,
  getDisplay: () => DisplayRect,
  options: InputOptions = {},
): () => void {
  if (el.tabIndex < 0) el.tabIndex = 0; // keyboard focus target

  const touchActive = (e: PointerEvent): boolean =>
    e.pointerType === 'touch' && options.isTouchMode?.() === true;

  const sendAt = (clientX: number, clientY: number, mask: number): void => {
    const { x, y } = mapCoords(clientX, clientY, el.getBoundingClientRect(), getDisplay());
    post({ c: 'mouse', mask, x, y, modifiers: [] });
  };

  const posted = (e: { clientX: number; clientY: number } & ModifierState, mask: number): void => {
    const { x, y } = mapCoords(e.clientX, e.clientY, el.getBoundingClientRect(), getDisplay());
    post({ c: 'mouse', mask, x, y, modifiers: modifierList(e) });
  };

  // Touch mode: fingers go through the gesture recogniser.
  const touchOut: TouchOutput = {
    mouse: (type, button, at) => {
      const t = type === 'move' ? MouseType.MOVE : type === 'down' ? MouseType.DOWN : MouseType.UP;
      sendAt(at.x, at.y, buttonMask(t, button));
    },
    wheel: (x, y) => post({ c: 'mouse', mask: buttonMask(MouseType.WHEEL, 0), x, y, modifiers: [] }),
    haptic: () => options.touch?.haptic?.(),
    keyboard: () => options.touch?.keyboard?.(),
    zoom: (scale, center, pan) => options.touch?.zoom?.(scale, center, pan),
  };
  const direct = new DirectTouchMapper(touchOut);
  const gestures = new GestureRecognizer((g) => {
    const mapper = options.touch?.mapper?.(touchOut) ?? direct;
    mapper.handle(g);
  });

  // Buttons each pointer holds down on the host (pointer mode), with where it
  // last was, so a gesture the browser cancels can still let go of them.
  const held = new Map<number, { mask: number; x: number; y: number }>();

  const onPointerMove = (e: PointerEvent): void => {
    if (touchActive(e)) {
      gestures.move(e.pointerId, e.clientX, e.clientY, e.timeStamp);
      return;
    }
    const h = held.get(e.pointerId);
    if (h) {
      h.x = e.clientX;
      h.y = e.clientY;
    }
    posted(e, buttonMask(MouseType.MOVE, 0));
  };
  const onPointerDown = (e: PointerEvent): void => {
    // Keep the on-screen keyboard's field focused while it is open: focusing
    // the canvas would close the keyboard on every tap.
    const focusTarget = options.focusTarget?.();
    if (focusTarget) focusTarget.focus({ preventScroll: true });
    else el.focus();
    try {
      el.setPointerCapture(e.pointerId);
    } catch {
      /* capture is best-effort */
    }
    e.preventDefault();
    if (touchActive(e)) {
      gestures.down(e.pointerId, e.clientX, e.clientY, e.timeStamp);
      return;
    }
    const mask = domButtonToMask(e.button);
    // The host moves its cursor only on MOVE. A mouse has always hovered there
    // first; a finger or a pen lands without one, and the click would hit
    // wherever the cursor was before.
    if (e.pointerType !== 'mouse') posted(e, buttonMask(MouseType.MOVE, 0));
    const h = held.get(e.pointerId);
    held.set(e.pointerId, { mask: (h?.mask ?? 0) | mask, x: e.clientX, y: e.clientY });
    posted(e, buttonMask(MouseType.DOWN, mask));
  };
  const onPointerUp = (e: PointerEvent): void => {
    e.preventDefault();
    if (e.pointerType === 'touch' && gestures.busy) {
      gestures.up(e.pointerId, e.clientX, e.clientY, e.timeStamp);
      return;
    }
    if (touchActive(e)) return;
    const mask = domButtonToMask(e.button);
    const h = held.get(e.pointerId);
    if (h) {
      h.mask &= ~mask;
      if (!h.mask) held.delete(e.pointerId);
    }
    posted(e, buttonMask(MouseType.UP, mask));
  };
  /**
   * The browser took the pointer away (a system gesture, the notification
   * shade, an alert, capture lost): no pointerup will come. Release whatever
   * the pointer holds on the host and end the touch gesture, or the host
   * keeps the button down and later touches are misread.
   */
  const onPointerCancel = (e: PointerEvent): void => {
    gestures.cancel(e.pointerId);
    const h = held.get(e.pointerId);
    if (!h) return;
    held.delete(e.pointerId);
    for (const b of [MouseButton.LEFT, MouseButton.RIGHT, MouseButton.MIDDLE, MouseButton.BACK, MouseButton.FORWARD]) {
      if (h.mask & b) sendAt(h.x, h.y, buttonMask(MouseType.UP, b));
    }
  };
  const onWheel = (e: WheelEvent): void => {
    e.preventDefault();
    const dx = wheelDelta(e.deltaX);
    const dy = wheelDelta(e.deltaY);
    if (dx === 0 && dy === 0) return;
    // WHEEL events carry scroll steps in x/y, not coordinates.
    post({ c: 'mouse', mask: buttonMask(MouseType.WHEEL, 0), x: dx, y: dy, modifiers: modifierList(e) });
  };
  const onContextMenu = (e: Event): void => {
    e.preventDefault();
  };

  const keys = createKeyHandler(post, options.keyboardTarget);
  const onKeyDown = (e: KeyboardEvent): void => void keys.onKey(e, true);
  const onKeyUp = (e: KeyboardEvent): void => void keys.onKey(e, false);
  const onBlur = (): void => keys.reset();

  const kb: KeyboardLock | undefined =
    typeof navigator !== 'undefined' && 'keyboard' in navigator
      ? (navigator as Navigator & { keyboard?: KeyboardLock }).keyboard
      : undefined;
  const onFullscreenChange = (): void => {
    if (!kb?.lock) return;
    const fs = document.fullscreenElement;
    if (fs && (fs === el || fs.contains(el))) {
      void kb.lock().catch(() => undefined);
    } else {
      kb.unlock?.();
    }
  };

  el.addEventListener('pointermove', onPointerMove);
  el.addEventListener('pointerdown', onPointerDown);
  el.addEventListener('pointerup', onPointerUp);
  el.addEventListener('pointercancel', onPointerCancel);
  el.addEventListener('lostpointercapture', onPointerCancel);
  el.addEventListener('wheel', onWheel, { passive: false });
  el.addEventListener('contextmenu', onContextMenu);
  el.addEventListener('keydown', onKeyDown);
  el.addEventListener('keyup', onKeyUp);
  el.addEventListener('blur', onBlur);
  document.addEventListener('fullscreenchange', onFullscreenChange);

  return () => {
    gestures.reset();
    for (const id of [...held.keys()]) onPointerCancel({ pointerId: id } as PointerEvent);
    keys.reset();
    el.removeEventListener('pointermove', onPointerMove);
    el.removeEventListener('pointerdown', onPointerDown);
    el.removeEventListener('pointerup', onPointerUp);
    el.removeEventListener('pointercancel', onPointerCancel);
    el.removeEventListener('lostpointercapture', onPointerCancel);
    el.removeEventListener('wheel', onWheel);
    el.removeEventListener('contextmenu', onContextMenu);
    el.removeEventListener('keydown', onKeyDown);
    el.removeEventListener('keyup', onKeyUp);
    el.removeEventListener('blur', onBlur);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    kb?.unlock?.();
  };
}
