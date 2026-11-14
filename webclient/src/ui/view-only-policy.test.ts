import { describe, expect, it, vi } from 'vitest';
import { RdApp } from './app';
import { remoteInputAllowed } from './view-only-policy';

describe('view-only remote input policy', () => {
  it.each(['pointer', 'keyboard', 'clipboard', 'terminal'] as const)(
    'blocks the %s channel while view-only mode is enabled',
    (channel) => {
      expect(remoteInputAllowed(true, channel)).toBe(false);
    },
  );

  it.each(['pointer', 'keyboard', 'clipboard', 'terminal'] as const)(
    'allows the %s channel when view-only mode is disabled',
    (channel) => {
      expect(remoteInputAllowed(false, channel)).toBe(true);
    },
  );

  it('rejects a direct terminal-open attempt in view-only mode', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.viewOnly = true;
    app.terminalSupported = true;
    app.state = 'streaming';
    app.terminalPanel = undefined;
    app.toast = vi.fn();
    app.el = { root: {} };

    expect(() => app.openTerminalPanel()).not.toThrow();
    expect(app.terminalPanel).toBeUndefined();
    expect(app.toast).toHaveBeenCalledWith('Remote terminal is unavailable in view-only mode');
  });

  it('blocks clipboard text at the worker command boundary', () => {
    const app = Object.create(RdApp.prototype) as any;
    const postMessage = vi.fn();
    app.viewOnly = true;
    app.worker = { postMessage };
    app.displays = [];
    app.current = 0;
    app.latchCtrl = false;
    app.latchAlt = false;

    app.post({ c: 'clipboardText', text: 'must not leave the browser' });

    expect(postMessage).not.toHaveBeenCalled();
  });

  it('closes an active terminal when view-only mode is enabled', () => {
    const app = Object.create(RdApp.prototype) as any;
    const destroy = vi.fn();
    app.viewOnly = false;
    app.terminalPanel = { destroy };
    app.el = {
      btnViewOnly: {
        setAttribute: vi.fn(),
        classList: { toggle: vi.fn() },
      },
    };
    app.setLatches = vi.fn();
    app.xkeys = { clearLatches: vi.fn() };
    app.removeClipboardSyncOffer = vi.fn();
    app.toast = vi.fn();

    app.toggleViewOnly();

    expect(destroy).toHaveBeenCalledOnce();
    expect(app.terminalPanel).toBeUndefined();
  });
});
