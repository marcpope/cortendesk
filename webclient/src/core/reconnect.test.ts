import { describe, expect, it } from 'vitest';
import { DROP_RECONNECT_TIMEOUT_MS, nextReconnectDelay, shouldAutoReconnect } from './reconnect';

const live = { wasStreaming: true, userClosed: false };

describe('shouldAutoReconnect', () => {
  it('a streaming session whose connection drops (Windows sign-in, relay reset) reconnects', () => {
    expect(shouldAutoReconnect({ state: 'error', detail: 'Connection lost (relay)' }, live)).toBe(true);
    expect(shouldAutoReconnect({ state: 'error', detail: 'Connection reset by peer (os error 10054)' }, live)).toBe(true);
    expect(shouldAutoReconnect({ state: 'closed' }, live)).toBe(true);
  });

  it('not when the host closed it on purpose (close_reason)', () => {
    expect(shouldAutoReconnect({ state: 'closed', detail: 'Closed manually by the peer', peerInitiated: true }, live)).toBe(false);
    expect(shouldAutoReconnect({ state: 'closed', detail: 'Connection failed due to inactivity', peerInitiated: true }, live)).toBe(false);
  });

  it('not after the user disconnected, nor before the session ever streamed', () => {
    expect(shouldAutoReconnect({ state: 'closed' }, { wasStreaming: true, userClosed: true })).toBe(false);
    expect(shouldAutoReconnect({ state: 'error', detail: 'relay connect failed' }, { wasStreaming: false, userClosed: false })).toBe(false);
  });

  it('not for the errors RustDesk never retries', () => {
    for (const detail of ['Remote desktop is offline', 'ID does not exist', 'Key mismatch', 'Connection not allowed', 'Incoming only mode', 'Wrong password']) {
      expect(shouldAutoReconnect({ state: 'error', detail }, live), detail).toBe(false);
    }
  });

  it('only for ends of a session', () => {
    expect(shouldAutoReconnect({ state: 'streaming' }, live)).toBe(false);
    expect(shouldAutoReconnect({ state: 'connecting' }, live)).toBe(false);
  });
});

describe('nextReconnectDelay', () => {
  it('backs off 1, 2, 4 ... 60 s and stays at 60 s', () => {
    const delays = [0, 1, 2, 3, 4, 5, 6, 7, 8].map((a) => nextReconnectDelay(a, 0));
    expect(delays).toEqual([1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000, 60000]);
  });

  it('never waits past the deadline, then gives up', () => {
    expect(nextReconnectDelay(6, DROP_RECONNECT_TIMEOUT_MS - 5000)).toBe(5000);
    expect(nextReconnectDelay(0, DROP_RECONNECT_TIMEOUT_MS)).toBeNull();
  });
});
