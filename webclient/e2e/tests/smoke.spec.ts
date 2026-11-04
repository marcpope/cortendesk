import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Optional smoke test against a REAL console and device. Never runs in CI: it is skipped unless
 * CD_E2E_URL, CD_E2E_USER, CD_E2E_PASSWORD_FILE and CD_E2E_PEER are set (see README.md).
 * It signs in, opens the web client for the device, waits for video, taps the middle of the
 * screen and takes screenshots. It types nothing on the device.
 */
const env = process.env;
const ready = !!(env.CD_E2E_URL && env.CD_E2E_USER && env.CD_E2E_PASSWORD_FILE && env.CD_E2E_PEER);

test('real host: sign in, open the web client, stream, tap', async ({ page }, info) => {
  test.skip(!ready, 'set CD_E2E_URL, CD_E2E_USER, CD_E2E_PASSWORD_FILE and CD_E2E_PEER to run');
  const password = readFileSync(env.CD_E2E_PASSWORD_FILE!, 'utf8').trim();
  await page.goto('/login');
  await page.locator('#username').fill(env.CD_E2E_USER!);
  await page.locator('#password').fill(password);
  await page.locator('#password').press('Enter');
  await page.waitForLoadState('networkidle');
  expect(new URL(page.url()).pathname).not.toBe('/login');

  await page.goto(`/webclient?id=${encodeURIComponent(env.CD_E2E_PEER!)}`);
  if (env.CD_E2E_PEER_PASSWORD_FILE) {
    // Only when the console has no address-book password for the device.
    await page.locator('#rd-password').fill(readFileSync(env.CD_E2E_PEER_PASSWORD_FILE, 'utf8').trim());
    await page.locator('#rd-connect').click();
  }
  await expect(page.locator('#rd-root')).toHaveAttribute('data-state', 'streaming', { timeout: 60_000 });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: info.outputPath('streaming.png') });

  const box = (await page.locator('#rd-viewport canvas').boundingBox())!;
  await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
  await page.waitForTimeout(2000);
  await page.screenshot({ path: info.outputPath('after-tap.png') });
  await expect(page.locator('#rd-root')).toHaveAttribute('data-state', 'streaming');
});
