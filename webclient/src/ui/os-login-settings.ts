export type OsLoginSetting = { enabled: boolean };

type Fetcher = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;

const SETTINGS_ERROR = 'OS auto-login settings are unavailable.';
const PEER_ID_PATTERN = /^[A-Za-z0-9_-]{1,255}$/;
const MAX_PASSWORD_LENGTH = 1024;

function isValidPeerId(peerId: string): boolean {
  return PEER_ID_PATTERN.test(peerId);
}

function isJsonResponse(response: Response): boolean {
  return (response.headers.get('content-type')?.toLowerCase() ?? '').startsWith('application/json');
}

function csrfHeaders(csrfToken: string): Record<string, string> {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken,
  };
}

export function canUseOsLoginSettings(
  url: string | undefined,
  csrfToken: string | undefined,
  secureContext: boolean,
): boolean {
  return secureContext && Boolean(url?.trim()) && Boolean(csrfToken?.trim());
}

export class OsLoginSettingsClient {
  constructor(
    private readonly url: string,
    private readonly csrfToken: string,
    private readonly fetcher: Fetcher = fetch,
  ) {}

  async load(peerId: string): Promise<OsLoginSetting> {
    if (!isValidPeerId(peerId)) throw new Error(SETTINGS_ERROR);

    const response = await this.fetcher(
      `${this.url}${this.url.includes('?') ? '&' : '?'}peerId=${encodeURIComponent(peerId)}`,
      {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: { Accept: 'application/json' },
      },
    );
    const body = await this.parseJson(response);
    if (
      !response.ok
      || response.redirected
      || typeof body !== 'object'
      || body === null
      || typeof (body as { enabled?: unknown }).enabled !== 'boolean'
      || Object.keys(body).length !== 1
    ) {
      throw new Error(SETTINGS_ERROR);
    }

    return { enabled: (body as { enabled: boolean }).enabled };
  }

  async reveal(peerId: string, currentPassword: string): Promise<string> {
    if (!isValidPeerId(peerId) || currentPassword.length === 0 || currentPassword.length > MAX_PASSWORD_LENGTH) {
      throw new Error(SETTINGS_ERROR);
    }

    const response = await this.fetcher(`${this.url}/reveal`, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: csrfHeaders(this.csrfToken),
      body: JSON.stringify({ peerId, currentPassword }),
    });
    const body = await this.parseJson(response);
    const password = typeof body === 'object' && body !== null
      ? (body as { password?: unknown }).password
      : undefined;
    if (
      !response.ok
      || response.redirected
      || typeof password !== 'string'
      || password.length === 0
      || password.length > MAX_PASSWORD_LENGTH
      || Object.keys(body as object).length !== 1
    ) {
      throw new Error(SETTINGS_ERROR);
    }

    return password;
  }

  save(peerId: string, password: string, currentPassword: string): Promise<void> {
    if (password.length === 0 || password.length > MAX_PASSWORD_LENGTH) {
      return Promise.reject(new Error(SETTINGS_ERROR));
    }
    return this.mutate('PUT', peerId, currentPassword, password);
  }

  remove(peerId: string, currentPassword: string): Promise<void> {
    return this.mutate('DELETE', peerId, currentPassword);
  }

  private async mutate(
    method: 'PUT' | 'DELETE',
    peerId: string,
    currentPassword: string,
    password?: string,
  ): Promise<void> {
    if (
      !isValidPeerId(peerId)
      || currentPassword.length === 0
      || currentPassword.length > MAX_PASSWORD_LENGTH
    ) {
      throw new Error(SETTINGS_ERROR);
    }

    const response = await this.fetcher(this.url, {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: csrfHeaders(this.csrfToken),
      body: JSON.stringify({
        peerId,
        ...(password === undefined ? {} : { password }),
        currentPassword,
      }),
    });
    if (response.status !== 204 || response.redirected) throw new Error(SETTINGS_ERROR);
  }

  private async parseJson(response: Response): Promise<unknown> {
    if (!isJsonResponse(response)) throw new Error(SETTINGS_ERROR);
    try {
      return await response.json();
    } catch {
      throw new Error(SETTINGS_ERROR);
    }
  }
}
