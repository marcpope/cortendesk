import { describe, it, expect } from 'vitest';
import { PeerInfo } from '../gen/message';
import { withLoginPeerInfo } from './session';

describe('withLoginPeerInfo', () => {
  const login = PeerInfo.fromPartial({
    username: 'kim', hostname: 'box', platform: 'Linux', version: '1.4.2',
    platform_additions: '{"supported_privacy_mode_impl":[]}',
    displays: [{ width: 1920, height: 1080 }],
  });

  it('fills a display-only PeerInfo from the login one', () => {
    const update = PeerInfo.fromPartial({ displays: [{ width: 2560, height: 1440 }] });
    const pi = withLoginPeerInfo(update, login);
    expect(pi.platform).toBe('Linux');
    expect(pi.version).toBe('1.4.2');
    expect(pi.username).toBe('kim');
    expect(pi.platform_additions).toBe(login.platform_additions);
    expect(pi.displays[0].width).toBe(2560);
  });

  it('leaves a full PeerInfo alone, and passes through without a login one', () => {
    const full = PeerInfo.fromPartial({ platform: 'Windows', version: '1.3.0' });
    expect(withLoginPeerInfo(full, login)).toBe(full);
    const bare = PeerInfo.fromPartial({});
    expect(withLoginPeerInfo(bare, undefined)).toBe(bare);
  });
});
