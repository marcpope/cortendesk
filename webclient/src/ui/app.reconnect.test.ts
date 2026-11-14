import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DROP_RECONNECT_TIMEOUT_MS } from '../core/reconnect';
import { RESTART_RECONNECT_TIMEOUT_MS } from '../core/session-controls';
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

/** An RdApp with just enough wired up to run the reconnect flows. */
function makeApp(): any {
  const app = Object.create(RdApp.prototype) as any;
  app.state = 'streaming';
  app.peerPlatform = '';
  app.streamStartMs = 0;
  app.reconnectConfig = config;
  app.view = { view: { scale: 1, x: 0, y: 0 } };
  app.el = {
    root: { dataset: {} },
    peerSub: { textContent: '' },
    reconnectCancel: { hidden: true },
  };
  app.canvas = { focus: vi.fn() };
  app.kbdSink = { focus: vi.fn() };
  for (const m of [
    'teardown', 'clearSecurityState', 'closeSide', 'closePop', 'showOverlay', 'setOverlayBusy',
    'setOverlayStatusText', 'dbg', 'toast', 'persistCredentialIfWanted', 'maybeShowHomeScreenHint',
    'hideOverlay', 'showClipboardSyncOffer', 'softKeyboardFocused', 'startSession', 'hideTwoFactor',
    'destroyAdvancedPanels', 'setOverlayError',
  ]) {
    app[m] = vi.fn();
  }
  return app;
}

describe('RdApp reconnect deadline', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(0);
    vi.stubGlobal('window', { confirm: vi.fn(() => true) });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  it('keeps a drop reconnect that succeeds just before the deadline', () => {
    const app = makeApp();
    app.beginDropReconnect('lost');
    vi.advanceTimersByTime(1_000); // first retry starts a session
    expect(app.startSession).toHaveBeenCalledOnce();

    vi.advanceTimersByTime(DROP_RECONNECT_TIMEOUT_MS - 6_000); // the attempt is slow
    app.setState('streaming');
    vi.advanceTimersByTime(10_000); // past the deadline, inside the 15 s stable window

    expect(app.teardown).toHaveBeenCalledOnce(); // only the one before the retry
    expect(app.state).toBe('streaming');
    expect(app.restartFlow?.reconnecting).toBe(false);
    vi.advanceTimersByTime(5_000);
    expect(app.restartFlow).toBeUndefined();
    expect(app.state).toBe('streaming');
  });

  it('keeps a restart reconnect that succeeds just before the deadline', () => {
    const app = makeApp();
    app.post = vi.fn();
    app.sessionHashHex = undefined;
    app.beginRemoteRestart();
    app.restartFlow.reconnecting = true; // the device went away and a retry is running
    app.state = 'connecting';

    vi.advanceTimersByTime(RESTART_RECONNECT_TIMEOUT_MS - 5_000);
    app.setState('streaming');
    vi.advanceTimersByTime(15_000);

    expect(app.teardown).not.toHaveBeenCalled();
    expect(app.state).toBe('streaming');
    expect(app.restartFlow).toBeUndefined();
    expect(app.toast).toHaveBeenLastCalledWith('Connection restored after the restart request');
  });
});
