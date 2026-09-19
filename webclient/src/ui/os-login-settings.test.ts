import { describe, expect, it, vi } from 'vitest';
import { canUseOsLoginSettings, OsLoginSettingsClient } from './os-login-settings';

const jsonResponse = (body: unknown, status = 200, contentType = 'application/json'): Response =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': contentType } });

describe('OsLoginSettingsClient', () => {
  it('is available only with endpoint config in a secure browser context', () => {
    expect(canUseOsLoginSettings('/webclient/os-login', 'csrf', true)).toBe(true);
    expect(canUseOsLoginSettings('/webclient/os-login', 'csrf', false)).toBe(false);
    expect(canUseOsLoginSettings('', 'csrf', true)).toBe(false);
    expect(canUseOsLoginSettings('/webclient/os-login', '', true)).toBe(false);
  });

  it('loads metadata without returning a password', async () => {
    const fetcher = vi.fn(async () => jsonResponse({ enabled: true }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.load('abc-123')).resolves.toEqual({ enabled: true });
    expect(fetcher).toHaveBeenCalledWith(
      '/webclient/os-login?peerId=abc-123',
      expect.objectContaining({
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
      }),
    );
  });

  it('reveals a saved password only through a CSRF-protected POST with reauthentication', async () => {
    const fetcher = vi.fn(async () => jsonResponse({ password: 'remote-os-secret' }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.reveal('abc-123', 'console-password')).resolves.toBe('remote-os-secret');
    expect(fetcher).toHaveBeenCalledWith('/webclient/os-login/reveal', expect.objectContaining({
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      body: JSON.stringify({ peerId: 'abc-123', currentPassword: 'console-password' }),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': 'csrf-token',
      }),
    }));
  });

  it.each([
    [{ enabled: true, password: 'leak' }],
    [{ enabled: 'true' }],
    [{ enabled: false, extra: true }],
    [null],
  ])('rejects malformed or expanded metadata response %j', async (body) => {
    const client = new OsLoginSettingsClient(
      '/webclient/os-login',
      'csrf-token',
      vi.fn(async () => jsonResponse(body)),
    );
    await expect(client.load('abc-123')).rejects.toThrow('OS auto-login settings are unavailable.');
  });

  it.each([
    [{ password: '' }],
    [{ password: 'x'.repeat(1025) }],
    [{ password: 'secret', extra: true }],
    [{ enabled: true }],
  ])('rejects malformed or expanded reveal response %j', async (body) => {
    const client = new OsLoginSettingsClient(
      '/webclient/os-login',
      'csrf-token',
      vi.fn(async () => jsonResponse(body)),
    );
    await expect(client.reveal('abc-123', 'console-password')).rejects.toThrow(
      'OS auto-login settings are unavailable.',
    );
  });

  it('rejects credential responses with a non-JSON media type', async () => {
    const client = new OsLoginSettingsClient(
      '/webclient/os-login',
      'csrf-token',
      vi.fn(async () => jsonResponse({ password: 'secret' }, 200, 'text/plain')),
    );
    await expect(client.reveal('abc-123', 'console-password')).rejects.toThrow(
      'OS auto-login settings are unavailable.',
    );
  });

  it('stores a password with CSRF protection and current-password reauthentication', async () => {
    const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.save('abc-123', 'remote-os-secret', 'console-password')).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith('/webclient/os-login', expect.objectContaining({
      method: 'PUT',
      body: JSON.stringify({
        peerId: 'abc-123',
        password: 'remote-os-secret',
        currentPassword: 'console-password',
      }),
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-token' }),
    }));
  });

  it('removes a saved password with CSRF protection and current-password reauthentication', async () => {
    const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);

    await expect(client.remove('abc-123', 'console-password')).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith('/webclient/os-login', expect.objectContaining({
      method: 'DELETE',
      body: JSON.stringify({ peerId: 'abc-123', currentPassword: 'console-password' }),
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-token' }),
    }));
  });

  it.each([
    ['', 'secret', 'console-password'],
    ['bad peer', 'secret', 'console-password'],
    ['abc-123', '', 'console-password'],
    ['abc-123', 'x'.repeat(1025), 'console-password'],
    ['abc-123', 'secret', ''],
  ])('rejects invalid save input before fetching', async (peerId, password, currentPassword) => {
    const fetcher = vi.fn();
    const client = new OsLoginSettingsClient('/webclient/os-login', 'csrf-token', fetcher);
    await expect(client.save(peerId, password, currentPassword)).rejects.toThrow(
      'OS auto-login settings are unavailable.',
    );
    expect(fetcher).not.toHaveBeenCalled();
  });
});
