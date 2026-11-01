import type { UiCommand } from '../core/contracts';
import { ControlKey } from '../gen/message';
import { tl } from '../i18n';

export type PrivacyModeMenuImpl = { key: string; label: string };
export type SecurityControlMenuItem = { id: string; label: string; checked: boolean };

export type SecurityControlMenuInput = {
  platform: string;
  permissions: Record<string, boolean>;
  privacyModeSupported: boolean;
  privacyModeImpls: PrivacyModeMenuImpl[];
  privacyModeOn: boolean;
  activePrivacyImplKey?: string;
  blockInputOn: boolean;
  lockAfterSessionEnd: boolean;
  viewOnly?: boolean;
};

export function buildLockScreenKeyCommand(): Extract<UiCommand, { c: 'key' }> {
  return { c: 'key', down: false, press: true, keyKind: 'control', value: ControlKey.LockScreen, modifiers: [] };
}

export function buildSecurityControlMenu(input: SecurityControlMenuInput): SecurityControlMenuItem[] {
  const platform = input.platform.toLowerCase();
  const desktop = platform.includes('windows') || platform.includes('linux') || platform.includes('mac');
  const canLockScreen = !input.viewOnly && input.permissions.Keyboard !== false;
  const lockScreenItem = { id: 'lockScreen', label: tl('lock_remote_screen'), checked: false };
  if (!desktop) return canLockScreen ? [lockScreenItem] : [];

  const items: SecurityControlMenuItem[] = [];
  if (input.permissions.Restart !== false) {
    items.push({ id: 'restart', label: tl('restart_remote_device'), checked: false });
  }
  if (platform.includes('windows') && input.permissions.Keyboard !== false) {
    items.push({ id: 'elevation', label: tl('request_elevation'), checked: false });
  }

  if ((input.permissions.PrivacyMode !== false || input.privacyModeOn) && input.privacyModeSupported) {
    const impls = input.privacyModeImpls.length > 0
      ? input.privacyModeImpls
      : [{ key: 'privacy_mode_impl_mag', label: '' }];
    for (const impl of impls) {
      const checked = input.privacyModeOn
        && (!input.activePrivacyImplKey || input.activePrivacyImplKey === impl.key);
      items.push({
        id: `privacy:${impl.key}`,
        label: impl.label ? tl('privacy_mode_impl', { label: impl.label }) : tl('privacy_mode'),
        checked,
      });
    }
  }

  if (platform.includes('windows') && (input.permissions.BlockInput !== false || input.blockInputOn)) {
    items.push({
      id: 'blockInput',
      label: tl('block_remote_keyboard_and_mouse'),
      checked: input.blockInputOn,
    });
  }
  if (canLockScreen) {
    items.push(lockScreenItem);
  }
  if (input.permissions.Keyboard !== false) {
    items.push({
      id: 'lockAfterSessionEnd',
      label: tl('request_lock_after_disconnect_best'),
      checked: input.lockAfterSessionEnd,
    });
  }
  return items;
}
