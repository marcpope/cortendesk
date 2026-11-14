import { describe, expect, it, vi } from 'vitest';
import {
  Message,
  Misc,
  OptionMessage_BoolOption,
  PeerInfo,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  SwitchDisplay,
} from '../gen/message';
import type { Encryptor, SessionEvent } from './contracts';

vi.mock('./crypto', () => ({
  StreamCipher: class {
    seal(bytes: Uint8Array): Uint8Array { return bytes; }
    open(bytes: Uint8Array): Uint8Array { return bytes; }
  },
  decodeB64: () => new Uint8Array(),
}));

import { Session } from './session';

const identityCipher: Encryptor = {
  seal: (bytes) => bytes,
  open: (bytes) => bytes,
};

function connectedSession(): { session: Session; events: SessionEvent[]; outbound: Message[] } {
  const events: SessionEvent[] = [];
  const outbound: Message[] = [];
  const session = new Session(
    {
      peerId: '42',
      serverKeyB64: 'AA==',
      wsIdUrl: 'ws://id',
      wsRelayUrl: 'ws://relay',
      password: '',
      myId: 'controller',
      myName: 'Controller',
    },
    {
      relayBuffered: () => 0,
      sendSignaling: () => {},
      sendRelay: (bytes) => outbound.push(Message.decode(bytes)),
      emit: (event) => events.push(event),
      onVideo: () => {},
      onAudioFormat: () => {},
      onAudioFrame: () => {},
      openRelay: () => {},
      closeAll: () => {},
    },
  );
  (session as unknown as { cipher: Encryptor }).cipher = identityCipher;
  return { session, events, outbound };
}

function relay(session: Session, message: Message): Promise<void> {
  return session.onRelayBytes(Message.encode(message).finish());
}

describe('display metadata', () => {
  it('omits non-authoritative current_display from mid-session display-list refreshes', async () => {
    const { session, events } = connectedSession();
    (session as unknown as { state: string }).state = 'streaming';

    await relay(session, Message.fromPartial({
      union: { $case: 'peer_info', peer_info: PeerInfo.fromPartial({
        current_display: 0,
        displays: [
          { width: 1920, height: 1080, online: true },
          { width: 1280, height: 1024, online: true },
        ],
      }) },
    }));

    expect(events.at(-1)).not.toHaveProperty('current');
  });

  it('does not copy the current display resolution list onto other monitors', async () => {
    const { session, events } = connectedSession();
    await relay(session, Message.fromPartial({
      union: { $case: 'peer_info', peer_info: PeerInfo.fromPartial({
        current_display: 0,
        displays: [
          { width: 1920, height: 1080, online: true },
          { width: 1280, height: 1024, online: true },
        ],
        resolutions: { resolutions: [{ width: 1920, height: 1080 }] },
      }) },
    }));
    const peer = events.find((event) => event.t === 'peerInfo');
    expect(peer?.t === 'peerInfo' ? peer.displays[0]?.resolutions : []).toEqual([{ width: 1920, height: 1080 }]);
    expect(peer?.t === 'peerInfo' ? peer.displays[1]?.resolutions : []).toEqual([]);
  });

  it('carries online, cursor, original resolution, and supported modes from PeerInfo', async () => {
    const { session, events } = connectedSession();
    await relay(session, {
      union: {
        $case: 'peer_info',
        peer_info: PeerInfo.fromPartial({
          platform: 'Windows',
          platform_additions: '{"is_installed":true,"idd_impl":"rustdesk_idd"}',
          current_display: 0,
          displays: [
            {
              name: '<Primary>',
              x: 0,
              y: 0,
              width: 1920,
              height: 1080,
              online: true,
              cursor_embedded: true,
              original_resolution: { width: 2560, height: 1440 },
            },
          ],
          resolutions: { resolutions: [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }] },
        }),
      },
    });

    expect(events).toContainEqual({
      t: 'peerInfo',
      username: '',
      hostname: '',
      platform: 'Windows',
      version: '',
      current: 0,
      platformAdditions: '{"is_installed":true,"idd_impl":"rustdesk_idd"}',
      privacyModeSupported: false,
      privacyModeImpls: [],
      terminalSupported: false,
      viewCameraSupported: false,
      displays: [
        {
          index: 0,
          x: 0,
          y: 0,
          width: 1920,
          height: 1080,
          name: '<Primary>',
          scale: 1,
          online: true,
          cursorEmbedded: true,
          originalResolution: { width: 2560, height: 1440 },
          resolutions: [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }],
        },
      ],
    });
  });

  it('uses the authoritative SwitchDisplay geometry and display-specific modes', async () => {
    const { session, events } = connectedSession();
    session.switchDisplay(1);
    await relay(session, {
      union: {
        $case: 'misc',
        misc: Misc.fromPartial({
          union: {
            $case: 'switch_display',
            switch_display: SwitchDisplay.fromPartial({
              display: 1,
              x: 1920,
              y: 0,
              width: 1600,
              height: 900,
              cursor_embedded: true,
              original_resolution: { width: 1920, height: 1080 },
              resolutions: { resolutions: [{ width: 1600, height: 900 }] },
            }),
          },
        }),
      },
    });

    expect(events.at(-1)).toEqual({
      t: 'switchDisplay',
      index: 1,
      x: 1920,
      y: 0,
      width: 1600,
      height: 900,
      cursorEmbedded: true,
      originalResolution: { width: 1920, height: 1080 },
      resolutions: [{ width: 1600, height: 900 }],
    });
  });
});

describe('resolution controls', () => {
  it('sends only valid per-display resolution changes through change_display_resolution', () => {
    const { session, outbound } = connectedSession();

    expect(session.changeDisplayResolution(1, 1600, 900)).toBe(true);
    expect(session.changeDisplayResolution(1, 0, 900)).toBe(false);

    expect(outbound).toHaveLength(1);
    expect(outbound[0]?.union).toEqual({
      $case: 'misc',
      misc: {
        union: {
          $case: 'change_display_resolution',
          change_display_resolution: { display: 1, resolution: { width: 1600, height: 900 } },
        },
      },
    });
  });

  it('sends a virtual-display request without fabricating a display list update', () => {
    const { session, events, outbound } = connectedSession();

    session.toggleVirtualDisplay(2, true);

    expect(events).toEqual([]);
    expect(outbound[0]?.union).toEqual({
      $case: 'misc',
      misc: { union: { $case: 'toggle_virtual_display', toggle_virtual_display: { display: 2, on: true } } },
    });
  });
});

describe('quality and cursor controls', () => {
  it('sends validated custom quality, FPS, and cursor options', () => {
    const { session, outbound } = connectedSession();

    expect(session.setCustomQuality(75)).toBe(true);
    expect(session.setCustomFps(60)).toBe(true);
    expect(session.setCustomFps(4)).toBe(false);
    session.setDisplayOption('showRemoteCursor', true);
    session.setDisplayOption('followRemoteCursor', true);
    session.setDisplayOption('followRemoteWindow', false);

    const options = outbound.map((message) => message.union?.$case === 'misc' ? message.union.misc.union : undefined);
    expect(options).toContainEqual({ $case: 'option', option: expect.objectContaining({ custom_image_quality: 75 << 8 }) });
    expect(options).toContainEqual({ $case: 'option', option: expect.objectContaining({ custom_fps: 60 }) });
    expect(options).toContainEqual({ $case: 'option', option: expect.objectContaining({ show_remote_cursor: OptionMessage_BoolOption.Yes }) });
    expect(options).toContainEqual({ $case: 'option', option: expect.objectContaining({ follow_remote_cursor: OptionMessage_BoolOption.Yes }) });
    expect(options).toContainEqual({ $case: 'option', option: expect.objectContaining({ follow_remote_window: OptionMessage_BoolOption.No }) });
  });

  it('only selects codecs actually advertised by the browser decoder probe', () => {
    const { session, outbound } = connectedSession();
    session.setSupportedDecoding(SupportedDecoding.fromPartial({ ability_vp9: 1, ability_h264: 1 }));
    (session as unknown as { loginSent: boolean }).loginSent = true;

    expect(session.setPreferredCodec(SupportedDecoding_PreferCodec.H264)).toBe(true);
    expect(session.setPreferredCodec(SupportedDecoding_PreferCodec.H265)).toBe(false);
    const option = outbound.at(-1)?.union;
    expect(option?.$case).toBe('misc');
    if (option?.$case === 'misc' && option.misc.union?.$case === 'option') {
      expect(option.misc.union.option.supported_decoding?.prefer).toBe(SupportedDecoding_PreferCodec.H264);
    }
  });

  it('emits host follow-current-display requests instead of remapping input locally', async () => {
    const { session, events } = connectedSession();
    await relay(session, Message.fromPartial({
      union: { $case: 'misc', misc: { union: { $case: 'follow_current_display', follow_current_display: 2 } } },
    }));
    expect(events.at(-1)).toEqual({ t: 'followDisplay', index: 2 });
  });
});
