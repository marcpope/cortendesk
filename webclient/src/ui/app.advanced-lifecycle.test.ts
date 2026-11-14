import { afterEach, describe, expect, it, vi } from 'vitest';
import { RdApp } from './app';

afterEach(() => vi.unstubAllGlobals());

describe('advanced-mode capability and lifecycle gates', () => {
  it('destroys independent terminal and camera sessions with the primary session', () => {
    const terminal = { destroy: vi.fn() };
    const camera = { destroy: vi.fn() };
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.terminalPanel = terminal;
    app.cameraPanel = camera;

    app.destroyAdvancedPanels();

    expect(terminal.destroy).toHaveBeenCalledOnce();
    expect(camera.destroy).toHaveBeenCalledOnce();
    expect(app.terminalPanel).toBeUndefined();
    expect(app.cameraPanel).toBeUndefined();
  });

  it('destroys advanced sessions on a primary closed state', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.state = 'streaming';
    app.el = {
      root: { dataset: {} },
      peerSub: { textContent: '' },
      toast: { classList: { remove: vi.fn() } },
    };
    app.refreshPeerSub = vi.fn();
    app.teardown = vi.fn();
    app.destroyAdvancedPanels = vi.fn();
    app.closeSide = vi.fn();
    app.closePop = vi.fn();
    app.showOverlay = vi.fn();
    app.setOverlayBusy = vi.fn();
    app.setOverlayStatusText = vi.fn();

    app.setState('closed');

    expect(app.destroyAdvancedPanels).toHaveBeenCalledOnce();
  });

  it('uses explicit peer capabilities rather than platform guesses', () => {
    vi.stubGlobal('document', { title: '' });
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.peerId = '123';
    app.platformAdditions = '';
    app.dbg = vi.fn();
    app.refreshPeerSub = vi.fn();
    app.el = {
      peerLabel: { textContent: '' },
      statVersion: { textContent: '' },
      statUser: { textContent: '' },
      statPlatform: { textContent: '' },
      btnMonitors: { hidden: false },
      remoteCursor: { hidden: false },
    };

    app.onEvent({
      t: 'peerInfo',
      displays: [],
      username: '',
      hostname: 'peer',
      platform: 'unknown',
      platformAdditions: '',
      version: '1.4.1',
      current: 0,
      privacyModeSupported: false,
      privacyModeImpls: [],
      terminalSupported: true,
      viewCameraSupported: true,
    });

    expect(app.terminalSupported).toBe(true);
    expect(app.cameraSupported).toBe(true);
  });
});
