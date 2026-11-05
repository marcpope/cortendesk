/**
 * Fit the page to what the user can actually see on a phone or tablet.
 *
 * iOS never resizes the layout for its on-screen keyboard: the keyboard
 * covers the bottom of the page (the Windows taskbar, a form's buttons) and
 * Safari scrolls the page around underneath. Android does resize with
 * `interactive-widget=resizes-content`, but not every browser honours it.
 * The visual viewport is right on both, so the session root is pinned to it.
 *
 * Keyboard open = the visual viewport is clearly shorter than the tallest it
 * has been in this orientation (an on-screen keyboard is never < 120 px).
 */

export type ViewportState = { width: number; height: number; offsetTop: number; keyboard: boolean };

type VisualViewportLike = Pick<VisualViewport, 'width' | 'height' | 'offsetTop' | 'addEventListener' | 'removeEventListener'>;

const KEYBOARD_MIN_PX = 120;

/** Pure part: is a keyboard taking this much of the tallest height seen? */
export class KeyboardDetector {
  private tallest = 0;
  private landscape: boolean | null = null;

  update(width: number, height: number): boolean {
    const landscape = width > height + KEYBOARD_MIN_PX ? true : width < height ? false : this.landscape;
    if (landscape !== this.landscape) {
      this.landscape = landscape;
      this.tallest = 0; // rotated: start over
    }
    this.tallest = Math.max(this.tallest, height);
    return this.tallest - height >= KEYBOARD_MIN_PX;
  }
}

export function trackVisualViewport(
  root: HTMLElement,
  onChange: (s: ViewportState) => void,
  vv: VisualViewportLike | null | undefined = typeof window !== 'undefined' ? window.visualViewport : undefined,
): () => void {
  if (!vv) return () => {};
  const detector = new KeyboardDetector();
  let frame = 0;
  const apply = (): void => {
    frame = 0;
    const keyboard = detector.update(window.innerWidth, vv.height);
    root.style.position = 'fixed';
    root.style.left = '0';
    root.style.right = '0';
    root.style.top = `${vv.offsetTop}px`;
    root.style.height = `${vv.height}px`;
    root.dataset.keyboard = keyboard ? 'open' : 'closed';
    onChange({ width: vv.width, height: vv.height, offsetTop: vv.offsetTop, keyboard });
  };
  const schedule = (): void => {
    if (!frame) frame = requestAnimationFrame(apply);
  };
  // iOS scrolls the page to "reveal" a focused field even when it is in view.
  const unscroll = (): void => {
    if (window.scrollY || window.scrollX) window.scrollTo(0, 0);
  };
  vv.addEventListener('resize', schedule);
  vv.addEventListener('scroll', schedule);
  window.addEventListener('orientationchange', schedule);
  window.addEventListener('scroll', unscroll);
  apply();
  return () => {
    if (frame) cancelAnimationFrame(frame);
    vv.removeEventListener('resize', schedule);
    vv.removeEventListener('scroll', schedule);
    window.removeEventListener('orientationchange', schedule);
    window.removeEventListener('scroll', unscroll);
  };
}
