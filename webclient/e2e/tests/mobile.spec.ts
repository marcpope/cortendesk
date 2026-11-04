import { expect, test } from '@playwright/test';
import { TYPE, clearCmds, clientFor, cmds, connect, mouse, touch } from './helpers';

test.beforeEach(async ({ page }, info) => {
  test.skip(info.project.name === 'desktop', 'phone / tablet only');
  // The phone UI (docked bar, on-screen keyboard...) ships in the release after 1.20.0. Against an
  // older bundle these specs skip instead of failing.
  const js = await (await page.request.get('/rdclient/app.js')).text();
  test.skip(!js.includes('rd-mbar'), 'this bundle has no phone UI yet');
});

test('phone layout: docked controls replace the top bar and dock, never cover the screen', async ({ page }, info) => {
  await connect(page);
  await expect(page.locator('#rd-root')).toHaveClass(/rd-mobile/);
  await expect(page.locator('#rd-mbar')).toBeVisible();
  await expect(page.locator('#rd-root')).toHaveAttribute('data-bar', 'bottom');
  const noOverlap = () => page.evaluate(() => {
    const a = document.querySelector('#rd-mbar')!.getBoundingClientRect();
    const b = document.querySelector('#rd-viewport')!.getBoundingClientRect();
    return a.right <= b.left + 0.5 || a.left >= b.right - 0.5 || a.bottom <= b.top + 0.5 || a.top >= b.bottom - 0.5;
  });
  expect(await noOverlap()).toBe(true);
  await expect(page.locator('#rd-toolbar')).toBeHidden();
  await expect(page.locator('#rd-dock')).toBeHidden();
  const overscroll = await page.evaluate(() => getComputedStyle(document.body).overscrollBehaviorY);
  if (overscroll !== undefined) expect(overscroll).toBe('none'); // not exposed by every WebKit build
  await page.screenshot({ path: info.outputPath('portrait.png') });
  const vp = page.viewportSize()!;
  await page.setViewportSize({ width: vp.height, height: vp.width });
  await page.waitForTimeout(300);
  await expect(page.locator('#rd-root')).toHaveAttribute('data-bar', 'right');
  expect(await noOverlap()).toBe(true);
  await expect(page.locator('#rd-mbar [data-mbar="disconnect"]')).toBeInViewport();
  await expect(page.locator('#rd-mbar [data-mbar="more"]')).toBeInViewport();
  await page.screenshot({ path: info.outputPath('landscape.png') });
});

test('real tap clicks where the finger is (MOVE before DOWN)', async ({ page }) => {
  await connect(page);
  const p = await clientFor(page, 960, 540);
  await page.touchscreen.tap(p.x, p.y);
  await expect.poll(() => mouse(page)).toHaveLength(3);
  const m = await mouse(page);
  expect(m.map((c) => [c[0], c[1]])).toEqual([[TYPE.MOVE, 0], [TYPE.DOWN, 1], [TYPE.UP, 1]]);
  expect(Math.abs(m[1]![2]! - 960)).toBeLessThanOrEqual(4);
  expect(Math.abs(m[1]![3]! - 540)).toBeLessThanOrEqual(4);
});

test('two-finger tap and long press right-click', async ({ page }) => {
  await connect(page);
  const a = await clientFor(page, 400, 400);
  const b = await clientFor(page, 600, 400);
  await touch(page, [['pointerdown', 1, a.x, a.y], ['pointerdown', 2, b.x, b.y], ['pointerup', 2, b.x, b.y], ['pointerup', 1, a.x, a.y]]);
  let m = await mouse(page);
  expect(m.map((c) => [c[0], c[1]])).toEqual([[TYPE.MOVE, 0], [TYPE.DOWN, 2], [TYPE.UP, 2]]);
  await clearCmds(page);
  await touch(page, [['pointerdown', 3, a.x, a.y]]);
  await page.waitForTimeout(650);
  await touch(page, [['pointerup', 3, a.x, a.y]]);
  m = await mouse(page);
  expect(m.map((c) => [c[0], c[1]])).toEqual([[TYPE.MOVE, 0], [TYPE.DOWN, 2], [TYPE.UP, 2]]);
});

test('drag, and a cancelled drag releases the button; the next tap still works', async ({ page }) => {
  await connect(page);
  const a = await clientFor(page, 200, 200);
  const b = await clientFor(page, 800, 600);
  await touch(page, [['pointerdown', 1, a.x, a.y], ['pointermove', 1, a.x + 30, a.y + 30], ['pointermove', 1, b.x, b.y], ['pointercancel', 1, b.x, b.y]]);
  const m = await mouse(page);
  expect(m[1]!.slice(0, 2)).toEqual([TYPE.DOWN, 1]);
  expect(m.at(-1)!.slice(0, 2)).toEqual([TYPE.UP, 1]);
  await clearCmds(page);
  await touch(page, [['pointerdown', 2, a.x, a.y], ['pointerup', 2, a.x, a.y]]);
  expect((await mouse(page)).map((c) => c[0])).toEqual([TYPE.MOVE, TYPE.DOWN, TYPE.UP]);
});

test('two-finger drag scrolls', async ({ page }) => {
  await connect(page);
  const a = await clientFor(page, 800, 300);
  const b = await clientFor(page, 1100, 300);
  const steps: [string, number, number, number][] = [['pointerdown', 1, a.x, a.y], ['pointerdown', 2, b.x, b.y]];
  for (let dy = 10; dy <= 160; dy += 10) steps.push(['pointermove', 1, a.x, a.y + dy], ['pointermove', 2, b.x, b.y + dy]);
  steps.push(['pointerup', 1, a.x, a.y + 160], ['pointerup', 2, b.x, b.y + 160]);
  await touch(page, steps, 0);
  const wheels = (await mouse(page)).filter((c) => c[0] === TYPE.WHEEL);
  expect(wheels.length).toBeGreaterThanOrEqual(3);
  expect(wheels.every((w) => w[3]! > 0)).toBe(true); // fingers down = scroll up
});

test('pinch zooms the remote screen, not the page; taps still map exactly', async ({ page }) => {
  await connect(page);
  const c = await clientFor(page, 960, 540);
  const steps: [string, number, number, number][] = [['pointerdown', 1, c.x - 30, c.y], ['pointerdown', 2, c.x + 30, c.y]];
  for (let d = 40; d <= 150; d += 10) steps.push(['pointermove', 1, c.x - d, c.y], ['pointermove', 2, c.x + d, c.y]);
  steps.push(['pointerup', 1, c.x - 150, c.y], ['pointerup', 2, c.x + 150, c.y]);
  await touch(page, steps, 0);
  const transform = await page.evaluate(() => document.querySelector<HTMLElement>('#rd-viewport canvas')!.style.transform);
  expect(transform).toMatch(/scale\((\d+(\.\d+)?)\)/);
  expect(Number(/scale\(([\d.]+)\)/.exec(transform)![1])).toBeGreaterThan(2);
  expect(await page.evaluate(() => window.visualViewport?.scale ?? 1)).toBe(1);
  await clearCmds(page);
  const p = await clientFor(page, 1000, 560);
  await touch(page, [['pointerdown', 5, p.x, p.y], ['pointerup', 5, p.x, p.y]]);
  const m = await mouse(page);
  expect(Math.abs(m[1]![2]! - 1000)).toBeLessThanOrEqual(2);
  expect(Math.abs(m[1]![3]! - 560)).toBeLessThanOrEqual(2);
});

test('on-screen keyboard: Android-style 229 + input sends Thaana and emoji as text', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="kbd"]').click();
  await expect(page.locator('#rd-kbd-sink')).toBeFocused();
  await page.evaluate(() => {
    const sink = document.querySelector<HTMLTextAreaElement>('#rd-kbd-sink')!;
    const type = (value: string, data: string) => {
      const kd = new KeyboardEvent('keydown', { key: 'Unidentified', bubbles: true, cancelable: true });
      Object.defineProperty(kd, 'keyCode', { value: 229 });
      sink.dispatchEvent(kd);
      sink.value = value;
      sink.dispatchEvent(new InputEvent('input', { inputType: 'insertText', data, bubbles: true }));
    };
    const base = sink.value;
    type(base + 'ދ', 'ދ');
    type(base + 'ދި', 'ި');
    type(base + 'ދި👍', '👍');
    // Backspace (Gboard edits the field)
    sink.value = base + 'ދި';
    sink.dispatchEvent(new InputEvent('input', { inputType: 'deleteContentBackward', bubbles: true }));
  });
  const c = (await cmds(page)).filter((x) => x.c === 'text' || x.c === 'key');
  expect(c.slice(0, 3)).toEqual([{ c: 'text', text: 'ދ' }, { c: 'text', text: 'ި' }, { c: 'text', text: '👍' }]);
  expect(c[3]).toMatchObject({ c: 'key', keyKind: 'control', press: true }); // Backspace
  // Tapping the remote screen keeps the keyboard open.
  const p = await clientFor(page, 500, 500);
  await touch(page, [['pointerdown', 9, p.x, p.y], ['pointerup', 9, p.x, p.y]]);
  await expect(page.locator('#rd-kbd-sink')).toBeFocused();
});

test('extra keys: Win+R reaches the host as Map-mode key down/up', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="xkeys"]').click();
  await expect(page.locator('#rd-xkeys')).toBeVisible();
  await page.locator('#rd-xkeys .rd-xk-page').click(); // F-keys
  await page.locator('#rd-xkeys .rd-xk-page').click(); // combos
  await page.locator('#rd-xkeys [data-xk="win-r"]').click();
  const keys = (await cmds(page)).filter((c) => c.c === 'key').map((c) => [c.keyKind, c.value, c.down]);
  expect(keys).toEqual([['map', 0xe05b, true], ['map', 0x13, true], ['map', 0x13, false], ['map', 0xe05b, false]]);
  await clearCmds(page);
  await page.locator('#rd-xkeys [data-xk="cad"]').click();
  expect(await cmds(page)).toEqual([{ c: 'ctrlAltDel' }]);
});

test('sticky Ctrl applies to the next click only', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="xkeys"]').click();
  await page.locator('#rd-xkeys [data-xk="ctrl"]').click();
  await expect(page.locator('#rd-xkeys [data-xk="ctrl"]')).toHaveAttribute('data-latch', 'once');
  const p = await clientFor(page, 300, 300);
  await touch(page, [['pointerdown', 1, p.x, p.y], ['pointerup', 1, p.x, p.y]]);
  const down = (await cmds(page)).find((c) => c.c === 'mouse' && ((c.mask as number) & 7) === TYPE.DOWN)!;
  expect(down.modifiers).toEqual([4]); // ControlKey.Control
  await expect(page.locator('#rd-xkeys [data-xk="ctrl"]')).toHaveAttribute('data-latch', 'off');
});

test('trackpad mode: slide moves a local cursor, tap clicks there', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="mode"]').click();
  await expect(page.locator('#rd-tp-cursor')).toBeVisible();
  const a = await clientFor(page, 960, 540);
  await touch(page, [['pointerdown', 1, a.x, a.y], ['pointermove', 1, a.x + 15, a.y], ['pointermove', 1, a.x + 30, a.y], ['pointerup', 1, a.x + 30, a.y]]);
  const moves = (await mouse(page)).filter((c) => c[0] === TYPE.MOVE);
  expect(moves.at(-1)![2]).toBeGreaterThan(960);
  await clearCmds(page);
  await touch(page, [['pointerdown', 2, 10, 10], ['pointerup', 2, 10, 10]]);
  await page.waitForTimeout(400);
  const m = await mouse(page);
  expect(m.map((c) => [c[0], c[1]])).toEqual([[TYPE.MOVE, 0], [TYPE.DOWN, 1], [TYPE.UP, 1]]);
  expect(m[1]![2]).toBeGreaterThan(960); // at the cursor, not at the finger (top-left)
});

test('bar side (per orientation) and input mode survive a reload', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="more"]').click();
  await page.locator('.rd-sheet [data-tile="swap"]').click();
  await expect(page.locator('#rd-root')).toHaveAttribute('data-bar', 'top');
  await page.locator('#rd-mbar [data-mbar="mode"]').click();
  await connect(page);
  await expect(page.locator('#rd-root')).toHaveAttribute('data-bar', 'top');
  await expect(page.locator('#rd-tp-cursor')).toBeVisible();
});

test('a dropped connection reconnects by itself and keeps the zoom', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="zoom"]').click();
  await expect(page.locator('#rd-mbar [data-mbar="zoom"]')).toHaveAttribute('aria-pressed', 'true');
  const zoomed = await page.evaluate(() => document.querySelector<HTMLElement>('#rd-viewport canvas')!.style.transform);
  expect(zoomed).toContain('scale');
  await page.evaluate(() => (window as unknown as { __worker: Worker }).__worker.postMessage({ c: '__drop' }));
  await expect(page.locator('#rd-overlay-status-text')).toBeVisible();
  await expect(page.locator('#rd-root')).toHaveAttribute('data-state', 'streaming', { timeout: 10_000 });
  expect(await page.evaluate(() => (window as unknown as { __connects: number }).__connects)).toBe(2);
  await page.waitForTimeout(200);
  expect(await page.evaluate(() => document.querySelector<HTMLElement>('#rd-viewport canvas')!.style.transform)).toBe(zoomed);
});

test('a close the host announced (close_reason) does not reconnect', async ({ page }) => {
  await connect(page);
  await page.evaluate(() => (window as unknown as { __worker: Worker }).__worker.postMessage({ c: '__peerclose' }));
  await expect(page.locator('#rd-root')).not.toHaveAttribute('data-state', 'streaming');
  await page.waitForTimeout(2500);
  expect(await page.evaluate(() => (window as unknown as { __connects: number }).__connects)).toBe(1);
});

test('landscape: compact keyboard (opt-in) types text and fits the picture above it', async ({ page }, info) => {
  test.skip(info.project.name === 'ipad', 'phone landscape');
  const vp = page.viewportSize()!;
  await page.setViewportSize({ width: Math.max(vp.width, vp.height), height: Math.min(vp.width, vp.height) });
  await page.addInitScript(() => localStorage.setItem('cortendesk.rdprefs.v1', JSON.stringify({ compactKeyboard: true })));
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="kbd"]').click();
  await expect(page.locator('#rd-ckbd')).toBeVisible();
  const h = await page.evaluate(() => document.querySelector('#rd-ckbd')!.getBoundingClientRect().height / window.innerHeight);
  expect(h).toBeLessThanOrEqual(0.45);
  await page.locator('#rd-ckbd button', { hasText: /^h$/ }).dispatchEvent('pointerdown');
  await page.locator('#rd-ckbd button', { hasText: /^i$/ }).dispatchEvent('pointerdown');
  await page.locator('#rd-ckbd .rd-ck-bksp').dispatchEvent('pointerdown');
  await page.locator('#rd-ckbd .rd-ck-bksp').dispatchEvent('pointerup');
  const c = (await cmds(page)).filter((x) => x.c === 'text' || x.c === 'key');
  expect(c.slice(0, 2)).toEqual([{ c: 'text', text: 'h' }, { c: 'text', text: 'i' }]);
  expect(c[2]).toMatchObject({ c: 'key', keyKind: 'control', press: true });
  await page.locator('#rd-ckbd .rd-ck-hide').dispatchEvent('pointerdown');
  await expect(page.locator('#rd-ckbd')).toBeHidden();
});

test('bar keeps only the essentials; the rest is in the More sheet', async ({ page }) => {
  await connect(page);
  const visible = await page.locator('#rd-mbar button:visible').evaluateAll((bs) => bs.map((b) => (b as HTMLElement).dataset.mbar));
  expect(visible).toEqual(['kbd', 'xkeys', 'mode', 'zoom', 'fullscreen', 'more', 'disconnect']); // one monitor: no monitor button
  for (const b of await page.locator('#rd-mbar button:visible').all()) {
    const box = (await b.boundingBox())!;
    expect(Math.min(box.width, box.height)).toBeGreaterThanOrEqual(38);
  }
  await page.locator('#rd-mbar [data-mbar="more"]').click();
  const sheet = page.locator('.rd-sheet');
  await expect(sheet).toBeVisible();
  await expect(sheet).toHaveAttribute('data-sheet', 'bottom');
  for (const id of ['clipboard', 'files', 'chat', 'details', 'viewonly', 'swap']) {
    await expect(sheet.locator(`[data-tile="${id}"]`)).toBeVisible();
  }
  await expect(sheet.locator('[data-action="opt:pointer"]')).toBeVisible();
  await expect(sheet.locator('[data-action="opt:suggestions"]')).toBeVisible();
  // The sheet does not cover the bar.
  const s = (await sheet.boundingBox())!;
  const bar = (await page.locator('#rd-mbar').boundingBox())!;
  expect(s.y + s.height).toBeLessThanOrEqual(bar.y + 1);
  await sheet.locator('[data-tile="viewonly"]').click();
  await page.locator('#rd-mbar [data-mbar="more"]').click();
  await expect(page.locator('.rd-sheet [data-tile="viewonly"]')).toHaveClass(/rd-on/);
});

test('landscape: the More sheet opens beside the bar', async ({ page }) => {
  const vp = page.viewportSize()!;
  await page.setViewportSize({ width: Math.max(vp.width, vp.height), height: Math.min(vp.width, vp.height) });
  await connect(page);
  await page.locator('#rd-mbar [data-mbar="more"]').click();
  await expect(page.locator('.rd-sheet')).toHaveAttribute('data-sheet', 'right');
  const s = (await page.locator('.rd-sheet').boundingBox())!;
  const bar = (await page.locator('#rd-mbar').boundingBox())!;
  expect(s.x + s.width).toBeLessThanOrEqual(bar.x + 1);
});

test('keyboard field asks for no suggestions by default; the option turns them back on', async ({ page }) => {
  await connect(page);
  const attrs = () => page.evaluate(() => {
    const t = document.querySelector('#rd-kbd-sink')!;
    return ['autocorrect', 'spellcheck', 'autocomplete', 'autocapitalize', 'writingsuggestions', 'inputmode'].map((a) => t.getAttribute(a));
  });
  expect(await attrs()).toEqual(['off', 'false', 'off', 'off', 'false', 'text']);
  await page.locator('#rd-mbar [data-mbar="more"]').click();
  await page.locator('.rd-sheet [data-action="opt:suggestions"]').click();
  expect(await attrs()).toEqual(['on', 'true', 'off', 'off', 'true', 'text']);
});

test('Fullscreen on the bar: toggles, or explains Add to Home Screen where there is no fullscreen', async ({ page }) => {
  await connect(page);
  const enabled = await page.evaluate(() => document.fullscreenEnabled === true);
  await expect(page.locator('.rd-sheet [data-tile="fullscreen"]')).toHaveCount(0);
  if (!enabled) {
    await page.locator('#rd-mbar [data-mbar="fullscreen"]').click();
    await expect(page.locator('#rd-toast')).toContainText('Home Screen');
    return;
  }
  await page.locator('#rd-mbar [data-mbar="fullscreen"]').click();
  await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
  await expect(page.locator('#rd-mbar [data-mbar="fullscreen"]')).toHaveAttribute('aria-pressed', 'true');
});
