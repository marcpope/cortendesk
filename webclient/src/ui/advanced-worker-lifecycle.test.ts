import { afterEach, describe, expect, it, vi } from 'vitest';
import { disconnectIndependentWorker } from './advanced-worker-lifecycle';

describe('independent advanced-session teardown', () => {
  afterEach(() => vi.useRealTimers());

  it('gives the encrypted disconnect frame a bounded chance to flush before termination', () => {
    vi.useFakeTimers();
    const worker = { postMessage: vi.fn(), terminate: vi.fn() } as unknown as Worker;

    disconnectIndependentWorker(worker);

    expect(worker.postMessage).toHaveBeenCalledWith({ c: 'disconnect' });
    expect(worker.terminate).not.toHaveBeenCalled();
    vi.advanceTimersByTime(249);
    expect(worker.terminate).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(worker.terminate).toHaveBeenCalledOnce();
  });
});
