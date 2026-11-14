import { describe, it, expect } from 'vitest';
import type {
  SessionConfig,
  SessionState,
  SessionStats,
  Transport,
  UiCommand,
} from '../core/contracts';
import type { SessionSinks } from '../core/session';
import type { EncodedCase } from '../media/video';
import {
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  VideoFrame,
  type Clipboard,
  type CursorData,
} from '../gen/message';
import {
  WorkerHost,
  type AudioPipelineLike,
  type SessionLike,
  type VideoPipelineLike,
  type WorkerOutMessage,
} from './session.worker';

const CONFIG: SessionConfig = {
  peerId: '123456789',
  serverKeyB64: 'AAAA',
  wsIdUrl: 'ws://example/id',
  wsRelayUrl: 'ws://example/relay',
  password: 'pw',
  myId: '987654321',
  myName: 'tester',
};

const PROBE = SupportedDecoding.fromPartial({
  ability_vp9: 1,
  ability_vp8: 1,
  ability_h264: 1,
  ability_av1: 0,
  prefer: SupportedDecoding_PreferCodec.Auto,
});

const tick = () => new Promise<void>((r) => setTimeout(r, 0));

class FakeTransport implements Transport {
  sent: Uint8Array[] = [];
  msgCb: ((b: Uint8Array) => void) | null = null;
  closeCbs: Array<() => void> = [];
  closed = false;
  send(b: Uint8Array): void {
    this.sent.push(b);
  }
  onMessage(cb: (b: Uint8Array) => void): void {
    this.msgCb = cb;
  }
  onClose(cb: () => void): void {
    this.closeCbs.push(cb);
  }
  close(): void {
    if (this.closed) return;
    this.closed = true;
    for (const cb of this.closeCbs) cb(); // mirrors WsStream: close fires onClose
  }
}

class FakeSession implements SessionLike {
  calls: Array<[string, ...unknown[]]> = [];
  state: SessionState = 'connecting';
  sinks: SessionSinks;
  constructor(sinks: SessionSinks) {
    this.sinks = sinks;
  }
  get currentState(): SessionState {
    return this.state;
  }
  start(): void {
    this.calls.push(['start']);
  }
  onSignalingBytes(b: Uint8Array): void {
    this.calls.push(['onSignalingBytes', b]);
  }
  async onRelayBytes(b: Uint8Array): Promise<void> {
    this.calls.push(['onRelayBytes', b]);
  }
  relayOpened(): void {
    this.calls.push(['relayOpened']);
  }
  setSupportedDecoding(sd: SupportedDecoding): void {
    this.calls.push(['setSupportedDecoding', sd]);
  }
  // PR #42 widened SessionLike; the fake records calls like the rest.
  restartRemoteDevice(): void { this.calls.push(['restartRemoteDevice']); }
  requestElevation(): void { this.calls.push(['requestElevation']); }
  setPrivacyMode(implKey: string, on: boolean): void { this.calls.push(['setPrivacyMode', implKey, on]); }
  setBlockInput(on: boolean): void { this.calls.push(['setBlockInput', on]); }
  setLockAfterSessionEnd(on: boolean): void { this.calls.push(['setLockAfterSessionEnd', on]); }
  changeDisplayResolution(display: number, width: number, height: number): boolean { this.calls.push(['changeDisplayResolution', display, width, height]); return true; }
  toggleVirtualDisplay(display: number, on: boolean): boolean { this.calls.push(['toggleVirtualDisplay', display, on]); return true; }
  setCustomQuality(quality: number): boolean { this.calls.push(['setCustomQuality', quality]); return true; }
  setCustomFps(fps: number): boolean { this.calls.push(['setCustomFps', fps]); return true; }
  setPreferredCodec(prefer: unknown): boolean { this.calls.push(['setPreferredCodec', prefer]); return true; }
  setDisplayOption(option: string, enabled: boolean): void { this.calls.push(['setDisplayOption', option, enabled]); }
  setRemoteAudioEnabled(enabled: boolean): void { this.calls.push(['setRemoteAudioEnabled', enabled]); }
  setClientRecording(recording: boolean): void { this.calls.push(['setClientRecording', recording]); }
  setClipboardEnabled(enabled: boolean): void { this.calls.push(['setClipboardEnabled', enabled]); }
  startVoiceCall(): void { this.calls.push(['startVoiceCall']); }
  closeVoiceCall(): void { this.calls.push(['closeVoiceCall']); }
  sendVoiceAudioFormat(sampleRate: number, channels: number): void { this.calls.push(['sendVoiceAudioFormat', sampleRate, channels]); }
  sendVoiceAudioFrame(data: Uint8Array): void { this.calls.push(['sendVoiceAudioFrame', data]); }
  openTerminal(terminalId: number, rows: number, cols: number): void { this.calls.push(['openTerminal', terminalId, rows, cols]); }
  sendTerminalData(terminalId: number, data: Uint8Array): void { this.calls.push(['sendTerminalData', terminalId, data]); }
  resizeTerminal(terminalId: number, rows: number, cols: number): void { this.calls.push(['resizeTerminal', terminalId, rows, cols]); }
  closeTerminal(terminalId: number): void { this.calls.push(['closeTerminal', terminalId]); }
  sendMouse(mask: number, x: number, y: number, modifiers: number[]): void {
    this.calls.push(['sendMouse', mask, x, y, modifiers]);
  }
  sendKey(
    down: boolean,
    press: boolean,
    keyKind: 'chr' | 'control' | 'unicode',
    value: number,
    modifiers: number[],
  ): void {
    this.calls.push(['sendKey', down, press, keyKind, value, modifiers]);
  }
  sendSeq(text: string): void {
    this.calls.push(['sendSeq', text]);
  }
  switchDisplay(index: number): void {
    this.calls.push(['switchDisplay', index]);
  }
  ctrlAltDel(): void {
    this.calls.push(['ctrlAltDel']);
  }
  refresh(): void {
    this.calls.push(['refresh']);
  }
  setQuality(q: number): void {
    this.calls.push(['setQuality', q]);
  }
  chats: string[] = [];
  sendChat(text: string): void {
    this.chats.push(text);
  }

  sendClipboardText(text: string): void {
    this.calls.push(['sendClipboardText', text]);
  }
  sendFileAction(union: unknown): void {
    this.calls.push(['sendFileAction', union]);
  }
  sendFileResponse(union: unknown): void {
    this.calls.push(['sendFileResponse', union]);
  }
  disconnect(): void {
    this.calls.push(['disconnect']);
    this.state = 'closed';
    this.sinks.closeAll();
  }
  names(): string[] {
    return this.calls.map(([n]) => n);
  }
}

class FakeVideo implements VideoPipelineLike {
  onNeedReadvertise: ((disabled: EncodedCase) => void) | null = null;
  disabled: EncodedCase[] = [];
  frames: VideoFrame[] = [];
  resets = 0;
  closes = 0;
  onStats: (s: Partial<SessionStats>) => void;
  onNeedRefresh: () => void;
  constructor(onNeedRefresh: () => void, onStats: (s: Partial<SessionStats>) => void) {
    this.onNeedRefresh = onNeedRefresh;
    this.onStats = onStats;
  }
  disabledCodecs(): EncodedCase[] {
    return this.disabled;
  }
  pushFrame(vf: VideoFrame): void {
    this.frames.push(vf);
  }
  reset(): void {
    this.resets++;
  }
  close(): void {
    this.closes++;
  }
}

class FakeAudio implements AudioPipelineLike {
  onPcm: ((pcm: Float32Array, sampleRate: number, channels: number) => void) | null = null;
  formats: Array<[number, number]> = [];
  frames: Uint8Array[] = [];
  closes = 0;
  setFormat(sampleRate: number, channels: number): void {
    this.formats.push([sampleRate, channels]);
  }
  pushFrame(data: Uint8Array): void {
    this.frames.push(data);
  }
  close(): void {
    this.closes++;
  }
}

interface Rig {
  host: WorkerHost;
  posts: WorkerOutMessage[];
  transfers: Transferable[][];
  transports: FakeTransport[];
  sessions: FakeSession[];
  videos: FakeVideo[];
  audios: FakeAudio[];
  order: string[];
  session(): FakeSession;
  video(): FakeVideo;
  audio(): FakeAudio;
  states(): Array<{ state: SessionState; detail?: string }>;
}

function makeRig(opts?: {
  openWs?: (url: string) => Promise<Transport>;
  cursorToPng?: (c: CursorData) => Promise<{ pngDataUrl: string; hotx: number; hoty: number }>;
  decodeClipboard?: (c: Clipboard) => string | null;
}): Rig {
  const posts: WorkerOutMessage[] = [];
  const transfers: Transferable[][] = [];
  const transports: FakeTransport[] = [];
  const sessions: FakeSession[] = [];
  const videos: FakeVideo[] = [];
  const audios: FakeAudio[] = [];
  const order: string[] = [];
  const host = new WorkerHost({
    post: (msg, transfer) => {
      posts.push(msg);
      transfers.push(transfer ?? []);
    },
    ready: async () => {
      order.push('ready');
    },
    probeDecoding: async () => {
      order.push('probe');
      return SupportedDecoding.fromPartial(PROBE);
    },
    openWs:
      opts?.openWs ??
      (async (url: string) => {
        order.push(`openWs:${url}`);
        const t = new FakeTransport();
        transports.push(t);
        return t;
      }),
    createSession: (_config, sinks) => {
      order.push('createSession');
      const s = new FakeSession(sinks);
      sessions.push(s);
      return s;
    },
    createVideoPipeline: (_canvas, _onAck, onNeedRefresh, onStats) => {
      const v = new FakeVideo(onNeedRefresh, onStats);
      videos.push(v);
      return v;
    },
    createAudioPipeline: () => {
      const a = new FakeAudio();
      audios.push(a);
      return a;
    },
    cursorToPng:
      opts?.cursorToPng ?? (async () => ({ pngDataUrl: 'data:image/png;base64,AA==', hotx: 1, hoty: 2 })),
    decodeClipboard: opts?.decodeClipboard ?? (() => null),
  });
  return {
    host,
    posts,
    transfers,
    transports,
    sessions,
    videos,
    audios,
    order,
    session: () => sessions[0]!,
    video: () => videos[0]!,
    audio: () => audios[0]!,
    states: () =>
      posts.flatMap((p) => (p.t === 'state' ? [{ state: p.state, ...(p.detail !== undefined ? { detail: p.detail } : {}) }] : [])),
  };
}

async function connect(rig: Rig): Promise<void> {
  rig.host.handle({ c: 'connect', config: { ...CONFIG }, canvas: {} as OffscreenCanvas });
  await tick();
}

// Runs sinks.openRelay so ws2 exists and relayOpened fired.
async function connectWithRelay(rig: Rig): Promise<void> {
  await connect(rig);
  rig.session().sinks.openRelay('relay.example.com:21117');
  await tick();
}

describe('connect flow', () => {
  it('readies crypto, probes codecs, opens ws1 and starts the session in order', async () => {
    const rig = makeRig();
    await connect(rig);
    expect(rig.order).toEqual(['ready', 'probe', `openWs:${CONFIG.wsIdUrl}`, 'createSession']);
    expect(rig.states()[0]).toEqual({ state: 'connecting' });
    const s = rig.session();
    // probe handed to the session before start()
    expect(s.names()).toEqual(['setSupportedDecoding', 'start']);
    expect(s.calls[0]![1]).toEqual(PROBE);
  });

  it('routes ws1 frames to onSignalingBytes and sendSignaling back to ws1', async () => {
    const rig = makeRig();
    await connect(rig);
    const ws1 = rig.transports[0]!;
    ws1.msgCb!(new Uint8Array([1, 2]));
    expect(rig.session().calls.at(-1)).toEqual(['onSignalingBytes', new Uint8Array([1, 2])]);
    rig.session().sinks.sendSignaling(new Uint8Array([3]));
    expect(ws1.sent).toEqual([new Uint8Array([3])]);
  });

  it('openRelay opens ws2 at wsRelayUrl, wires onRelayBytes, then calls relayOpened', async () => {
    const rig = makeRig();
    await connectWithRelay(rig);
    expect(rig.order.at(-1)).toBe(`openWs:${CONFIG.wsRelayUrl}`);
    expect(rig.session().names()).toContain('relayOpened');
    const ws2 = rig.transports[1]!;
    ws2.msgCb!(new Uint8Array([9]));
    expect(rig.session().calls.at(-1)).toEqual(['onRelayBytes', new Uint8Array([9])]);
    rig.session().sinks.sendRelay(new Uint8Array([7]));
    expect(ws2.sent).toEqual([new Uint8Array([7])]);
  });

  it('posts an error state when ws1 fails to open', async () => {
    const rig = makeRig({ openWs: async () => Promise.reject(new Error('refused')) });
    await connect(rig);
    expect(rig.states().at(-1)).toEqual({ state: 'error', detail: 'refused' });
  });

  it('posts an error state when the relay socket fails to open', async () => {
    let calls = 0;
    const transports: FakeTransport[] = [];
    const rig = makeRig({
      openWs: async () => {
        if (calls++ > 0) throw new Error('relay down');
        const t = new FakeTransport();
        transports.push(t);
        return t;
      },
    });
    await connect(rig);
    rig.session().sinks.openRelay('relay.example.com:21117');
    await tick();
    const last = rig.states().at(-1)!;
    expect(last.state).toBe('error');
    expect(last.detail).toContain('relay down');
    expect(transports[0]!.closed).toBe(true);
  });

  it('rejects a second connect', async () => {
    const rig = makeRig();
    await connect(rig);
    await connect(rig);
    expect(rig.states().at(-1)).toEqual({ state: 'error', detail: 'worker already connected' });
    expect(rig.sessions.length).toBe(1);
  });
});

describe('UiCommand routing', () => {
  it('forwards input/control commands to the session', async () => {
    const rig = makeRig();
    await connect(rig);
    const s = rig.session();
    s.calls.length = 0;
    const cmds: UiCommand[] = [
      { c: 'mouse', mask: 9, x: 10, y: 20, modifiers: [4] },
      { c: 'key', down: true, press: false, keyKind: 'chr', value: 30, modifiers: [] },
      { c: 'ctrlAltDel' },
      { c: 'refresh' },
      { c: 'quality', imageQuality: 3 },
      { c: 'clipboardText', text: 'hi' },
    ];
    for (const cmd of cmds) rig.host.handle(cmd);
    expect(s.calls).toEqual([
      ['sendMouse', 9, 10, 20, [4]],
      ['sendKey', true, false, 'chr', 30, []],
      ['ctrlAltDel'],
      ['refresh'],
      ['setQuality', 3],
      ['sendClipboardText', 'hi'],
    ]);
  });

  it('switchDisplay also resets the video pipeline', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.host.handle({ c: 'switchDisplay', index: 1 });
    expect(rig.session().calls.at(-1)).toEqual(['switchDisplay', 1]);
    expect(rig.video().resets).toBe(1);
  });

  it('ignores commands before connect', () => {
    const rig = makeRig();
    rig.host.handle({ c: 'mouse', mask: 1, x: 0, y: 0, modifiers: [] });
    rig.host.handle({ c: 'disconnect' });
    expect(rig.posts).toEqual([]);
  });

  it('disconnect tears everything down once', async () => {
    const rig = makeRig();
    await connectWithRelay(rig);
    rig.host.handle({ c: 'disconnect' });
    expect(rig.session().names()).toContain('disconnect');
    expect(rig.video().closes).toBe(1);
    expect(rig.audio().closes).toBe(1);
    expect(rig.transports[0]!.closed).toBe(true);
    expect(rig.transports[1]!.closed).toBe(true);
    // socket close callbacks fired during teardown must not add error states
    expect(rig.states().every((s) => s.state !== 'error')).toBe(true);
  });
});

describe('media plumbing', () => {
  it('routes video frames to the pipeline and refresh requests back to the session', async () => {
    const rig = makeRig();
    await connect(rig);
    const vf = VideoFrame.fromPartial({
      union: { $case: 'vp9s', vp9s: { frames: [{ data: new Uint8Array([1]), key: true, pts: 0n }] } },
    });
    rig.session().sinks.onVideo(vf);
    expect(rig.video().frames).toEqual([vf]);
    rig.video().onNeedRefresh();
    expect(rig.session().calls.at(-1)).toEqual(['refresh']);
  });

  it('fills partial pipeline stats into a complete stats event', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.video().onStats({ codec: 'vp9', width: 1920, height: 1080, fps: 30, mbps: 2.5, framesDropped: 1, startedAtMs: 111 });
    expect(rig.posts.at(-1)).toEqual({
      t: 'stats',
      stats: { codec: 'vp9', width: 1920, height: 1080, fps: 30, mbps: 2.5, framesDropped: 1, startedAtMs: 111 },
    });
    rig.video().onStats({});
    const last = rig.posts.at(-1)!;
    if (last.t !== 'stats') throw new Error('expected stats');
    expect(last.stats.codec).toBe('');
    expect(last.stats.fps).toBe(0);
    expect(last.stats.startedAtMs).toBeGreaterThan(0);
  });

  it('re-advertises the probe minus disabled codecs when the pipeline gives up on one', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().calls.length = 0;
    rig.video().disabled = ['h264s'];
    rig.video().onNeedReadvertise!('h264s');
    const call = rig.session().calls.at(-1)!;
    expect(call[0]).toBe('setSupportedDecoding');
    const sd = call[1] as SupportedDecoding;
    expect(sd.ability_h264).toBe(0);
    expect(sd.ability_vp9).toBe(1);
    expect(sd.ability_vp8).toBe(1);
  });

  it('routes audio format/frames to the pipeline and PCM up with a transfer list', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.onAudioFormat(48000, 2);
    rig.session().sinks.onAudioFrame(new Uint8Array([5]));
    expect(rig.audio().formats).toEqual([[48000, 2]]);
    expect(rig.audio().frames).toEqual([new Uint8Array([5])]);
    const pcm = new Float32Array([0.5, -0.5]);
    rig.audio().onPcm!(pcm, 48000, 2);
    expect(rig.posts.at(-1)).toEqual({ t: 'audioPcm', pcm, sampleRate: 48000, channels: 2 });
    expect(rig.transfers.at(-1)).toEqual([pcm.buffer]);
  });
});

describe('cursor and clipboard plumbing', () => {
  it('converts cursor_data to a png data url event', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.onCursor!({ width: 8, height: 8 } as CursorData);
    await tick();
    expect(rig.posts.at(-1)).toEqual({ t: 'cursor', pngDataUrl: 'data:image/png;base64,AA==', hotx: 1, hoty: 2 });
  });

  it('swallows cursor decode failures', async () => {
    const rig = makeRig({ cursorToPng: async () => Promise.reject(new Error('bad zstd')) });
    await connect(rig);
    const before = rig.posts.length;
    rig.session().sinks.onCursor!({} as CursorData);
    await tick();
    expect(rig.posts.length).toBe(before);
  });

  it('posts decoded compressed clipboards and drops undecodable ones', async () => {
    const rig = makeRig({ decodeClipboard: (c) => (c.compress ? 'unzstd' : null) });
    await connect(rig);
    rig.session().sinks.onClipboard!({ compress: true } as Clipboard);
    expect(rig.posts.at(-1)).toEqual({ t: 'clipboard', text: 'unzstd' });
    const before = rig.posts.length;
    rig.session().sinks.onClipboard!({ compress: false } as Clipboard);
    expect(rig.posts.length).toBe(before);
  });

  it('survives a decodeClipboard throw', async () => {
    const rig = makeRig({
      decodeClipboard: () => {
        throw new Error('zstd decoder not initialized');
      },
    });
    await connect(rig);
    const before = rig.posts.length;
    rig.session().sinks.onClipboard!({} as Clipboard);
    expect(rig.posts.length).toBe(before);
  });
});

describe('socket loss', () => {
  it('posts an error and tears down when a socket drops mid-session', async () => {
    const rig = makeRig();
    await connectWithRelay(rig);
    rig.session().state = 'streaming';
    rig.transports[1]!.close();
    expect(rig.states().at(-1)).toEqual({ state: 'error', detail: 'relay connection lost' });
    expect(rig.video().closes).toBe(1);
    expect(rig.transports[0]!.closed).toBe(true);
  });

  it('stays quiet when the session already closed', async () => {
    const rig = makeRig();
    await connectWithRelay(rig);
    rig.session().state = 'closed';
    rig.transports[0]!.close();
    expect(rig.states().every((s) => s.state !== 'error')).toBe(true);
  });

  it('closeAll from the session tears down sockets and pipelines', async () => {
    const rig = makeRig();
    await connectWithRelay(rig);
    rig.session().sinks.closeAll();
    expect(rig.transports[0]!.closed).toBe(true);
    expect(rig.transports[1]!.closed).toBe(true);
    expect(rig.video().closes).toBe(1);
    expect(rig.audio().closes).toBe(1);
  });
});

describe('uac video kick', () => {
  it('resets the decoder and requests a keyframe on a uac transition', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().calls.length = 0;
    rig.session().sinks.emit({ t: 'uac', on: true });
    expect(rig.video().resets).toBe(1);
    expect(rig.session().names()).toContain('refresh');
    expect(rig.posts.at(-1)).toEqual({ t: 'uac', on: true });
  });

  it('debounces a uac open/close burst to one kick but forwards both events', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.emit({ t: 'uac', on: true });
    rig.session().sinks.emit({ t: 'uac', on: false });
    expect(rig.video().resets).toBe(1);
    expect(rig.posts.at(-1)).toEqual({ t: 'uac', on: false });
  });

  it('kicks on a wait-uac msgbox as well', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.emit({ t: 'msgbox', msgtype: 'wait-uac', title: '', text: 'Please wait', link: '' });
    expect(rig.video().resets).toBe(1);
    expect(rig.posts.at(-1)).toEqual({ t: 'msgbox', msgtype: 'wait-uac', title: '', text: 'Please wait', link: '' });
  });

  it('does not kick video for unrelated msgbox types', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.emit({ t: 'msgbox', msgtype: 'error', title: 'Oops', text: 'nope', link: '' });
    expect(rig.video().resets).toBe(0);
  });
});

describe('display switch kicks the video', () => {
  /*
   * A display switch restarts the host's capture pipeline AND changes the
   * frame size, so the decoder is left configured for a stream that no longer
   * exists. Without a kick the picture freezes on the last frame of the old
   * monitor — which reads to a user as "the switch did nothing", because a
   * frozen picture and an unchanged picture look identical.
   *
   * Same failure the uac kick above exists for; a display switch was simply
   * never wired to it.
   */
  it('resets the decoder and requests a keyframe on switchDisplay', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().calls.length = 0;
    rig.session().sinks.emit({
      t: 'switchDisplay',
      index: 1,
      x: 1920,
      y: 0,
      width: 2560,
      height: 1440,
      cursorEmbedded: false, resolutions: [] });
    expect(rig.video().resets).toBe(1);
    expect(rig.session().names()).toContain('refresh');
  });

  it('forwards the event to the UI as well as kicking', async () => {
    const rig = makeRig();
    await connect(rig);
    const ev = {
      t: 'switchDisplay' as const,
      index: 0,
      x: 0,
      y: 0,
      width: 1920,
      height: 1080,
      cursorEmbedded: false, resolutions: [] };
    rig.session().sinks.emit(ev);
    // The kick must not swallow the event: the UI needs it to know which
    // display it is now looking at, which is what input coordinates use.
    expect(rig.posts.at(-1)).toEqual(ev);
  });
});

describe('cursor id cache', () => {
  /*
   * The host sends each cursor bitmap ONCE and refers to it by id thereafter —
   * server/input_service.rs MouseCursorSub::send: "only send id out, require
   * client side cache also". Ignoring cursor_id froze the pointer on whichever
   * shape last arrived as full data.
   */
  it('replays a cached cursor when the host sends only an id', async () => {
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.onCursor!({ id: 7n, width: 8, height: 8 } as CursorData);
    await tick();
    const posted = rig.posts.at(-1);

    rig.session().sinks.onCursorId!(7n);
    expect(rig.posts.at(-1)).toEqual(posted);
  });

  it('distinguishes ids, so the right shape comes back', async () => {
    // One fake whose output changes per call, so the two ids genuinely carry
    // different bitmaps rather than the same string twice.
    let shape = 'arrow';
    const rig = makeRig({ cursorToPng: async () => ({ pngDataUrl: shape, hotx: 1, hoty: 2 }) });
    await connect(rig);
    rig.session().sinks.onCursor!({ id: 1n } as CursorData);
    await tick();
    shape = 'ibeam';
    rig.session().sinks.onCursor!({ id: 2n } as CursorData);
    await tick();

    rig.session().sinks.onCursorId!(1n);
    expect(rig.posts.at(-1)).toMatchObject({ pngDataUrl: 'arrow' });
    rig.session().sinks.onCursorId!(2n);
    expect(rig.posts.at(-1)).toMatchObject({ pngDataUrl: 'ibeam' });
  });

  it('keeps the current cursor on an unknown id rather than clearing it', async () => {
    // A miss is survivable and there is no way to ask for the bitmap again,
    // so emitting nothing beats emitting a blank cursor.
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.onCursor!({ id: 1n } as CursorData);
    await tick();
    const before = rig.posts.length;
    rig.session().sinks.onCursorId!(999n);
    expect(rig.posts.length).toBe(before);
  });

  it('does not cache a cursor that failed to render', async () => {
    const rig = makeRig({ cursorToPng: async () => Promise.reject(new Error('bad')) });
    await connect(rig);
    rig.session().sinks.onCursor!({ id: 5n } as CursorData);
    await tick();
    const before = rig.posts.length;
    rig.session().sinks.onCursorId!(5n);
    expect(rig.posts.length).toBe(before);
  });
});

describe('cursor caching never blocks display', () => {
  it('still draws a cursor that carries no id', async () => {
    // Regression: keying the cache before posting meant a CursorData without
    // an id threw, the catch swallowed it, and no cursor was drawn at all.
    const rig = makeRig();
    await connect(rig);
    rig.session().sinks.onCursor!({ width: 8, height: 8 } as CursorData);
    await tick();
    expect(rig.posts.at(-1)).toMatchObject({ t: 'cursor' });
  });
});
