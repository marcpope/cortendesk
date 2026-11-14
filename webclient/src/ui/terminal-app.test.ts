import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SessionConfig } from '../core/contracts';
import { consumeTwoFactorCode, loginErrorText, showTwoFactor } from './connect-card';
import { TerminalApp, terminalModeFromSearch } from './terminal-app';

const SAVED = 'cortendesk.rdpw.123456789';

function node(extra: Record<string, unknown> = {}) {
  return {
    value: '', hidden: false, disabled: false, checked: false, placeholder: '', textContent: '',
    focus: vi.fn(),
    classList: { add: vi.fn(), remove: vi.fn(), toggle: vi.fn() },
    ...extra,
  };
}

function fakeEls() {
  return {
    root: { dataset: {} as Record<string, string> },
    overlay: node(), toast: node(), viewport: node(),
    overlayPeer: node(), overlayTarget: node(), fieldId: node({ hidden: true }),
    peerIdInput: node({ value: '123456789' }), passwordRow: node(), passwordInput: node(),
    saveRow: node(), saveCheckbox: node(), connectBtn: node(), twoFactorRow: node({ hidden: true }),
    twoFactorInput: node(), twoFactorBtn: node(), overlayStatus: node({ hidden: true }),
    overlayStatusText: node(), overlayError: node({ hidden: true }), reconnectCancel: node(),
    keepCheckbox: node(), keepRow: node(), forceRelayCheckbox: node(), forceRelayRow: node(),
  };
}

function app(store: Map<string, string>) {
  const a = Object.create(TerminalApp.prototype) as any;
  a.cfg = {
    serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    wsIdUrl: 'ws://example.test/id', wsRelayUrl: 'ws://example.test/relay', myId: 'web-1', myName: 'Admin',
  };
  a.el = fakeEls();
  a.fixedPeerId = '123456789';
  a.peerId = '';
  a.pendingConfig = null;
  const seen: (SessionConfig | null)[] = [];
  a.panel = {
    connected: false,
    connect: vi.fn(() => seen.push(a.pendingConfig)),
    setTitle: vi.fn(),
    submitTwoFactor: vi.fn(),
    open: vi.fn(),
    destroy: vi.fn(),
  };
  return { a, seen, store };
}

describe('terminal mode switch', () => {
  it('turns on only for mode=terminal', () => {
    expect(terminalModeFromSearch('?id=1&mode=terminal')).toBe(true);
    expect(terminalModeFromSearch('?id=1')).toBe(false);
    expect(terminalModeFromSearch('?mode=desktop')).toBe(false);
  });
});

describe('connect card helpers', () => {
  it('words the device\'s known refusals in the page language', () => {
    expect(loginErrorText('Wrong Password')).toBe('Wrong password');
    expect(loginErrorText('Offline')).toBe('The device is offline');
    expect(loginErrorText('No permission of terminal')).toBe('This device does not allow terminal connections');
    expect(loginErrorText('')).toBe('Login failed');
    expect(loginErrorText('No active console user logged on, please connect and logon first.'))
      .toBe('No active console user logged on, please connect and logon first.');
  });

  it('swaps the password row for the code row and back', () => {
    const els = fakeEls();
    showTwoFactor(els as any, true, true);
    expect(els.twoFactorRow.hidden).toBe(false);
    expect(els.passwordRow.hidden).toBe(true);
    expect(els.saveRow.hidden).toBe(true);
    expect(els.fieldId.hidden).toBe(true);

    els.twoFactorInput.value = '123';
    showTwoFactor(els as any, false, false);
    expect(els.twoFactorRow.hidden).toBe(true);
    expect(els.passwordRow.hidden).toBe(false);
    expect(els.fieldId.hidden).toBe(false);
    expect(els.twoFactorInput.value).toBe('');
  });

  it('reads the code without spaces and clears the field', () => {
    const input = { value: ' 123 456 ' };
    expect(consumeTwoFactorCode(input)).toBe('123456');
    expect(input.value).toBe('');
  });
});

describe('TerminalApp login', () => {
  let store: Map<string, string>;

  beforeEach(() => {
    store = new Map();
    vi.stubGlobal('localStorage', {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => void store.set(k, v),
      removeItem: (k: string) => void store.delete(k),
    });
    vi.stubGlobal('document', { title: '' });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('connects a terminal with the saved password hash when the field is empty', () => {
    store.set(SAVED, 'ab'.repeat(32));
    const { a, seen } = app(store);
    a.el.saveCheckbox.checked = true;

    a.onConnectClick();

    expect(a.panel.connect).toHaveBeenCalledOnce();
    expect(seen[0]).toMatchObject({
      peerId: '123456789', connType: 'terminal', password: '', savedHashHex: 'ab'.repeat(32),
    });
    expect(seen[0]?.sessionId).toMatch(/^\d+$/);
    expect(a.pendingConfig).toBeNull();
    expect(a.el.passwordInput.disabled).toBe(true);
  });

  it('sends a typed password and forgets a saved one when "save" is off', () => {
    store.set(SAVED, 'ab'.repeat(32));
    const { a, seen } = app(store);
    a.el.passwordInput.value = 'secret';

    a.onConnectClick();

    expect(seen[0]).toMatchObject({ connType: 'terminal', password: 'secret', savedHashHex: undefined });
    expect(a.el.passwordInput.value).toBe('');
    expect(store.has(SAVED)).toBe(false);
  });

  it('does not start a second connection while one is open', () => {
    const { a } = app(store);
    a.panel.connected = true;
    a.onConnectClick();
    expect(a.panel.connect).not.toHaveBeenCalled();
  });

  it('shows a wrong password on the card and drops a saved hash that failed', () => {
    store.set(SAVED, 'ab'.repeat(32));
    const { a } = app(store);
    a.el.saveCheckbox.checked = true;
    a.onConnectClick();

    a.onLoginError('Wrong Password');

    expect(store.has(SAVED)).toBe(false);
    expect(a.el.saveCheckbox.checked).toBe(false);
    expect(a.el.overlayError.hidden).toBe(false);
    expect(a.el.overlayError.textContent).toBe('Wrong password');
    expect(a.el.passwordInput.disabled).toBe(false);
    expect(a.el.overlay.classList.remove).toHaveBeenCalledWith('rd-hidden');
  });

  it('shows a terminal permission refusal plainly', () => {
    const { a } = app(store);
    a.onLoginError('No permission of terminal');
    expect(a.el.overlayError.textContent).toBe('This device does not allow terminal connections');
  });

  it('asks for the 2FA code, then sends it on the open connection', () => {
    const { a } = app(store);
    a.onTwoFactor(false);
    expect(a.el.twoFactorRow.hidden).toBe(false);
    expect(a.el.passwordRow.hidden).toBe(true);
    expect(a.el.keepRow.hidden).toBe(true);
    expect(a.el.overlayError.hidden).toBe(true);
    expect(a.el.twoFactorInput.focus).toHaveBeenCalled();

    a.el.twoFactorInput.value = '123 456';
    a.submitTwoFactor();
    expect(a.panel.submitTwoFactor).toHaveBeenCalledWith('123456');
    expect(a.el.twoFactorInput.disabled).toBe(true);

    a.onTwoFactor(true);
    expect(a.el.overlayError.textContent).toBe('Wrong 2FA code');
    expect(a.el.twoFactorInput.disabled).toBe(false);
  });

  it('will not send an empty 2FA code', () => {
    const { a } = app(store);
    a.onTwoFactor(false);
    a.submitTwoFactor();
    expect(a.panel.submitTwoFactor).not.toHaveBeenCalled();
    expect(a.el.overlayError.textContent).toBe('Enter the 2FA code');
  });

  it('saves the new password hash once the terminal is live and hides the card', () => {
    const { a } = app(store);
    a.el.passwordInput.value = 'secret';
    a.el.saveCheckbox.checked = true;
    a.onConnectClick();
    a.hooks().onCredentials('cd'.repeat(32));
    a.onTwoFactor(false);

    a.onLive();

    expect(store.get(SAVED)).toBe('cd'.repeat(32));
    expect(a.el.twoFactorRow.hidden).toBe(true);
    expect(a.el.overlay.classList.add).toHaveBeenCalledWith('rd-hidden');
    expect(a.el.root.dataset.state).toBe('streaming');
  });

  it('shows why the connection ended, and a user disconnect as status only', () => {
    const { a } = app(store);
    a.onEnded('The device is offline', false);
    expect(a.el.overlayError.textContent).toBe('The device is offline');
    expect(a.el.root.dataset.state).toBe('error');

    a.onEnded('Terminal disconnected', true);
    expect(a.el.overlayError.hidden).toBe(true);
    expect(a.el.overlayStatusText.textContent).toBe('Terminal disconnected');
    expect(a.el.root.dataset.state).toBe('closed');
  });
});
