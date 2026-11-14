import { describe, it, expect, beforeAll } from 'vitest';
import sodium from 'libsodium-wrappers';
import type { SessionConfig, SessionEvent } from './contracts';
import {
  Clipboard,
  ClipboardFormat,
  ControlKey,
  CursorData,
  FileResponse,
  FileTransferSendConfirmRequest,
  Hash,
  IdPk,
  ImageQuality,
  LoginResponse,
  Message,
  PeerInfo,
  PermissionInfo,
  PermissionInfo_Permission,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  TestDelay,
  VideoFrame,
} from '../gen/message';
import { PunchHoleResponse, PunchHoleResponse_Failure, RelayResponse, RendezvousMessage } from '../gen/rendezvous';
import { StreamCipher, sodiumReady } from './crypto';
import { loginPasswordHash } from './auth';
import { CLIENT_VERSION, Session, type SessionSinks } from './session';

const PEER_ID = '123456789';
const PASSWORD = 'pw123';
const UUID = 'test-uuid-1';
const RELAY_SERVER = 'relay.example.com:21117';

let serverKp: sodium.KeyPair;

beforeAll(async () => {
  await sodiumReady();
  serverKp = sodium.crypto_sign_keypair();
});

function signIdPk(id: string, pk: Uint8Array, signSk: Uint8Array): Uint8Array {
  return sodium.crypto_sign(IdPk.encode(IdPk.fromPartial({ id, pk })).finish(), signSk);
}

class FakePeer {
  edKp = sodium.crypto_sign_keypair();
  boxKp = sodium.crypto_box_keypair();
  cipher!: StreamCipher;

  signedIdFrame(id: string = PEER_ID, signSk: Uint8Array = this.edKp.privateKey): Uint8Array {
    return Message.encode({
      union: { $case: 'signed_id', signed_id: { id: signIdPk(id, this.boxKp.publicKey, signSk) } },
    }).finish();
  }

  acceptPublicKey(frame: Uint8Array): void {
    const msg = Message.decode(frame);
    if (msg.union?.$case !== 'public_key') throw new Error('expected public_key');
    const pk = msg.union.public_key;
    expect(pk.asymmetric_value.length).toBe(32);
    expect(pk.symmetric_value.length).toBe(48);
    const key = sodium.crypto_box_open_easy(
      pk.symmetric_value,
      new Uint8Array(24),
      pk.asymmetric_value,
      this.boxKp.privateKey,
    );
    this.cipher = new StreamCipher(key);
  }

  seal(msg: Message): Uint8Array {
    return this.cipher.seal(Message.encode(msg).finish());
  }

  open(frame: Uint8Array): Message {
    return Message.decode(this.cipher.open(frame));
  }
}

function relayResponseFrame(peer: FakePeer, opts?: { signedId?: string; signSk?: Uint8Array; omitPk?: boolean }): Uint8Array {
  return RendezvousMessage.encode({
    union: {
      $case: 'relay_response',
      relay_response: RelayResponse.fromPartial({
        uuid: UUID,
        relay_server: RELAY_SERVER,
        union: opts?.omitPk
          ? undefined
          : {
              $case: 'pk',
              pk: signIdPk(opts?.signedId ?? PEER_ID, peer.edKp.publicKey, opts?.signSk ?? serverKp.privateKey),
            },
      }),
    },
  }).finish();
}

interface Harness {
  session: Session;
  peer: FakePeer;
  events: SessionEvent[];
  signaling: Uint8Array[];
  relay: Uint8Array[];
  videos: VideoFrame[];
  audioFrames: Uint8Array[];
  audioFormats: { sampleRate: number; channels: number }[];
  cursors: CursorData[];
  cursorIds: bigint[];
  rawClipboards: Clipboard[];
  fileResponses: FileResponse[];
  sendConfirms: FileTransferSendConfirmRequest[];
  openRelayCalls: string[];
  closeCalls: { n: number };
  nextRelay(): Uint8Array;
  lastState(): SessionEvent | undefined;
}

function makeHarness(config?: Partial<SessionConfig>): Harness {
  const events: SessionEvent[] = [];
  const signaling: Uint8Array[] = [];
  const relay: Uint8Array[] = [];
  const videos: VideoFrame[] = [];
  const audioFrames: Uint8Array[] = [];
  const audioFormats: { sampleRate: number; channels: number }[] = [];
  const cursors: CursorData[] = [];
  const cursorIds: bigint[] = [];
  const rawClipboards: Clipboard[] = [];
  const fileResponses: FileResponse[] = [];
  const sendConfirms: FileTransferSendConfirmRequest[] = [];
  const openRelayCalls: string[] = [];
  const closeCalls = { n: 0 };
  const sinks: SessionSinks = {
    relayBuffered: () => 0,
    sendSignaling: (b) => signaling.push(b),
    sendRelay: (b) => relay.push(b),
    emit: (ev) => events.push(ev),
    onVideo: (f) => videos.push(f),
    onAudioFormat: (sampleRate, channels) => audioFormats.push({ sampleRate, channels }),
    onAudioFrame: (d) => audioFrames.push(d),
    openRelay: (s) => openRelayCalls.push(s),
    closeAll: () => closeCalls.n++,
    onCursor: (c) => cursors.push(c),
    onCursorId: (id) => cursorIds.push(id),
    onClipboard: (cb) => rawClipboards.push(cb),
    onFileResponse: (fr) => fileResponses.push(fr),
    onFileSendConfirm: (c) => sendConfirms.push(c),
  };
  const peer = new FakePeer();
  const session = new Session(
    {
      peerId: PEER_ID,
      serverKeyB64: sodium.to_base64(serverKp.publicKey, sodium.base64_variants.ORIGINAL),
      wsIdUrl: 'ws://unused/id',
      wsRelayUrl: 'ws://unused/relay',
      password: PASSWORD,
      myId: '987654321',
      myName: 'tester',
      ...config,
    },
    sinks,
  );
  return {
    session,
    peer,
    events,
    signaling,
    relay,
    videos,
    audioFrames,
    audioFormats,
    cursors,
    cursorIds,
    rawClipboards,
    fileResponses,
    sendConfirms,
    openRelayCalls,
    closeCalls,
    nextRelay() {
      const b = relay.shift();
      if (!b) throw new Error('no relay frame queued');
      return b;
    },
    lastState() {
      return events.filter((e) => e.t === 'state').at(-1);
    },
  };
}

// Runs rendezvous + relay + SignedId/PublicKey exchange; cipher installed on both ends.
async function establish(config?: Partial<SessionConfig>): Promise<Harness> {
  const h = makeHarness(config);
  h.session.start();
  h.session.onSignalingBytes(relayResponseFrame(h.peer));
  h.session.relayOpened();
  const rr = RendezvousMessage.decode(h.nextRelay());
  expect(rr.union?.$case).toBe('request_relay');
  await h.session.onRelayBytes(h.peer.signedIdFrame());
  h.peer.acceptPublicKey(h.nextRelay());
  return h;
}

// establish + Hash/LoginRequest + LoginResponse{peer_info}; session is 'streaming'.
async function establishLoggedIn(config?: Partial<SessionConfig>): Promise<Harness> {
  const h = await establish(config);
  await h.session.onRelayBytes(
    h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's1', challenge: 'c1' }) } }),
  );
  h.peer.open(h.nextRelay()); // consume LoginRequest to keep peer recv counter in sync
  await h.session.onRelayBytes(
    h.peer.seal({
      union: {
        $case: 'login_response',
        login_response: LoginResponse.fromPartial({
          union: {
            $case: 'peer_info',
            peer_info: PeerInfo.fromPartial({
              username: 'remoteuser',
              hostname: 'remotehost',
              version: '1.4.0',
              current_display: 0,
              displays: [{ x: 0, y: 0, width: 1920, height: 1080, name: 'D1', scale: 1.25 }],
            }),
          },
        }),
      },
    }),
  );
  expect(h.session.currentState).toBe('streaming');
  return h;
}

describe('rendezvous phase', () => {
  it('start() sends PunchHoleRequest and enters rendezvous', () => {
    const h = makeHarness();
    h.session.start();
    expect(h.session.currentState).toBe('rendezvous');
    const rm = RendezvousMessage.decode(h.signaling[0]!);
    expect(rm.union?.$case).toBe('punch_hole_request');
    if (rm.union?.$case !== 'punch_hole_request') return;
    expect(rm.union.punch_hole_request.id).toBe(PEER_ID);
    expect(rm.union.punch_hole_request.force_relay).toBe(true);
    expect(rm.union.punch_hole_request.licence_key).toBe(sodium.to_base64(serverKp.publicKey, sodium.base64_variants.ORIGINAL));
    expect(rm.union.punch_hole_request.version).toBe(CLIENT_VERSION);
  });

  it('verifies trust link 1 and opens the relay', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    expect(h.openRelayCalls).toEqual([RELAY_SERVER]);
    expect(h.session.currentState).toBe('relay');
    h.session.relayOpened();
    expect(h.session.currentState).toBe('handshake');
    const rr = RendezvousMessage.decode(h.nextRelay());
    expect(rr.union?.$case).toBe('request_relay');
    if (rr.union?.$case !== 'request_relay') return;
    expect(rr.union.request_relay.uuid).toBe(UUID);
    expect(rr.union.request_relay.id).toBe(PEER_ID);
  });

  it('fails on a RelayResponse without pk', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer, { omitPk: true }));
    expect(h.session.currentState).toBe('error');
    expect(h.closeCalls.n).toBe(1);
    expect(h.openRelayCalls).toEqual([]);
  });

  it('fails when the relay pk is not signed by the server key', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer, { signSk: h.peer.edKp.privateKey }));
    expect(h.session.currentState).toBe('error');
    expect(h.openRelayCalls).toEqual([]);
  });

  it('fails when the server-signed id does not match the peer id', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer, { signedId: '000000000' }));
    expect(h.session.currentState).toBe('error');
  });

  it('surfaces punch-hole failures', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(
      RendezvousMessage.encode({
        union: {
          $case: 'punch_hole_response',
          punch_hole_response: PunchHoleResponse.fromPartial({ failure: PunchHoleResponse_Failure.OFFLINE }),
        },
      }).finish(),
    );
    const st = h.lastState();
    expect(st).toMatchObject({ t: 'state', state: 'error' });
    if (st?.t === 'state') expect(st.detail).toContain('OFFLINE');
  });

  it('fails on malformed rendezvous bytes', () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(new Uint8Array([0xff, 0xff, 0xff, 0xff, 0xff]));
    expect(h.session.currentState).toBe('error');
  });
});

describe('handshake phase', () => {
  it('answers a valid SignedId with a PublicKey message and installs the cipher', async () => {
    const h = await establish();
    // Cipher live: peer-sealed frame dispatches, session-sealed reply opens on the peer side.
    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'test_delay', test_delay: TestDelay.fromPartial({ time: 42n, from_client: false }) },
      }),
    );
    const echo = h.peer.open(h.nextRelay());
    expect(echo.union?.$case).toBe('test_delay');
  });

  it('accepts a SignedId whose id carries a NUL-separated suffix', async () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    h.session.relayOpened();
    h.nextRelay();
    await h.session.onRelayBytes(h.peer.signedIdFrame(`${PEER_ID}\u0000extra`));
    expect(h.session.currentState).toBe('handshake');
    h.peer.acceptPublicKey(h.nextRelay());
  });

  it('rejects a SignedId signed by the wrong key', async () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    h.session.relayOpened();
    h.nextRelay();
    const other = sodium.crypto_sign_keypair();
    await h.session.onRelayBytes(h.peer.signedIdFrame(PEER_ID, other.privateKey));
    expect(h.session.currentState).toBe('error');
    expect(h.closeCalls.n).toBe(1);
  });

  it('rejects a SignedId embedding a different peer id', async () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    h.session.relayOpened();
    h.nextRelay();
    await h.session.onRelayBytes(h.peer.signedIdFrame('000000000'));
    expect(h.session.currentState).toBe('error');
  });

  it('rejects a first relay frame that is not signed_id', async () => {
    const h = makeHarness();
    h.session.start();
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    h.session.relayOpened();
    h.nextRelay();
    await h.session.onRelayBytes(
      Message.encode({ union: { $case: 'cursor_id', cursor_id: 1n } }).finish(),
    );
    expect(h.session.currentState).toBe('error');
  });

  it('drops outbound controls before the cipher exists', () => {
    const h = makeHarness();
    h.session.start();
    h.session.sendMouse(9, 100, 200, []);
    h.session.refresh();
    expect(h.relay.length).toBe(0);
  });
});

describe('login phase', () => {
  it('answers Hash with an encrypted LoginRequest carrying the double-SHA256 password', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 'salt123', challenge: 'chal456' }) } }),
    );
    expect(h.session.currentState).toBe('login');
    const msg = h.peer.open(h.nextRelay());
    expect(msg.union?.$case).toBe('login_request');
    if (msg.union?.$case !== 'login_request') return;
    const lr = msg.union.login_request;
    expect(lr.password).toEqual(await loginPasswordHash(PASSWORD, 'salt123', 'chal456'));
    expect(lr.username).toBe(PEER_ID);
    expect(lr.my_id).toBe('987654321');
    expect(lr.my_name).toBe('tester');
    expect(lr.video_ack_required).toBe(true); // paced flow control (flood-safe)
    expect(lr.session_id).toBeGreaterThan(0n);
    expect(lr.option?.supported_decoding?.ability_vp9).toBe(1);
    expect(lr.option?.supported_decoding?.ability_vp8).toBe(1);
    expect(lr.option?.supported_decoding?.ability_h264).toBe(0);
  });

  it('emits peerInfo and enters streaming on LoginResponse{peer_info}', async () => {
    const h = await establishLoggedIn();
    const pi = h.events.find((e) => e.t === 'peerInfo');
    expect(pi).toBeDefined();
    if (pi?.t !== 'peerInfo') return;
    expect(pi.username).toBe('remoteuser');
    expect(pi.hostname).toBe('remotehost');
    expect(pi.version).toBe('1.4.0');
    expect(pi.current).toBe(0);
    expect(pi.displays).toEqual([{ index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'D1', scale: 1.25, online: false, cursorEmbedded: false, resolutions: [] }]);
    expect(h.lastState()).toMatchObject({ t: 'state', state: 'streaming' });
  });

  it('emits loginError on LoginResponse{error} without changing state', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    h.peer.open(h.nextRelay());
    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'login_response', login_response: LoginResponse.fromPartial({ union: { $case: 'error', error: 'Wrong Password' } }) },
      }),
    );
    expect(h.events.at(-1)).toEqual({ t: 'loginError', message: 'Wrong Password' });
    expect(h.session.currentState).toBe('login');
  });

  it('enters needAccept when the error signals manual acceptance', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'login_response',
          login_response: LoginResponse.fromPartial({
            union: { $case: 'error', error: 'Please wait for the remote side accepting your session request' },
          }),
        },
      }),
    );
    expect(h.session.currentState).toBe('needAccept');
    // No loginError here: that path tears down and clears a saved password,
    // and waiting for acceptance is not a credential failure.
    expect(h.events.some((e) => e.t === 'loginError')).toBe(false);
  });
});

describe('click-to-approve (issue #98)', () => {
  it('treats "No Password Access" as waiting for acceptance, not a failure', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'login_response',
          login_response: LoginResponse.fromPartial({ union: { $case: 'error', error: 'No Password Access' } }),
        },
      }),
    );
    expect(h.session.currentState).toBe('needAccept');
    expect(h.events.some((e) => e.t === 'loginError')).toBe(false);
  });

  it('enters needAccept as soon as an empty-password login is sent', async () => {
    const h = await establish({ password: '', savedHashHex: undefined });
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    const msg = h.peer.open(h.nextRelay());
    expect(msg.union?.$case).toBe('login_request');
    if (msg.union?.$case !== 'login_request') return;
    expect(msg.union.login_request.password.length).toBe(0);
    expect(h.session.currentState).toBe('needAccept');
    // Acceptance still lands as a normal login response.
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'login_response',
          login_response: LoginResponse.fromPartial({ union: { $case: 'peer_info', peer_info: { username: 'u', hostname: 'h', version: '1.4.0' } } }),
        },
      }),
    );
    expect(h.session.currentState).toBe('streaming');
  });

  it('still reports a wrong password typed by the user as a login error', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    h.peer.open(h.nextRelay());
    expect(h.session.currentState).toBe('login');
  });
});

describe('streaming dispatch', () => {
  it('echoes test_delay verbatim before anything else', async () => {
    const h = await establishLoggedIn();
    const td = TestDelay.fromPartial({ time: 123456n, from_client: false, last_delay: 7, target_bitrate: 900 });
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'test_delay', test_delay: td } }));
    const echo = h.peer.open(h.nextRelay());
    expect(echo.union?.$case).toBe('test_delay');
    if (echo.union?.$case !== 'test_delay') return;
    expect(echo.union.test_delay).toEqual(td);
  });

  it('does not echo a test_delay marked from_client', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'test_delay', test_delay: TestDelay.fromPartial({ time: 1n, from_client: true }) } }),
    );
    expect(h.relay.length).toBe(0);
  });

  it('routes video frames to onVideo and acks with video_received', async () => {
    const h = await establishLoggedIn();
    const vf = VideoFrame.fromPartial({
      display: 0,
      union: { $case: 'vp9s', vp9s: { frames: [{ data: new Uint8Array([1, 2, 3]), key: true, pts: 40n }] } },
    });
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'video_frame', video_frame: vf } }));
    expect(h.videos.length).toBe(1);
    expect(h.videos[0]).toEqual(vf);
    const ack = h.peer.open(h.nextRelay());
    expect(ack.union?.$case).toBe('misc');
    if (ack.union?.$case !== 'misc') return;
    expect(ack.union.misc.union).toEqual({ $case: 'video_received', video_received: true });
  });

  it('free-runs without acks when told to (WebCodecs path)', async () => {
    const h = await establish({ videoAck: false });
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's1', challenge: 'c1' }) } }),
    );
    const lr = h.peer.open(h.nextRelay());
    if (lr.union?.$case !== 'login_request') throw new Error('expected login_request');
    expect(lr.union.login_request.video_ack_required).toBe(false);
  });

  it('sends no video_received when acks are off', async () => {
    const h = await establishLoggedIn({ videoAck: false });
    const vf = VideoFrame.fromPartial({
      display: 0,
      union: { $case: 'vp9s', vp9s: { frames: [{ data: new Uint8Array([1]), key: true, pts: 1n }] } },
    });
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'video_frame', video_frame: vf } }));
    expect(h.videos.length).toBe(1);
    expect(h.relay.length).toBe(0);
  });

  it('routes audio format and frames', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'misc', misc: { union: { $case: 'audio_format', audio_format: { sample_rate: 48000, channels: 2 } } } },
      }),
    );
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'audio_frame', audio_frame: { data: new Uint8Array([9, 9]) } } }),
    );
    expect(h.audioFormats).toEqual([{ sampleRate: 48000, channels: 2 }]);
    expect(h.audioFrames).toEqual([new Uint8Array([9, 9])]);
  });

  it('emits cursorPos and forwards cursor_data / cursor_id to sinks', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'cursor_position', cursor_position: { x: 10, y: 20 } } }));
    expect(h.events.at(-1)).toEqual({ t: 'cursorPos', x: 10, y: 20 });
    const cd = CursorData.fromPartial({ id: 5n, hotx: 1, hoty: 2, width: 8, height: 8, colors: new Uint8Array(4) });
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'cursor_data', cursor_data: cd } }));
    expect(h.cursors[0]).toEqual(cd);
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'cursor_id', cursor_id: 5n } }));
    expect(h.cursorIds).toEqual([5n]);
  });

  it('emits clipboard text for uncompressed Text and defers compressed to onClipboard', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'clipboard',
          clipboard: Clipboard.fromPartial({ compress: false, content: new TextEncoder().encode('hello'), format: ClipboardFormat.Text }),
        },
      }),
    );
    expect(h.events.at(-1)).toEqual({ t: 'clipboard', text: 'hello' });
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'clipboard',
          clipboard: Clipboard.fromPartial({ compress: true, content: new Uint8Array([1]), format: ClipboardFormat.Text }),
        },
      }),
    );
    expect(h.rawClipboards.length).toBe(1);
  });

  it('emits permission events with the enum name as kind', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'misc',
          misc: {
            union: {
              $case: 'permission_info',
              permission_info: PermissionInfo.fromPartial({ permission: PermissionInfo_Permission.Clipboard, enabled: true }),
            },
          },
        },
      }),
    );
    expect(h.events.at(-1)).toEqual({ t: 'permission', kind: 'Clipboard', enabled: true });
  });

  it('emits inbound chat and ignores empty keepalive texts', async () => {
    const h = await establishLoggedIn();
    const chat = (text: string) =>
      h.peer.seal({
        union: { $case: 'misc', misc: { union: { $case: 'chat_message', chat_message: { text } } } },
      });

    await h.session.onRelayBytes(chat('hello from the peer'));
    expect(h.events.at(-1)).toEqual({ t: 'chat', text: 'hello from the peer' });

    // An empty text carries no message and must not surface as one.
    const before = h.events.length;
    await h.session.onRelayBytes(chat(''));
    expect(h.events.length).toBe(before);
  });

  it('sends chat as a Misc message, not a top-level one', async () => {
    const h = await establishLoggedIn();
    h.session.sendChat('reply from the console');
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'misc') throw new Error('expected misc');
    const misc = msg.union.misc.union;
    if (misc?.$case !== 'chat_message') throw new Error('expected chat_message inside misc');
    expect(misc.chat_message.text).toBe('reply from the console');
  });

  it('closes on misc close_reason', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'misc', misc: { union: { $case: 'close_reason', close_reason: 'Peer exit' } } } }),
    );
    expect(h.session.currentState).toBe('closed');
    expect(h.closeCalls.n).toBe(1);
  });

  it('passes len<=1 relay frames through without consuming the recv counter', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(new Uint8Array(0)); // bypass — must not touch recvSeq
    expect(h.session.currentState).toBe('streaming');
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'cursor_position', cursor_position: { x: 1, y: 2 } } }));
    expect(h.events.at(-1)).toEqual({ t: 'cursorPos', x: 1, y: 2 });
  });

  it('fails on a tampered ciphertext', async () => {
    const h = await establishLoggedIn();
    const frame = h.peer.seal({ union: { $case: 'cursor_position', cursor_position: { x: 1, y: 2 } } });
    frame[5]! ^= 0xff;
    await h.session.onRelayBytes(frame);
    expect(h.session.currentState).toBe('error');
    expect(h.closeCalls.n).toBe(1);
  });

  it('emits peerInfo on a top-level mid-session PeerInfo', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'peer_info',
          peer_info: PeerInfo.fromPartial({
            username: 'remoteuser',
            hostname: 'remotehost',
            version: '1.4.0',
            current_display: 1,
            displays: [
              { x: 0, y: 0, width: 1920, height: 1080, name: 'D1', scale: 0 },
              { x: 1920, y: 0, width: 2560, height: 1440, name: 'D2', scale: 2 },
            ],
          }),
        },
      }),
    );
    const pi = h.events.at(-1);
    expect(pi?.t).toBe('peerInfo');
    if (pi?.t !== 'peerInfo') return;
    // Mid-session refreshes omit `current`: the display list changed, but the
    // remote's current display must not yank the local selection.
    expect(pi.current).toBeUndefined();
    expect(pi.displays.map((d) => d.index)).toEqual([0, 1]);
    expect(pi.displays[0]!.scale).toBe(1); // proto default 0 normalized to 1
    expect(pi.displays[1]!.scale).toBe(2);
    expect(h.session.currentState).toBe('streaming');
  });
});

describe('setSupportedDecoding', () => {
  it('rides in the LoginRequest when set before the Hash arrives', async () => {
    const h = await establish();
    h.session.setSupportedDecoding(
      SupportedDecoding.fromPartial({
        ability_vp9: 1,
        ability_vp8: 1,
        ability_h264: 1,
        ability_av1: 1,
        prefer: SupportedDecoding_PreferCodec.Auto,
      }),
    );
    expect(h.relay.length).toBe(0); // pre-login: stored, not re-advertised
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'login_request') throw new Error('expected login_request');
    const sd = msg.union.login_request.option?.supported_decoding;
    expect(sd?.ability_h264).toBe(1);
    expect(sd?.ability_av1).toBe(1);
  });

  it('sends remembered session options and codec preference in the LoginRequest (issue #92)', async () => {
    const h = await establish({
      initialOptions: {
        imageQuality: ImageQuality.Best,
        customFps: 60,
        preferCodec: SupportedDecoding_PreferCodec.H264,
        disableClipboard: true,
        showRemoteCursor: true,
      },
    });
    h.session.setSupportedDecoding(
      SupportedDecoding.fromPartial({ ability_vp9: 1, ability_h264: 1, prefer: SupportedDecoding_PreferCodec.Auto }),
    );
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'login_request') throw new Error('expected login_request');
    const option = msg.union.login_request.option!;
    expect(option.image_quality).toBe(ImageQuality.Best);
    expect(option.custom_fps).toBe(60);
    expect(option.custom_image_quality).toBe(0);
    expect(option.disable_clipboard).toBe(2); // BoolOption.Yes
    expect(option.show_remote_cursor).toBe(2);
    expect(option.disable_audio).toBe(0); // not set: the peer keeps its default
    expect(option.supported_decoding?.prefer).toBe(SupportedDecoding_PreferCodec.H264);
  });

  it('ignores a remembered codec this browser cannot decode', async () => {
    const h = await establish({ initialOptions: { preferCodec: SupportedDecoding_PreferCodec.AV1 } });
    h.session.setSupportedDecoding(
      SupportedDecoding.fromPartial({ ability_vp9: 1, prefer: SupportedDecoding_PreferCodec.Auto }),
    );
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'login_request') throw new Error('expected login_request');
    expect(msg.union.login_request.option?.supported_decoding?.prefer).toBe(SupportedDecoding_PreferCodec.Auto);
  });

  it('re-advertises via Misc option after login', async () => {
    const h = await establishLoggedIn();
    h.session.setSupportedDecoding(
      SupportedDecoding.fromPartial({ ability_vp9: 1, prefer: SupportedDecoding_PreferCodec.Auto }),
    );
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'misc') throw new Error('expected misc');
    expect(msg.union.misc.union?.$case).toBe('option');
    if (msg.union.misc.union?.$case !== 'option') return;
    const sd = msg.union.misc.union.option.supported_decoding;
    expect(sd?.ability_vp9).toBe(1);
    expect(sd?.ability_vp8).toBe(0);
  });
});

describe('outbound controls', () => {
  it('seals mouse events', async () => {
    const h = await establishLoggedIn();
    h.session.sendMouse(9, 100, 200, [ControlKey.Control]);
    const msg = h.peer.open(h.nextRelay());
    expect(msg.union?.$case).toBe('mouse_event');
    if (msg.union?.$case !== 'mouse_event') return;
    expect(msg.union.mouse_event.mask).toBe(9);
    expect(msg.union.mouse_event.x).toBe(100);
    expect(msg.union.mouse_event.y).toBe(200);
    expect(msg.union.mouse_event.modifiers).toEqual([ControlKey.Control]);
  });

  it('seals key events for chr, control and unicode kinds', async () => {
    const h = await establishLoggedIn();
    h.session.sendKey(true, false, 'chr', 30, []);
    h.session.sendKey(false, true, 'control', ControlKey.Return, [ControlKey.Shift]);
    h.session.sendKey(true, false, 'unicode', 0x00e9, []);
    const a = h.peer.open(h.nextRelay());
    const b = h.peer.open(h.nextRelay());
    const c = h.peer.open(h.nextRelay());
    if (a.union?.$case !== 'key_event' || b.union?.$case !== 'key_event' || c.union?.$case !== 'key_event') {
      throw new Error('expected key_event frames');
    }
    expect(a.union.key_event.down).toBe(true);
    expect(a.union.key_event.union).toEqual({ $case: 'chr', chr: 30 });
    expect(b.union.key_event.press).toBe(true);
    expect(b.union.key_event.union).toEqual({ $case: 'control_key', control_key: ControlKey.Return });
    expect(b.union.key_event.modifiers).toEqual([ControlKey.Shift]);
    expect(c.union.key_event.union).toEqual({ $case: 'unicode', unicode: 0x00e9 });
  });

  it('sends CtrlAltDel as a pressed control key', async () => {
    const h = await establishLoggedIn();
    h.session.ctrlAltDel();
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'key_event') throw new Error('expected key_event');
    expect(msg.union.key_event.press).toBe(true);
    expect(msg.union.key_event.union).toEqual({ $case: 'control_key', control_key: ControlKey.CtrlAltDel });
  });

  it('sends switchDisplay, refresh and quality as misc messages', async () => {
    const h = await establishLoggedIn();
    h.session.switchDisplay(2);
    h.session.refresh();
    h.session.setQuality(ImageQuality.Balanced);
    const sw = h.peer.open(h.nextRelay());
    // switchDisplay emits a capture_displays straight after, to prune the
    // capture set — see 'prunes the capture set' below.
    const cd = h.peer.open(h.nextRelay());
    const rf = h.peer.open(h.nextRelay());
    const q = h.peer.open(h.nextRelay());
    if (
      sw.union?.$case !== 'misc' || cd.union?.$case !== 'misc' ||
      rf.union?.$case !== 'misc' || q.union?.$case !== 'misc'
    ) {
      throw new Error('expected misc frames');
    }
    expect(sw.union.misc.union?.$case).toBe('switch_display');
    if (sw.union.misc.union?.$case === 'switch_display') expect(sw.union.misc.union.switch_display.display).toBe(2);
    expect(cd.union.misc.union?.$case).toBe('capture_displays');
    expect(rf.union.misc.union).toEqual({ $case: 'refresh_video', refresh_video: true });
    expect(q.union.misc.union?.$case).toBe('option');
    if (q.union.misc.union?.$case === 'option') expect(q.union.misc.union.option.image_quality).toBe(ImageQuality.Balanced);
  });

  it('sends clipboard text uncompressed', async () => {
    const h = await establishLoggedIn();
    h.session.sendClipboardText('copy me');
    const msg = h.peer.open(h.nextRelay());
    if (msg.union?.$case !== 'clipboard') throw new Error('expected clipboard');
    expect(msg.union.clipboard.compress).toBe(false);
    expect(msg.union.clipboard.format).toBe(ClipboardFormat.Text);
    expect(new TextDecoder().decode(msg.union.clipboard.content)).toBe('copy me');
  });

  it('disconnect() closes the session and its transports', async () => {
    const h = await establishLoggedIn();
    h.session.disconnect();
    expect(h.session.currentState).toBe('closed');
    expect(h.closeCalls.n).toBe(1);
    // Post-close inbound frames are ignored.
    await h.session.onRelayBytes(h.peer.seal({ union: { $case: 'cursor_position', cursor_position: { x: 3, y: 4 } } }));
    expect(h.events.some((e) => e.t === 'cursorPos' && e.x === 3)).toBe(false);
  });
});

describe('uac and message_box', () => {
  it('emits a uac event from misc.uac', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'misc', misc: { union: { $case: 'uac', uac: true } } } }),
    );
    expect(h.events.at(-1)).toEqual({ t: 'uac', on: true });
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'misc', misc: { union: { $case: 'uac', uac: false } } } }),
    );
    expect(h.events.at(-1)).toEqual({ t: 'uac', on: false });
  });

  it('emits a msgbox event from message_box', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'message_box',
          message_box: { msgtype: 'wait-uac', title: 'UAC', text: 'Waiting for elevation', link: '' },
        },
      }),
    );
    expect(h.events.at(-1)).toEqual({
      t: 'msgbox',
      msgtype: 'wait-uac',
      title: 'UAC',
      text: 'Waiting for elevation',
      link: '',
    });
  });
});

describe('file-transfer connections', () => {
  it('advertises FILE_TRANSFER in punch hole and request relay', async () => {
    const h = makeHarness({ connType: 'fileTransfer' });
    h.session.start();
    const ph = RendezvousMessage.decode(h.signaling[0]!);
    expect(ph.union?.$case).toBe('punch_hole_request');
    if (ph.union?.$case === 'punch_hole_request') {
      expect(ph.union.punch_hole_request.conn_type).toBe(1); // FILE_TRANSFER
    }
    h.session.onSignalingBytes(relayResponseFrame(h.peer));
    h.session.relayOpened();
    const rr = RendezvousMessage.decode(h.nextRelay());
    expect(rr.union?.$case).toBe('request_relay');
    if (rr.union?.$case === 'request_relay') {
      expect(rr.union.request_relay.conn_type).toBe(1);
    }
  });

  it('sends a LoginRequest carrying the file_transfer union and no video options', async () => {
    const h = await establish({ connType: 'fileTransfer' });
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's1', challenge: 'c1' }) } }),
    );
    const lr = h.peer.open(h.nextRelay());
    expect(lr.union?.$case).toBe('login_request');
    if (lr.union?.$case === 'login_request') {
      expect(lr.union.login_request.union?.$case).toBe('file_transfer');
      expect(lr.union.login_request.option).toBeUndefined();
      expect(lr.union.login_request.video_ack_required).toBe(false);
    }
  });

  it('logs in with the session_id it was given, so a side connection joins the live session (#122)', async () => {
    const h = await establish({ connType: 'fileTransfer', sessionId: '1234567890123456789' });
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's1', challenge: 'c1' }) } }),
    );
    const lr = h.peer.open(h.nextRelay());
    if (lr.union?.$case !== 'login_request') throw new Error('expected login_request');
    expect(lr.union.login_request.session_id.toString()).toBe('1234567890123456789');
  });

  it('routes inbound file_response to the sink', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({ union: { $case: 'hash', hash: Hash.fromPartial({ salt: 's', challenge: 'c' }) } }),
    );
    h.peer.open(h.nextRelay());
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'file_response',
          file_response: {
            union: {
              $case: 'dir',
              dir: { id: 0, path: '/home/user', entries: [] },
            },
          },
        },
      }),
    );
    expect(h.fileResponses).toHaveLength(1);
    expect(h.fileResponses[0]!.union?.$case).toBe('dir');
  });

  it('routes inbound file_action send_confirm to the sink', async () => {
    const h = await establish();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'file_action',
          file_action: {
            union: {
              $case: 'send_confirm',
              send_confirm: { id: 7, file_num: 2, union: { $case: 'offset_blk', offset_blk: 4096 } },
            },
          },
        },
      }),
    );
    expect(h.sendConfirms).toHaveLength(1);
    expect(h.sendConfirms[0]!.id).toBe(7);
    expect(h.sendConfirms[0]!.union).toEqual({ $case: 'offset_blk', offset_blk: 4096 });
  });

  it('encodes outbound file actions and file responses', async () => {
    const h = await establish();
    h.session.sendFileAction({ $case: 'read_dir', read_dir: { path: 'C:\\Users', include_hidden: true } });
    const fa = h.peer.open(h.nextRelay());
    expect(fa.union?.$case).toBe('file_action');
    if (fa.union?.$case === 'file_action') {
      expect(fa.union.file_action.union).toEqual({
        $case: 'read_dir',
        read_dir: { path: 'C:\\Users', include_hidden: true },
      });
    }
    h.session.sendFileResponse({
      $case: 'block',
      block: {
        id: 3,
        file_num: 0,
        data: new Uint8Array([1, 2, 3]),
        compressed: false,
        blk_id: 0,
      },
    });
    const fr = h.peer.open(h.nextRelay());
    expect(fr.union?.$case).toBe('file_response');
    if (fr.union?.$case === 'file_response') {
      expect(fr.union.file_response.union?.$case).toBe('block');
      if (fr.union.file_response.union?.$case === 'block') {
        expect(Array.from(fr.union.file_response.union.block.data)).toEqual([1, 2, 3]);
      }
    }
  });
});

describe('display switching', () => {
  /*
   * The host's ONLY reply to a switch_display request is Misc.switch_display
   * (server/video_service.rs make_display_changed_msg). It carries the origin
   * of the display now being captured, and input coordinates are absolute
   * virtual-desktop positions — so dropping this message meant clicks kept
   * landing on whichever monitor the stale origin pointed at.
   */
  it('emits switchDisplay with the geometry the host reports', async () => {
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'misc',
          misc: {
            union: {
              $case: 'switch_display',
              switch_display: {
                display: 1,
                x: 1920,
                y: 0,
                width: 2560,
                height: 1440,
                cursor_embedded: false,
              },
            },
          },
        },
      }),
    );
    expect(h.events.at(-1)).toEqual({
      t: 'switchDisplay',
      index: 1,
      x: 1920,
      y: 0,
      width: 2560,
      height: 1440,
      cursorEmbedded: false,
      originalResolution: undefined,
      resolutions: [],
    });
  });

  it('carries a non-zero origin, which is what selects the monitor', async () => {
    // Guards the specific regression: an origin flattened to 0 sends every
    // click to the primary monitor no matter which display is on screen.
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: {
          $case: 'misc',
          misc: {
            union: {
              $case: 'switch_display',
              switch_display: {
                display: 2,
                x: -1920,
                y: -200,
                width: 1920,
                height: 1080,
                cursor_embedded: true,
              },
            },
          },
        },
      }),
    );
    const ev = h.events.at(-1);
    expect(ev?.t).toBe('switchDisplay');
    if (ev?.t === 'switchDisplay') {
      // Negative origins are ordinary: a monitor placed left of / above the
      // primary has them, and they must survive intact.
      expect(ev.x).toBe(-1920);
      expect(ev.y).toBe(-200);
      expect(ev.cursorEmbedded).toBe(true);
    }
  });

  it('prunes the capture set so the old display stops broadcasting', async () => {
    // connection.rs switch_display_to only unsubscribes the old video service
    // for clients BELOW 1.2.4; we advertise 1.4.0, so the host waits for a
    // CaptureDisplays and leaves the old service running until it arrives.
    // Without this the old service replayed a stale switch_display snapshot
    // that put the display index -- and therefore all input -- back on the
    // monitor we had just left.
    const h = await establishLoggedIn();
    h.session.switchDisplay(1);
    const first = h.peer.open(h.nextRelay());
    const second = h.peer.open(h.nextRelay());
    expect(first.union?.$case).toBe('misc');
    expect(second.union?.$case).toBe('misc');
    if (second.union?.$case === 'misc' && second.union.misc.union?.$case === 'capture_displays') {
      const cd = second.union.misc.union.capture_displays;
      expect(cd.set).toEqual([1]);
      expect(cd.add).toEqual([]);
      expect(cd.sub).toEqual([]);
    } else {
      throw new Error('expected a capture_displays after switch_display');
    }
  });

  it('ignores a stale switch_display for a display we did not ask for', async () => {
    // The real trace: the host confirmed display 1, then the old display's
    // service replayed its snapshot naming display 0, and we followed it.
    const h = await establishLoggedIn();
    h.session.switchDisplay(1);
    h.peer.open(h.nextRelay());
    h.peer.open(h.nextRelay());
    const before = h.events.length;

    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'misc', misc: { union: { $case: 'switch_display',
          switch_display: { display: 0, x: 0, y: 0, width: 1920, height: 1080, cursor_embedded: false } } } },
      }),
    );
    expect(h.events.length).toBe(before); // dropped, not emitted

    // The one we actually asked for still gets through.
    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'misc', misc: { union: { $case: 'switch_display',
          switch_display: { display: 1, x: 1920, y: 0, width: 3840, height: 2160, cursor_embedded: false } } } },
      }),
    );
    expect(h.events.at(-1)).toMatchObject({ t: 'switchDisplay', index: 1, x: 1920 });
  });

  it('follows a host-initiated switch once nothing is pending', async () => {
    // The guard must not deafen us permanently: a switch we never requested
    // (monitor unplugged, host-side change) is legitimate news.
    const h = await establishLoggedIn();
    await h.session.onRelayBytes(
      h.peer.seal({
        union: { $case: 'misc', misc: { union: { $case: 'switch_display',
          switch_display: { display: 0, x: 0, y: 0, width: 1920, height: 1080, cursor_embedded: false } } } },
      }),
    );
    expect(h.events.at(-1)).toMatchObject({ t: 'switchDisplay', index: 0 });
  });

  it('never populates width/height on an outgoing switch request', async () => {
    // server/connection.rs handle_switch_display: a non-zero width/height on
    // the REQUEST makes the host CHANGE THAT MONITOR'S RESOLUTION. We only
    // ever want to change which display is captured, so these must stay 0.
    const h = await establishLoggedIn();
    h.session.switchDisplay(1);
    const msg = h.peer.open(h.nextRelay());
    expect(msg.union?.$case).toBe('misc');
    if (msg.union?.$case === 'misc' && msg.union.misc.union?.$case === 'switch_display') {
      const sd = msg.union.misc.union.switch_display;
      expect(sd.display).toBe(1);
      expect(sd.width).toBe(0);
      expect(sd.height).toBe(0);
    }
  });
});
