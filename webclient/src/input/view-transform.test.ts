import { describe, expect, it } from 'vitest';
import { mapCoords } from './mouse-keyboard';
import { ViewTransformer, containRect } from './view-transform';

/** The rect getBoundingClientRect() reports for a w x h element at (0,0) under the view's transform. */
function transformedRect(v: ViewTransformer, w: number, h: number): DOMRect {
  const { scale, tx, ty } = v.view;
  return { left: tx, top: ty, width: w * scale, height: h * scale, x: tx, y: ty, right: tx + w * scale, bottom: ty + h * scale } as DOMRect;
}

const display = { x: 0, y: 0, width: 1920, height: 1080 };

describe('containRect', () => {
  it('letterboxes a 16:9 image in a portrait phone viewport', () => {
    expect(containRect(390, 700, 16 / 9)).toEqual({ x: 0, y: (700 - 219.375) / 2, width: 390, height: 219.375 });
  });
  it('pillarboxes in a wide box', () => {
    const r = containRect(1000, 400, 16 / 9);
    expect(r.width).toBeCloseTo(711.11, 1);
    expect(r.x).toBeCloseTo(144.44, 1);
  });
});

describe('ViewTransformer', () => {
  it('is the identity at rest (desktop untouched)', () => {
    const v = new ViewTransformer();
    v.setGeometry(1280, 720, 16 / 9);
    expect(v.css()).toBe('');
    v.setGeometry(390, 700, 16 / 9);
    expect(v.css()).toBe('');
  });

  it('pinch keeps the point under the fingers fixed', () => {
    const v = new ViewTransformer();
    v.setGeometry(800, 450, 16 / 9);
    const before = mapCoords(600, 300, transformedRect(v, 800, 450), display);
    v.zoomAt(2, 600, 300);
    expect(v.view.scale).toBe(2);
    const after = mapCoords(600, 300, transformedRect(v, 800, 450), display);
    expect(after).toEqual(before);
  });

  it('mapCoords stays exact on a zoomed, panned, letterboxed view', () => {
    const v = new ViewTransformer();
    v.setGeometry(390, 700, 16 / 9); // portrait phone
    v.zoomAt(3, 195, 350);
    v.panBy(-100, 40);
    const rect = transformedRect(v, 390, 700);
    // A remote point drawn at screen (sx, sy) must map back to itself.
    const content = containRect(390, 700, 16 / 9);
    const remote = { x: 1500, y: 300 };
    const lx = content.x + (remote.x / 1920) * content.width;
    const ly = content.y + (remote.y / 1080) * content.height;
    const s = v.toScreen(lx, ly);
    expect(mapCoords(s.x, s.y, rect, display)).toEqual(remote);
  });

  it('clamps: never zooms below fit or past the cap, never pans into the bars', () => {
    const v = new ViewTransformer();
    v.setGeometry(800, 450, 16 / 9);
    v.zoomAt(0.2, 0, 0);
    expect(v.view).toEqual({ scale: 1, tx: 0, ty: 0 });
    v.zoomAt(100, 0, 0);
    expect(v.view.scale).toBe(5);
    v.panBy(10_000, 10_000);
    expect(v.view.tx).toBe(0);
    expect(v.view.ty).toBe(0);
    v.panBy(-1e6, -1e6);
    expect(v.view.tx).toBe(800 - 800 * 5);
    expect(v.view.ty).toBe(450 - 450 * 5);
  });

  it('a letterboxed image is centred when it is smaller than the view', () => {
    const v = new ViewTransformer();
    v.setGeometry(390, 700, 16 / 9);
    v.zoomAt(2, 0, 0); // image 780 x 439: wider than the view, shorter than it
    const c = containRect(390, 700, 16 / 9);
    expect(v.view.ty).toBeCloseTo((700 - c.height * 2) / 2 - c.y * 2, 6);
  });

  it('1:1 zoom and a higher cap for big remote screens', () => {
    const v = new ViewTransformer();
    v.setGeometry(390, 700, 16 / 9);
    const s = v.oneToOneScale(3840);
    expect(s).toBeCloseTo(3840 / 390, 6);
    v.allowZoom(s);
    v.zoomTo(s);
    expect(v.view.scale).toBeCloseTo(s, 6);
  });

  it('ensureVisible brings a point above a keyboard, a third of the way down', () => {
    const v = new ViewTransformer();
    v.setGeometry(390, 700, 16 / 9, 390, 300); // keyboard covers 400px
    v.zoomAt(3, 0, 0);
    const moved = v.ensureVisible(300, 400);
    expect(moved).toBe(true);
    const s = v.toScreen(300, 400);
    expect(s.y).toBeGreaterThanOrEqual(24);
    expect(s.y).toBeLessThanOrEqual(300 - 24);
    expect(v.ensureVisible(300, 400)).toBe(false); // already visible
  });

  it('fillWidth: a landscape keyboard strip zooms the picture to the strip width, never out', () => {
    const v = new ViewTransformer();
    v.setGeometry(800, 120, 16 / 9); // a phone with its keyboard open: picture fitted into 213 x 120
    v.fillWidth();
    expect(v.view.scale).toBeCloseTo(800 / (120 * 16 / 9), 4);
    const before = v.view.scale;
    v.setGeometry(800, 450, 16 / 9);
    v.fillWidth(); // already wider than needed: unchanged
    expect(v.view.scale).toBe(before);
  });
});
