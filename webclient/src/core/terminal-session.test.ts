import { describe, expect, it, vi } from 'vitest';

vi.mock('./crypto', () => ({
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({ ourBoxPk: new Uint8Array(32), sealed: new Uint8Array(48), key: new Uint8Array(32) }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import type { Encryptor, SessionConfig, SessionEvent } from './contracts';
import { Session, type SessionSinks } from './session';
import { Message, PeerInfo, TerminalResponse } from '../gen/message';
import { ConnType, RendezvousMessage } from '../gen/rendezvous';

function config(connType: 'terminal' | 'viewCamera'): SessionConfig {
  return {
    peerId: '123456789',
    serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    wsIdUrl: 'wss://example.invalid/id',
    wsRelayUrl: 'wss://example.invalid/relay',
    password: '',
    myId: 'browser',
    myName: 'Browser',
    connType,
    terminalServiceId: connType === 'terminal' ? 'service-1' : undefined,
    terminalPersistent: connType === 'terminal',
  };
}

function harness(connType: 'terminal' | 'viewCamera' = 'terminal') {
  const signaling: Uint8Array[] = [];
  const relay: Uint8Array[] = [];
  const events: SessionEvent[] = [];
  const sinks: SessionSinks = {
    relayBuffered: () => 0,
    sendSignaling: (bytes) => signaling.push(bytes),
    sendRelay: (bytes) => relay.push(bytes),
    emit: (event) => events.push(event),
    onVideo: vi.fn(),
    onAudioFormat: vi.fn(),
    onAudioFrame: vi.fn(),
    openRelay: vi.fn(),
    closeAll: vi.fn(),
  };
  const session = new Session(config(connType), sinks);
  const identity: Encryptor = { seal: (bytes) => bytes, open: (bytes) => bytes };
  (session as unknown as { cipher: Encryptor }).cipher = identity;
  return { session, signaling, relay, events };
}

function lastMessage(bytes: Uint8Array[]): Message {
  return Message.decode(bytes.at(-1)!);
}

async function deliver(session: Session, message: Message): Promise<void> {
  await session.onRelayBytes(Message.encode(message).finish());
}

describe('advanced rendezvous connection types', () => {
  it.each([
    ['terminal', ConnType.TERMINAL],
    ['viewCamera', ConnType.VIEW_CAMERA],
  ] as const)('uses %s rendezvous type', (kind, expected) => {
    const { session, signaling } = harness(kind);
    session.start();
    const rendezvous = RendezvousMessage.decode(signaling[0]!);
    expect(rendezvous.union?.$case).toBe('punch_hole_request');
    if (rendezvous.union?.$case !== 'punch_hole_request') throw new Error('wrong request');
    expect(rendezvous.union.punch_hole_request.conn_type).toBe(expected);
  });
});

describe('advanced capability metadata', () => {
  it('forwards the peer terminal feature instead of inventing a PermissionInfo gate', async () => {
    const { session, events } = harness();
    const peerInfo = PeerInfo.fromPartial({
      hostname: 'peer',
      platform: 'Windows',
      features: { terminal: true },
    });

    await deliver(session, Message.fromPartial({
      union: {
        $case: 'login_response',
        login_response: { union: { $case: 'peer_info', peer_info: peerInfo }, enable_trusted_devices: false },
      },
    }));

    expect(events).toContainEqual(expect.objectContaining({
      t: 'peerInfo',
      terminalSupported: true,
    }));
  });
  it('forwards platform_additions camera support as an explicit capability', async () => {
    const { session, events } = harness();
    const peerInfo = PeerInfo.fromPartial({
      hostname: 'peer',
      platform: 'Windows',
      platform_additions: '{"support_view_camera":true}',
    });

    await deliver(session, Message.fromPartial({
      union: {
        $case: 'login_response',
        login_response: { union: { $case: 'peer_info', peer_info: peerInfo }, enable_trusted_devices: false },
      },
    }));

    expect(events).toContainEqual(expect.objectContaining({
      t: 'peerInfo',
      viewCameraSupported: true,
    }));
  });
});

describe('terminal protocol bridge', () => {
  it('sends open, input, resize, and close actions with terminal ids', () => {
    const { session, relay } = harness();

    session.openTerminal(7, 30, 100);
    expect(lastMessage(relay).union).toMatchObject({
      $case: 'terminal_action', terminal_action: { union: { $case: 'open', open: { terminal_id: 7, rows: 30, cols: 100 } } },
    });

    session.sendTerminalData(7, new Uint8Array([65, 66]));
    expect(lastMessage(relay).union).toMatchObject({
      $case: 'terminal_action', terminal_action: { union: { $case: 'data', data: { terminal_id: 7, data: new Uint8Array([65, 66]), compressed: false } } },
    });

    session.resizeTerminal(7, 40, 120);
    expect(lastMessage(relay).union).toMatchObject({
      $case: 'terminal_action', terminal_action: { union: { $case: 'resize', resize: { terminal_id: 7, rows: 40, cols: 120 } } },
    });

    session.closeTerminal(7);
    expect(lastMessage(relay).union).toMatchObject({
      $case: 'terminal_action', terminal_action: { union: { $case: 'close', close: { terminal_id: 7 } } },
    });
  });

  it('emits opened, data, closed, and error responses without rendering bytes as HTML', async () => {
    const { session, events } = harness();
    const responses = [
      TerminalResponse.fromPartial({ union: { $case: 'opened', opened: {
        terminal_id: 7, success: true, message: '', pid: 123, service_id: 'service-2', persistent_sessions: [2, 7], replay_terminal_output: true,
      } } }),
      TerminalResponse.fromPartial({ union: { $case: 'data', data: { terminal_id: 7, data: new TextEncoder().encode('<b>not html</b>'), compressed: false } } }),
      TerminalResponse.fromPartial({ union: { $case: 'closed', closed: { terminal_id: 7, exit_code: 0 } } }),
      TerminalResponse.fromPartial({ union: { $case: 'error', error: { terminal_id: 7, message: 'failed' } } }),
    ];
    for (const terminal_response of responses) {
      await deliver(session, Message.fromPartial({ union: { $case: 'terminal_response', terminal_response } }));
    }

    expect(events).toContainEqual({
      t: 'terminalOpened', terminalId: 7, success: true, message: '', pid: 123,
      serviceId: 'service-2', persistentSessions: [2, 7], replayTerminalOutput: true,
    });
    expect(events).toContainEqual({
      t: 'terminalData', terminalId: 7, data: new TextEncoder().encode('<b>not html</b>'), compressed: false,
    });
    expect(events).toContainEqual({ t: 'terminalClosed', terminalId: 7, exitCode: 0 });
    expect(events).toContainEqual({ t: 'terminalError', terminalId: 7, message: 'failed' });
  });
});

describe('2FA login', () => {
  function loginError(error: string): Message {
    return Message.fromPartial({
      union: { $case: 'login_response', login_response: { union: { $case: 'error', error }, enable_trusted_devices: false } },
    });
  }

  async function loggedIn() {
    const h = harness();
    await deliver(h.session, Message.fromPartial({ union: { $case: 'hash', hash: { salt: 'salt', challenge: 'challenge' } } }));
    return h;
  }

  it('asks for the code instead of failing the login', async () => {
    const { session, events } = await loggedIn();
    await deliver(session, loginError('2FA Required'));
    expect(events).toContainEqual({ t: 'twoFactor', wrong: false });
    expect(events).not.toContainEqual(expect.objectContaining({ t: 'loginError' }));

    await deliver(session, loginError('Wrong 2FA Code'));
    expect(events).toContainEqual({ t: 'twoFactor', wrong: true });
  });

  it('keeps other refusals as login errors', async () => {
    const { session, events } = await loggedIn();
    await deliver(session, loginError('No permission of terminal'));
    expect(events).toContainEqual({ t: 'loginError', message: 'No permission of terminal' });
  });

  it('sends the code as Auth2FA on the same connection', async () => {
    const { session, relay } = await loggedIn();
    session.sendAuth2fa(' 123456 ');
    const msg = lastMessage(relay);
    expect(msg.union?.$case).toBe('auth_2fa');
    if (msg.union?.$case !== 'auth_2fa') throw new Error('wrong message');
    expect(msg.union.auth_2fa.code).toBe('123456');
  });

  it('sends no code before the login request', () => {
    const { session, relay } = harness();
    session.sendAuth2fa('123456');
    expect(relay).toHaveLength(0);
  });
});
