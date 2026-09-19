import { describe, expect, it, vi } from 'vitest';
import type { SessionConfig, UiCommand } from '../core/contracts';
import { postConnectWithSecretScrub } from './session-secret-handoff';

type ConnectCommand = Extract<UiCommand, { c: 'connect' }>;

const config = (): SessionConfig => ({
  peerId: '123456',
  password: '',
  myId: 'browser',
  myName: 'Browser',
  wsIdUrl: 'wss://id.example.test',
  wsRelayUrl: 'wss://relay.example.test',
  serverKeyB64: 'key',
  osPassword: 'remote-os-secret',
});

describe('postConnectWithSecretScrub', () => {
  it('delivers the transient password to structured-clone handoff then scrubs the caller copy', () => {
    const sessionConfig = config();
    const command: ConnectCommand = { c: 'connect', config: sessionConfig, canvas: {} as OffscreenCanvas };
    let deliveredPassword: string | undefined;
    const poster = {
      postMessage: vi.fn((message: ConnectCommand) => {
        deliveredPassword = message.config.osPassword;
      }),
    };

    postConnectWithSecretScrub(poster, command, []);

    expect(deliveredPassword).toBe('remote-os-secret');
    expect(sessionConfig.osPassword).toBeUndefined();
    expect(command.config.osPassword).toBeUndefined();
  });

  it('scrubs the caller copy even when postMessage throws', () => {
    const sessionConfig = config();
    const command: ConnectCommand = { c: 'connect', config: sessionConfig, canvas: {} as OffscreenCanvas };
    const poster = {
      postMessage: vi.fn(() => {
        throw new DOMException('clone failed', 'DataCloneError');
      }),
    };

    expect(() => postConnectWithSecretScrub(poster, command, [])).toThrow('clone failed');
    expect(sessionConfig.osPassword).toBeUndefined();
  });
});
