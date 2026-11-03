import { describe, expect, it } from 'vitest';
import { addressBookReady, buildSessionConfig, type RdGlobalConfig } from './common';

const g: RdGlobalConfig = {
  peerId: '123 456 789',
  serverKeyB64: 'k',
  wsIdUrl: 'ws://x/id',
  wsRelayUrl: 'ws://x/relay',
  myId: 'web-1',
  myName: 'Tester',
  addressBook: { url: '/webclient/address-book-password', csrfToken: 't', available: true },
};

describe('address-book session config', () => {
  it('asks the address book only for a password nobody gave', () => {
    expect(buildSessionConfig(g, '123456789', '').addressBook).toEqual({
      url: '/webclient/address-book-password',
      csrfToken: 't',
    });
    expect(buildSessionConfig(g, '123456789', 'typed').addressBook).toBeUndefined();
    expect(buildSessionConfig(g, '123456789', '', 'ab'.repeat(32)).addressBook).toBeUndefined();
    expect(buildSessionConfig(g, '123456789', '', undefined, undefined, false).addressBook).toBeUndefined();
    expect(buildSessionConfig({ ...g, addressBook: null }, '123456789', '').addressBook).toBeUndefined();
  });

  it('auto-connects only for the device the page was opened for', () => {
    expect(addressBookReady(g, '123456789')).toBe(true);
    expect(addressBookReady(g, '987654321')).toBe(false);
    expect(addressBookReady({ ...g, addressBook: { ...g.addressBook!, available: false } }, '123456789')).toBe(false);
    expect(addressBookReady({ ...g, addressBook: null }, '123456789')).toBe(false);
  });
});
