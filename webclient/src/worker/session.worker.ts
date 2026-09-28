// Composition root: DedicatedWorker owning both WebSockets, the Session (and
// its ordered StreamCipher), the OffscreenCanvas, VideoPipeline, AudioPipeline.
// Bridges UiCommand/SessionEvent postMessage traffic <-> Session calls.
// The module is Node-import-safe: worker-global wiring only happens when the
// script actually runs inside a worker scope; tests drive WorkerHost directly.
import type {
  SessionConfig,
  SessionEvent,
  SessionState,
  SessionStats,
  Transport,
  UiCommand,
} from '../core/contracts';
import {
  FileTransferBlock,
  FileTransferDigest,
  FileTransferDone,
  FileTransferError,
  SupportedDecoding,
  SupportedDecoding_PreferCodec,
  type Clipboard,
  type CursorData,
  type FileAction,
  type FileResponse,
  type VideoFrame,
} from '../gen/message';
import { sodiumReady } from '../core/crypto';
import { Session, type SessionSinks } from '../core/session';
import { fileResponseToEvents } from '../core/file-transfer';
import { WsStream } from '../transport/ws-stream';
import { AudioPipeline } from '../media/audio';
import { VideoPipeline, mseH264Available, probeSupportedDecoding, type EncodedCase } from '../media/video';
import { ForwardingVideoPipeline } from '../media/mse-video';
import { cursorToDataUrl, decodeClipboardText, initZstd, zstdDecode } from '../input/clipboard-cursor';
import { decodeTerminalOutput } from './terminal-output';
import { OsAutoLoginAttempt } from './os-auto-login';

// Decoded audio is outside the frozen SessionEvent union: the main thread
// feeds it to an AudioWorklet ring buffer and ignores unknown `t` otherwise.
export type WorkerOutMessage =
  | SessionEvent
  | { t: 'audioPcm'; pcm: Float32Array; sampleRate: number; channels: number };

export function codecNames(sd: SupportedDecoding): Array<'auto'|'vp9'|'h264'|'h265'|'vp8'|'av1'> {
  const codecs: Array<'auto'|'vp9'|'h264'|'h265'|'vp8'|'av1'> = ['auto'];
  if (sd.ability_vp9 > 0) codecs.push('vp9');
  if (sd.ability_h264 > 0) codecs.push('h264');
  if (sd.ability_h265 > 0) codecs.push('h265');
  if (sd.ability_vp8 > 0) codecs.push('vp8');
  if (sd.ability_av1 > 0) codecs.push('av1');
  return codecs;
}

export interface SessionLike {
  readonly currentState: SessionState;
  start(): void;
  onSignalingBytes(b: Uint8Array): void;
  onRelayBytes(b: Uint8Array): Promise<void>;
  relayOpened(): void;
  setSupportedDecoding(sd: SupportedDecoding): void;
  sendMouse(mask: number, x: number, y: number, modifiers: number[]): void;
  sendOsPassword(password: string): boolean;
  sendKey(
    down: boolean,
    press: boolean,
    keyKind: 'chr' | 'control' | 'unicode',
    value: number,
    modifiers: number[],
  ): void;
  switchDisplay(index: number): void;
  ctrlAltDel(): void;
  refresh(): void;
  setQuality(imageQuality: number): void;
  restartRemoteDevice(): void;
  requestElevation(): void;
  setPrivacyMode(implKey: string, on: boolean): void;
  setBlockInput(on: boolean): void;
  setLockAfterSessionEnd(on: boolean): void;
  changeDisplayResolution(display: number, width: number, height: number): boolean;
  toggleVirtualDisplay(display: number, on: boolean): boolean;
  setCustomQuality(quality: number): boolean;
  setCustomFps(fps: number): boolean;
  setPreferredCodec(prefer: SupportedDecoding_PreferCodec): boolean;
  setDisplayOption(option: 'showRemoteCursor'|'followRemoteCursor'|'followRemoteWindow', enabled: boolean): void;
  setRemoteAudioEnabled(enabled: boolean): void;
  setClipboardEnabled(enabled: boolean): void;
  setClientRecording(recording: boolean): void;
  sendClipboardText(text: string): void;
  sendChat(text: string): void;
  startVoiceCall(): void;
  closeVoiceCall(): void;
  sendVoiceAudioFormat(sampleRate: number, channels: number): void;
  sendVoiceAudioFrame(data: Uint8Array): void;
  openTerminal(terminalId: number, rows: number, cols: number): void;
  sendTerminalData(terminalId: number, data: Uint8Array): void;
  resizeTerminal(terminalId: number, rows: number, cols: number): void;
  closeTerminal(terminalId: number): void;
  sendFileAction(union: NonNullable<FileAction['union']>): void;
  sendFileResponse(union: NonNullable<FileResponse['union']>): void;
  disconnect(): void;
}

export interface VideoPipelineLike {
  onNeedReadvertise: ((disabled: EncodedCase) => void) | null;
  disabledCodecs(): EncodedCase[];
  pushFrame(vf: VideoFrame): void;
  reset(): void;
  close(): void;
}

export interface AudioPipelineLike {
  onPcm: ((pcm: Float32Array, sampleRate: number, channels: number) => void) | null;
  setFormat(sampleRate: number, channels: number): void;
  pushFrame(data: Uint8Array): void;
  close(): void;
}

export interface WorkerDeps {
  post(msg: WorkerOutMessage, transfer?: Transferable[]): void;
  openWs?(url: string): Promise<Transport>;
  createSession?(config: SessionConfig, sinks: SessionSinks): SessionLike;
  createVideoPipeline?(
    canvas: OffscreenCanvas,
    onAck: () => void,
    onNeedRefresh: () => void,
    onStats: (s: Partial<SessionStats>) => void,
  ): VideoPipelineLike;
  /** Fallback used when WebCodecs is absent: forwards H.264 instead of decoding. */
  createForwardingVideoPipeline?(
    emit: (data: Uint8Array, key: boolean) => void,
    onNeedRefresh: () => void,
  ): VideoPipelineLike;
  createAudioPipeline?(): AudioPipelineLike;
  probeDecoding?(): Promise<SupportedDecoding>;
  ready?(): Promise<void>;
  cursorToPng?(c: CursorData): Promise<{ pngDataUrl: string; hotx: number; hoty: number }>;
  decodeClipboard?(c: Clipboard): string | null;
}

function errMsg(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}

const CONNECT_TIMEOUT_MS = 25000;
const CONNECT_STAGE: Partial<Record<string, string>> = {
  connecting: 'connecting',
  rendezvous: 'contacting the server',
  relay: 'opening the relay',
  handshake: 'securing the channel',
  login: 'authenticating',
  needAccept: 'waiting for the remote user to accept',
};

export class WorkerHost {
  private readonly deps: Required<WorkerDeps>;
  private session: SessionLike | null = null;
  private video: VideoPipelineLike | null = null;
  private audio: AudioPipelineLike | null = null;
  private ws1: Transport | null = null;
  private ws2: Transport | null = null;
  private probe: SupportedDecoding | null = null;
  private connectStarted = false;
  private tornDown = false;
  private connectTimer: ReturnType<typeof setTimeout> | null = null;
  private osAutoLogin: OsAutoLoginAttempt | null = null;
  private osAutoLoginPassword = '';
  private keyboardPermission: boolean | undefined;
  private streaming = false;
  private viewOnly = false;
  private activeConfig: SessionConfig | null = null;

  constructor(deps: WorkerDeps) {
    this.deps = {
      openWs: (url) => WsStream.open(url),
      createSession: (config, sinks) => new Session(config, sinks),
      createVideoPipeline: (canvas, onAck, onNeedRefresh, onStats) =>
        new VideoPipeline(canvas, onAck, onNeedRefresh, onStats),
      createForwardingVideoPipeline: (emit, onNeedRefresh) =>
        new ForwardingVideoPipeline(emit, onNeedRefresh),
      createAudioPipeline: () => new AudioPipeline(),
      probeDecoding: () => probeSupportedDecoding(),
      ready: async () => {
        await sodiumReady();
        try {
          await initZstd(); // clipboard/cursor decompression degrades gracefully
        } catch {
          // zstd wasm unavailable: text clipboard (uncompressed) still works
        }
      },
      cursorToPng: (c) => cursorToDataUrl(c),
      decodeClipboard: (c) => decodeClipboardText(c),
      ...deps,
    };
  }

  handle(cmd: UiCommand): void {
    switch (cmd.c) {
      case 'connect':
        void this.connect(cmd.config, cmd.canvas);
        return;
      case 'connectFile':
      case 'connectTerminal':
        void this.connect(cmd.config, undefined);
        return;
      case 'terminalOpen':
        this.session?.openTerminal(cmd.terminalId, cmd.rows, cmd.cols);
        return;
      case 'terminalData':
        this.session?.sendTerminalData(cmd.terminalId, cmd.data);
        return;
      case 'terminalResize':
        this.session?.resizeTerminal(cmd.terminalId, cmd.rows, cmd.cols);
        return;
      case 'terminalClose':
        this.session?.closeTerminal(cmd.terminalId);
        return;
      case 'ftReadDir':
        this.session?.sendFileAction({
          $case: 'read_dir',
          read_dir: { path: cmd.path, include_hidden: cmd.includeHidden },
        });
        return;
      case 'ftSend':
        this.session?.sendFileAction({
          $case: 'send',
          send: {
            id: cmd.id,
            path: cmd.path,
            include_hidden: cmd.includeHidden,
            file_num: cmd.fileNum,
            file_type: 0,
          },
        });
        return;
      case 'ftReceive':
        this.session?.sendFileAction({
          $case: 'receive',
          receive: {
            id: cmd.id,
            path: cmd.path,
            files: cmd.files.map((f) => ({
              entry_type: 4, // File
              name: f.name,
              is_hidden: false,
              size: BigInt(f.size),
              modified_time: BigInt(f.modifiedSec),
            })),
            file_num: cmd.fileNum,
            total_size: BigInt(cmd.totalSize),
          },
        });
        return;
      case 'ftDigest':
        // Upload handshake: announce the source file; blocks wait for a confirm.
        this.session?.sendFileResponse({
          $case: 'digest',
          digest: FileTransferDigest.fromPartial({
            id: cmd.id,
            file_num: cmd.fileNum,
            file_size: BigInt(cmd.fileSize),
            last_modified: BigInt(cmd.lastModifiedSec),
          }),
        });
        return;
      case 'ftBlock':
        this.session?.sendFileResponse({
          $case: 'block',
          block: FileTransferBlock.fromPartial({
            id: cmd.id,
            file_num: cmd.fileNum,
            data: cmd.data,
            compressed: false,
            blk_id: cmd.blkId,
          }),
        });
        // Ack with the socket backlog so the UI can self-clock the upload.
        this.deps.post({ t: 'ftSent', id: cmd.id, fileNum: cmd.fileNum, buffered: this.ws2?.buffered?.() ?? 0 });
        return;
      case 'ftDone':
        this.session?.sendFileResponse({
          $case: 'done',
          done: FileTransferDone.fromPartial({ id: cmd.id, file_num: cmd.fileNum }),
        });
        return;
      case 'ftError':
        this.session?.sendFileResponse({
          $case: 'error',
          error: FileTransferError.fromPartial({ id: cmd.id, file_num: cmd.fileNum, error: cmd.error }),
        });
        return;
      case 'ftConfirm':
        this.session?.sendFileAction({
          $case: 'send_confirm',
          send_confirm: {
            id: cmd.id,
            file_num: cmd.fileNum,
            union: cmd.skip ? { $case: 'skip', skip: true } : { $case: 'offset_blk', offset_blk: cmd.offsetBlk },
          },
        });
        return;
      case 'ftCancel':
        this.session?.sendFileAction({ $case: 'cancel', cancel: { id: cmd.id } });
        return;
      case 'ftCreateDir':
        this.session?.sendFileAction({ $case: 'create', create: { id: cmd.id, path: cmd.path } });
        return;
      case 'ftRemoveFile':
        this.session?.sendFileAction({
          $case: 'remove_file',
          remove_file: { id: cmd.id, path: cmd.path, file_num: cmd.fileNum },
        });
        return;
      case 'ftRemoveDir':
        this.session?.sendFileAction({
          $case: 'remove_dir',
          remove_dir: { id: cmd.id, path: cmd.path, recursive: true },
        });
        return;
      case 'ftRename':
        this.session?.sendFileAction({
          $case: 'rename',
          rename: { id: cmd.id, path: cmd.path, new_name: cmd.newName },
        });
        return;
      case 'mouse':
        this.session?.sendMouse(cmd.mask, cmd.x, cmd.y, cmd.modifiers);
        return;
      case 'key':
        this.session?.sendKey(cmd.down, cmd.press, cmd.keyKind, cmd.value, cmd.modifiers);
        return;
      case 'switchDisplay':
        this.session?.switchDisplay(cmd.index);
        this.video?.reset(); // new display = new stream; wait for its keyframe
        return;
      case 'ctrlAltDel':
        this.session?.ctrlAltDel();
        return;
      case 'refresh':
        this.session?.refresh();
        return;
      case 'quality':
        this.session?.setQuality(cmd.imageQuality);
        return;
      case 'restartRemoteDevice':
        this.session?.restartRemoteDevice();
        return;
      case 'requestElevation':
        this.session?.requestElevation();
        return;
      case 'privacyMode':
        this.session?.setPrivacyMode(cmd.implKey, cmd.on);
        return;
      case 'blockInput':
        this.session?.setBlockInput(cmd.on);
        return;
      case 'lockAfterSessionEnd':
        this.session?.setLockAfterSessionEnd(cmd.on);
        return;
      case 'viewOnly':
        this.viewOnly = cmd.enabled;
        if (cmd.enabled) this.cancelOsAutoLogin();
        return;
      case 'displayResolution':
        this.session?.changeDisplayResolution(cmd.display, cmd.width, cmd.height);
        return;
      case 'virtualDisplay':
        this.session?.toggleVirtualDisplay(cmd.display, cmd.on);
        return;
      case 'customQuality':
        this.session?.setCustomQuality(cmd.quality);
        return;
      case 'customFps':
        this.session?.setCustomFps(cmd.fps);
        return;
      case 'preferredCodec':
        this.session?.setPreferredCodec(cmd.prefer);
        return;
      case 'displayOption':
        this.session?.setDisplayOption(cmd.option, cmd.enabled);
        return;
      case 'remoteAudio':
        this.session?.setRemoteAudioEnabled(cmd.enabled);
        return;
      case 'clipboardEnabled':
        this.session?.setClipboardEnabled(cmd.enabled);
        return;
      case 'clientRecording':
        this.session?.setClientRecording(cmd.recording);
        return;
      case 'clipboardText':
        this.session?.sendClipboardText(cmd.text);
        return;
      case 'chat':
        this.session?.sendChat(cmd.text);
        return;
      case 'voiceCallStart':
        this.session?.startVoiceCall();
        return;
      case 'voiceCallClose':
        this.session?.closeVoiceCall();
        return;
      case 'voiceAudioFormat':
        this.session?.sendVoiceAudioFormat(cmd.sampleRate, cmd.channels);
        return;
      case 'voiceAudioFrame':
        this.session?.sendVoiceAudioFrame(cmd.data);
        return;
      case 'disconnect':
        this.session?.disconnect(); // emits 'closed' + closeAll -> teardown
        this.teardown();
        return;
      default:
        return;
    }
  }

  // canvas === undefined -> non-video connection (file transfer or terminal):
  // no video/audio pipelines and no codec probe.
  private async connect(config: SessionConfig, canvas: OffscreenCanvas | undefined): Promise<void> {
    if (this.connectStarted) {
      this.deps.post({ t: 'state', state: 'error', detail: 'worker already connected' });
      return;
    }
    this.connectStarted = true;
    this.activeConfig = config;
    this.keyboardPermission = undefined;
    this.streaming = false;
    this.viewOnly = false;
    const defaultDesktop = !config.connType || config.connType === 'default';
    this.osAutoLoginPassword = defaultDesktop ? (config.osPassword ?? '') : '';
    if (!defaultDesktop) config.osPassword = undefined;
    try {
      this.deps.post({ t: 'state', state: 'connecting' });
      await this.deps.ready();

      if (canvas) {
        this.probe = await this.deps.probeDecoding();
        this.deps.post({ t: 'codecSupport', codecs: codecNames(this.probe) });

        // No WebCodecs (insecure origin) but MSE can play H.264: forward the
        // bitstream to the main thread instead of decoding here. The canvas is
        // unused in that mode — a <video> element renders instead.
        const useMse = typeof VideoDecoder === 'undefined' && mseH264Available();
        // Session acks every video_frame on receipt (video_ack_required), so the
        // per-drawn-frame ack hook is a no-op here.
        const video = useMse
          ? this.deps.createForwardingVideoPipeline(
              (data, key) => this.deps.post({ t: 'h264', data, key }, [data.buffer]),
              () => this.session?.refresh(),
            )
          : this.deps.createVideoPipeline(
              canvas,
              () => {},
              () => this.session?.refresh(),
              (s) => this.postStats(s),
            );
        video.onNeedReadvertise = () => this.readvertise();
        this.video = video;

        const audio = this.deps.createAudioPipeline();
        audio.onPcm = (pcm, sampleRate, channels) =>
          this.deps.post({ t: 'audioPcm', pcm, sampleRate, channels }, [pcm.buffer]);
        this.audio = audio;
      }

      const ws1 = await this.deps.openWs(config.wsIdUrl);
      if (this.tornDown) {
        ws1.close();
        return;
      }
      this.ws1 = ws1;

      const session = this.deps.createSession(config, {
        sendSignaling: (b) => this.ws1?.send(b),
        sendRelay: (b) => this.ws2?.send(b),
        relayBuffered: () => this.ws2?.buffered?.() ?? 0,
        emit: (ev) => {
          if (ev.t === 'permission' && ev.kind === 'Keyboard') {
            this.keyboardPermission = ev.enabled;
            if (ev.enabled) this.maybeBeginOsAutoLogin(config);
            else this.cancelOsAutoLogin();
          }
          if (ev.t === 'state' && ev.state === 'streaming') {
            this.streaming = true;
            this.maybeBeginOsAutoLogin(config);
          } else if (
            ev.t === 'loginError'
            || (ev.t === 'state' && (ev.state === 'error' || ev.state === 'closed'))
          ) {
            this.cancelOsAutoLogin();
          }
          // Clear the connect watchdog once we reach a settled state or enter
          // explicit manual-accept waiting; that wait is controlled by the
          // remote user and must not be cut off by the transport watchdog.
          if (ev.t === 'state' && (ev.state === 'needAccept' || ev.state === 'streaming' || ev.state === 'error' || ev.state === 'closed')) {
            this.clearConnectTimer();
          }
          // Anything that restarts the host's capture pipeline leaves our
          // decoder configured for a stream that no longer exists, and without
          // a kick it stays frozen until reconnect. Two things do that:
          //
          //  - a UAC secure-desktop switch, either direction;
          //  - a display switch, which additionally changes the frame size,
          //    so the decoder must be reconfigured and not merely fed.
          //
          // reset() drops the decoder and re-arms awaitingKey; refresh() asks
          // the peer for the key frame it then needs.
          if (
            ev.t === 'uac' ||
            ev.t === 'switchDisplay' ||
            (ev.t === 'msgbox' && /uac/i.test(ev.msgtype))
          ) {
            this.kickVideo();
          }
          if (ev.t === 'terminalData') {
            let data: Uint8Array;
            try {
              data = decodeTerminalOutput(ev.data, ev.compressed, zstdDecode);
            } catch (error) {
              this.deps.post({
                t: 'terminalError',
                terminalId: ev.terminalId,
                message: `Could not decompress terminal output: ${errMsg(error)}`,
              });
              return;
            }
            this.deps.post(
              { ...ev, data, compressed: false },
              [data.buffer as ArrayBuffer],
            );
            return;
          }
          this.deps.post(ev);
        },
        onVideo: (vf) => this.video?.pushFrame(vf),
        onAudioFormat: (sampleRate, channels) => this.audio?.setFormat(sampleRate, channels),
        onAudioFrame: (d) => this.audio?.pushFrame(d),
        openRelay: () => {
          void this.openRelay(config.wsRelayUrl);
        },
        closeAll: () => this.teardown(),
        onCursor: (c) => {
          void this.postCursor(c);
        },
        onCursorId: (id) => this.postCursorId(id),
        onClipboard: (cb) => this.postClipboard(cb),
        onFileResponse: (fr) => this.postFileResponse(fr),
        onFileSendConfirm: (c) =>
          this.deps.post({
            t: 'ftSendConfirm',
            id: c.id,
            fileNum: c.file_num,
            skip: c.union?.$case === 'skip' && c.union.skip,
            // Field is named offset_blk but carries an absolute BYTE offset.
            offsetBytes: c.union?.$case === 'offset_blk' ? c.union.offset_blk : 0,
          }),
      });
      this.session = session;
      if (this.osAutoLoginPassword) {
        this.osAutoLogin = new OsAutoLoginAttempt({
          eligible: () =>
            !this.tornDown
            && !this.viewOnly
            && this.keyboardPermission !== false
            && session.currentState === 'streaming',
          sendMouse: (mask, x, y) => session.sendMouse(mask, x, y, []),
          sendPassword: (password) => session.sendOsPassword(password),
        });
      }
      if (this.probe) session.setSupportedDecoding(this.probe);

      ws1.onMessage((b) => session.onSignalingBytes(b));
      ws1.onClose(() => this.onIdSocketClosed());
      session.start();

      // Watchdog: if pairing/handshake/login stalls (busy relay, peer offline,
      // controller already attached), fail with a reason instead of spinning.
      // Session.start() may synchronously enter needAccept, which is an
      // intentional unbounded wait for the remote user rather than a stall.
      if (session.currentState !== 'needAccept' && session.currentState !== 'streaming' && session.currentState !== 'error' && session.currentState !== 'closed') {
        this.connectTimer = setTimeout(() => {
          this.connectTimer = null;
          const st = this.session?.currentState;
          if (st && st !== 'needAccept' && st !== 'streaming' && st !== 'error' && st !== 'closed') {
            this.deps.post({
              t: 'state',
              state: 'error',
              detail: `Timed out while ${CONNECT_STAGE[st] ?? 'connecting'} — the device may be offline or busy. Try again.`,
            });
            this.teardown();
          }
        }, CONNECT_TIMEOUT_MS);
      }
    } catch (e) {
      this.deps.post({ t: 'state', state: 'error', detail: errMsg(e) });
      this.teardown();
    }
  }

  private async openRelay(url: string): Promise<void> {
    try {
      const ws2 = await this.deps.openWs(url);
      if (this.tornDown) {
        ws2.close();
        return;
      }
      this.ws2 = ws2;
      // onRelayBytes decrypts synchronously before its first await, so
      // fire-and-forget preserves cipher order across frames.
      ws2.onMessage((b) => {
        void this.session?.onRelayBytes(b);
      });
      ws2.onClose(() => this.onSocketClosed('relay'));
      this.session?.relayOpened();
    } catch (e) {
      this.deps.post({ t: 'state', state: 'error', detail: `relay connect failed: ${errMsg(e)}` });
      this.teardown();
    }
  }

  // Debounced video restart: drop deltas until the next keyframe and request
  // one. uac(true) and uac(false) often arrive close together with msgbox
  // variants; one kick per burst is enough.
  private lastKickMs = 0;
  private kickVideo(): void {
    const now = Date.now();
    if (now - this.lastKickMs < 500) return;
    this.lastKickMs = now;
    this.video?.reset();
    this.session?.refresh();
  }

  private readvertise(): void {
    if (!this.probe || !this.video || !this.session) return;
    const sd = SupportedDecoding.fromPartial(this.probe);
    for (const c of this.video.disabledCodecs()) {
      switch (c) {
        case 'vp9s':
          sd.ability_vp9 = 0;
          break;
        case 'vp8s':
          sd.ability_vp8 = 0;
          break;
        case 'h264s':
          sd.ability_h264 = 0;
          break;
        case 'h265s':
          sd.ability_h265 = 0;
          break;
        case 'av1s':
          sd.ability_av1 = 0;
          break;
      }
    }
    this.session.setSupportedDecoding(sd);
    this.deps.post({ t: 'codecSupport', codecs: codecNames(sd) });
  }

  private postStats(p: Partial<SessionStats>): void {
    this.deps.post({
      t: 'stats',
      stats: {
        codec: p.codec ?? '',
        width: p.width ?? 0,
        height: p.height ?? 0,
        fps: p.fps ?? 0,
        mbps: p.mbps ?? 0,
        framesDropped: p.framesDropped ?? 0,
        startedAtMs: p.startedAtMs ?? Date.now(),
      },
    });
  }

  /**
   * Rendered cursors, keyed by the host's cursor id.
   *
   * The host sends each cursor's bitmap ONCE and refers to it by id from then
   * on — server/input_service.rs MouseCursorSub::send, whose own comment reads
   * "only send id out, require client side cache also". Without this cache the
   * pointer freezes on whichever shape last arrived as full data, which is
   * most obvious after a display switch: by then the host has cached nearly
   * every shape, so almost nothing arrives as a bitmap any more.
   */
  private readonly cursorCache = new Map<string, { pngDataUrl: string; hotx: number; hoty: number }>();

  /**
   * Bound on the cache. Real hosts cycle through a couple of dozen shapes, so
   * this is far above normal use — it exists so a peer that minted a new id
   * per frame could not grow it without limit for the life of a session.
   */
  private static readonly MAX_CURSORS = 128;

  private async postCursor(c: CursorData): Promise<void> {
    let entry: { pngDataUrl: string; hotx: number; hoty: number };
    try {
      entry = await this.deps.cursorToPng(c);
    } catch {
      return; // cursor rendering is best-effort; position still flows via cursorPos
    }
    // Show it first, cache second. Caching is an optimisation for later ids and
    // must never be able to stop the cursor being drawn now — an earlier
    // version keyed the Map before posting, so a CursorData without an id threw
    // and the catch swallowed the cursor entirely.
    this.deps.post({ t: 'cursor', ...entry });
    if (c.id === undefined || c.id === null) return;
    if (this.cursorCache.size >= WorkerHost.MAX_CURSORS) {
      // Oldest first: insertion order is Map's iteration order.
      const oldest = this.cursorCache.keys().next();
      if (!oldest.done) this.cursorCache.delete(oldest.value);
    }
    this.cursorCache.set(c.id.toString(), entry);
  }

  /**
   * The host referring to a cursor it has already sent us.
   *
   * A miss is possible and survivable — we may have evicted it, or the entry
   * failed to render — and there is no protocol message to ask for the bitmap
   * again. Keeping the current cursor is better than clearing it.
   */
  private postCursorId(id: bigint): void {
    const hit = this.cursorCache.get(id.toString());
    if (hit) this.deps.post({ t: 'cursor', ...hit });
  }

  private postFileResponse(fr: FileResponse): void {
    for (const ev of fileResponseToEvents(fr, (d) => zstdDecode(d))) {
      // Block payloads can be large — transfer the buffer instead of copying.
      if (ev.t === 'ftBlock' && ev.data.buffer instanceof ArrayBuffer && ev.data.byteLength === ev.data.buffer.byteLength) {
        this.deps.post(ev, [ev.data.buffer]);
      } else {
        this.deps.post(ev);
      }
    }
  }

  private postClipboard(cb: Clipboard): void {
    try {
      const text = this.deps.decodeClipboard(cb);
      if (text !== null) this.deps.post({ t: 'clipboard', text });
    } catch {
      // undecodable (zstd not ready / non-text): drop silently
    }
  }

  // The id server (hbbs) is only needed for rendezvous. Once the relay is
  // opened, hbbs routinely closes this socket — that is expected and MUST NOT
  // tear down the live relay session. Only a close while we still need it
  // (before the relay hand-off) is a real failure.
  private onIdSocketClosed(): void {
    if (this.tornDown) return;
    const st = this.session?.currentState;
    if (st === 'connecting' || st === 'rendezvous') {
      this.deps.post({ t: 'state', state: 'error', detail: 'id server connection lost' });
      this.teardown();
    }
    // else: relay is live; drop the id socket silently, keep the session.
    this.ws1 = null;
  }

  private onSocketClosed(which: string): void {
    if (this.tornDown) return;
    const st = this.session?.currentState;
    if (st !== 'closed' && st !== 'error') {
      this.deps.post({ t: 'state', state: 'error', detail: `${which} connection lost` });
    }
    this.teardown();
  }

  private clearConnectTimer(): void {
    if (this.connectTimer !== null) {
      clearTimeout(this.connectTimer);
      this.connectTimer = null;
    }
  }

  private maybeBeginOsAutoLogin(config: SessionConfig): void {
    if (this.viewOnly) {
      this.cancelOsAutoLogin();
      return;
    }
    if (!this.osAutoLoginPassword || !this.osAutoLogin || !this.streaming) return;
    if (this.keyboardPermission === false) {
      this.cancelOsAutoLogin();
      return;
    }
    this.beginOsAutoLogin(config);
  }

  private beginOsAutoLogin(config: SessionConfig): void {
    const password = this.osAutoLoginPassword;
    this.osAutoLoginPassword = '';
    config.osPassword = undefined;
    if (!password || !this.osAutoLogin || this.viewOnly || this.keyboardPermission === false) {
      this.osAutoLogin?.cancel();
      return;
    }
    void this.osAutoLogin.start(password).catch(() => {
      this.cancelOsAutoLogin();
    });
  }

  private cancelOsAutoLogin(): void {
    this.osAutoLogin?.cancel();
    this.osAutoLogin = null;
    this.osAutoLoginPassword = '';
    if (this.activeConfig) this.activeConfig.osPassword = undefined;
  }

  private teardown(): void {
    if (this.tornDown) return;
    this.tornDown = true;
    this.cancelOsAutoLogin();
    this.activeConfig = null;
    this.clearConnectTimer();
    // The host's cursor cache is per-connection and starts empty, so it will
    // resend every bitmap on the next one. Keeping ours would risk answering a
    // reused id with the previous session's shape.
    this.cursorCache.clear();
    this.video?.close();
    this.video = null;
    this.audio?.close();
    this.audio = null;
    const w1 = this.ws1;
    const w2 = this.ws2;
    this.ws1 = null;
    this.ws2 = null;
    try {
      w1?.close();
    } catch {
      // already closed
    }
    try {
      w2?.close();
    } catch {
      // already closed
    }
  }
}

// Wire up only when actually running inside a worker scope (importScripts is a
// WorkerGlobalScope method; absent on window and in Node, where tests import us).
type WorkerScope = {
  postMessage(msg: unknown, transfer?: Transferable[]): void;
  onmessage: ((ev: { data: unknown }) => void) | null;
  importScripts?: unknown;
  document?: unknown;
};
const scope = globalThis as Partial<WorkerScope>;
if (
  typeof scope.postMessage === 'function' &&
  typeof scope.importScripts === 'function' &&
  scope.document === undefined
) {
  const host = new WorkerHost({
    post: (msg, transfer) => scope.postMessage!(msg, transfer ?? []),
  });
  scope.onmessage = (ev) => host.handle(ev.data as UiCommand);
}
