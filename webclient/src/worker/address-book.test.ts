import { describe, expect, it, vi } from 'vitest';
import { fetchAddressBookHash } from './address-book';

const signIn = { url: '/webclient/address-book-password', csrfToken: 'csrf-token-value' };
const H1 = 'AB'.repeat(32);

function respond(status: number, body: unknown): typeof fetch {
  return vi.fn(async () => new Response(JSON.stringify(body), { status })) as unknown as typeof fetch;
}

describe('fetchAddressBookHash', () => {
  it('POSTs the device id and salt with the CSRF token, never in the URL', async () => {
    const fetchImpl = respond(200, { hash: H1 });
    await expect(fetchAddressBookHash(signIn, '123456789', 'salt!', fetchImpl)).resolves.toBe(H1.toLowerCase());

    const [url, init] = (fetchImpl as unknown as ReturnType<typeof vi.fn>).mock.calls[0]!;
    expect(url).toBe('/webclient/address-book-password');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.cache).toBe('no-store');
    expect(init.headers['X-CSRF-TOKEN']).toBe('csrf-token-value');
    expect(JSON.parse(init.body)).toEqual({ id: '123456789', salt: 'salt!' });
  });

  it('only calls a path on the console itself', async () => {
    for (const url of ['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', 'webclient/x', '/a b']) {
      const fetchImpl = respond(200, { hash: H1 });
      await expect(fetchAddressBookHash({ ...signIn, url }, '1', 's', fetchImpl)).resolves.toBeNull();
      expect(fetchImpl).not.toHaveBeenCalled();
    }
    const prefixed = respond(200, { hash: H1 });
    await expect(fetchAddressBookHash({ ...signIn, url: '/console/webclient/address-book-password' }, '1', 's', prefixed)).resolves.toBe(H1.toLowerCase());
  });

  it('resolves null when the console has no entry', async () => {
    await expect(fetchAddressBookHash(signIn, '1', 's', respond(404, { error: 'x' }))).resolves.toBeNull();
  });

  it('resolves null for anything that is not a 32-byte hex hash', async () => {
    await expect(fetchAddressBookHash(signIn, '1', 's', respond(200, { hash: 'plaintext' }))).resolves.toBeNull();
    await expect(fetchAddressBookHash(signIn, '1', 's', respond(200, { password: 'x' }))).resolves.toBeNull();
  });

  it('resolves null when the request fails', async () => {
    const fetchImpl = vi.fn(async () => {
      throw new TypeError('network');
    }) as unknown as typeof fetch;
    await expect(fetchAddressBookHash(signIn, '1', 's', fetchImpl)).resolves.toBeNull();
  });
});
