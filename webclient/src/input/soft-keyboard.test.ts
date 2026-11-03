import { beforeEach, describe, expect, it } from 'vitest';
import { SENTINEL, SoftKeyboardInput, isHardwareKey, type SoftKeyAction } from './soft-keyboard';

class FakeSink {
  value = '';
  setSelectionRange(): void {}
}

function key(init: Partial<KeyboardEvent>): KeyboardEvent & { prevented: boolean } {
  const e = {
    key: 'Unidentified', code: '', keyCode: 229, isComposing: false, prevented: false,
    ctrlKey: false, shiftKey: false, altKey: false, metaKey: false,
    preventDefault() { e.prevented = true; },
    ...init,
  };
  return e as unknown as KeyboardEvent & { prevented: boolean };
}

function beforeinput(inputType: string, cancelable = true) {
  const e = { inputType, cancelable, prevented: false, preventDefault() { e.prevented = true; } };
  return e as unknown as InputEvent & { prevented: boolean };
}

const summary = (a: SoftKeyAction): string =>
  a.k === 'text' ? `text:${a.text}` : a.k === 'key' ? `${a.ctrl ? 'ctrl+' : ''}${a.key}` : `hw:${a.event.code}:${a.down ? 'down' : 'up'}`;

describe('isHardwareKey', () => {
  it('Android keyboards send keyCode 229 / Unidentified: not a key', () => {
    expect(isHardwareKey(key({}))).toBe(false);
    expect(isHardwareKey(key({ key: 'a', code: 'KeyA', keyCode: 229 }))).toBe(false);
  });
  it('iOS on-screen keys carry no physical code', () => {
    expect(isHardwareKey(key({ key: 'Enter', code: '', keyCode: 13 }))).toBe(false);
    expect(isHardwareKey(key({ key: 'a', code: '', keyCode: 65 }))).toBe(false);
  });
  it('a composing key belongs to the IME', () => {
    expect(isHardwareKey(key({ key: 'a', code: 'KeyA', keyCode: 65, isComposing: true }))).toBe(false);
    expect(isHardwareKey(key({ key: 'Process', code: 'KeyA', keyCode: 65 }))).toBe(false);
  });
  it('an iPad / Bluetooth keyboard key is a key', () => {
    expect(isHardwareKey(key({ key: 'c', code: 'KeyC', keyCode: 67, ctrlKey: true }))).toBe(true);
    expect(isHardwareKey(key({ key: 'ArrowLeft', code: 'ArrowLeft', keyCode: 37 }))).toBe(true);
  });
});

describe('SoftKeyboardInput', () => {
  let sink: FakeSink;
  let out: SoftKeyAction[];
  let now: number;
  let kb: SoftKeyboardInput;
  const type = (v: string): void => {
    sink.value = v;
    kb.input();
  };
  const got = (): string[] => out.map(summary);

  beforeEach(() => {
    sink = new FakeSink();
    out = [];
    now = 1000;
    kb = new SoftKeyboardInput(sink, (a) => out.push(a), () => now);
  });

  it('starts with the sentinel so Backspace on an empty field still registers', () => {
    expect(sink.value).toBe(SENTINEL);
    type(SENTINEL.slice(1));
    expect(got()).toEqual(['Backspace']);
    expect(sink.value).toBe(SENTINEL); // re-seeded
  });

  it('typing goes as text; autocorrect as Backspaces + text', () => {
    type(SENTINEL + 't');
    type(SENTINEL + 'te');
    type(SENTINEL + 'teh');
    type(SENTINEL + 'the ');
    expect(got()).toEqual(['text:t', 'text:e', 'text:h', 'Backspace', 'Backspace', 'text:he ']);
  });

  it('Gboard composition: live, and no re-seed until it ends', () => {
    kb.compositionstart();
    type(SENTINEL + 'h');
    type(SENTINEL + 'hi');
    expect(kb.isComposing).toBe(true);
    kb.compositionend();
    type(SENTINEL + 'hi ');
    expect(got()).toEqual(['text:h', 'text:i', 'text: ']);
  });

  it('Thaana typed through Android (229 + input) arrives as text', () => {
    const k = key({});
    kb.keydown(k);
    expect(k.prevented).toBe(false); // the IME must still edit the field
    type(SENTINEL + 'ދ');
    type(SENTINEL + 'ދި');
    expect(got()).toEqual(['text:ދ', 'text:ި']);
  });

  it('emoji is one text, one Backspace', () => {
    type(SENTINEL + '👍🏽');
    type(SENTINEL + '👍');
    expect(got()).toEqual(['text:👍🏽', 'Backspace']);
  });

  it('Enter from beforeinput is a key and starts a fresh line', () => {
    type(SENTINEL + 'o');
    type(SENTINEL + 'ok');
    const e = beforeinput('insertLineBreak');
    kb.beforeinput(e);
    expect(e.prevented).toBe(true);
    expect(sink.value).toBe(SENTINEL);
    expect(got()).toEqual(['text:o', 'text:k', 'Enter']);
  });

  it('iOS Enter (key without a code) is left to beforeinput', () => {
    const k = key({ key: 'Enter', code: '', keyCode: 13 });
    kb.keydown(k);
    expect(k.prevented).toBe(false);
    kb.beforeinput(beforeinput('insertParagraph'));
    expect(got()).toEqual(['Enter']);
  });

  it('a newline that slips into the field (uncancelable beforeinput) is still Enter', () => {
    kb.beforeinput(beforeinput('insertLineBreak', false));
    type(SENTINEL + '\n');
    expect(got()).toEqual(['Enter']);
    expect(sink.value).toBe(SENTINEL);
  });

  it('forward delete and delete-word', () => {
    kb.beforeinput(beforeinput('deleteContentForward'));
    kb.beforeinput(beforeinput('deleteWordBackward'));
    expect(got()).toEqual(['Delete', 'ctrl+Backspace']);
  });

  it('hardware Ctrl+C goes to the key translators, down and up', () => {
    const ctrl = key({ key: 'Control', code: 'ControlLeft', keyCode: 17, ctrlKey: true });
    const c = key({ key: 'c', code: 'KeyC', keyCode: 67, ctrlKey: true });
    kb.keydown(ctrl);
    kb.keydown(c);
    kb.keyup(key({ key: 'c', code: 'KeyC', keyCode: 67 }));
    kb.keyup(key({ key: 'Control', code: 'ControlLeft', keyCode: 17 }));
    expect(c.prevented).toBe(true);
    expect(got()).toEqual(['hw:ControlLeft:down', 'hw:KeyC:down', 'hw:KeyC:up', 'hw:ControlLeft:up']);
  });

  it('a key-up whose key-down went to the IME is not sent', () => {
    kb.keyup(key({ key: 'a', code: 'KeyA', keyCode: 65 }));
    expect(out).toEqual([]);
  });

  it('a hardware Backspace the keyboard also applied to the field is sent once', () => {
    type(SENTINEL + 'ab');
    out = [];
    kb.keydown(key({ key: 'Backspace', code: 'Backspace', keyCode: 8 }));
    type(SENTINEL + 'a'); // default action happened anyway
    expect(got()).toEqual(['hw:Backspace:down']);
    now += 1000;
    type(SENTINEL); // a later, real soft Backspace
    expect(got()).toEqual(['hw:Backspace:down', 'Backspace']);
  });

  it('pasted text: runs as text, line breaks and tabs as keys', () => {
    type(SENTINEL + 'a\tb\r\nc');
    expect(got()).toEqual(['text:a', 'Tab', 'text:b', 'Enter', 'text:c']);
  });

  it('reset forgets routed keys and re-seeds', () => {
    kb.keydown(key({ key: 'Shift', code: 'ShiftLeft', keyCode: 16, shiftKey: true }));
    sink.value = 'junk';
    kb.reset();
    kb.keyup(key({ key: 'Shift', code: 'ShiftLeft', keyCode: 16 }));
    expect(got()).toEqual(['hw:ShiftLeft:down']);
    expect(sink.value).toBe(SENTINEL);
  });
});
