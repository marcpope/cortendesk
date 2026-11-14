import { describe, it, expect } from 'vitest';
import { WsStream, type WebSocketLike } from './ws-stream';

type Listener = (ev: { data?: unknown }) => void;

class FakeWs implements WebSocketLike {
  binaryType = 'blob';
  sent: Uint8Array[] = [];
  closeCalls = 0;
  private listeners = new Map<string, Listener[]>();

  addEventListener(type: 'open' | 'message' | 'close' | 'error', listener: Listener): void {
    const arr = this.listeners.get(type) ?? [];
    arr.push(listener);
    this.listeners.set(type, arr);
  }

  send(data: Uint8Array): void {
    this.sent.push(data);
  }

  close(): void {
    this.closeCalls++;
    this.emit('close', {});
  }

  emit(type: string, ev: { data?: unknown }): void {
    for (const l of [...(this.listeners.get(type) ?? [])]) l(ev);
  }
}

const bytes = (...b: number[]) => new Uint8Array(b);

function makeStream(): { ws: FakeWs; stream: WsStream } {
  const ws = new FakeWs();
  return { ws, stream: new WsStream(ws) };
}

describe('WsStream.open', () => {
  it('resolves on open with binaryType=arraybuffer', async () => {
    let created: FakeWs | undefined;
    const Ctor = class extends FakeWs {
      constructor(_url: string) {
        super();
        created = this;
      }
    };
    const p = WsStream.open('ws://x', Ctor as unknown as new (url: string) => WebSocketLike);
    created!.emit('open', {});
    const stream = await p;
    expect(stream).toBeInstanceOf(WsStream);
    expect(created!.binaryType).toBe('arraybuffer');
  });

  it('rejects on error before open', async () => {
    let created: FakeWs | undefined;
    const Ctor = class extends FakeWs {
      constructor(_url: string) {
        super();
        created = this;
      }
    };
    const p = WsStream.open('ws://x', Ctor as unknown as new (url: string) => WebSocketLike);
    created!.emit('error', {});
    await expect(p).rejects.toThrow(/error before open/);
  });

  it('rejects on close before open', async () => {
    let created: FakeWs | undefined;
    const Ctor = class extends FakeWs {
      constructor(_url: string) {
        super();
        created = this;
      }
    };
    const p = WsStream.open('ws://x', Ctor as unknown as new (url: string) => WebSocketLike);
    created!.emit('close', {});
    await expect(p).rejects.toThrow(/closed before open/);
  });
});

describe('WsStream.open URL guard', () => {
  it('refuses anything that is not a ws:// or wss:// URL', async () => {
    let constructed = 0;
    const Ctor = class { constructor() { constructed++; } };
    for (const bad of ['http://x', 'https://x/ws', 'javascript:alert(1)', 'x', '']) {
      await expect(WsStream.open(bad, Ctor as unknown as new (url: string) => WebSocketLike)).rejects.toThrow(/refusing non-WebSocket URL/);
    }
    expect(constructed).toBe(0);
  });
});

describe('WsStream frames', () => {
  it('normalizes ArrayBuffer and Uint8Array payloads', async () => {
    const { ws, stream } = makeStream();
    ws.emit('message', { data: bytes(1, 2).buffer });
    ws.emit('message', { data: bytes(3, 4) });
    expect(await stream.next()).toEqual(bytes(1, 2));
    expect(await stream.next()).toEqual(bytes(3, 4));
  });

  it('buffers frames arriving before any consumer, in order', async () => {
    const { ws, stream } = makeStream();
    ws.emit('message', { data: bytes(1) });
    ws.emit('message', { data: bytes(2) });
    ws.emit('message', { data: bytes(3) });
    expect(await stream.next()).toEqual(bytes(1));
    const got: Uint8Array[] = [];
    stream.onMessage((b) => got.push(b));
    expect(got).toEqual([bytes(2), bytes(3)]);
  });

  it('next() awaits a future frame', async () => {
    const { ws, stream } = makeStream();
    const p = stream.next();
    ws.emit('message', { data: bytes(9) });
    expect(await p).toEqual(bytes(9));
  });

  it('pending next() wins over onMessage callback for one frame', async () => {
    const { ws, stream } = makeStream();
    const got: Uint8Array[] = [];
    stream.onMessage((b) => got.push(b));
    const p = stream.next();
    ws.emit('message', { data: bytes(1) });
    ws.emit('message', { data: bytes(2) });
    expect(await p).toEqual(bytes(1));
    expect(got).toEqual([bytes(2)]);
  });

  it('handshake pattern: await first frame, then stream via onMessage', async () => {
    const { ws, stream } = makeStream();
    ws.emit('message', { data: bytes(0xaa) }); // SignedId lands before consumer
    const first = await stream.next();
    expect(first).toEqual(bytes(0xaa));
    const got: Uint8Array[] = [];
    stream.onMessage((b) => got.push(b));
    ws.emit('message', { data: bytes(0xbb) });
    expect(got).toEqual([bytes(0xbb)]);
  });

  it('async iterator yields frames and ends on close', async () => {
    const { ws, stream } = makeStream();
    ws.emit('message', { data: bytes(1) });
    ws.emit('message', { data: bytes(2) });
    const got: Uint8Array[] = [];
    const done = (async () => {
      for await (const b of stream) got.push(b);
    })();
    await Promise.resolve(); // let the iterator drain the buffer
    ws.emit('message', { data: bytes(3) });
    await Promise.resolve();
    ws.emit('close', {});
    await done;
    expect(got).toEqual([bytes(1), bytes(2), bytes(3)]);
  });

  it('next() drains buffered frames after close, then rejects', async () => {
    const { ws, stream } = makeStream();
    ws.emit('message', { data: bytes(7) });
    ws.emit('close', {});
    expect(await stream.next()).toEqual(bytes(7));
    await expect(stream.next()).rejects.toThrow(/closed/);
  });
});

describe('WsStream close', () => {
  it('close event fires onClose callbacks once and rejects pending waiters', async () => {
    const { ws, stream } = makeStream();
    let closes = 0;
    stream.onClose(() => closes++);
    const p = stream.next();
    ws.emit('close', {});
    ws.emit('close', {});
    await expect(p).rejects.toThrow(/closed/);
    expect(closes).toBe(1);
    expect(stream.isClosed).toBe(true);
  });

  it('error event tears down like close', async () => {
    const { ws, stream } = makeStream();
    let closed = false;
    stream.onClose(() => (closed = true));
    ws.emit('error', {});
    expect(closed).toBe(true);
    await expect(stream.next()).rejects.toThrow(/closed/);
  });

  it('close() closes the socket and tears down immediately', () => {
    const { ws, stream } = makeStream();
    let closed = false;
    stream.onClose(() => (closed = true));
    stream.close();
    stream.close();
    expect(ws.closeCalls).toBe(1);
    expect(closed).toBe(true);
  });

  it('onClose after close fires immediately', () => {
    const { stream } = makeStream();
    stream.close();
    let fired = false;
    stream.onClose(() => (fired = true));
    expect(fired).toBe(true);
  });
});

describe('WsStream send', () => {
  it('forwards bytes one frame per message', () => {
    const { ws, stream } = makeStream();
    stream.send(bytes(1, 2, 3));
    stream.send(bytes(4));
    expect(ws.sent).toEqual([bytes(1, 2, 3), bytes(4)]);
  });

  it('throws on send after close', () => {
    const { stream } = makeStream();
    stream.close();
    expect(() => stream.send(bytes(1))).toThrow(/closed/);
  });
});
