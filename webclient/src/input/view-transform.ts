/**
 * Local zoom and pan of the remote screen (pinch, Fit / 1:1, keyboard
 * avoidance). A CSS transform on the canvas (or MSE <video>), origin top
 * left: no re-layout and no extra video work.
 *
 * Input stays exact without any change to the mapper: mapCoords() works
 * from getBoundingClientRect(), which includes transforms.
 *
 * Coordinates here are the viewport's (the element's untransformed box is
 * the viewport box, so they are element-local too). `content` is where the
 * remote image sits inside the element (object-fit: contain letterbox):
 * panning is clamped to it, so a zoomed view never drifts into the bars.
 */

export type Rect = { x: number; y: number; width: number; height: number };
export type View = { scale: number; tx: number; ty: number };

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 5;

/** Where an object-fit: contain image of `aspect` sits in a w x h box. */
export function containRect(w: number, h: number, aspect: number): Rect {
  if (w <= 0 || h <= 0 || !(aspect > 0)) return { x: 0, y: 0, width: Math.max(w, 0), height: Math.max(h, 0) };
  if (w / h > aspect) {
    const width = h * aspect;
    return { x: (w - width) / 2, y: 0, width, height: h };
  }
  const height = w / aspect;
  return { x: 0, y: (h - height) / 2, width: w, height };
}

export class ViewTransformer {
  private v: View = { scale: 1, tx: 0, ty: 0 };
  /** Untransformed element box. */
  private w = 0;
  private h = 0;
  /** Visible part of the viewport (smaller than w x h while a keyboard covers it). */
  private vw = 0;
  private vh = 0;
  private content: Rect = { x: 0, y: 0, width: 0, height: 0 };
  private maxZoom = MAX_ZOOM;

  get view(): Readonly<View> {
    return this.v;
  }

  get zoomed(): boolean {
    return this.v.scale > 1.001;
  }

  /** Element box, visible area and remote aspect ratio; re-clamps the view. */
  setGeometry(w: number, h: number, aspect: number, visibleW = w, visibleH = h): void {
    this.w = w;
    this.h = h;
    this.vw = visibleW;
    this.vh = visibleH;
    this.content = containRect(w, h, aspect);
    this.clamp();
  }

  /** Zoom where one remote pixel is one CSS pixel (never below Fit). */
  oneToOneScale(remoteWidth: number): number {
    return this.content.width > 0 ? Math.max(1, remoteWidth / this.content.width) : 1;
  }

  /** Where the remote image sits in the untransformed element. */
  get contentRect(): Readonly<Rect> {
    return this.content;
  }

  /**
   * Zoom in until the image is as wide as the visible area (never out): a
   * landscape phone with its keyboard open leaves a wide, short strip, and
   * the picture fitted into it would be tiny.
   */
  fillWidth(): void {
    if (this.content.width <= 0 || this.vw <= 0) return;
    const s = this.vw / this.content.width;
    if (s > this.v.scale + 0.001) this.zoomTo(Math.min(s, this.maxZoom));
  }

  /** Allow at least `scale` (so 1:1 works on a 4K remote), capped sanely. */
  allowZoom(scale: number): void {
    this.maxZoom = Math.min(Math.max(MAX_ZOOM, scale), 16);
  }

  reset(): void {
    this.v = { scale: 1, tx: 0, ty: 0 };
    this.clamp();
  }

  /** Go back to a saved view (re-clamped to the current geometry). */
  restore(v: View): void {
    this.v = { ...v };
    this.clamp();
  }

  /** Multiply the zoom by `factor` keeping the point (cx, cy) under the fingers. */
  zoomAt(factor: number, cx: number, cy: number): void {
    const s0 = this.v.scale;
    const s1 = Math.min(this.maxZoom, Math.max(MIN_ZOOM, s0 * factor));
    this.v = {
      scale: s1,
      tx: cx - ((cx - this.v.tx) * s1) / s0,
      ty: cy - ((cy - this.v.ty) * s1) / s0,
    };
    this.clamp();
  }

  /** Set an absolute zoom, centred on the visible area. */
  zoomTo(scale: number): void {
    this.zoomAt(scale / this.v.scale, this.vw / 2, this.vh / 2);
  }

  panBy(dx: number, dy: number): void {
    this.v = { ...this.v, tx: this.v.tx + dx, ty: this.v.ty + dy };
    this.clamp();
  }

  /**
   * Bring an element-local point (untransformed) into view, if it is not
   * already inside the visible area minus `margin`: horizontally centred,
   * vertically at `frac` of the visible height (a third: above a keyboard).
   * True when the view moved.
   */
  ensureVisible(px: number, py: number, frac = 1 / 3, margin = 24): boolean {
    const before = this.v;
    const { scale: s } = this.v;
    const sx = this.v.tx + px * s;
    const sy = this.v.ty + py * s;
    let { tx, ty } = this.v;
    if (sx < margin || sx > this.vw - margin) tx = this.vw / 2 - px * s;
    if (sy < margin || sy > this.vh - margin) ty = this.vh * frac - py * s;
    this.v = { scale: s, tx, ty };
    this.clamp();
    return this.v.tx !== before.tx || this.v.ty !== before.ty;
  }

  /**
   * Keep a moving point (the trackpad cursor) inside the visible area minus
   * `margin` by panning just enough, never by re-centring: edge scrolling,
   * smooth, and stable when called again with the same point. True when
   * the view moved. The margin is capped at half the visible size, or a
   * short strip (landscape keyboard) would push the point back and forth.
   */
  keepInView(px: number, py: number, margin = 40): boolean {
    const before = this.v;
    const s = this.v.scale;
    const sx = this.v.tx + px * s;
    const sy = this.v.ty + py * s;
    const mx = Math.min(margin, this.vw / 2);
    const my = Math.min(margin, this.vh / 2);
    let { tx, ty } = this.v;
    if (sx < mx) tx += mx - sx;
    else if (sx > this.vw - mx) tx -= sx - (this.vw - mx);
    if (sy < my) ty += my - sy;
    else if (sy > this.vh - my) ty -= sy - (this.vh - my);
    this.v = { scale: s, tx, ty };
    this.clamp();
    return this.v.tx !== before.tx || this.v.ty !== before.ty;
  }

  /** Element-local (untransformed) point -> viewport point. */
  toScreen(px: number, py: number): { x: number; y: number } {
    return { x: this.v.tx + px * this.v.scale, y: this.v.ty + py * this.v.scale };
  }

  /** CSS transform for the surface; '' when it is the identity. */
  css(): string {
    const { scale, tx, ty } = this.v;
    if (scale === 1 && Math.abs(tx) < 0.01 && Math.abs(ty) < 0.01) return '';
    return `translate(${round(tx)}px, ${round(ty)}px) scale(${round(scale, 4)})`;
  }

  private clamp(): void {
    const s = this.v.scale;
    this.v = {
      scale: s,
      tx: clampAxis(this.v.tx, s, this.content.x, this.content.width, this.vw),
      ty: clampAxis(this.v.ty, s, this.content.y, this.content.height, this.vh),
    };
  }
}

/**
 * Keep the scaled content [c0*s, (c0+len)*s] covering the visible span
 * [0, visible]; centre it when it is smaller than the span.
 */
function clampAxis(t: number, s: number, c0: number, len: number, visible: number): number {
  if (len <= 0 || visible <= 0) return 0;
  const size = len * s;
  if (size <= visible) return (visible - size) / 2 - c0 * s + 0;
  const max = -c0 * s;
  const min = visible - (c0 + len) * s;
  return Math.min(max, Math.max(min, t)) + 0; // + 0: no -0
}

function round(n: number, digits = 2): number {
  const f = 10 ** digits;
  return Math.round(n * f) / f;
}
