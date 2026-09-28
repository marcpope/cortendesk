import { describe, expect, it, vi } from 'vitest';
import { OsLoginFlow } from './os-login-flow';

function client(enabled: boolean, revealed = 'saved-fixture') {
  return {
    load: vi.fn(async () => ({ enabled })),
    save: vi.fn(async () => {}),
    reveal: vi.fn(async () => revealed),
    remove: vi.fn(async () => {}),
  };
}

describe('OsLoginFlow', () => {
  it('hydrates metadata without loading plaintext', async () => {
    const port = client(true);
    const flow = new OsLoginFlow(port);

    await expect(flow.hydrate('abc-123')).resolves.toEqual({
      enabled: true,
      hasSavedPassword: true,
    });
    expect(port.reveal).not.toHaveBeenCalled();
  });

  it('reveals a saved password only when preparing an enabled connection', async () => {
    const port = client(true);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', true, '', 'console-password')).resolves.toBe('saved-fixture');
    expect(port.reveal).toHaveBeenCalledWith('abc-123', 'console-password');
    expect(port.save).not.toHaveBeenCalled();
  });

  it('saves and returns a newly typed password for this connection', async () => {
    const port = client(false);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', true, 'new-fixture', 'console-password')).resolves.toBe('new-fixture');
    expect(port.save).toHaveBeenCalledWith('abc-123', 'new-fixture', 'console-password');
    expect(port.reveal).not.toHaveBeenCalled();
  });

  it('deletes a saved password when auto-login is disabled', async () => {
    const port = client(true);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', false, '', 'console-password')).resolves.toBeUndefined();
    expect(port.remove).toHaveBeenCalledWith('abc-123', 'console-password');
  });

  it('does not require reauthentication when disabled and nothing is stored', async () => {
    const port = client(false);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', false, '', '')).resolves.toBeUndefined();
    expect(port.remove).not.toHaveBeenCalled();
  });

  it('requires the current CortenDesk password before save, reveal, or delete', async () => {
    const port = client(true);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', true, '', '')).rejects.toThrow('Enter your CortenDesk password.');
    await expect(flow.prepare('abc-123', true, 'new-fixture', '')).rejects.toThrow('Enter your CortenDesk password.');
    await expect(flow.prepare('abc-123', false, '', '')).rejects.toThrow('Enter your CortenDesk password.');
    expect(port.save).not.toHaveBeenCalled();
    expect(port.reveal).not.toHaveBeenCalled();
    expect(port.remove).not.toHaveBeenCalled();
  });

  it('requires a remote password when no saved setting exists', async () => {
    const port = client(false);
    const flow = new OsLoginFlow(port);
    await flow.hydrate('abc-123');

    await expect(flow.prepare('abc-123', true, '', 'console-password')).rejects.toThrow(
      'Enter the remote OS password.',
    );
  });

  it('ignores a stale metadata response after the peer changes', async () => {
    let resolveFirst!: (value: { enabled: boolean }) => void;
    let resolveSecond!: (value: { enabled: boolean }) => void;
    const first = new Promise<{ enabled: boolean }>((resolve) => { resolveFirst = resolve; });
    const second = new Promise<{ enabled: boolean }>((resolve) => { resolveSecond = resolve; });
    const port = {
      load: vi.fn((peerId: string) => peerId === 'first' ? first : second),
      save: vi.fn(async () => {}),
      reveal: vi.fn(async () => 'second-fixture'),
      remove: vi.fn(async () => {}),
    };
    const flow = new OsLoginFlow(port);

    const oldHydration = flow.hydrate('first');
    const currentHydration = flow.hydrate('second');
    resolveSecond({ enabled: true });
    await expect(currentHydration).resolves.toEqual({ enabled: true, hasSavedPassword: true });
    resolveFirst({ enabled: true });
    await expect(oldHydration).resolves.toBeNull();
    await expect(flow.prepare('second', true, '', 'console-password')).resolves.toBe('second-fixture');
  });
});
