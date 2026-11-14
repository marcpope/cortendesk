import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BackNotification_BlockInputState, BackNotification_PrivacyModeState } from '../gen/message';
import { RdApp } from './app';

const config = {
  peerId: '123456789',
  serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  wsIdUrl: 'ws://example.test/id',
  wsRelayUrl: 'ws://example.test/relay',
  password: '',
  savedHashHex: 'a'.repeat(64),
  myId: 'controller',
  myName: 'Controller',
};

describe('RdApp security lifecycle', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(0);
    vi.stubGlobal('window', { confirm: vi.fn(() => true) });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  it('does not retry after an explicit peer-initiated close', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.restartFlow = { startedAt: 0, attempt: 1, config };
    app.el = { reconnectCancel: { hidden: false } };
    app.scheduleRestartReconnect = vi.fn();
    app.setState = vi.fn();

    app.onEvent({
      t: 'state',
      state: 'closed',
      detail: 'remote user closed the session',
      peerInitiated: true,
    });

    expect(app.restartFlow).toBeUndefined();
    expect(app.scheduleRestartReconnect).not.toHaveBeenCalled();
    expect(app.setState).toHaveBeenCalledWith('closed', 'remote user closed the session');
  });

  it('stops restart recovery when the peer withdraws Restart permission', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.permissions = {};
    app.state = 'connecting';
    app.restartFlow = { startedAt: 0, attempt: 1, config };
    app.el = { reconnectCancel: { hidden: false } };
    app.setState = vi.fn();
    app.toast = vi.fn();

    app.applyPermission('Restart', false);

    expect(app.restartFlow).toBeUndefined();
    expect(app.setState).toHaveBeenCalledWith('error', 'Automatic reconnect stopped because restart permission was denied');
  });

  it('describes stable reconnection without claiming the reboot succeeded', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.state = 'connecting';
    app.peerPlatform = '';
    app.streamStartMs = 0;
    app.restartFlow = { startedAt: 0, attempt: 1, config };
    app.el = {
      root: { dataset: {} },
      peerSub: { textContent: '' },
      reconnectCancel: { hidden: false },
    };
    app.persistCredentialIfWanted = vi.fn();
    app.maybeShowHomeScreenHint = vi.fn();
    app.hideOverlay = vi.fn();
    app.canvas = { focus: vi.fn() };
    app.toast = vi.fn();

    app.setState('streaming');
    vi.advanceTimersByTime(15_000);

    expect(app.toast).toHaveBeenLastCalledWith('Connection restored after the restart request');
    expect(app.toast.mock.calls.flat().join(' ')).not.toContain('device restarted');
  });

  it('consumes and clears a typed password from the UI immediately', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.el = { passwordInput: { value: 'temporary secret' } };

    expect(app.consumePasswordInput()).toBe('temporary secret');
    expect(app.el.passwordInput.value).toBe('');
  });

  it('suppresses controller input in View only while allowing non-input commands', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.viewOnly = true;
    app.latchCtrl = false;
    app.latchAlt = false;
    app.lastDbgMouseMs = 0;
    app.worker = { postMessage: vi.fn() };
    app.current = 0;
    app.displays = [{ online: true }];

    app.post({ c: 'mouse', mask: 0, x: 1, y: 2, modifiers: [] });
    app.post({ c: 'key', down: true, chr: 'a', modifiers: [] });
    app.post({ c: 'ctrlAltDel' });
    app.post({ c: 'chat', text: 'still allowed' });

    expect(app.worker.postMessage).toHaveBeenCalledOnce();
    expect(app.worker.postMessage).toHaveBeenCalledWith({ c: 'chat', text: 'still allowed' });
  });

  it('labels lock-after-disconnect as an unacknowledged best-effort request', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.lockAfterSessionEnd = false;
    app.post = vi.fn();
    app.toast = vi.fn();
    app.closePop = vi.fn();

    app.activateSecurityControl('lockAfterSessionEnd');

    expect(app.lockAfterSessionEnd).toBe(true);
    expect(app.post).toHaveBeenCalledWith({ c: 'lockAfterSessionEnd', on: true });
    expect(app.toast).toHaveBeenCalledWith(
      'Lock-after-disconnect request sent (best effort; the remote device does not acknowledge this setting)',
    );
  });

  it('uses peer back-notifications as authoritative privacy and block-input state', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.privacyModePending = true;
    app.privacyModeOn = false;
    app.activePrivacyImplKey = '';
    app.blockInputPending = true;
    app.blockInputOn = false;
    app.refreshSecurityToast = vi.fn();
    app.toast = vi.fn();

    app.applyPrivacyModeState(
      BackNotification_PrivacyModeState.PrvOnSucceeded,
      '',
      'privacy_mode_impl_virtual_display',
    );
    app.applyBlockInputState(BackNotification_BlockInputState.BlkOnSucceeded, '');

    expect(app.privacyModePending).toBe(false);
    expect(app.privacyModeOn).toBe(true);
    expect(app.activePrivacyImplKey).toBe('privacy_mode_impl_virtual_display');
    expect(app.blockInputPending).toBe(false);
    expect(app.blockInputOn).toBe(true);
    expect(app.refreshSecurityToast).toHaveBeenCalledTimes(2);
  });

  it('cancels the restart deadline timer with the reconnect flow', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.state = 'streaming';
    app.reconnectConfig = config;
    app.sessionHashHex = config.savedHashHex;
    app.el = { reconnectCancel: { hidden: true } };
    app.post = vi.fn();
    app.toast = vi.fn();

    app.beginRemoteRestart();
    expect(vi.getTimerCount()).toBe(1);
    app.cancelRestartReconnect();

    expect(app.restartFlow).toBeUndefined();
    expect(vi.getTimerCount()).toBe(0);
  });

  it('compensates for pending block-input and privacy enables before disconnect', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.blockInputOn = false;
    app.blockInputPending = true;
    app.privacyModeOn = false;
    app.privacyModePending = true;
    app.activePrivacyImplKey = 'privacy_mode_impl_virtual_display';
    app.post = vi.fn();

    app.releaseRemoteSecurityState();

    expect(app.post.mock.calls).toEqual([
      [{ c: 'blockInput', on: false }],
      [{ c: 'privacyMode', implKey: 'privacy_mode_impl_virtual_display', on: false }],
    ]);
  });

  it('compensates intrusive remote state during normal teardown', () => {
    const app = Object.create(RdApp.prototype) as any;
    const worker = { postMessage: vi.fn(), terminate: vi.fn(), onmessage: vi.fn(), onerror: vi.fn() };
    app.worker = worker;
    app.blockInputOn = true;
    app.blockInputPending = false;
    app.privacyModeOn = false;
    app.privacyModePending = true;
    app.activePrivacyImplKey = 'privacy_mode_impl_virtual_display';
    app.detach = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();
    app.el = { recordingIndicator: { hidden: false }, root: { querySelector: vi.fn(() => null) } };

    app.teardown();

    expect(worker.postMessage.mock.calls).toEqual([
      [{ c: 'blockInput', on: false }],
      [{ c: 'privacyMode', implKey: 'privacy_mode_impl_virtual_display', on: false }],
    ]);
  });

  it('starts the 120-second deadline when the restart request is dispatched', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.state = 'streaming';
    app.reconnectConfig = config;
    app.sessionHashHex = config.savedHashHex;
    app.el = { reconnectCancel: { hidden: true } };
    app.post = vi.fn(() => vi.advanceTimersByTime(250));
    app.toast = vi.fn();
    app.setState = vi.fn();

    app.beginRemoteRestart();
    app.state = 'connecting';
    vi.advanceTimersByTime(119_999);

    expect(app.restartFlow).toBeDefined();
    expect(app.setState).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(app.restartFlow).toBeUndefined();
    expect(app.setState).toHaveBeenCalledWith(
      'error',
      'The remote device did not return within 120 seconds after the restart request',
    );
  });

  it('terminates an active reconnect attempt at the hard deadline', () => {
    const app = Object.create(RdApp.prototype) as any;
    const terminate = vi.fn();
    const worker = { postMessage: vi.fn(), terminate, onmessage: vi.fn(), onerror: vi.fn() };
    app.state = 'streaming';
    app.reconnectConfig = config;
    app.sessionHashHex = config.savedHashHex;
    app.el = {
      reconnectCancel: { hidden: true },
      recordingIndicator: { hidden: false },
      root: { querySelector: vi.fn(() => null) },
    };
    app.worker = worker;
    app.post = vi.fn();
    app.toast = vi.fn();
    app.setState = vi.fn();
    app.teardownMse = vi.fn();
    app.detach = vi.fn();
    app.clearSecurityState = vi.fn();
    app.resetPermissions = vi.fn();
    app.blockInputOn = false;
    app.blockInputPending = false;
    app.privacyModeOn = false;
    app.privacyModePending = false;

    app.beginRemoteRestart();
    app.restartFlow.reconnecting = true;
    app.state = 'connecting';
    vi.advanceTimersByTime(120_000);

    expect(app.worker).toBeUndefined();
    expect(worker.onmessage).toBeNull();
    expect(worker.onerror).toBeNull();
    expect(terminate).toHaveBeenCalledOnce();
    vi.advanceTimersByTime(250);
    expect(terminate).toHaveBeenCalledOnce();
  });

  it('ends an in-flight restart reconnect at the 120-second hard deadline', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.state = 'streaming';
    app.reconnectConfig = config;
    app.sessionHashHex = config.savedHashHex;
    app.el = { reconnectCancel: { hidden: true } };
    app.post = vi.fn();
    app.toast = vi.fn();
    app.setState = vi.fn();

    app.beginRemoteRestart();
    app.state = 'connecting';
    vi.advanceTimersByTime(120_000);

    expect(app.restartFlow).toBeUndefined();
    expect(app.setState).toHaveBeenCalledWith(
      'error',
      'The remote device did not return within 120 seconds after the restart request',
    );
  });
});

describe('RdApp 2FA prompt', () => {
  const el = (extra: Record<string, unknown> = {}) => ({
    hidden: false, disabled: false, value: '', textContent: '', focus: vi.fn(),
    classList: { add: vi.fn(), remove: vi.fn(), toggle: vi.fn() }, ...extra,
  });

  it('asks for the code on the open connection instead of tearing it down', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.fixedPeerId = '123456789';
    app.worker = { postMessage: vi.fn() };
    app.displays = [];
    app.el = {
      overlay: el(), connectBtn: el(), peerIdInput: el(), passwordInput: el(), passwordRow: el(),
      saveRow: el(), fieldId: el({ hidden: true }), twoFactorRow: el({ hidden: true }), twoFactorInput: el(),
      twoFactorBtn: el(), overlayStatus: el(), overlayStatusText: el(), overlayError: el({ hidden: true }),
    };
    app.teardown = vi.fn();

    app.onEvent({ t: 'twoFactor', wrong: false });
    expect(app.teardown).not.toHaveBeenCalled();
    expect(app.el.twoFactorRow.hidden).toBe(false);
    expect(app.el.passwordRow.hidden).toBe(true);

    app.el.twoFactorInput.value = '123456';
    app.submitTwoFactor();
    expect(app.worker.postMessage).toHaveBeenCalledWith({ c: 'auth2fa', code: '123456' });

    app.onEvent({ t: 'twoFactor', wrong: true });
    expect(app.el.overlayError.textContent).toBe('Wrong 2FA code');
  });
});
