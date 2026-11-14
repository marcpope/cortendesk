import { beforeAll, describe, expect, it } from 'vitest';
import { Message } from '../gen/message';
import type { SessionConfig, SessionEvent } from './contracts';
import { computeLoginH1, loginHashFromH1 } from './auth';
import { PlainChannel, sodiumReady } from './crypto';
import { Session, type SessionSinks } from './session';

const hex = (b: Uint8Array): string => Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');

function harness(config: Partial<SessionConfig>, addressBookHash?: (salt: string) => Promise<string | null>) {
  const sent: Uint8Array[] = [];
  const events: SessionEvent[] = [];
  const sinks: SessionSinks = {
    sendSignaling: () => {},
    sendRelay: (b) => sent.push(b),
    relayBuffered: () => 0,
    emit: (ev) => events.push(ev),
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
    ...(addressBookHash ? { addressBookHash } : {}),
  };
  const session = new Session(
    {
      peerId: '123456789',
      serverKeyB64: 'A'.repeat(43) + '=',
      wsIdUrl: 'ws://x/id',
      wsRelayUrl: 'ws://x/relay',
      password: '',
      myId: 'web-1',
      myName: 'Tester',
      ...config,
    },
    sinks,
  );
  // Past the key exchange: the login travels on the session channel.
  (session as unknown as { cipher: PlainChannel }).cipher = new PlainChannel();
  const challenge = async (salt: string, chal: string) =>
    (session as unknown as { dispatch(m: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'hash', hash: { salt, challenge: chal } } }),
    );
  const loginPassword = (): Uint8Array => {
    const msg = Message.decode(sent[sent.length - 1]!);
    if (msg.union?.$case !== 'login_request') throw new Error('no login request');
    return msg.union.login_request.password;
  };
  return { events, challenge, loginPassword };
}

describe('address-book sign-in', () => {
  beforeAll(async () => {
    await sodiumReady();
  });

  it('logs in with the h1 the console derives from the salt', async () => {
    const h1 = await computeLoginH1('escrowed-pw', 'device-salt');
    const asked: string[] = [];
    const h = harness({ addressBook: { url: '/u', csrfToken: 't' } }, async (salt) => {
      asked.push(salt);
      return hex(h1);
    });

    await h.challenge('device-salt', 'chal-1');

    expect(asked).toEqual(['device-salt']);
    expect(h.loginPassword()).toEqual(await loginHashFromH1(h1, 'chal-1'));
    expect(h.events).toContainEqual({ t: 'credentials', hashHex: hex(h1), fromAddressBook: true });
  });

  it('agrees with the console on h1 for a fixed vector (raw digests, hex only on the wire)', async () => {
    // Same vector as tests/Feature/WebClientAddressBookPasswordTest.php.
    const h1Hex = 'a3359ea34ba87a32d2701ea1eec9b1873ba1f634c2880715e1ad40a37dbe182a';
    expect(hex(await computeLoginH1('Escrowed-Pa55', 'Zx8pQ2'))).toBe(h1Hex);

    const h = harness({ addressBook: { url: '/u', csrfToken: 't' } }, async () => h1Hex);
    await h.challenge('Zx8pQ2', 'chal-2');
    // What a typed password produces for the same device and challenge.
    expect(h.loginPassword()).toEqual(
      await loginHashFromH1(await computeLoginH1('Escrowed-Pa55', 'Zx8pQ2'), 'chal-2'),
    );
    expect(h.loginPassword()).toHaveLength(32);
  });

  it('falls back to an empty password when the console has nothing', async () => {
    const h = harness({ addressBook: { url: '/u', csrfToken: 't' } }, async () => null);
    await h.challenge('s', 'c');
    expect(h.loginPassword()).toHaveLength(0);
    expect(h.events.some((e) => e.t === 'credentials')).toBe(false);
  });

  it('never asks the console when a password was typed', async () => {
    let asked = false;
    const h = harness({ password: 'typed', addressBook: { url: '/u', csrfToken: 't' } }, async () => {
      asked = true;
      return null;
    });
    await h.challenge('s', 'c');
    expect(asked).toBe(false);
    expect(h.loginPassword()).toEqual(await loginHashFromH1(await computeLoginH1('typed', 's'), 'c'));
  });

  it('never asks the console without an address-book config', async () => {
    let asked = false;
    const h = harness({}, async () => {
      asked = true;
      return 'ab'.repeat(32);
    });
    await h.challenge('s', 'c');
    expect(asked).toBe(false);
    expect(h.loginPassword()).toHaveLength(0);
  });
});
