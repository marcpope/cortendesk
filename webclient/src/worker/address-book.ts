import type { AddressBookSignIn } from '../core/contracts';

/** How long the console gets to answer before we sign in without it. */
const ADDRESS_BOOK_TIMEOUT_MS = 8000;

/** A path on the console's own origin: no scheme, no host, no `//`. */
const SAME_ORIGIN_PATH = /^\/(?!\/)[A-Za-z0-9._~\-/]*$/;

/**
 * Ask the console for h1 = SHA256(password || salt) of an address-book entry
 * for this device (POST, same origin, session cookie + CSRF token). The
 * password itself never comes back, and nothing is cached or stored: the
 * caller uses the value for this login and the live session only.
 *
 * Resolves null on any failure (no entry, signed out, network, timeout), so
 * the login falls back to what it did before: an empty password.
 */
export async function fetchAddressBookHash(
  signIn: AddressBookSignIn,
  peerId: string,
  salt: string,
  fetchImpl: typeof fetch = fetch,
): Promise<string | null> {
  // The console hands the page this path; never send the session cookie,
  // CSRF token or salt anywhere else, whatever ends up in the config.
  if (!SAME_ORIGIN_PATH.test(signIn.url)) return null;

  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), ADDRESS_BOOK_TIMEOUT_MS);
  try {
    const res = await fetchImpl(signIn.url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': signIn.csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ id: peerId, salt }),
      signal: ctrl.signal,
    });
    if (!res.ok) return null;
    const body = (await res.json()) as { hash?: unknown };
    return typeof body.hash === 'string' && /^[0-9a-f]{64}$/i.test(body.hash) ? body.hash.toLowerCase() : null;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}
