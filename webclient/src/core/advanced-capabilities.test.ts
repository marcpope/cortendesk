import { describe, expect, it } from 'vitest';
import { parseAdvancedPeerCapabilities } from './advanced-capabilities';

describe('advanced peer capability parsing', () => {
  it('uses RustDesk platform_additions support_view_camera and fails closed', () => {
    expect(parseAdvancedPeerCapabilities('{"support_view_camera":true}').viewCamera).toBe(true);
    expect(parseAdvancedPeerCapabilities('{"support_view_camera":false}').viewCamera).toBe(false);
    expect(parseAdvancedPeerCapabilities('{"support_view_camera":"true"}').viewCamera).toBe(false);
    expect(parseAdvancedPeerCapabilities('{bad json').viewCamera).toBe(false);
    expect(parseAdvancedPeerCapabilities('').viewCamera).toBe(false);
  });
});
