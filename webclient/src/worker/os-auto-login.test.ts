import { afterEach, describe, expect, it, vi } from 'vitest';
import { buttonMask, MouseButton, MouseType } from '../input/mouse-keyboard';
import { OsAutoLoginAttempt } from './os-auto-login';

const primaryDown = buttonMask(MouseType.DOWN, MouseButton.LEFT);
const primaryUp = buttonMask(MouseType.UP, MouseButton.LEFT);

afterEach(() => {
  vi.useRealTimers();
});

describe('OsAutoLoginAttempt', () => {
  it('uses a bounded wake gesture before sending the password once', async () => {
    vi.useFakeTimers();
    const mouse: Array<[number, number, number]> = [];
    const passwords: string[] = [];
    const attempt = new OsAutoLoginAttempt({
      eligible: () => true,
      sendMouse: (mask, x, y) => mouse.push([mask, x, y]),
      sendPassword: (password) => {
        passwords.push(password);
        return true;
      },
    });

    const result = attempt.start('remote-os-secret');
    expect(mouse).toEqual([[primaryUp, 0, 0]]);
    expect(passwords).toEqual([]);

    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([[primaryUp, 0, 0], [0, 0, 0]]);
    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([[primaryUp, 0, 0], [0, 0, 0], [0, 3, 3]]);
    await vi.advanceTimersByTimeAsync(50);
    expect(mouse).toEqual([
      [primaryUp, 0, 0],
      [0, 0, 0],
      [0, 3, 3],
      [primaryDown, 0, 0],
      [primaryUp, 0, 0],
    ]);
    expect(passwords).toEqual([]);

    await vi.advanceTimersByTimeAsync(1199);
    expect(passwords).toEqual([]);
    await vi.advanceTimersByTimeAsync(1);
    await expect(result).resolves.toBe('sent');
    expect(passwords).toEqual(['remote-os-secret']);
    await expect(attempt.start('second-secret')).resolves.toBe('already-attempted');
    expect(passwords).toEqual(['remote-os-secret']);
  });

  it('cancels an in-flight attempt before any later input or password frame', async () => {
    vi.useFakeTimers();
    const mouse: Array<[number, number, number]> = [];
    const passwords: string[] = [];
    const attempt = new OsAutoLoginAttempt({
      eligible: () => true,
      sendMouse: (mask, x, y) => mouse.push([mask, x, y]),
      sendPassword: (password) => {
        passwords.push(password);
        return true;
      },
    });

    const result = attempt.start('must-not-send');
    expect(mouse).toEqual([[primaryUp, 0, 0]]);
    attempt.cancel();
    await vi.runAllTimersAsync();

    await expect(result).resolves.toBe('cancelled');
    expect(mouse).toEqual([[primaryUp, 0, 0]]);
    expect(passwords).toEqual([]);
  });
});
