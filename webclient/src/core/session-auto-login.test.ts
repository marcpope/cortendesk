import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

vi.mock('./crypto', () => ({
  decodeB64: () => new Uint8Array(32),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import {
  ControlKey,
  Message,
  OptionMessage_BoolOption,
} from '../gen/message';
import type { SessionConfig, SessionEvent } from './contracts';
import { Session } from './session';

function sessionWith(config: SessionConfig) {
  const sent: Uint8Array[] = [];
  const events: SessionEvent[] = [];
  const session = new Session(config, {
    sendSignaling: () => {},
    sendRelay: (bytes) => sent.push(bytes),
    relayBuffered: () => 0,
    emit: (event) => events.push(event),
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
  });
  (session as unknown as { cipher: { seal(bytes: Uint8Array): Uint8Array } }).cipher = {
    seal: (bytes) => bytes,
  };
  return { session, sent, events };
}

const baseConfig: SessionConfig = {
  peerId: '123456',
  serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  wsIdUrl: 'wss://id.example.test',
  wsRelayUrl: 'wss://relay.example.test',
  password: '',
  myId: 'controller',
  myName: 'Controller',
};

async function sendHash(session: Session): Promise<void> {
  await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
    Message.fromPartial({
      union: {
        $case: 'hash',
        hash: { salt: 'salt', challenge: 'challenge' },
      },
    }),
  );
}

describe('Session OS auto-login setup', () => {
  it('does not couple UI lock-after-disconnect state to the OS password', () => {
    const appSource = readFileSync(new URL('../ui/app.ts', import.meta.url), 'utf8');

    expect(appSource).not.toMatch(/lockAfterSessionEnd\s*=\s*Boolean\(config\.osPassword\)/);
  });

  it('does not force lock-after-session in the default desktop LoginRequest', async () => {
    const { session, sent } = sessionWith({
      ...baseConfig,
      osPassword: 'remote-os-secret',
    });

    await sendHash(session);

    const message = Message.decode(sent[0]!);
    expect(message.union?.$case).toBe('login_request');
    if (message.union?.$case !== 'login_request') throw new Error('expected login request');
    expect(message.union.login_request.option?.lock_after_session_end)
      .not.toBe(OptionMessage_BoolOption.Yes);
    expect(message.union.login_request.os_login?.password ?? '').toBe('');
  });

  it('cannot transition to streaming after a non-manual login error', async () => {
    const { session, events } = sessionWith(baseConfig);
    (session as unknown as { state: string }).state = 'login';

    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({
        union: {
          $case: 'login_response',
          login_response: { union: { $case: 'error', error: 'Wrong password' } },
        },
      }),
    );

    expect(session.currentState).toBe('error');
    expect(events).toContainEqual({ t: 'loginError', message: 'Wrong password' });

    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({
        union: {
          $case: 'login_response',
          login_response: { union: { $case: 'peer_info', peer_info: {} } },
        },
      }),
    );

    expect(session.currentState).toBe('error');
    expect(events.some((event) => event.t === 'state' && event.state === 'streaming')).toBe(false);
  });

  it('sends the OS password as one legacy sequence followed by Return', () => {
    const { session, sent } = sessionWith(baseConfig);
    (session as unknown as { state: string }).state = 'streaming';

    expect(session.sendOsPassword('p@ssw0rd-✓')).toBe(true);

    expect(sent).toHaveLength(2);
    const password = Message.decode(sent[0]!);
    const submit = Message.decode(sent[1]!);
    expect(password.union).toMatchObject({
      $case: 'key_event',
      key_event: {
        press: true,
        mode: 0,
        modifiers: [],
        union: { $case: 'seq', seq: 'p@ssw0rd-✓' },
      },
    });
    expect(submit.union).toMatchObject({
      $case: 'key_event',
      key_event: {
        press: true,
        mode: 0,
        modifiers: [],
        union: { $case: 'control_key', control_key: ControlKey.Return },
      },
    });
  });

  it.each([
    { state: 'login', connType: undefined, password: 'secret', label: 'before streaming' },
    { state: 'streaming', connType: 'viewCamera' as const, password: 'secret', label: 'outside desktop control' },
    { state: 'streaming', connType: undefined, password: '', label: 'with an empty password' },
  ])('emits no password frames $label', ({ state, connType, password }) => {
    const { session, sent } = sessionWith({ ...baseConfig, connType });
    (session as unknown as { state: string }).state = state;

    expect(session.sendOsPassword(password)).toBe(false);
    expect(sent).toHaveLength(0);
  });

  it.each(['fileTransfer', 'viewCamera', 'terminal'] as const)(
    'does not enable OS auto-login for %s connections',
    async (connType) => {
      const { session, sent } = sessionWith({
        ...baseConfig,
        connType,
        osPassword: 'remote-os-secret',
      });

      await sendHash(session);

      const message = Message.decode(sent[0]!);
      if (message.union?.$case !== 'login_request') throw new Error('expected login request');
      expect(message.union.login_request.option?.lock_after_session_end)
        .not.toBe(OptionMessage_BoolOption.Yes);
    },
  );
});
