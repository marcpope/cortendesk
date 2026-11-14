import { afterEach, describe, expect, it, vi } from 'vitest';
import { RdApp } from './app';

afterEach(() => vi.unstubAllGlobals());

describe('display event lifecycle', () => {
  it('follows host display notifications when cursor-follow is enabled', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.current = 0;
    app.followRemoteCursor = true;
    app.followRemoteWindow = false;
    app.displays = [
      { online: true },
      { online: true },
    ];
    app.post = vi.fn();

    app.onEvent({ t: 'followDisplay', index: 1 });

    expect(app.post).toHaveBeenCalledWith({ c: 'switchDisplay', index: 1 });
  });

  it('preserves stable platform capability across display-list refreshes', () => {
    vi.stubGlobal('document', { title: '' });
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.current = 0;
    app.displays = [];
    app.peerId = '123';
    app.platformAdditions = '{"is_installed":true,"idd_impl":"rustdesk_idd","rustdesk_virtual_displays":[1]}';
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
      t: 'peerInfo', displays: [], username: '', hostname: 'peer', platform: 'Windows',
      platformAdditions: '{"idd_impl":"rustdesk_idd"}', version: '1.4.1', current: undefined,
    });

    expect(JSON.parse(app.platformAdditions)).toEqual({ is_installed: true, idd_impl: 'rustdesk_idd' });
  });

  it('hides a visible cursor immediately when refreshed metadata invalidates it', () => {
    vi.stubGlobal('document', { title: '' });
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.current = 1;
    app.displays = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 1, x: 1920, y: 0, width: 1920, height: 1080, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
    ];
    app.peerId = '123';
    app.dbg = vi.fn();
    app.refreshPeerSub = vi.fn();
    app.el = {
      peerLabel: { textContent: '' }, statVersion: { textContent: '' }, statUser: { textContent: '' },
      statPlatform: { textContent: '' }, btnMonitors: { hidden: false }, remoteCursor: { hidden: false },
    };

    app.onEvent({
      t: 'peerInfo',
      displays: [{ index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] }],
      username: '', hostname: 'peer', platform: 'Windows', platformAdditions: '{}', version: '1.4.1', current: undefined,
    });

    expect(app.el.remoteCursor.hidden).toBe(true);
  });

  it('fails closed for input when the active display disappears', () => {
    vi.stubGlobal('document', { title: '' });
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.current = 1;
    app.displays = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 1, x: 1920, y: 0, width: 1600, height: 900, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
    ];
    app.peerId = '123';
    app.viewOnly = false;
    app.latchCtrl = false;
    app.latchAlt = false;
    app.lastDbgMouseMs = 0;
    app.worker = { postMessage: vi.fn() };
    app.dbg = vi.fn();
    app.refreshPeerSub = vi.fn();
    app.el = {
      peerLabel: { textContent: '' }, statVersion: { textContent: '' }, statUser: { textContent: '' },
      statPlatform: { textContent: '' }, btnMonitors: { hidden: false }, remoteCursor: { hidden: false },
    };

    app.onEvent({
      t: 'peerInfo',
      displays: [{ index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] }],
      username: '', hostname: 'peer', platform: 'Windows', platformAdditions: '{}', version: '1.4.1', current: undefined,
    });
    app.post({ c: 'key', down: true, press: false, keyKind: 'chr', value: 65, modifiers: [] });

    expect(app.displays[1]).toBeUndefined();
    expect(app.worker.postMessage).not.toHaveBeenCalled();
  });

  it('does not replace the authoritative active display from a display-list refresh', () => {
    vi.stubGlobal('document', { title: '' });
    const app = Object.create(RdApp.prototype) as any;
    app.resetView = app.updateView = () => {}; // peerInfo queues these; no viewport here
    app.current = 2;
    app.displays = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 1, x: 1920, y: 0, width: 1920, height: 1080, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 2, x: 4000, y: 100, width: 1600, height: 900, name: 'Third', scale: 1, online: true, cursorEmbedded: true, originalResolution: { width: 1920, height: 1080 }, resolutions: [{ width: 1600, height: 900 }] },
    ];
    app.peerId = '123';
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
      displays: [
        { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
        { index: 1, x: 1920, y: 0, width: 1920, height: 1080, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
        { index: 2, x: 3840, y: 0, width: 1920, height: 1080, name: 'Third', scale: 1, online: true, cursorEmbedded: false, originalResolution: { width: 2560, height: 1440 }, resolutions: [{ width: 1920, height: 1080 }] },
      ],
      username: '',
      hostname: 'peer',
      platform: 'Windows',
      platformAdditions: '{}',
      version: '1.4.1',
      current: undefined,
    });

    expect(app.current).toBe(2);
    expect(app.displays[2]).toMatchObject({
      x: 4000,
      y: 100,
      width: 1600,
      height: 900,
      cursorEmbedded: true,
      originalResolution: { width: 1920, height: 1080 },
      resolutions: [{ width: 1600, height: 900 }],
    });
  });
});
