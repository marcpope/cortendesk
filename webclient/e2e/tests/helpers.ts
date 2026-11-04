import { expect, type Page } from '@playwright/test';

export type Cmd = { c: string; [k: string]: unknown };
export const TYPE = { MOVE: 0, DOWN: 1, UP: 2, WHEEL: 3 } as const;

export async function connect(page: Page): Promise<void> {
  await page.goto('/');
  await page.locator('#rd-password').fill('pw');
  await page.locator('#rd-connect').click();
  await expect(page.locator('#rd-root')).toHaveAttribute('data-state', 'streaming');
  // peerInfo is applied, the view laid out.
  await page.waitForFunction(() => document.querySelector('#rd-viewport')!.clientWidth > 0);
  await page.waitForTimeout(100);
  await clearCmds(page);
}

export async function clearCmds(page: Page): Promise<void> {
  await page.evaluate(() => ((window as unknown as { __cmds: unknown[] }).__cmds.length = 0));
}

export async function cmds(page: Page): Promise<Cmd[]> {
  return page.evaluate(() => (window as unknown as { __cmds: Cmd[] }).__cmds.slice());
}

/** Mouse commands as [type, buttons, x, y]. */
export async function mouse(page: Page): Promise<number[][]> {
  return (await cmds(page))
    .filter((c) => c.c === 'mouse')
    .map((c) => [(c.mask as number) & 7, (c.mask as number) >> 3, c.x as number, c.y as number]);
}

/** Client point for a remote (1920x1080) coordinate, given the canvas's current on-screen rect. */
export async function clientFor(page: Page, rx: number, ry: number): Promise<{ x: number; y: number }> {
  return page.evaluate(([rx, ry]) => {
    const r = document.querySelector('#rd-viewport canvas')!.getBoundingClientRect();
    const aspect = 1920 / 1080;
    let w = r.width;
    let h = r.height;
    if (w / h > aspect) w = h * aspect;
    else h = w / aspect;
    const left = r.left + (r.width - w) / 2;
    const top = r.top + (r.height - h) / 2;
    return { x: left + (rx / 1920) * w, y: top + (ry / 1080) * h };
  }, [rx, ry]);
}

/**
 * Synthetic touch pointer events on the remote screen (multi-touch works the
 * same in every engine this way). steps: [type, id, x, y].
 */
export async function touch(page: Page, steps: [string, number, number, number][], gapMs = 16): Promise<void> {
  for (const [type, id, x, y] of steps) {
    await page.evaluate(([type, id, x, y]) => {
      const el = document.querySelector('#rd-viewport canvas')!;
      el.dispatchEvent(new PointerEvent(type as string, {
        pointerId: id as number, pointerType: 'touch', isPrimary: id === 1, clientX: x as number, clientY: y as number,
        bubbles: true, cancelable: true, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' ? 0 : 1,
      }));
    }, [type, id, x, y] as const);
    if (gapMs) await page.waitForTimeout(gapMs);
  }
}
