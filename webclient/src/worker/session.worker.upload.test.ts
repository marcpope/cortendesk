import { afterEach, describe, expect, it, vi } from 'vitest';
import { WorkerHost } from './session.worker';

type Internals = {
  session: { sendFileResponse: ReturnType<typeof vi.fn> };
  transport: 'relay' | 'webrtc';
  rtcPosted: number;
};

function upload(): { host: WorkerHost; posts: unknown[]; inner: Internals } {
  const posts: unknown[] = [];
  const host = new WorkerHost({ post: (m) => posts.push(m) });
  const inner = host as unknown as Internals;
  inner.session = { sendFileResponse: vi.fn() };
  return { host, posts, inner };
}

const acks = (posts: unknown[]) => posts.filter((p) => (p as { t: string }).t === 'ftSent');

describe('WorkerHost upload self-clocking (#155)', () => {
  afterEach(() => vi.useRealTimers());

  it('holds the block ack while the WebRTC queue is deep, counting bytes still on their way to the page', async () => {
    vi.useFakeTimers();
    const { host, posts, inner } = upload();
    inner.transport = 'webrtc';
    inner.rtcPosted = 12 * 1024 * 1024; // posted to the page, none taken yet

    host.handle({ c: 'ftBlock', id: 1, fileNum: 0, data: new Uint8Array(4), blkId: 0 });
    await vi.advanceTimersByTimeAsync(200);
    expect(inner.session.sendFileResponse).toHaveBeenCalledOnce();
    expect(acks(posts)).toHaveLength(0);

    // The page took it all, but the channel still queues 6 MB.
    host.handle({ c: 'rtcBuffered', bytes: 6 * 1024 * 1024, taken: 12 * 1024 * 1024 });
    await vi.advanceTimersByTimeAsync(200);
    expect(acks(posts)).toHaveLength(0);

    host.handle({ c: 'rtcBuffered', bytes: 256 * 1024, taken: 12 * 1024 * 1024 });
    await vi.advanceTimersByTimeAsync(40);
    expect(acks(posts)).toEqual([{ t: 'ftSent', id: 1, fileNum: 0, buffered: 256 * 1024 }]);
  });

  it('releases a held ack when the channel closes', async () => {
    vi.useFakeTimers();
    const { host, posts, inner } = upload();
    inner.transport = 'webrtc';
    inner.rtcPosted = 12 * 1024 * 1024;

    host.handle({ c: 'ftBlock', id: 2, fileNum: 0, data: new Uint8Array(4), blkId: 0 });
    host.handle({ c: 'rtcClosed' });
    await vi.advanceTimersByTimeAsync(40);
    expect(acks(posts)).toHaveLength(1);
  });

  it('acks at once over the relay when the socket is not backed up', async () => {
    const { host, posts } = upload();
    host.handle({ c: 'ftBlock', id: 3, fileNum: 0, data: new Uint8Array(4), blkId: 0 });
    await Promise.resolve();
    expect(acks(posts)).toEqual([{ t: 'ftSent', id: 3, fileNum: 0, buffered: 0 }]);
  });
});
