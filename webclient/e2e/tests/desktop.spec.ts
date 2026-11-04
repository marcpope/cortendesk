import { expect, test } from '@playwright/test';
import { TYPE, clientFor, cmds, connect, mouse } from './helpers';

test.beforeEach(({}, info) => {
  test.skip(info.project.name !== 'desktop', 'desktop regression only');
});

test('desktop layout is unchanged: top bar and dock, no phone controls', async ({ page }) => {
  await connect(page);
  await expect(page.locator('#rd-root')).not.toHaveClass(/rd-mobile/);
  await expect(page.locator('#rd-toolbar')).toBeVisible();
  await expect(page.locator('#rd-dock')).toBeVisible();
  // Phone-only controls (present from the release after 1.20.0) stay out of the desktop layout.
  await expect(page.locator('#rd-mbar')).toHaveCount(0);
  await expect(page.locator('#rd-btn-kbd')).toBeHidden();
  await expect(page.locator('#rd-btn-xkeys')).toBeHidden();
  await expect(page.locator('#rd-xkeys')).toBeHidden();
  await expect(page.locator('#rd-dock #rd-mode-pointer')).toHaveClass(/rd-on/);
  expect(await page.evaluate(() => document.querySelector<HTMLElement>('#rd-viewport canvas')!.style.transform)).toBe('');
});

test('mouse click: no extra MOVE before DOWN, exact coordinates', async ({ page }) => {
  await connect(page);
  const p = await clientFor(page, 960, 540);
  await page.mouse.move(p.x, p.y);
  await page.mouse.down();
  await page.mouse.up();
  const m = await mouse(page);
  const i = m.findIndex((c) => c[0] === TYPE.DOWN);
  expect(m[i - 1]?.[0]).toBe(TYPE.MOVE); // the hover move the mouse itself made
  expect(m.slice(i).map((c) => [c[0], c[1]])).toEqual([[TYPE.DOWN, 1], [TYPE.UP, 1]]);
  expect(Math.abs(m[i]![2]! - 960)).toBeLessThanOrEqual(3);
});

test('keyboard on the canvas still goes through the Map translator', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-viewport canvas').click();
  await page.keyboard.press('KeyA');
  const keys = (await cmds(page)).filter((c) => c.c === 'key');
  expect(keys.map((k) => [k.keyKind, k.value, k.down])).toEqual([['map', 0x1e, true], ['map', 0x1e, false]]);
});

test('Type sends the text (as seq, or one key press per character in older bundles)', async ({ page }) => {
  await connect(page);
  await page.locator('#rd-btn-type').click();
  await page.locator('#rd-type-text').fill('héllo ދިވެހި');
  await page.locator('#rd-type-send').click();
  const sent = (await cmds(page)).filter((c) => c.c === 'text' || c.c === 'key');
  const typed = sent
    .map((c) => (c.c === 'text' ? (c.text as string) : String.fromCodePoint(c.value as number)))
    .join('');
  expect(typed).toBe('héllo ދިވެހި');
});
