// The connect card: device id, password, "save password", 2FA code, status
// and error lines. Shared by the desktop client and the terminal page, so both
// sign in the same way.
import { htl, tl } from '../i18n';

export type ConnectCardEls = {
  overlayPeer: HTMLElement;
  overlayTarget: HTMLElement;
  fieldId: HTMLElement;
  peerIdInput: HTMLInputElement;
  passwordRow: HTMLElement;
  passwordInput: HTMLInputElement;
  saveRow: HTMLElement;
  saveCheckbox: HTMLInputElement;
  connectBtn: HTMLButtonElement;
  twoFactorRow: HTMLElement;
  twoFactorInput: HTMLInputElement;
  twoFactorBtn: HTMLButtonElement;
  overlayStatus: HTMLElement;
  overlayStatusText: HTMLElement;
  overlayError: HTMLElement;
  reconnectCancel: HTMLButtonElement;
};

export type ConnectCardOptions = {
  /** Line under the wordmark, e.g. "Web client v1.17.1". */
  tagline: string;
  /** Extra markup placed after "save password" (already escaped). */
  extra?: string;
};

function q<T extends Element>(scope: ParentNode, sel: string): T {
  const el = scope.querySelector<T>(sel);
  if (!el) throw new Error(`rdclient: missing element ${sel}`);
  return el;
}

const svg = (paths: string, size = 20): string =>
  `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" ` +
  `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;

/** Render the card into the overlay and return its elements. */
export function renderConnectCard(overlay: HTMLElement, opts: ConnectCardOptions): ConnectCardEls {
  const lock = svg('<rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>');
  const device = svg('<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>');
  const shield = svg('<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>');
  const arrow = svg('<path d="M5 12h13M12 5.5 18.5 12 12 18.5"/>', 24);
  overlay.innerHTML = `
    <div class="rd-card">
      <div class="rd-brand">
        <img class="rd-logo" src="/rdclient/logo.png" alt="CortenDesk" width="60" height="60">
        <span class="rd-wordmark">Corten<span>Desk</span></span>
      </div>
      <div class="rd-tagline">${opts.tagline}</div>
      <div class="rd-divider" aria-hidden="true"></div>
      <p class="rd-help">${htl('enter_clients_temporary_or_permanent')}</p>
      <div class="rd-target" id="rd-target" hidden>
        <span class="rd-target-ic">${device}</span>
        <span class="rd-target-cap">${htl('client_id')}</span>
        <span class="rd-target-id" id="rd-overlay-peer"></span>
      </div>
      <label class="rd-field" id="rd-field-id" hidden>
        <span class="rd-input">
          <span class="rd-input-ic">${device}</span>
          <input id="rd-peer-id" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="${htl('device_id')}">
        </span>
      </label>
      <div class="rd-connect-row" id="rd-password-row">
        <span class="rd-input">
          <span class="rd-input-ic">${lock}</span>
          <input id="rd-password" type="password" autocomplete="new-password" placeholder="${htl('enter_password')}">
        </span>
        <button type="button" class="rd-go" id="rd-connect" aria-label="${htl('connect')}">${arrow}</button>
      </div>
      <div class="rd-connect-row" id="rd-2fa-row" hidden>
        <span class="rd-input">
          <span class="rd-input-ic">${shield}</span>
          <input id="rd-2fa" type="text" inputmode="numeric" autocomplete="one-time-code" spellcheck="false" placeholder="${htl('enter_2fa_code')}" aria-label="${htl('enter_2fa_code')}">
        </span>
        <button type="button" class="rd-go" id="rd-2fa-go" aria-label="${htl('verify')}">${arrow}</button>
      </div>
      <label class="rd-save" id="rd-save-row">
        <input type="checkbox" id="rd-save-pw">
        <span>${htl('save_password_on_this_device')}</span>
      </label>
      ${opts.extra ?? ''}
      <div class="rd-msg" id="rd-msg">
        <div class="rd-overlay-status" id="rd-overlay-status" hidden>
          <span class="rd-spinner" aria-hidden="true"></span><span id="rd-overlay-status-text"></span>
        </div>
        <div class="rd-overlay-error" id="rd-overlay-error" role="alert" hidden></div>
      </div>
      <button type="button" class="rd-chip" id="rd-restart-cancel" hidden>${htl('cancel_reconnect')}</button>
    </div>`;
  const o = overlay;
  return {
    overlayPeer: q(o, '#rd-overlay-peer'),
    overlayTarget: q(o, '#rd-target'),
    fieldId: q(o, '#rd-field-id'),
    peerIdInput: q(o, '#rd-peer-id'),
    passwordRow: q(o, '#rd-password-row'),
    passwordInput: q(o, '#rd-password'),
    saveRow: q(o, '#rd-save-row'),
    saveCheckbox: q(o, '#rd-save-pw'),
    connectBtn: q(o, '#rd-connect'),
    twoFactorRow: q(o, '#rd-2fa-row'),
    twoFactorInput: q(o, '#rd-2fa'),
    twoFactorBtn: q(o, '#rd-2fa-go'),
    overlayStatus: q(o, '#rd-overlay-status'),
    overlayStatusText: q(o, '#rd-overlay-status-text'),
    overlayError: q(o, '#rd-overlay-error'),
    reconnectCancel: q(o, '#rd-restart-cancel'),
  };
}

type TwoFactorEls = Partial<Pick<ConnectCardEls, 'passwordRow' | 'saveRow' | 'fieldId' | 'twoFactorRow' | 'twoFactorInput'>>;

/**
 * Swap the password row for the 2FA code row, or back. The id field stays
 * hidden while a code is asked for: the connection is already open.
 */
export function showTwoFactor(els: TwoFactorEls, on: boolean, fixedPeer: boolean): void {
  if (!els.twoFactorRow) return;
  els.twoFactorRow.hidden = !on;
  if (els.passwordRow) els.passwordRow.hidden = on;
  if (els.saveRow) els.saveRow.hidden = on;
  if (els.fieldId) els.fieldId.hidden = on || fixedPeer;
  if (els.twoFactorInput && !on) els.twoFactorInput.value = '';
}

/** Read and clear the typed 2FA code. */
export function consumeTwoFactorCode(input: { value: string }): string {
  const code = input.value.replace(/\s+/g, '');
  input.value = '';
  return code;
}

/**
 * The device's login refusals in the page's language. Anything unknown is
 * shown as the device sent it.
 */
export function loginErrorText(message: string): string {
  switch (message) {
    case 'Wrong Password': return tl('wrong_password');
    case 'Offline': return tl('failure_offline');
    case 'No permission of terminal': return tl('terminal_not_permitted');
    case '': return tl('login_failed');
    default: return message;
  }
}
