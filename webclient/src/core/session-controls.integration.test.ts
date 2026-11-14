import { describe, expect, it, vi } from 'vitest';
import type { Encryptor, SessionConfig, SessionEvent } from './contracts';

vi.mock('./crypto', () => ({
  decodeB64: () => new Uint8Array(32),
  signOpen: (bytes: Uint8Array) => bytes,
  sealSymmetricKey: () => ({
    ourBoxPk: new Uint8Array(32),
    sealed: new Uint8Array(48),
    key: new Uint8Array(32),
  }),
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
}));

import { Session, type SessionSinks } from './session';
import {
  BackNotification,
  BackNotification_BlockInputState,
  BackNotification_PrivacyModeState,
  Message,
  Misc,
  OptionMessage_BoolOption,
  PeerInfo,
} from '../gen/message';

const config: SessionConfig = {
  peerId: '123456789',
  serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  wsIdUrl: 'wss://example.invalid/id',
  wsRelayUrl: 'wss://example.invalid/relay',
  password: '',
  myId: 'controller',
  myName: 'Controller',
};

function harness(sessionConfig: SessionConfig = config): { session: Session; sent: Uint8Array[]; events: SessionEvent[] } {
  const sent: Uint8Array[] = [];
  const events: SessionEvent[] = [];
  const sinks: SessionSinks = {
    relayBuffered: () => 0,
    sendSignaling: () => {},
    sendRelay: (bytes) => sent.push(bytes),
    emit: (event) => events.push(event),
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
  };
  const session = new Session(sessionConfig, sinks);
  const identity: Encryptor = { seal: (bytes) => bytes, open: (bytes) => bytes };
  (session as unknown as { cipher: Encryptor }).cipher = identity;
  return { session, sent, events };
}

function lastMisc(sent: Uint8Array[]): Misc {
  const message = Message.decode(sent.at(-1)!);
  expect(message.union?.$case).toBe('misc');
  if (message.union?.$case !== 'misc') throw new Error('not misc');
  return message.union.misc;
}

async function deliverMisc(session: Session, misc: Misc): Promise<void> {
  const message = Message.fromPartial({ union: { $case: 'misc', misc } });
  await session.onRelayBytes(Message.encode(message).finish());
}

describe('Session security controls', () => {
  it('clears typed plaintext from retained session config after deriving H1', async () => {
    const { session } = harness({ ...config, password: 'typed-secret' });
    const message = Message.fromPartial({
      union: {
        $case: 'hash',
        hash: { salt: 'salt', challenge: 'challenge' },
      },
    });

    await session.onRelayBytes(Message.encode(message).finish());

    expect((session as unknown as { config: SessionConfig }).config.password).toBe('');
  });

  it('emits manual acceptance without a terminal login error', async () => {
    const { session, events } = harness();
    const detail = 'Please wait for the remote user to accept';
    const message = Message.fromPartial({
      union: {
        $case: 'login_response',
        login_response: { union: { $case: 'error', error: detail }, enable_trusted_devices: false },
      },
    });

    await session.onRelayBytes(Message.encode(message).finish());

    expect(events).toContainEqual({ t: 'state', state: 'needAccept', detail });
    expect(events.some((event) => event.t === 'loginError')).toBe(false);
  });

  it('sends restart, direct elevation, privacy, block-input, and lock controls', () => {
    const { session, sent } = harness();

    session.restartRemoteDevice();
    expect(lastMisc(sent).union).toEqual({ $case: 'restart_remote_device', restart_remote_device: true });

    session.requestElevation();
    expect(lastMisc(sent).union).toMatchObject({
      $case: 'elevation_request',
      elevation_request: { union: { $case: 'direct', direct: true } },
    });

    session.setPrivacyMode('privacy_mode_impl_virtual_display', true);
    expect(lastMisc(sent).union).toEqual({
      $case: 'toggle_privacy_mode',
      toggle_privacy_mode: { impl_key: 'privacy_mode_impl_virtual_display', on: true },
    });

    session.setBlockInput(true);
    expect(lastMisc(sent).union).toMatchObject({
      $case: 'option',
      option: { block_input: OptionMessage_BoolOption.Yes },
    });

    session.setLockAfterSessionEnd(false);
    expect(lastMisc(sent).union).toMatchObject({
      $case: 'option',
      option: { lock_after_session_end: OptionMessage_BoolOption.No },
    });
  });

  it('marks a peer close reason as an explicit peer-initiated disconnect', async () => {
    const { session, events } = harness();

    await deliverMisc(session, Misc.fromPartial({
      union: { $case: 'close_reason', close_reason: 'remote user closed the session' },
    }));

    expect(events).toContainEqual({
      t: 'state',
      state: 'closed',
      detail: 'remote user closed the session',
      peerInitiated: true,
    });
  });

  it('emits authoritative privacy and block-input back-notifications', async () => {
    const { session, events } = harness();

    await deliverMisc(session, Misc.fromPartial({
      union: {
        $case: 'back_notification',
        back_notification: BackNotification.fromPartial({
          union: {
            $case: 'privacy_mode_state',
            privacy_mode_state: BackNotification_PrivacyModeState.PrvOnSucceeded,
          },
          impl_key: 'privacy_mode_impl_virtual_display',
          details: '',
        }),
      },
    }));
    await deliverMisc(session, Misc.fromPartial({
      union: {
        $case: 'back_notification',
        back_notification: BackNotification.fromPartial({
          union: {
            $case: 'block_input_state',
            block_input_state: BackNotification_BlockInputState.BlkOnSucceeded,
          },
          details: '',
        }),
      },
    }));

    expect(events).toContainEqual({
      t: 'privacyMode',
      state: BackNotification_PrivacyModeState.PrvOnSucceeded,
      details: '',
      implKey: 'privacy_mode_impl_virtual_display',
    });
    expect(events).toContainEqual({
      t: 'blockInput',
      state: BackNotification_BlockInputState.BlkOnSucceeded,
      details: '',
    });
  });

  it('emits peer privacy capabilities and implementation choices', async () => {
    const { session, events } = harness();
    const peerInfo = PeerInfo.fromPartial({
      username: 'user',
      hostname: 'peer',
      platform: 'Windows',
      version: '1.4.9',
      features: { privacy_mode: true, terminal: true },
      platform_additions: JSON.stringify({
        supported_privacy_mode_impl: [
          ['privacy_mode_impl_virtual_display', 'Virtual display'],
          ['privacy_mode_impl_mag', 'Screen curtain'],
        ],
      }),
    });
    const message = Message.fromPartial({
      union: {
        $case: 'login_response',
        login_response: { union: { $case: 'peer_info', peer_info: peerInfo }, enable_trusted_devices: false },
      },
    });

    await session.onRelayBytes(Message.encode(message).finish());

    expect(events).toContainEqual(expect.objectContaining({
      t: 'peerInfo',
      privacyModeSupported: true,
      privacyModeImpls: [
        { key: 'privacy_mode_impl_virtual_display', label: 'Virtual display' },
        { key: 'privacy_mode_impl_mag', label: 'Screen curtain' },
      ],
    }));
  });

  it('emits elevation failure, pending approval, and service-running states without credentials', async () => {
    const { session, events } = harness();

    await deliverMisc(session, Misc.fromPartial({ union: { $case: 'elevation_response', elevation_response: '' } }));
    await deliverMisc(session, Misc.fromPartial({ union: { $case: 'portable_service_running', portable_service_running: true } }));
    await deliverMisc(session, Misc.fromPartial({ union: { $case: 'elevation_response', elevation_response: 'No permission' } }));

    expect(events).toContainEqual({ t: 'elevation', state: 'pending', detail: '' });
    expect(events).toContainEqual({ t: 'elevation', state: 'succeeded', detail: '' });
    expect(events).toContainEqual({ t: 'elevation', state: 'failed', detail: 'No permission' });
    expect(JSON.stringify(events)).not.toMatch(/username|password/i);
  });
});
