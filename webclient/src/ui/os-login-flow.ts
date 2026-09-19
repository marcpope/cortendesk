import type { OsLoginSetting } from './os-login-settings';

export interface OsLoginSettingsPort {
  load(peerId: string): Promise<OsLoginSetting>;
  save(peerId: string, password: string, currentPassword: string): Promise<void>;
  reveal(peerId: string, currentPassword: string): Promise<string>;
  remove(peerId: string, currentPassword: string): Promise<void>;
}

export type OsLoginView = { enabled: boolean; hasSavedPassword: boolean };

export class OsLoginFlow {
  private peerId = '';
  private hasSavedPassword = false;
  private hydrationEpoch = 0;

  constructor(private readonly client: OsLoginSettingsPort) {}

  async hydrate(peerId: string): Promise<OsLoginView | null> {
    const epoch = ++this.hydrationEpoch;
    const setting = await this.client.load(peerId);
    if (epoch !== this.hydrationEpoch) return null;

    this.peerId = peerId;
    this.hasSavedPassword = setting.enabled;
    return {
      enabled: setting.enabled,
      hasSavedPassword: setting.enabled,
    };
  }

  async prepare(
    peerId: string,
    enabled: boolean,
    typedPassword: string,
    currentPassword: string,
  ): Promise<string | undefined> {
    const hasSavedPassword = peerId === this.peerId && this.hasSavedPassword;

    if (!enabled) {
      if (!hasSavedPassword) return undefined;
      this.requireCurrentPassword(currentPassword);
      await this.client.remove(peerId, currentPassword);
      this.hasSavedPassword = false;
      return undefined;
    }

    this.requireCurrentPassword(currentPassword);
    if (typedPassword) {
      await this.client.save(peerId, typedPassword, currentPassword);
      this.peerId = peerId;
      this.hasSavedPassword = true;
      return typedPassword;
    }
    if (hasSavedPassword) {
      return this.client.reveal(peerId, currentPassword);
    }

    throw new Error('Enter the remote OS password.');
  }

  private requireCurrentPassword(currentPassword: string): void {
    if (!currentPassword) throw new Error('Enter your CortenDesk password.');
  }
}
