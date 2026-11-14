import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Message, OptionMessage_BoolOption, SupportedDecoding, SupportedDecoding_PreferCodec } from '../gen/message';
import { buildLoginRequest } from '../core/auth';
import type { SessionConfig } from '../core/contracts';
import { QUALITY } from './common';
import { RdApp } from './app';
import {
  DEFAULT_PREFS,
  PREFS_KEY,
  clearPrefsMemory,
  initialOptionsFor,
  loadPrefs,
  resetCommands,
  resetPrefs,
  resolveInputMode,
  savePrefs,
  validatePrefs,
  withRememberedOptions,
  type SessionPrefs,
} from './session-prefs';

function memoryStore() {
  const data = new Map<string, string>();
  return {
    data,
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    removeItem: (k: string) => void data.delete(k),
  };
}

function throwingStore() {
  const fail = () => {
    throw new DOMException('denied', 'SecurityError');
  };
  return { getItem: fail, setItem: fail, removeItem: fail };
}

const reporter: SessionPrefs = {
  ...DEFAULT_PREFS,
  quality: QUALITY.best,
  customQuality: 100,
  customQualityActive: true,
  clipboard: false,
};

beforeEach(() => clearPrefsMemory());
afterEach(() => vi.unstubAllGlobals());

describe('session prefs storage', () => {
  it('returns defaults when nothing is stored', () => {
    expect(loadPrefs(memoryStore())).toEqual(DEFAULT_PREFS);
  });

  it('round-trips saved settings', () => {
    const store = memoryStore();
    savePrefs(reporter, store);
    expect(JSON.parse(store.data.get(PREFS_KEY)!)).toEqual(reporter);
    expect(loadPrefs(store)).toEqual(reporter);
  });

  it('replaces garbage field by field and survives corrupt JSON', () => {
    const store = memoryStore();
    store.data.set(PREFS_KEY, JSON.stringify({
      quality: 99,
      customQuality: 100,
      customFps: 33,
      codec: 'mpeg2',
      fitMode: 'stretch',
      clipboard: 'no',
      audioVolume: 7,
      cursorScale: 2,
      extra: true,
    }));
    expect(loadPrefs(store)).toEqual({ ...DEFAULT_PREFS, customQuality: 100, cursorScale: 2 });

    store.data.set(PREFS_KEY, '{not json');
    expect(loadPrefs(store)).toEqual(DEFAULT_PREFS);

    for (const raw of [null, 42, 'x', [1, 2]]) expect(validatePrefs(raw)).toEqual(DEFAULT_PREFS);
  });

  it('rejects out-of-range numbers', () => {
    expect(validatePrefs({ customQuality: 9 }).customQuality).toBe(75);
    expect(validatePrefs({ customQuality: 100.5 }).customQuality).toBe(75);
    expect(validatePrefs({ customFps: 125 }).customFps).toBe(30);
    expect(validatePrefs({ customFps: 120 }).customFps).toBe(120);
    expect(validatePrefs({ audioVolume: -0.1 }).audioVolume).toBe(1);
    expect(validatePrefs({ audioVolume: 0.4 }).audioVolume).toBe(0.4);
  });

  it('reset removes the stored settings', () => {
    const store = memoryStore();
    savePrefs(reporter, store);
    expect(resetPrefs(store)).toEqual(DEFAULT_PREFS);
    expect(store.data.has(PREFS_KEY)).toBe(false);
    expect(loadPrefs(store)).toEqual(DEFAULT_PREFS);
  });

  it('keeps working when storage throws, using an in-page copy', () => {
    const store = throwingStore();
    expect(() => savePrefs(reporter, store)).not.toThrow();
    expect(loadPrefs(store)).toEqual(reporter);
    expect(() => resetPrefs(store)).not.toThrow();
    expect(loadPrefs(store)).toEqual(DEFAULT_PREFS);
  });

  it('copes with a localStorage getter that throws', () => {
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get() {
        throw new DOMException('denied', 'SecurityError');
      },
    });
    try {
      expect(() => savePrefs(reporter)).not.toThrow();
      expect(loadPrefs()).toEqual(reporter);
    } finally {
      delete (globalThis as { localStorage?: unknown }).localStorage;
    }
  });
});

describe('initial session options', () => {
  it('sends nothing extra when nothing was changed', () => {
    expect(initialOptionsFor(DEFAULT_PREFS)).toEqual({});
  });

  it('sends the custom quality when the slider was touched last, else the preset', () => {
    expect(initialOptionsFor(reporter)).toEqual({ customImageQuality: 100, disableClipboard: true });
    expect(initialOptionsFor({ ...reporter, customQualityActive: false })).toEqual({
      imageQuality: QUALITY.best,
      disableClipboard: true,
    });
  });

  it('maps fps, codec, cursor and audio choices', () => {
    expect(initialOptionsFor({
      ...DEFAULT_PREFS,
      customFps: 60,
      codec: 'h264',
      followRemoteCursor: true,
      followRemoteWindow: true,
      remoteAudio: false,
      fitMode: 'actual',
      cursorScale: 2,
      audioMuted: true,
    })).toEqual({
      customFps: 60,
      preferCodec: SupportedDecoding_PreferCodec.H264,
      showRemoteCursor: true,
      followRemoteCursor: true,
      followRemoteWindow: true,
      disableAudio: true,
    });
  });

  it('puts remembered values into the LoginRequest the peer receives', () => {
    const store = memoryStore();
    savePrefs({ ...reporter, customFps: 60, showRemoteCursor: true, remoteAudio: false }, store);
    const config = withRememberedOptions({ peerId: '1' } as SessionConfig, loadPrefs(store));

    const msg = Message.decode(buildLoginRequest({
      peerId: '1',
      passwordHash: new Uint8Array(0),
      myId: 'me',
      myName: 'Me',
      sessionId: 1n,
      version: '1.4.0',
      supportedDecoding: SupportedDecoding.fromPartial({ ability_vp9: 1 }),
      initialOptions: config.initialOptions,
    }));
    if (msg.union?.$case !== 'login_request') throw new Error('not a login request');
    const option = msg.union.login_request.option!;

    expect(option.custom_image_quality).toBe(100 << 8);
    expect(option.custom_fps).toBe(60);
    expect(option.disable_clipboard).toBe(OptionMessage_BoolOption.Yes);
    expect(option.disable_audio).toBe(OptionMessage_BoolOption.Yes);
    expect(option.show_remote_cursor).toBe(OptionMessage_BoolOption.Yes);
    expect(option.supported_decoding?.ability_vp9).toBe(1);
  });

  it('leaves file transfer and terminal configs alone', () => {
    const config = { peerId: '1', connType: 'fileTransfer' } as SessionConfig;
    expect(withRememberedOptions(config, reporter)).toBe(config);
    expect(withRememberedOptions({ peerId: '1', connType: 'default' } as SessionConfig, reporter).initialOptions)
      .toEqual({ customImageQuality: 100, disableClipboard: true });
  });
});

describe('reset commands', () => {
  it('sends only the peer settings that differ from the defaults', () => {
    expect(resetCommands(DEFAULT_PREFS)).toEqual([]);
    expect(resetCommands({ ...reporter, codec: 'vp9', followRemoteCursor: true, showRemoteCursor: true, fitMode: 'actual' })).toEqual([
      { c: 'quality', imageQuality: QUALITY.balanced },
      { c: 'preferredCodec', prefer: SupportedDecoding_PreferCodec.Auto },
      { c: 'displayOption', option: 'showRemoteCursor', enabled: false },
      { c: 'displayOption', option: 'followRemoteCursor', enabled: false },
      { c: 'clipboardEnabled', enabled: true },
    ]);
  });
});

describe('RdApp remembered settings', () => {
  it('saves only the field a menu changed', () => {
    const store = memoryStore();
    vi.stubGlobal('localStorage', store);
    savePrefs({ ...DEFAULT_PREFS, codec: 'h264' });

    const app = Object.create(RdApp.prototype) as any;
    app.rememberPrefs({ clipboard: false });

    // A codec the session fell back from must not overwrite the saved choice.
    expect(loadPrefs()).toEqual({ ...DEFAULT_PREFS, codec: 'h264', clipboard: false });
  });

  it('applies remembered settings to the session state', () => {
    const app = Object.create(RdApp.prototype) as any;
    const label = { textContent: '' };
    app.el = {
      viewport: { dataset: {} },
      toolbar: { querySelector: () => label },
      dock: { querySelector: () => null },
      remoteCursor: { style: {} },
    };
    app.remoteCursorHot = { x: 0, y: 0 };

    app.applyPrefs({ ...reporter, fitMode: 'actual', followRemoteCursor: true, remoteAudio: false });

    expect(app.quality).toBe(QUALITY.best);
    expect(app.customQuality).toBe(100);
    expect(app.customQualityActive).toBe(true);
    expect(app.clipboardEnabled).toBe(false);
    expect(app.remoteAudioEnabled).toBe(false);
    expect(app.showRemoteCursor).toBe(true);
    expect(app.el.viewport.dataset.fit).toBe('actual');
    expect(label.textContent).toBe('Actual size');
  });
});

describe('input mode preference', () => {
  it('defaults to auto: touch on a phone, pointer on a desktop', () => {
    expect(DEFAULT_PREFS.inputMode).toBe('auto');
    expect(resolveInputMode('auto', true)).toBe('touch');
    expect(resolveInputMode('auto', false)).toBe('pointer');
  });

  it('keeps an explicit choice on any device', () => {
    expect(resolveInputMode('pointer', true)).toBe('pointer');
    expect(resolveInputMode('touch', false)).toBe('touch');
  });

  it('drops unknown values', () => {
    expect(validatePrefs({ inputMode: 'joystick' }).inputMode).toBe('auto');
    expect(validatePrefs({ inputMode: 'touch' }).inputMode).toBe('touch');
  });
});
