import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import {
  QUALITY,
  STATE_LABEL,
  ICONS,
  PERMISSION_CONTROLS,
  applySwitchDisplay,
  buildSessionConfig,
  newSessionId,
  buildKeyPressCommands,
  buildTypeCommands,
  cursorCss,
  debugEnabled,
  displayToRect,
  formatDuration,
  formatMbps,
  iconHtml,
  loggedOutFromSearch,
  normalizePeerId,
  peerIdFromSearch,
  resolveWorkerUrl,
  type IconName,
  type RdGlobalConfig,
} from './app';
import type { DisplayInfo, SessionState } from '../core/contracts';

describe('formatDuration', () => {
  it('formats sub-hour as mm:ss', () => {
    expect(formatDuration(0)).toBe('00:00');
    expect(formatDuration(999)).toBe('00:00');
    expect(formatDuration(61_000)).toBe('01:01');
    expect(formatDuration(59 * 60_000 + 59_000)).toBe('59:59');
  });

  it('formats hour+ as h:mm:ss', () => {
    expect(formatDuration(3_600_000)).toBe('1:00:00');
    expect(formatDuration(3_661_000)).toBe('1:01:01');
    expect(formatDuration(10 * 3_600_000 + 5_000)).toBe('10:00:05');
  });

  it('clamps negative to zero', () => {
    expect(formatDuration(-5000)).toBe('00:00');
  });
});

describe('formatMbps', () => {
  it('scales precision with magnitude', () => {
    expect(formatMbps(0)).toBe('0.00 Mbps');
    expect(formatMbps(3.456)).toBe('3.46 Mbps');
    expect(formatMbps(12.34)).toBe('12.3 Mbps');
    expect(formatMbps(123.4)).toBe('123 Mbps');
  });

  it('sanitizes NaN/negative/Infinity', () => {
    expect(formatMbps(Number.NaN)).toBe('0.00 Mbps');
    expect(formatMbps(-1)).toBe('0.00 Mbps');
    expect(formatMbps(Number.POSITIVE_INFINITY)).toBe('0.00 Mbps');
  });
});

describe('peerIdFromSearch', () => {
  it('extracts and trims id', () => {
    expect(peerIdFromSearch('?id=123456789')).toBe('123456789');
    expect(peerIdFromSearch('?foo=1&id=%20abc%20')).toBe('abc');
  });

  it('returns null when absent or empty', () => {
    expect(peerIdFromSearch('')).toBeNull();
    expect(peerIdFromSearch('?foo=1')).toBeNull();
    expect(peerIdFromSearch('?id=')).toBeNull();
    expect(peerIdFromSearch('?id=%20%20')).toBeNull();
  });
});

describe('loggedOutFromSearch', () => {
  it('is true only for lo=1', () => {
    expect(loggedOutFromSearch('?id=123&lo=1')).toBe(true);
    expect(loggedOutFromSearch('?lo=1')).toBe(true);
    expect(loggedOutFromSearch('?id=123')).toBe(false);
    expect(loggedOutFromSearch('?lo=0')).toBe(false);
    expect(loggedOutFromSearch('')).toBe(false);
  });
});

describe('resolveWorkerUrl', () => {
  it('prefers explicit config url', () => {
    expect(resolveWorkerUrl('/x/w.js', '/y/w.js', 'https://h/rdclient/app.js')).toBe('/x/w.js');
  });

  it('falls back to script data attribute', () => {
    expect(resolveWorkerUrl(undefined, '/rdclient/session.worker.js', 'https://h/a/app.js')).toBe(
      '/rdclient/session.worker.js',
    );
  });

  it('defaults to session.worker.js next to app.js', () => {
    expect(resolveWorkerUrl(undefined, null, 'https://h/rdclient/app.js')).toBe(
      'https://h/rdclient/session.worker.js',
    );
    expect(resolveWorkerUrl('', undefined, 'https://h/deep/path/app.js')).toBe(
      'https://h/deep/path/session.worker.js',
    );
  });
});

describe('cursorCss', () => {
  it('builds a css cursor with hotspot and auto fallback', () => {
    expect(cursorCss('data:image/png;base64,AAA=', 3, 5)).toBe(
      'url("data:image/png;base64,AAA=") 3 5, auto',
    );
  });

  it('clamps negative and rounds fractional hotspots', () => {
    expect(cursorCss('data:x', -2, 4.6)).toBe('url("data:x") 0 5, auto');
  });
});

describe('newSessionId', () => {
  it('is a decimal id below 2^63, fresh each time', () => {
    const a = newSessionId();
    expect(a).toMatch(/^\d+$/);
    expect(BigInt(a) < 2n ** 63n).toBe(true);
    expect(newSessionId()).not.toBe(a);
  });
});

describe('buildSessionConfig', () => {
  it('maps global config + peer id + password to SessionConfig', () => {
    const g: RdGlobalConfig = {
      serverKeyB64: 'k'.repeat(43) + '=',
      wsIdUrl: 'wss://s/ws/id',
      wsRelayUrl: 'wss://s/ws/relay',
      myId: 'web-1',
      myName: 'CortenDesk',
      workerUrl: '/rdclient/session.worker.js',
    };
    expect(buildSessionConfig(g, '987654321', 'pw')).toEqual({
      peerId: '987654321',
      serverKeyB64: g.serverKeyB64,
      wsIdUrl: g.wsIdUrl,
      wsRelayUrl: g.wsRelayUrl,
      password: 'pw',
      myId: 'web-1',
      myName: 'CortenDesk',
    });
  });
});

describe('displayToRect', () => {
  it('projects DisplayInfo to the input mapper rect', () => {
    const d: DisplayInfo = { index: 1, x: -1920, y: 0, width: 1920, height: 1080, name: 'DP-1', scale: 1, online: true, cursorEmbedded: false, resolutions: [] };
    expect(displayToRect(d)).toEqual({ x: -1920, y: 0, width: 1920, height: 1080 });
  });
});

describe('QUALITY', () => {
  it('matches RustDesk ImageQuality enum values', () => {
    expect(QUALITY.best).toBe(4);
    expect(QUALITY.balanced).toBe(3);
    expect(QUALITY.speed).toBe(2);
  });
});

describe('STATE_LABEL', () => {
  it('covers every SessionState', () => {
    const states: SessionState[] = [
      'connecting',
      'rendezvous',
      'relay',
      'handshake',
      'login',
      'streaming',
      'error',
      'closed',
      'needAccept',
    ];
    for (const s of states) {
      expect(STATE_LABEL[s]).toBeTruthy();
    }
  });
});

describe('iconHtml', () => {
  it('renders inline SVG fallback outside the browser (no Remix font)', () => {
    for (const name of Object.keys(ICONS) as IconName[]) {
      const html = iconHtml(name);
      expect(html).toContain('<svg');
      expect(html).toContain('stroke="currentColor"');
      expect(html).toContain('aria-hidden="true"');
    }
  });
});

describe('PERMISSION_CONTROLS', () => {
  it('maps the peer permissions that gate a toolbar control', () => {
    // Names must match PermissionInfo_Permission in the generated protobuf; a
    // rename there silently stops the gating from ever firing.
    expect(PERMISSION_CONTROLS.File.id).toBe('rd-btn-files');
    expect(PERMISSION_CONTROLS.Clipboard.id).toBe('rd-btn-clip');
    expect(PERMISSION_CONTROLS.Keyboard.id).toBe('rd-btn-cad');
  });

  it('leaves ungated permissions out rather than mapping them to nothing', () => {
    for (const kind of ['Audio', 'Restart', 'Recording', 'BlockInput', 'PrivacyMode']) {
      expect(PERMISSION_CONTROLS[kind]).toBeUndefined();
    }
  });
});

describe('secure context requirement', () => {
  // Issue #3: over plain http the first symptom was
  // "Cannot read properties of undefined (reading 'digest')", because
  // crypto.subtle is absent outside a secure context.
  //
  // Both original blockers are now gone: the login hash is vendored
  // (core/sha256.ts), and video falls back to Media Source Extensions, which
  // is not secure-context gated. These tests pin the CURRENT contract — an
  // earlier version asserted the source still mentioned crypto.subtle, which
  // kept passing off a doc comment after the dependency itself had gone.
  it('no longer depends on crypto.subtle anywhere in the client source', () => {
    for (const f of ['../core/auth.ts', '../core/session.ts', '../core/crypto.ts']) {
      const source = readFileSync(new URL(f, import.meta.url), 'utf8');
      const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
      expect(code, f).not.toContain('crypto.subtle');
    }
  });

  it('gates on decode capability, not on the origin being secure', () => {
    const source = readFileSync(new URL('./app.ts', import.meta.url), 'utf8');
    // The guard must consult both paths before refusing.
    expect(source).toContain('VideoDecoder');
    expect(source).toContain('mseH264Available');
    // The MSE path is not announced in the UI — it looks the same to the
    // operator — but it must remain a real branch, not a claim.
    expect(source).toContain('mse-video');
  });

  it('checks capability before connecting, not only on load', () => {
    const source = readFileSync(new URL('./app.ts', import.meta.url), 'utf8');
    const calls = source.match(/secureContextProblem\(\)/g) ?? [];

    // definition + on-load check + connect check
    expect(calls.length).toBeGreaterThanOrEqual(3);
  });
});

describe('buildTypeCommands', () => {
  it('sends runs of text as one seq command, whatever the script', () => {
    expect(buildTypeCommands('aé 🙂')).toEqual([{ c: 'text', text: 'aé 🙂' }]);
  });

  it('maps newline and tab to control keys and drops carriage returns', () => {
    expect(buildTypeCommands('a\r\n\tb')).toEqual([
      { c: 'text', text: 'a' },
      { c: 'key', down: false, press: true, keyKind: 'control', value: 27, modifiers: [] }, // Return — \r of the CRLF pair must not double it
      { c: 'key', down: false, press: true, keyKind: 'control', value: 31, modifiers: [] }, // Tab
      { c: 'text', text: 'b' },
    ]);
  });
});

describe('buildKeyPressCommands', () => {
  it('sends ASCII as chr presses and non-ASCII as unicode', () => {
    expect(buildKeyPressCommands('aé')).toEqual([
      { c: 'key', down: false, press: true, keyKind: 'chr', value: 97, modifiers: [] },
      { c: 'key', down: false, press: true, keyKind: 'unicode', value: 0xe9, modifiers: [] },
    ]);
  });

  it('maps newline and tab to control keys and drops carriage returns', () => {
    const kinds = buildKeyPressCommands('a\r\n\tb').map((c) => (c.c === 'key' ? [c.keyKind, c.value] : null));
    expect(kinds).toEqual([
      ['chr', 97],
      ['control', 27],
      ['control', 31],
      ['chr', 98],
    ]);
  });

  it('handles astral code points as single unicode presses', () => {
    const cmds = buildKeyPressCommands('🙂');
    expect(cmds).toHaveLength(1);
    expect(cmds[0]).toMatchObject({ keyKind: 'unicode', value: 0x1f642 });
  });
});

describe('applySwitchDisplay', () => {
  const mk = (): DisplayInfo[] => [
    { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'Primary', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
    { index: 1, x: 1920, y: 0, width: 1920, height: 1080, name: 'Second', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
  ];

  it('writes the origin the host reports', () => {
    const d = mk();
    applySwitchDisplay(d, { index: 1, x: 2560, y: -120, width: 3840, height: 2160 });
    expect(d[1]).toMatchObject({ x: 2560, y: -120, width: 3840, height: 2160 });
  });

  it('leaves the other displays untouched', () => {
    const d = mk();
    applySwitchDisplay(d, { index: 1, x: 2560, y: 0, width: 3840, height: 2160 });
    expect(d[0]).toMatchObject({ x: 0, y: 0, width: 1920, height: 1080 });
  });

  it('treats zero width/height as "unchanged", not as a collapsed display', () => {
    // A 0 here would make the coordinate mapping scale against nothing while
    // the capture is still running at its real size.
    const d = mk();
    applySwitchDisplay(d, { index: 1, x: 1920, y: 0, width: 0, height: 0 });
    expect(d[1]).toMatchObject({ x: 1920, y: 0, width: 1920, height: 1080 });
  });

  it('ignores an index the display list does not have', () => {
    const d = mk();
    expect(() => applySwitchDisplay(d, { index: 7, x: 1, y: 2, width: 3, height: 4 })).not.toThrow();
    expect(d).toHaveLength(2);
  });

  it('keeps a negative origin, which a left/above monitor legitimately has', () => {
    const d = mk();
    applySwitchDisplay(d, { index: 0, x: -1920, y: -1080, width: 1920, height: 1080 });
    expect(d[0]).toMatchObject({ x: -1920, y: -1080 });
  });
});

describe('switched display feeds the coordinate mapping', () => {
  it('sends a centre click to the monitor the host says it is capturing', () => {
    // The bug this covers end to end: the click used to land on whichever
    // monitor the stale origin pointed at, so switching monitors appeared to
    // move the picture but not the mouse.
    const displays: DisplayInfo[] = [
      { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'A', scale: 1, online: true, cursorEmbedded: false, resolutions: [] },
      { index: 1, x: 0, y: 0, width: 1920, height: 1080, name: 'B', scale: 1, online: true, cursorEmbedded: false, resolutions: [] }, // stale: origin not yet known
    ];
    applySwitchDisplay(displays, { index: 1, x: 1920, y: 0, width: 1920, height: 1080 });
    const rect = displayToRect(displays[1]!);
    expect(rect.x).toBe(1920);
    // A click at the centre of display 1 must be past the primary's width,
    // i.e. on the second monitor rather than the middle of the first.
    const centreX = rect.x + rect.width / 2;
    expect(centreX).toBe(2880);
    expect(centreX).toBeGreaterThan(displays[0]!.width);
  });
});

describe('normalizePeerId', () => {
  it('strips the grouping spaces the RustDesk client displays', () => {
    // "123 456 789" is how the client shows an ID, so it is what people copy.
    expect(normalizePeerId('123 456 789')).toBe('123456789');
  });

  it('strips leading and trailing whitespace', () => {
    expect(normalizePeerId('  123456789  ')).toBe('123456789');
    expect(normalizePeerId('\t123 456 789\n')).toBe('123456789');
  });

  it('strips the non-breaking space that copying from a rendered UI produces', () => {
    // Written as an escape on purpose: a literal U+00A0 here is invisible,
    // and an editor normalising it would silently turn this into a copy of
    // the plain-space test above while still passing.
    expect(normalizePeerId('123\u00A0456\u00A0789')).toBe('123456789');
  });

  it('leaves a clean id untouched', () => {
    expect(normalizePeerId('123456789')).toBe('123456789');
  });

  it('preserves non-numeric ids, which custom IDs may be', () => {
    // Only whitespace is removed: a device can be given an alphanumeric ID,
    // and stripping anything broader would break it.
    expect(normalizePeerId('my desk-01')).toBe('mydesk-01');
    expect(normalizePeerId('office_pc')).toBe('office_pc');
  });

  it('returns empty for whitespace-only input', () => {
    expect(normalizePeerId('   ')).toBe('');
  });
});

describe('debugEnabled', () => {
  it('is off by default', () => {
    expect(debugEnabled('', {})).toBe(false);
    expect(debugEnabled('?id=123', {})).toBe(false);
  });

  it('turns on from the query string', () => {
    expect(debugEnabled('?debug=1', {})).toBe(true);
    expect(debugEnabled('?debug=true', {})).toBe(true);
    expect(debugEnabled('?id=1&debug=1', {})).toBe(true);
  });

  it('turns on from a runtime flag, so a live session can be instrumented', () => {
    expect(debugEnabled('', { __rdDebug: true })).toBe(true);
  });

  it('requires the flag to be exactly true, not merely truthy', () => {
    // Guards against a stray assignment of a string or object switching on
    // per-event logging for someone who never asked for it.
    expect(debugEnabled('', { __rdDebug: 'yes' })).toBe(false);
    expect(debugEnabled('', { __rdDebug: 1 })).toBe(false);
    expect(debugEnabled('?debug=0', {})).toBe(false);
  });
});
