import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { ControlKey } from '../gen/message';
import { buildLockScreenKeyCommand, buildSecurityControlMenu } from './session-controls-menu';
import english from '../i18n-en.json';

describe('security control menu capability gates', () => {
  it('shows supported Windows controls and preserves confirmed states', () => {
    expect(buildSecurityControlMenu({
      platform: 'Windows',
      permissions: {},
      privacyModeSupported: true,
      privacyModeImpls: [{ key: 'privacy_mode_impl_virtual_display', label: 'Virtual display' }],
      privacyModeOn: true,
      blockInputOn: true,
      lockAfterSessionEnd: false,
    })).toEqual([
      { id: 'restart', label: 'Restart remote device', checked: false },
      { id: 'elevation', label: 'Request elevation', checked: false },
      { id: 'privacy:privacy_mode_impl_virtual_display', label: 'Privacy mode — Virtual display', checked: true },
      { id: 'blockInput', label: 'Block remote keyboard and mouse', checked: true },
      { id: 'lockScreen', label: 'Lock remote screen', checked: false },
      { id: 'lockAfterSessionEnd', label: 'Request lock after disconnect (best effort)', checked: false },
    ]);
  });

  it('fails closed when permissions are withdrawn', () => {
    const menu = buildSecurityControlMenu({
      platform: 'Windows',
      permissions: { Restart: false, Keyboard: false, PrivacyMode: false, BlockInput: false },
      privacyModeSupported: true,
      privacyModeImpls: [],
      privacyModeOn: false,
      blockInputOn: false,
      lockAfterSessionEnd: false,
    });
    expect(menu).toEqual([]);
  });

  it('uses RustDesk’s legacy screen-curtain implementation when capability exists without a list', () => {
    const menu = buildSecurityControlMenu({
      platform: 'Linux',
      permissions: {},
      privacyModeSupported: true,
      privacyModeImpls: [],
      privacyModeOn: false,
      blockInputOn: false,
      lockAfterSessionEnd: false,
    });
    expect(menu.find((item) => item.id.startsWith('privacy:'))).toEqual({
      id: 'privacy:privacy_mode_impl_mag',
      label: 'Privacy mode',
      checked: false,
    });
    expect(menu.some((item) => item.id === 'elevation')).toBe(false);
    expect(menu.some((item) => item.id === 'blockInput')).toBe(false);
  });
});

describe('View only regression guard', () => {
  it('keeps the existing View only label and local input-suppression copy', () => {
    const source = readFileSync(new URL('./app.ts', import.meta.url), 'utf8');
    // The wording lives in the catalog now; the guard checks both ends.
    expect(source).toContain("<span>${htl('view_only')}</span>");
    expect(source).toContain("this.viewOnly ? tl('view_only_input_is_not') : tl('input_enabled')");
    expect(english.view_only).toBe('View only');
    expect(english.view_only_input_is_not).toBe('View only — input is not sent');
    expect(english.input_enabled).toBe('Input enabled');
    expect(source).toContain('title="${htl(\'block_all_input_remote_device\')}"');
    expect(english.block_all_input_remote_device).toBe('Block all input to the remote device');
  });
});

describe('Lock remote screen', () => {
  const input = (overrides: Record<string, unknown> = {}) => ({
    platform: 'linux',
    permissions: { Keyboard: true },
    privacyModeSupported: false,
    privacyModeImpls: [],
    privacyModeOn: false,
    blockInputOn: false,
    lockAfterSessionEnd: false,
    ...overrides,
  });

  it('is offered for a normal session with keyboard permission', () => {
    expect(buildSecurityControlMenu(input())).toContainEqual({ id: 'lockScreen', label: 'Lock remote screen', checked: false });
  });

  it('is offered on non-desktop peers too', () => {
    expect(buildSecurityControlMenu(input({ platform: 'android' })).map((i) => i.id)).toEqual(['lockScreen']);
  });

  it('is hidden in a View only session', () => {
    expect(buildSecurityControlMenu(input({ viewOnly: true })).map((i) => i.id)).not.toContain('lockScreen');
  });

  it('is hidden without keyboard permission', () => {
    expect(buildSecurityControlMenu(input({ permissions: { Keyboard: false } })).map((i) => i.id)).not.toContain('lockScreen');
  });

  it('sends the LockScreen control key as a one-shot press', () => {
    expect(buildLockScreenKeyCommand()).toEqual({
      c: 'key', down: false, press: true, keyKind: 'control', value: ControlKey.LockScreen, modifiers: [],
    });
  });
});
