import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { OptionMessage_BoolOption } from '../gen/message';
import {
  RESTART_RECONNECT_DELAYS_MS,
  buildBlockInputOption,
  buildDirectElevation,
  buildLockAfterSessionEndOption,
  buildPrivacyToggle,
  buildRestartRemoteDevice,
  nextRestartReconnectDelay,
  parsePrivacyModeImpls,
} from './session-controls';
import { Session } from './session';
import english from '../i18n-en.json';

describe('remote-session control messages', () => {
  it('builds the RustDesk restart command', () => {
    expect(buildRestartRemoteDevice()).toEqual({
      $case: 'restart_remote_device',
      restart_remote_device: true,
    });
  });

  it('builds remote-user-approved elevation without logon credentials', () => {
    const control = buildDirectElevation();
    expect(control.$case).toBe('elevation_request');
    if (control.$case !== 'elevation_request') throw new Error('wrong control');
    expect(control.elevation_request.union).toEqual({ $case: 'direct', direct: true });
    expect(JSON.stringify(control)).not.toMatch(/username|password|logon/i);
  });

  it('builds privacy-mode toggles with an implementation key', () => {
    expect(buildPrivacyToggle('privacy_mode_impl_virtual_display', true)).toEqual({
      $case: 'toggle_privacy_mode',
      toggle_privacy_mode: {
        impl_key: 'privacy_mode_impl_virtual_display',
        on: true,
      },
    });
  });

  it('encodes host-side input blocking independently from View only', () => {
    const on = buildBlockInputOption(true);
    const off = buildBlockInputOption(false);
    expect(on.block_input).toBe(OptionMessage_BoolOption.Yes);
    expect(off.block_input).toBe(OptionMessage_BoolOption.No);
    expect(on.disable_keyboard).toBe(OptionMessage_BoolOption.NotSet);
    expect(off.disable_keyboard).toBe(OptionMessage_BoolOption.NotSet);
  });

  it('encodes lock-after-session-end without changing other options', () => {
    const on = buildLockAfterSessionEndOption(true);
    const off = buildLockAfterSessionEndOption(false);
    expect(on.lock_after_session_end).toBe(OptionMessage_BoolOption.Yes);
    expect(off.lock_after_session_end).toBe(OptionMessage_BoolOption.No);
    expect(on.block_input).toBe(OptionMessage_BoolOption.NotSet);
    expect(off.block_input).toBe(OptionMessage_BoolOption.NotSet);
  });
});

describe('privacy-mode capability parsing', () => {
  it('accepts only string implementation pairs from platform additions', () => {
    expect(parsePrivacyModeImpls(JSON.stringify({
      supported_privacy_mode_impl: [
        ['privacy_mode_impl_virtual_display', 'Virtual display'],
        [42, 'invalid'],
        ['privacy_mode_impl_mag', 'Screen curtain'],
      ],
    }))).toEqual([
      { key: 'privacy_mode_impl_virtual_display', label: 'Virtual display' },
      { key: 'privacy_mode_impl_mag', label: 'Screen curtain' },
    ]);
  });

  it('fails closed on malformed or missing JSON', () => {
    expect(parsePrivacyModeImpls('{')).toEqual([]);
    expect(parsePrivacyModeImpls('')).toEqual([]);
    expect(parsePrivacyModeImpls('{}')).toEqual([]);
  });
});

describe('restart reconnect policy', () => {
  it('uses a bounded backoff schedule', () => {
    expect(RESTART_RECONNECT_DELAYS_MS).toEqual([1_000, 2_000, 4_000, 8_000, 12_000, 15_000]);
    expect(RESTART_RECONNECT_DELAYS_MS.at(-1)).toBeLessThanOrEqual(15_000);
  });

  it('returns each delay while inside the overall timeout', () => {
    expect(nextRestartReconnectDelay(0, 0)).toBe(1_000);
    expect(nextRestartReconnectDelay(3, 20_000)).toBe(8_000);
  });

  it('keeps retrying at the capped delay until the overall timeout', () => {
    expect(nextRestartReconnectDelay(RESTART_RECONNECT_DELAYS_MS.length, 42_000)).toBe(15_000);
    expect(nextRestartReconnectDelay(99, 115_000)).toBe(5_000);
  });

  it('stops at the overall timeout', () => {
    expect(nextRestartReconnectDelay(0, 120_000)).toBeNull();
  });
});

vi.mock('./crypto', () => ({
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

function makeSession(): { session: Session; sent: unknown[] } {
  const sent: unknown[] = [];
  const session = new Session(
    {
      peerId: '123456789',
      serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      wsIdUrl: 'ws://example.test/id',
      wsRelayUrl: 'ws://example.test/relay',
      password: '',
      myId: 'controller',
      myName: 'Controller',
    },
    {
      relayBuffered: () => 0,
      sendSignaling: () => {},
      sendRelay: () => {},
      emit: () => {},
      onVideo: () => {},
      onAudioFormat: () => {},
      onAudioFrame: () => {},
      openRelay: () => {},
      closeAll: () => {},
    },
  );
  (session as unknown as { sendMisc(union: unknown): void }).sendMisc = (union) => sent.push(union);
  return { session, sent };
}

describe('Session media controls', () => {
  it('preserves the existing View only wording', () => {
    const appSource = readFileSync(new URL('../ui/app.ts', import.meta.url), 'utf8');
    expect(appSource).toContain("<span>${htl('view_only')}</span>");
    expect(english.view_only).toBe('View only');
  });

  it('sends RustDesk disable_audio when remote playback is turned off', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setRemoteAudioEnabled(enabled: boolean): void }).setRemoteAudioEnabled(false);

    const option = (sent[0] as { $case: string; option: { disable_audio: OptionMessage_BoolOption } });
    expect(option.$case).toBe('option');
    expect(option.option.disable_audio).toBe(OptionMessage_BoolOption.Yes);
  });

  it('sends RustDesk disable_clipboard for the whole session', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setClipboardEnabled(enabled: boolean): void }).setClipboardEnabled(false);

    const option = sent[0] as { $case: string; option: { disable_clipboard: OptionMessage_BoolOption } };
    expect(option.$case).toBe('option');
    expect(option.option.disable_clipboard).toBe(OptionMessage_BoolOption.Yes);
  });

  it('reports local recording state only through client_record_status', () => {
    const { session, sent } = makeSession();

    (session as unknown as { setClientRecording(recording: boolean): void }).setClientRecording(true);
    (session as unknown as { setClientRecording(recording: boolean): void }).setClientRecording(false);

    expect(sent).toEqual([
      { $case: 'client_record_status', client_record_status: true },
      { $case: 'client_record_status', client_record_status: false },
    ]);
  });
});
