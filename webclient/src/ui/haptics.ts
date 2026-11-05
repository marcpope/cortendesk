/**
 * A short buzz to confirm a touch action the screen cannot show well (a
 * long-press right-click, a modifier locking). Android only: iOS Safari has
 * no Vibration API, and calls there do nothing.
 */
let enabled = true;

export function setHapticsEnabled(on: boolean): void {
  enabled = on;
}

export function haptic(ms = 10): void {
  if (!enabled || typeof navigator === 'undefined' || typeof navigator.vibrate !== 'function') return;
  try {
    navigator.vibrate(ms);
  } catch {
    /* blocked until the first user gesture: nothing to do */
  }
}
