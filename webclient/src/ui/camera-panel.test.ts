import { afterEach, expect, it, vi } from 'vitest';
import type { SessionConfig } from '../core/contracts';
import { buildCameraConfig, CameraPanel } from './camera-panel';

afterEach(() => vi.useRealTimers());

it('creates an isolated view-camera session without changing the source config', () => {
  const source: SessionConfig = {
    peerId: '123', serverKeyB64: 'key', wsIdUrl: 'wss://id', wsRelayUrl: 'wss://relay',
    password: '', savedHashHex: 'hash', myId: 'browser', myName: 'Browser', connType: 'default',
  };
  const camera = buildCameraConfig(source);
  expect(camera.connType).toBe('viewCamera');
  expect(camera.savedHashHex).toBe('hash');
  expect(source.connType).toBe('default');
});

it('disconnects and terminates its independent worker after a camera session error', () => {
  vi.useFakeTimers();
  const panel = new CameraPanel({
    root: {} as HTMLElement,
    workerUrl: 'worker.js',
    getConfig: () => null,
    toast: () => {},
  }) as any;
  const worker = { postMessage: vi.fn(), terminate: vi.fn() };
  panel.worker = worker;
  panel.status = { textContent: '' };

  panel.onEvent({ t: 'state', state: 'error', detail: 'Camera failed' });

  expect(worker.postMessage).toHaveBeenCalledWith({ c: 'disconnect' });
  expect(panel.worker).toBeUndefined();
  vi.advanceTimersByTime(249);
  expect(worker.terminate).not.toHaveBeenCalled();
  vi.advanceTimersByTime(1);
  expect(worker.terminate).toHaveBeenCalledOnce();
});
