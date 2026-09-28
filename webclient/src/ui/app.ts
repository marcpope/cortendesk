// CortenDesk web client — main-thread UI shell: top bar, command dock, side panel, connect overlay.
//
// DOM id contract (the Blade view provides some; each is created here if missing, and
// #rd-canvas / #rd-side / #rd-overlay are normalized INTO #rd-viewport on mount):
//   #rd-root       page wrapper (toolbar + viewport); gets data-state="<SessionState>"
//   #rd-toolbar    top bar — brand / control island / avatar, rendered by this module
//   #rd-viewport   region between toolbar and page bottom; holds canvas, side panel, dock, overlay, toast
//   #rd-canvas     <canvas> transferred to the session worker (replaced with a fresh node on reconnect)
//   #rd-dock       floating bottom command bar — input, clipboard, panels
//   #rd-side       right slide-out with Files / Chat / Details tabs; .rd-open = visible
//   #rd-edge       edge strip of shortcuts shown while the side panel is closed
//   #rd-overlay    connect overlay — rendered children: #rd-peer-id (row #rd-field-id, hidden when
//                  the peer id is server-injected or in ?id=), #rd-password, #rd-connect,
//                  #rd-overlay-status, #rd-overlay-error; .rd-hidden hides the overlay
//   #rd-toast      transient notification (created here)
//
// Server-injected config:
//   window.__RD__ = { peerId?, serverKeyB64, wsIdUrl, wsRelayUrl, myId, myName, version?, workerUrl? }
// Worker script resolution order: __RD__.workerUrl → <script data-rd-worker="/rdclient/session.worker.js">
// → 'session.worker.js' resolved next to the built app.js (import.meta.url).

import './app.css';
import type {
  DisplayInfo,
  SessionConfig,
  SessionEvent,
  SessionState,
  SessionStats,
  UiCommand,
} from '../core/contracts';
import { attachInput, type DisplayRect } from '../input/mouse-keyboard';
import { readLocalClipboardText } from '../input/clipboard-cursor';
import {
  BackNotification_BlockInputState,
  BackNotification_PrivacyModeState,
  ControlKey,
  SupportedDecoding_PreferCodec,
} from '../gen/message';
import { RESTART_RECONNECT_TIMEOUT_MS, nextRestartReconnectDelay } from '../core/session-controls';
import { buildLockScreenKeyCommand, buildSecurityControlMenu } from './session-controls-menu';
import {
  overlayVersion,
  QUALITY,
  STATE_LABEL,
  buildSessionConfig,
  buildTypeCommands,
  clearSavedHash,
  cursorCss,
  debugEnabled,
  applySwitchDisplay,
  displayToRect,
  escapeHtml,
  formatDuration,
  formatMbps,
  iconHtml,
  loadSavedHash,
  loggedOutFromSearch,
  normalizePeerId,
  peerIdFromSearch,
  placePopover,
  resolveWorkerUrl,
  saveSavedHash,
  type IconName,
  type RdGlobalConfig,
} from './common';
import { FilePanel } from './file-panel';
import { TerminalPanel } from './terminal-panel';
import { CameraPanel } from './camera-panel';
import { MseVideoPlayer } from '../media/mse-video';
import { mseH264Available } from '../media/video';
import {
  adaptFps,
  buildResolutionChoices,
  canUseRemoteCursor,
  codecPreferenceValue,
  mapRemoteCursorToCanvas,
  mergeDisplayRefresh,
  mergePlatformAdditions,
  parseVirtualDisplayCapability,
} from './display-controls';
import { RemoteAudioPlayback, type RemoteAudioContext } from '../media/remote-audio';
import { LocalSessionRecorder, type RecordingSurface } from '../media/session-recorder';
import { VoiceCaptureController, browserVoiceCaptureDeps } from '../media/voice-capture';
import { voiceCallUiModel } from './voice-call-ui';
import { VoiceCallAttemptOwner } from './voice-call-attempt';
import { remoteInputAllowed, type RemoteInputChannel } from './view-only-policy';
import { canUseOsLoginSettings, OsLoginSettingsClient } from './os-login-settings';
import { OsLoginFlow } from './os-login-flow';
import { postConnectWithSecretScrub } from './session-secret-handoff';

// Back-compat: everything that used to live here is re-exported for tests and
// external importers.
export * from './common';

type RdWindow = Window & { __RD__?: RdGlobalConfig };

type SideTab = 'files' | 'chat' | 'details';
type InputMode = 'pointer' | 'touch';
type FitMode = 'fit' | 'actual';

type ChatEntry = { who: 'me' | 'peer'; text: string; at: number };
type UiWorkerEvent = SessionEvent | { t: 'audioPcm'; pcm: Float32Array; sampleRate: number; channels: number };

type RestartFlow = {
  startedAt: number;
  attempt: number;
  config: SessionConfig;
  timer?: ReturnType<typeof setTimeout>;
  stableTimer?: ReturnType<typeof setTimeout>;
  deadlineTimer?: ReturnType<typeof setTimeout>;
  reconnecting: boolean;
};

type Els = {
  root: HTMLElement;
  toolbar: HTMLElement;
  viewport: HTMLElement;
  dock: HTMLElement;
  side: HTMLElement;
  edge: HTMLElement;
  overlay: HTMLElement;
  toast: HTMLElement;
  remoteCursor: HTMLImageElement;
  peerLabel: HTMLElement;
  peerSub: HTMLElement;
  recordingIndicator: HTMLElement;
  btnMonitors: HTMLButtonElement;
  btnFit: HTMLButtonElement;
  btnViewOnly: HTMLButtonElement;
  chatList: HTMLElement;
  chatInput: HTMLInputElement;
  voiceCallButton: HTMLButtonElement;
  voiceCallStatus: HTMLElement;
  statCodec: HTMLElement;
  statRes: HTMLElement;
  statFps: HTMLElement;
  statBitrate: HTMLElement;
  statDropped: HTMLElement;
  statDuration: HTMLElement;
  statVersion: HTMLElement;
  statDevice: HTMLElement;
  statUser: HTMLElement;
  statPlatform: HTMLElement;
  overlayPeer: HTMLElement;
  overlayTarget: HTMLElement;
  fieldId: HTMLElement;
  peerIdInput: HTMLInputElement;
  passwordInput: HTMLInputElement;
  saveCheckbox: HTMLInputElement;
  osLoginArea: HTMLElement;
  osLoginCheckbox: HTMLInputElement;
  osLoginFields: HTMLElement;
  osLoginAuthFields: HTMLElement;
  osPasswordInput: HTMLInputElement;
  osCurrentPasswordInput: HTMLInputElement;
  connectBtn: HTMLButtonElement;
  overlayStatus: HTMLElement;
  overlayStatusText: HTMLElement;
  overlayError: HTMLElement;
  reconnectCancel: HTMLButtonElement;
};

function q<T extends Element>(scope: ParentNode, sel: string): T {
  const el = scope.querySelector<T>(sel);
  if (!el) throw new Error(`rdclient: missing element ${sel}`);
  return el;
}

/**
 * Peer permission name (PermissionInfo_Permission) -> the control it governs.
 * Permissions with no control here are tracked but change nothing. Keyboard
 * additionally gates the modifier latches and Type (KEYBOARD_EXTRA_IDS) — the
 * map keeps one canonical id per permission because the peer reports the
 * permission, not the widgets.
 */
export const PERMISSION_CONTROLS: Record<string, { id: string; title: string }> = {
  File: { id: 'rd-btn-files', title: 'File transfer' },
  Clipboard: { id: 'rd-btn-clip', title: 'Send clipboard to remote' },
  Keyboard: { id: 'rd-btn-cad', title: 'Keyboard shortcuts' },
};

/** Controls beyond the canonical one that a withdrawn Keyboard permission disables. */
const KEYBOARD_EXTRA_IDS = ['rd-lat-ctrl', 'rd-lat-alt', 'rd-key-del', 'rd-btn-type'];

export class RdApp {
  private cfg: RdGlobalConfig | undefined;
  private el!: Els;
  private canvas!: HTMLCanvasElement;
  private worker: Worker | undefined;
  private detach: (() => void) | undefined;
  private workerUrl = '';
  private fixedPeerId = '';
  private peerId = '';
  private state: SessionState = 'closed';
  private sessionEpoch = 0;
  private canvasTransferred = false;
  private displays: DisplayInfo[] = [];
  private current = 0;
  private stats: SessionStats | undefined;
  private streamStartMs = 0;
  private ticker: ReturnType<typeof setInterval> | undefined;
  private toastTimer: ReturnType<typeof setTimeout> | undefined;
  private pendingHashHex: string | undefined; // h1 emitted this session, pending persist
  private connectedWithSavedHash = false; // this attempt used a stored hash
  private sessionHashHex: string | undefined; // h1 of the live session — lets the file panel log in silently
  /** Peer-advertised permissions for THIS session; absent means granted. */
  private permissions: Record<string, boolean> = {};
  private privacyModeSupported = false;
  private privacyModeImpls: { key: string; label: string }[] = [];
  private privacyModeOn = false;
  private privacyModePending = false;
  private activePrivacyImplKey = '';
  private blockInputOn = false;
  private blockInputPending = false;
  private lockAfterSessionEnd = false;
  private osLoginFlow: OsLoginFlow | undefined;
  private osLoginHydration: Promise<void> = Promise.resolve();
  private osLoginHydratedPeer = '';
  private osLoginHasSavedPassword = false;
  private reconnectConfig: SessionConfig | undefined;
  private restartFlow: RestartFlow | undefined;
  private terminalSupported = false;
  private cameraSupported = false;

  private filePanel: FilePanel | undefined;
  private terminalPanel: TerminalPanel | undefined;
  private cameraPanel: CameraPanel | undefined;
  private audioPlayback: RemoteAudioPlayback | undefined;
  private recorder: LocalSessionRecorder | undefined;
  private remoteAudioEnabled = true;
  private audioStarted = false;
  private audioMuted = false;
  private audioVolume = 1;
  private clipboardEnabled = true;
  private clipboardSyncPrompt: HTMLElement | undefined;
  private recording = false;
  private recordingStartedMs = 0;
  private videoEl!: HTMLVideoElement;
  /** Set only on insecure origins, where WebCodecs is unavailable. */
  private msePlayer: MseVideoPlayer | undefined;

  // --- chrome state ---------------------------------------------------------
  private viewOnly = false;
  private latchCtrl = false;
  private latchAlt = false;
  private inputMode: InputMode = 'pointer';
  private fitMode: FitMode = 'fit';
  private quality: number = QUALITY.balanced;
  private customQuality = 75;
  private customFps = 30;
  private adaptiveFpsTarget = 30;
  private adaptiveFps = false;
  private adaptiveStableSamples = 0;
  private lastDroppedFrames = 0;
  private codecSupport: Array<'auto'|'vp9'|'h264'|'h265'|'vp8'|'av1'> = ['auto'];
  private preferredCodec = 'auto';
  private showRemoteCursor = false;
  private followRemoteCursor = false;
  private followRemoteWindow = false;
  private cursorScale = 1;
  private remoteCursorHot = { x: 0, y: 0 };
  private platformAdditions = '';
  private sideTab: SideTab = 'files';
  private chatLog: ChatEntry[] = [];
  private chatUnread = 0;
  private voiceCall: VoiceCaptureController | undefined;
  private voiceCallState: 'idle'|'preparing'|'waiting'|'connected' = 'idle';
  private readonly voiceCallAttempts = new VoiceCallAttemptOwner();
  /** Secure context + getUserMedia + WebCodecs AudioEncoder; decided once per page. */
  private voiceCallSupported: boolean | undefined;
  private peerWho = ''; // user@host once peerInfo arrives
  private peerPlatform = '';
  private pop: HTMLElement | undefined; // the one open popover
  private popAnchor: HTMLElement | undefined;
  private popCleanup: (() => void) | undefined;

  mount(): void {
    (window as unknown as { __rdApp?: RdApp }).__rdApp = this; // console/debug handle
    this.cfg = (window as unknown as RdWindow).__RD__;
    this.ensureDom();
    this.renderTopBar();
    this.renderDock();
    this.renderSide();
    this.renderOverlay();
    const osLoginUrl = this.cfg?.osLoginUrl ?? '';
    const osLoginCsrf = this.cfg?.csrfToken ?? '';
    if (canUseOsLoginSettings(osLoginUrl, osLoginCsrf, window.isSecureContext)) {
      this.osLoginFlow = new OsLoginFlow(
        new OsLoginSettingsClient(osLoginUrl, osLoginCsrf),
      );
      this.el.osLoginArea.hidden = false;
    } else {
      this.el.osLoginArea.hidden = true;
    }

    const attr = document
      .querySelector('script[data-rd-worker]')
      ?.getAttribute('data-rd-worker');
    this.workerUrl = resolveWorkerUrl(this.cfg?.workerUrl, attr, import.meta.url);

    this.fixedPeerId =
      normalizePeerId(this.cfg?.peerId ?? '') || peerIdFromSearch(location.search) || '';
    if (this.fixedPeerId) {
      this.el.fieldId.hidden = true;
      this.el.overlayTarget.hidden = false;
      this.el.overlayPeer.textContent = this.fixedPeerId;
      this.el.peerIdInput.value = this.fixedPeerId;
      this.el.peerLabel.textContent = this.fixedPeerId;
      this.hydrateSavedPassword(this.fixedPeerId);
      this.osLoginHydration = this.hydrateOsLogin(this.fixedPeerId);
      this.el.passwordInput.focus();
    } else {
      this.el.fieldId.hidden = false;
      this.el.overlayTarget.hidden = true;
      this.el.peerIdInput.focus();
    }
    // Rewrite the field itself, not just the value read out of it: the saved
    // password is keyed by ID, so "123 456 789" and "123456789" would otherwise
    // look like two different devices, and the user would see their pasted
    // spaces survive a failed connect with no hint as to why.
    this.el.peerIdInput.addEventListener('change', () => {
      this.el.peerIdInput.value = normalizePeerId(this.el.peerIdInput.value);
      this.hydrateSavedPassword(this.el.peerIdInput.value);
      this.osLoginHydration = this.hydrateOsLogin(this.el.peerIdInput.value);
    });

    // Saved password + fixed peer -> sign straight in. Skipped when ?lo=1
    // (the user logged out on purpose) so the connect screen stays put.
    if (this.fixedPeerId && !loggedOutFromSearch(location.search) && loadSavedHash(this.fixedPeerId)) {
      void this.osLoginHydration.then(() => this.onConnectClick());
    }

    // Surface it before they type a password and press Connect.
    const blocked = this.secureContextProblem();
    if (blocked) {
      this.setOverlayError(blocked);
    }

    this.ticker = setInterval(() => {
      const start = this.stats?.startedAtMs || this.streamStartMs;
      this.el.statDuration.textContent =
        start && this.state === 'streaming' ? formatDuration(Date.now() - start) : '—';
      if (this.recording && this.recordingStartedMs) {
        const time = this.el.recordingIndicator.querySelector('span');
        if (time) time.textContent = formatDuration(Date.now() - this.recordingStartedMs);
      }
    }, 1000);

    window.addEventListener('beforeunload', () => {
      this.endVoiceCall(true);
      this.releaseRemoteSecurityState();
      this.post({ c: 'disconnect' });
    });
  }

  // --- DOM scaffolding -------------------------------------------------------

  private ensureDom(): void {
    let root = document.getElementById('rd-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'rd-root';
      document.body.appendChild(root);
    }
    let toolbar = document.getElementById('rd-toolbar');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.id = 'rd-toolbar';
    }
    root.prepend(toolbar);
    let viewport = document.getElementById('rd-viewport');
    if (!viewport) {
      viewport = document.createElement('div');
      viewport.id = 'rd-viewport';
    }
    root.appendChild(viewport);
    let canvas = document.getElementById('rd-canvas') as HTMLCanvasElement | null;
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'rd-canvas';
    }
    viewport.appendChild(canvas);
    let vid = document.getElementById('rd-video') as HTMLVideoElement | null;
    if (!vid) {
      vid = document.createElement('video');
      vid.id = 'rd-video';
      vid.muted = true; // audio has its own path; a muted element may autoplay
      vid.playsInline = true;
      vid.hidden = true;
    }
    viewport.appendChild(vid);
    this.videoEl = vid;
    // Pre-redesign mount point some cached pages still carry.
    document.getElementById('rd-stats')?.remove();
    const make = (id: string): HTMLElement => {
      let n = document.getElementById(id);
      if (!n) {
        n = document.createElement('div');
        n.id = id;
      }
      viewport.appendChild(n);
      return n;
    };
    const side = make('rd-side');
    const edge = make('rd-edge');
    const overlay = make('rd-overlay');
    const toast = make('rd-toast');
    let remoteCursor = document.getElementById('rd-remote-cursor') as HTMLImageElement | null;
    if (!remoteCursor || remoteCursor.tagName !== 'IMG') {
      remoteCursor?.remove();
      remoteCursor = document.createElement('img');
      remoteCursor.id = 'rd-remote-cursor';
      remoteCursor.alt = '';
      remoteCursor.hidden = true;
      viewport.appendChild(remoteCursor);
    }
    // The dock sits BELOW the viewport in normal flow, never over it — an
    // overlay here hides exactly the strip of remote screen (the Windows
    // taskbar) an operator most often needs.
    let dock = document.getElementById('rd-dock');
    if (!dock) {
      dock = document.createElement('div');
      dock.id = 'rd-dock';
    }
    root.appendChild(dock);
    root.dataset.state = 'closed';
    viewport.dataset.fit = 'fit';
    this.canvas = canvas;
    this.el = {
      root,
      toolbar,
      viewport,
      dock,
      side,
      edge,
      overlay,
      toast,
      remoteCursor,
    } as Els; // remaining refs filled by render*()

    this.setupFullscreenBars();
  }

  /**
   * Fullscreen bar toggles (PR #45). In fullscreen both bars slide off-screen
   * and a 60px arrow at each edge slides them back on click — hover-reveal kept
   * stealing the pointer from the remote desktop's own menus and tabs. The
   * side panel dims until hovered and gives back the rows a shown bar covers.
   * Everything resets when fullscreen ends so the windowed layout is untouched.
   */
  private setupFullscreenBars(): void {
    const rootEl = document.getElementById('rd-root');
    const dockEl = document.getElementById('rd-dock');
    const toolbarEl = document.getElementById('rd-toolbar');
    const edgeEl = document.getElementById('rd-edge');
    const sideEl = document.getElementById('rd-side');
    if (!rootEl) return;

    let bottomOpen = false;
    let topOpen = false;

    // The side panel loses the rows a shown bar covers.
    const updateSideLayout = () => {
      if (!sideEl) return;
      if (document.fullscreenElement) {
        const top = topOpen && toolbarEl ? toolbarEl.offsetHeight : 0;
        const bottom = bottomOpen && dockEl ? dockEl.offsetHeight : 0;
        sideEl.style.top = `${top}px`;
        sideEl.style.height = `calc(100vh - ${top}px - ${bottom}px)`;
      } else {
        sideEl.style.top = '';
        sideEl.style.height = '';
      }
    };

    const makeArrow = (id: string, glyph: string): HTMLButtonElement => {
      const btn = document.createElement('button');
      btn.id = id;
      btn.type = 'button';
      btn.className = 'rd-arrow-btn';
      btn.textContent = glyph;
      rootEl.appendChild(btn);
      return btn;
    };

    const bottomArrow = dockEl ? makeArrow('rd-dock-arrow', '\u25b2') : null;
    bottomArrow?.addEventListener('click', () => {
      bottomOpen = !bottomOpen;
      dockEl!.style.transform = bottomOpen ? 'translateY(0)' : 'translateY(100%)';
      bottomArrow.textContent = bottomOpen ? '\u25bc' : '\u25b2';
      bottomArrow.style.bottom = bottomOpen ? `${dockEl!.offsetHeight}px` : '0px';
      updateSideLayout();
    });

    const topArrow = toolbarEl ? makeArrow('rd-toolbar-arrow', '\u25bc') : null;
    topArrow?.addEventListener('click', () => {
      topOpen = !topOpen;
      toolbarEl!.style.transform = topOpen ? 'translateY(0)' : 'translateY(-100%)';
      topArrow.textContent = topOpen ? '\u25b2' : '\u25bc';
      topArrow.style.top = topOpen ? `${toolbarEl!.offsetHeight}px` : '0px';
      updateSideLayout();
    });

    if (edgeEl) {
      edgeEl.addEventListener('mouseenter', () => { edgeEl.style.opacity = '1'; });
      edgeEl.addEventListener('mouseleave', () => {
        if (document.fullscreenElement) edgeEl.style.opacity = '0.30';
      });
    }

    const onFullscreenChange = () => {
      const fs = !!document.fullscreenElement;
      if (fs) {
        bottomOpen = false;
        topOpen = false;
        document.body.classList.add('rd-fullscreen-active');
        if (edgeEl) edgeEl.style.opacity = '0.30';
        if (bottomArrow) { bottomArrow.textContent = '\u25b2'; bottomArrow.style.bottom = '0px'; }
        if (topArrow) { topArrow.textContent = '\u25bc'; topArrow.style.top = '0px'; }
      } else {
        document.body.classList.remove('rd-fullscreen-active');
        if (dockEl) dockEl.style.transform = '';
        if (toolbarEl) toolbarEl.style.transform = '';
        if (edgeEl) edgeEl.style.opacity = '';
        if (bottomArrow) bottomArrow.style.bottom = '';
        if (topArrow) topArrow.style.top = '';
      }
      updateSideLayout();
    };

    document.addEventListener('fullscreenchange', onFullscreenChange);
    onFullscreenChange();
  }

  // --- top bar -----------------------------------------------------------------

  private renderTopBar(): void {
    const initials =
      (this.cfg?.myName || this.cfg?.myId || '?')
        .split(/[\s._-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join('') || '?';
    this.el.toolbar.innerHTML = `
      <div class="rd-tb-brand">
        <img src="/rdclient/logo.png" alt="" width="30" height="30">
        <span class="rd-tb-brandtext">
          <span class="rd-tb-name">Corten<span>Desk</span></span>
          <span class="rd-tb-sub">RustDesk Remote Console</span>
        </span>
      </div>
      <div class="rd-tb-island">
        <span class="rd-peer-chip">
          <span class="rd-status-dot" aria-hidden="true"></span>
          <span class="rd-peer-meta">
            <span class="rd-peer" id="rd-peer-label">—</span>
            <span class="rd-peer-sub" id="rd-peer-sub"></span>
          </span>
        </span>
        <span class="rd-island-sep rd-stream-only" aria-hidden="true"></span>
        <span class="rd-recording-indicator rd-stream-only" id="rd-recording-indicator" hidden aria-live="polite">REC <span>00:00</span></span>
        <button type="button" class="rd-ib rd-stream-only" id="rd-btn-monitors" title="Select monitor" aria-label="Select monitor" aria-haspopup="true" hidden>${iconHtml('monitor')}</button>
        <button type="button" class="rd-ib rd-stream-only" id="rd-btn-more" title="More options" aria-label="More options" aria-haspopup="true">${iconHtml('more')}</button>
        <span class="rd-island-sep rd-stream-only" aria-hidden="true"></span>
        <button type="button" class="rd-chip rd-stream-only" id="rd-btn-fit" aria-haspopup="true" title="Scale mode">
          <span id="rd-fit-label">Fit to screen</span>${iconHtml('chevronDown')}
        </button>
        <button type="button" class="rd-chip rd-stream-only" id="rd-btn-viewonly" aria-pressed="false" title="Block all input to the remote device">
          ${iconHtml('eye')}<span>View only</span>
        </button>
        <button type="button" class="rd-disconnect rd-stream-only" id="rd-btn-disconnect">Disconnect</button>
      </div>
      <div class="rd-tb-user">
        <span class="rd-avatar" title="${escapeHtml(this.cfg?.myName || this.cfg?.myId || '')}">${escapeHtml(initials)}</span>
      </div>`;
    const t = this.el.toolbar;
    this.el.peerLabel = q(t, '#rd-peer-label');
    this.el.peerSub = q(t, '#rd-peer-sub');
    this.el.recordingIndicator = q(t, '#rd-recording-indicator');
    this.el.btnMonitors = q(t, '#rd-btn-monitors');
    this.el.btnFit = q(t, '#rd-btn-fit');
    this.el.btnViewOnly = q(t, '#rd-btn-viewonly');

    this.el.btnMonitors.addEventListener('click', () => this.openMonitorPop());
    q<HTMLButtonElement>(t, '#rd-btn-more').addEventListener('click', (e) =>
      this.openMorePop(e.currentTarget as HTMLElement),
    );
    this.el.btnFit.addEventListener('click', () => this.openFitPop());
    this.el.btnViewOnly.addEventListener('click', () => this.toggleViewOnly());
    q<HTMLButtonElement>(t, '#rd-btn-disconnect').addEventListener('click', () => {
      this.setLoggedOutFlag(true); // an explicit logout must not auto-login on reload
      this.clearRestartFlow();
      this.endVoiceCall(true);
      this.releaseRemoteSecurityState();
      this.destroyAdvancedPanels();
      this.post({ c: 'disconnect' });
      this.setState('closed');
    });
  }

  private toggleViewOnly(): void {
    this.viewOnly = !this.viewOnly;
    this.post({ c: 'viewOnly', enabled: this.viewOnly });
    this.el.btnViewOnly.setAttribute('aria-pressed', String(this.viewOnly));
    this.el.btnViewOnly.classList.toggle('rd-on', this.viewOnly);
    // Latched modifiers make no sense with input off; drop them quietly.
    if (this.viewOnly) {
      this.setLatches(false, false);
      this.terminalPanel?.destroy();
      this.terminalPanel = undefined;
      this.removeClipboardSyncOffer();
    }
    this.toast(this.viewOnly ? 'View only — input is not sent' : 'Input enabled');
  }

  // --- bottom dock ---------------------------------------------------------------

  private renderDock(): void {
    const db = (id: string, icon: IconName, label: string, title = label): string =>
      `<button type="button" class="rd-db" id="${id}" title="${title}" aria-label="${title}">` +
      `${iconHtml(icon)}<span>${label}</span></button>`;
    this.el.dock.innerHTML = `
      <div class="rd-dock-group" role="group" aria-label="Keyboard">
        ${db('rd-lat-ctrl', 'keyboard', 'Ctrl', 'Hold Ctrl for clicks and keys')}
        ${db('rd-lat-alt', 'keyboard', 'Alt', 'Hold Alt for clicks and keys')}
        ${db('rd-key-del', 'keyboard', 'Del', 'Send Delete')}
        ${db('rd-btn-cad', 'keyboard', 'Keys', 'Keyboard shortcuts')}
      </div>
      <span class="rd-dock-sep" aria-hidden="true"></span>
      <div class="rd-dock-group" role="group" aria-label="Input mode">
        ${db('rd-mode-pointer', 'pointer', 'Pointer', 'Pointer mode — touch acts as a pressed button')}
        ${db('rd-mode-touch', 'touch', 'Touch', 'Touch mode — drag moves the cursor, tap clicks, long-press right-clicks')}
      </div>
      <span class="rd-dock-sep" aria-hidden="true"></span>
      <div class="rd-dock-group" role="group" aria-label="Send">
        ${db('rd-btn-type', 'typeText', 'Type', 'Type text on the remote device')}
        ${db('rd-btn-clip', 'clipboard', 'Clipboard', 'Send clipboard to remote')}
      </div>
      <span class="rd-dock-sep" aria-hidden="true"></span>
      <div class="rd-dock-group" role="group" aria-label="Panels">
        ${db('rd-btn-files', 'folderTransfer', 'File Transfer')}
        ${db('rd-btn-chat', 'chat', 'Chat')}
        ${db('rd-btn-session', 'info', 'Session', 'Session details')}
      </div>`;
    const d = this.el.dock;
    q<HTMLButtonElement>(d, '#rd-lat-ctrl').addEventListener('click', () =>
      this.setLatches(!this.latchCtrl, this.latchAlt),
    );
    q<HTMLButtonElement>(d, '#rd-lat-alt').addEventListener('click', () =>
      this.setLatches(this.latchCtrl, !this.latchAlt),
    );
    q<HTMLButtonElement>(d, '#rd-key-del').addEventListener('click', () => {
      this.pressControl(ControlKey.Delete, 'Delete sent');
    });
    q<HTMLButtonElement>(d, '#rd-btn-cad').addEventListener('click', (e) =>
      this.openKeysPop(e.currentTarget as HTMLElement),
    );
    q<HTMLButtonElement>(d, '#rd-mode-pointer').addEventListener('click', () => this.setInputMode('pointer'));
    q<HTMLButtonElement>(d, '#rd-mode-touch').addEventListener('click', () => this.setInputMode('touch'));
    this.setInputMode('pointer');
    q<HTMLButtonElement>(d, '#rd-btn-type').addEventListener('click', (e) =>
      this.openTypePop(e.currentTarget as HTMLElement),
    );
    q<HTMLButtonElement>(d, '#rd-btn-clip').addEventListener('click', () => {
      void this.sendClipboard();
    });
    q<HTMLButtonElement>(d, '#rd-btn-files').addEventListener('click', () => this.openSide('files'));
    q<HTMLButtonElement>(d, '#rd-btn-chat').addEventListener('click', () => this.openSide('chat'));
    q<HTMLButtonElement>(d, '#rd-btn-session').addEventListener('click', () => this.openSide('details'));
  }

  private setInputMode(mode: InputMode): void {
    this.inputMode = mode;
    this.el.dock.querySelector('#rd-mode-pointer')?.classList.toggle('rd-on', mode === 'pointer');
    this.el.dock.querySelector('#rd-mode-touch')?.classList.toggle('rd-on', mode === 'touch');
  }

  private setLatches(ctrl: boolean, alt: boolean): void {
    this.latchCtrl = ctrl;
    this.latchAlt = alt;
    for (const [id, on] of [
      ['rd-lat-ctrl', ctrl],
      ['rd-lat-alt', alt],
    ] as const) {
      const b = this.el.dock.querySelector<HTMLButtonElement>(`#${id}`);
      b?.classList.toggle('rd-on', on);
      b?.setAttribute('aria-pressed', String(on));
    }
  }

  /** Send a single control key as a press (down+up in one message). */
  private pressControl(key: ControlKey, note?: string): void {
    this.post({ c: 'key', down: false, press: true, keyKind: 'control', value: key, modifiers: [] });
    if (note) this.toast(note);
  }

  // --- side panel (Files / Chat / Details) ----------------------------------------

  private renderSide(): void {
    const tab = (id: SideTab, icon: IconName, label: string): string =>
      `<button type="button" class="rd-tab" data-tab="${id}" role="tab" aria-selected="false">` +
      `${iconHtml(icon)}<span>${label}</span><i class="rd-badge" hidden></i></button>`;
    this.el.side.innerHTML = `
      <header class="rd-side-head">
        <div class="rd-side-tabs" role="tablist">
          ${tab('files', 'folderTransfer', 'Files')}
          ${tab('chat', 'chat', 'Chat')}
          ${tab('details', 'info', 'Details')}
        </div>
        <button type="button" class="rd-ib" id="rd-side-close" title="Close panel" aria-label="Close panel">${iconHtml('close')}</button>
      </header>
      <div class="rd-side-body">
        <section class="rd-pane" data-pane="files" hidden></section>
        <section class="rd-pane rd-pane-chat" data-pane="chat" hidden>
          <div class="rd-voice-call">
            <button type="button" class="rd-voice-call-btn" id="rd-voice-call" aria-describedby="rd-voice-call-status">Voice call</button>
            <span id="rd-voice-call-status" role="status" aria-live="polite">Voice calls are off</span>
          </div>
          <div class="rd-chat-list" id="rd-chat-list"></div>
          <form class="rd-chat-compose" id="rd-chat-form">
            <input type="text" id="rd-chat-input" autocomplete="off" placeholder="Message the remote user…" maxlength="2000">
            <button type="submit" class="rd-ib rd-chat-send" title="Send" aria-label="Send message">${iconHtml('send')}</button>
          </form>
        </section>
        <section class="rd-pane rd-pane-details" data-pane="details" hidden>
          <dl class="rd-stats-body">
            <div class="rd-stat-row"><dt>Device</dt><dd id="rd-stat-device">—</dd></div>
            <div class="rd-stat-row"><dt>User</dt><dd id="rd-stat-user">—</dd></div>
            <div class="rd-stat-row"><dt>Platform</dt><dd id="rd-stat-platform">—</dd></div>
            <div class="rd-stat-row"><dt>Peer version</dt><dd id="rd-stat-version">—</dd></div>
            <div class="rd-stat-row"><dt>Codec</dt><dd id="rd-stat-codec">—</dd></div>
            <div class="rd-stat-row"><dt>Resolution</dt><dd id="rd-stat-res">—</dd></div>
            <div class="rd-stat-row"><dt>FPS</dt><dd id="rd-stat-fps">—</dd></div>
            <div class="rd-stat-row"><dt>Bitrate</dt><dd id="rd-stat-bitrate">—</dd></div>
            <div class="rd-stat-row"><dt>Frames dropped</dt><dd id="rd-stat-dropped">—</dd></div>
            <div class="rd-stat-row"><dt>Duration</dt><dd id="rd-stat-duration">—</dd></div>
          </dl>
        </section>
      </div>`;
    this.el.edge.innerHTML = `
      <button type="button" class="rd-edge-btn" data-open="files" title="File transfer" aria-label="Open file transfer">${iconHtml('folderTransfer')}</button>
      <button type="button" class="rd-edge-btn" data-open="chat" title="Chat" aria-label="Open chat">${iconHtml('chat')}<i class="rd-badge" hidden></i></button>
      <button type="button" class="rd-edge-btn" data-open="details" title="Session details" aria-label="Open session details">${iconHtml('info')}</button>`;

    const s = this.el.side;
    this.el.chatList = q(s, '#rd-chat-list');
    this.el.chatInput = q(s, '#rd-chat-input');
    this.el.voiceCallButton = q(s, '#rd-voice-call');
    this.el.voiceCallStatus = q(s, '#rd-voice-call-status');
    this.renderVoiceCall();
    this.el.statDevice = q(s, '#rd-stat-device');
    this.el.statUser = q(s, '#rd-stat-user');
    this.el.statPlatform = q(s, '#rd-stat-platform');
    this.el.statVersion = q(s, '#rd-stat-version');
    this.el.statCodec = q(s, '#rd-stat-codec');
    this.el.statRes = q(s, '#rd-stat-res');
    this.el.statFps = q(s, '#rd-stat-fps');
    this.el.statBitrate = q(s, '#rd-stat-bitrate');
    this.el.statDropped = q(s, '#rd-stat-dropped');
    this.el.statDuration = q(s, '#rd-stat-duration');

    for (const b of s.querySelectorAll<HTMLButtonElement>('.rd-tab')) {
      b.addEventListener('click', () => this.openSide(b.dataset.tab as SideTab));
    }
    q<HTMLButtonElement>(s, '#rd-side-close').addEventListener('click', () => this.closeSide());
    for (const b of this.el.edge.querySelectorAll<HTMLButtonElement>('.rd-edge-btn')) {
      b.addEventListener('click', () => this.openSide(b.dataset.open as SideTab));
    }
    q<HTMLFormElement>(s, '#rd-chat-form').addEventListener('submit', (e) => {
      e.preventDefault();
      this.sendChatFromInput();
    });
    this.el.voiceCallButton.addEventListener('click', () => { void this.toggleVoiceCall(); });
  }

  private get sideOpen(): boolean {
    return this.el.side.classList.contains('rd-open');
  }

  private openSide(tabName: SideTab): void {
    if (this.state !== 'streaming') {
      this.toast('Connect to a device first');
      return;
    }
    // Same tab, already open -> the dock button acts as a toggle.
    if (this.sideOpen && this.sideTab === tabName) {
      this.closeSide();
      return;
    }
    if (tabName === 'files') {
      if (this.permissions.File === false) {
        this.toast('This device does not permit file transfer');
        return;
      }
      this.ensureFilePanel();
    }
    this.sideTab = tabName;
    this.el.side.classList.add('rd-open');
    this.el.root.classList.add('rd-side-is-open');
    for (const b of this.el.side.querySelectorAll<HTMLButtonElement>('.rd-tab')) {
      const active = b.dataset.tab === tabName;
      b.classList.toggle('rd-active', active);
      b.setAttribute('aria-selected', String(active));
    }
    for (const p of this.el.side.querySelectorAll<HTMLElement>('.rd-pane')) {
      p.hidden = p.dataset.pane !== tabName;
    }
    for (const [id, on] of [
      ['rd-btn-files', tabName === 'files'],
      ['rd-btn-chat', tabName === 'chat'],
      ['rd-btn-session', tabName === 'details'],
    ] as const) {
      this.el.dock.querySelector(`#${id}`)?.classList.toggle('rd-on', on);
    }
    if (tabName === 'chat') {
      this.chatUnread = 0;
      this.renderChatBadges();
      this.renderChatList();
      this.el.chatInput.focus();
    }
  }

  private closeSide(): void {
    this.el.side.classList.remove('rd-open');
    this.el.root.classList.remove('rd-side-is-open');
    for (const id of ['rd-btn-files', 'rd-btn-chat', 'rd-btn-session']) {
      this.el.dock.querySelector(`#${id}`)?.classList.remove('rd-on');
    }
  }

  // The file panel mounts INSIDE the Files pane. Its FILE_TRANSFER connection
  // reuses this session's h1 credential — no second password prompt.
  private ensureFilePanel(): void {
    if (this.filePanel) {
      this.filePanel.open();
      return;
    }
    const pane = q<HTMLElement>(this.el.side, '[data-pane="files"]');
    this.filePanel = new FilePanel({
      viewport: pane,
      workerUrl: this.workerUrl,
      toast: (msg) => this.toast(msg),
      getConfig: () => {
        if (!this.cfg || this.state !== 'streaming') return null;
        return buildSessionConfig(this.cfg, this.peerId, '', this.sessionHashHex, 'fileTransfer');
      },
    });
    this.filePanel.open();
  }

  private openTerminalPanel(): void {
    if (!remoteInputAllowed(this.viewOnly, 'terminal')) {
      this.toast('Remote terminal is unavailable in view-only mode');
      return;
    }
    if (!this.terminalPanel) {
      this.terminalPanel = new TerminalPanel({
        root: this.el.root,
        workerUrl: this.workerUrl,
        toast: (message) => this.toast(message),
        getConfig: () => {
          if (!this.cfg || this.state !== 'streaming' || !this.terminalSupported
              || !remoteInputAllowed(this.viewOnly, 'terminal')) return null;
          return buildSessionConfig(this.cfg, this.peerId, '', this.sessionHashHex, 'terminal');
        },
      });
    }
    this.terminalPanel.open();
  }

  private openCameraPanel(): void {
    if (!this.cameraPanel) {
      this.cameraPanel = new CameraPanel({
        root: this.el.root,
        workerUrl: this.workerUrl,
        toast: (message) => this.toast(message),
        getConfig: () => {
          if (!this.cfg || this.state !== 'streaming' || !this.cameraSupported) return null;
          return buildSessionConfig(this.cfg, this.peerId, '', this.sessionHashHex, 'viewCamera');
        },
      });
    }
    this.cameraPanel.open();
  }

  // --- chat -----------------------------------------------------------------------

  private sendChatFromInput(): void {
    const text = this.el.chatInput.value.trim();
    if (!text || this.state !== 'streaming') return;
    this.post({ c: 'chat', text });
    // No delivery ack exists in the protocol; echo what we sent.
    this.chatLog.push({ who: 'me', text, at: Date.now() });
    this.el.chatInput.value = '';
    this.renderChatList();
  }

  /** Called directly by the Voice call button so getUserMedia retains its user gesture. */
  private async toggleVoiceCall(): Promise<void> {
    if (this.voiceCallState === 'preparing') return;
    if (this.voiceCallState === 'waiting' || this.voiceCallState === 'connected') {
      this.endVoiceCall(true);
      return;
    }
    if (this.state !== 'streaming') {
      this.toast('Voice calls require a connected session');
      return;
    }
    if (this.permissions.Audio === false) {
      this.toast('This device does not permit audio calls');
      return;
    }
    if (!this.remoteAudioEnabled) {
      // The peer drops call audio and never opens its mic while we have
      // remote audio off, so the call would connect and stay silent.
      this.toast('Turn on "Receive remote audio" before starting a voice call');
      return;
    }
    // Same click that grants the mic also unlocks playback of the peer's voice.
    void this.resumeAudioFromUserGesture();
    if (!this.voiceCall) {
      this.voiceCall = new VoiceCaptureController(browserVoiceCaptureDeps(), {
        sendFormat: (sampleRate, channels) => this.post({ c: 'voiceAudioFormat', sampleRate, channels }),
        sendFrame: (data) => this.post({ c: 'voiceAudioFrame', data }),
        onError: (message) => {
          this.toast(message);
          this.endVoiceCall(true);
        },
      });
    }
    // Calling getUserMedia here, before any protocol packet, preserves the direct-click permission gesture.
    const sessionEpoch = this.sessionEpoch;
    const attempt = this.voiceCallAttempts.begin();
    const voiceCall = this.voiceCall;
    this.voiceCallState = 'preparing';
    this.renderVoiceCall();
    const outcome = await this.voiceCallAttempts.wait(attempt, () => voiceCall.prepare());
    if (!outcome.owned) return;
    const prepared = outcome.value;
    if (sessionEpoch !== this.sessionEpoch || this.voiceCallState !== 'preparing') return;
    if (!prepared.ok) {
      this.voiceCallState = 'idle';
      this.renderVoiceCall();
      this.toast(prepared.message ?? 'Microphone is unavailable');
      return;
    }
    const currentPermissions = this.permissions as Record<string, boolean | undefined>;
    if (this.state !== 'streaming' || currentPermissions.Audio === false) {
      this.endVoiceCall(false);
      return;
    }
    this.voiceCallState = 'waiting';
    this.renderVoiceCall();
    this.post({ c: 'voiceCallStart' });
  }

  private onVoiceCall(state: 'waiting'|'accepted'|'rejected'|'closed', detail?: string): void {
    if (state === 'accepted') {
      if (!this.voiceCall?.start()) {
        this.endVoiceCall(true);
        return;
      }
      this.voiceCallState = 'connected';
    } else if (state === 'waiting') {
      this.voiceCallState = 'waiting';
    } else {
      this.voiceCallAttempts?.invalidate();
      this.voiceCall?.stop();
      this.voiceCallState = 'idle';
      if (state === 'rejected') this.toast('Voice call was declined');
      else if (detail) this.toast(detail);
    }
    this.renderVoiceCall();
  }

  private endVoiceCall(sendClose: boolean): void {
    const hadProtocolCall = this.voiceCallState === 'waiting' || this.voiceCallState === 'connected';
    this.voiceCallAttempts?.invalidate();
    this.voiceCall?.stop();
    this.voiceCallState = 'idle';
    this.renderVoiceCall();
    if (sendClose && hadProtocolCall) this.post({ c: 'voiceCallClose' });
  }

  private renderVoiceCall(): void {
    if (!this.el?.voiceCallButton) return;
    this.voiceCallSupported ??= browserVoiceCaptureDeps().available();
    const model = voiceCallUiModel(this.state, this.permissions.Audio !== false, this.voiceCallState, this.voiceCallSupported);
    this.el.voiceCallButton.disabled = model.disabled;
    this.el.voiceCallButton.textContent = model.label;
    this.el.voiceCallButton.setAttribute('aria-label', model.ariaLabel);
    this.el.voiceCallStatus.textContent = model.status;
  }

  private onChat(text: string): void {
    this.chatLog.push({ who: 'peer', text, at: Date.now() });
    if (this.sideOpen && this.sideTab === 'chat') {
      this.renderChatList();
    } else {
      this.chatUnread++;
      this.renderChatBadges();
      this.toast(`Chat: ${text.length > 80 ? text.slice(0, 77) + '…' : text}`);
    }
  }

  private renderChatList(): void {
    const fmt = (at: number): string => {
      const d = new Date(at);
      return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    };
    this.el.chatList.innerHTML = this.chatLog.length
      ? this.chatLog
          .map(
            (m) =>
              `<div class="rd-msg-row rd-from-${m.who}"><span class="rd-bubble">${escapeHtml(m.text)}</span>` +
              `<span class="rd-msg-time">${fmt(m.at)}</span></div>`,
          )
          .join('')
      : '<div class="rd-chat-empty">No messages yet. Anything you send pops up on the remote screen.</div>';
    this.el.chatList.scrollTop = this.el.chatList.scrollHeight;
  }

  private renderChatBadges(): void {
    // The dock chat button gains its badge lazily so renderDock stays simple.
    if (!this.el.dock.querySelector('#rd-btn-chat .rd-badge')) {
      const b = document.createElement('i');
      b.className = 'rd-badge';
      b.hidden = true;
      this.el.dock.querySelector('#rd-btn-chat')?.appendChild(b);
    }
    const label = this.chatUnread > 9 ? '9+' : String(this.chatUnread);
    for (const b of this.el.root.querySelectorAll<HTMLElement>('.rd-badge')) {
      b.hidden = this.chatUnread === 0;
      b.textContent = label;
    }
  }

  // --- popovers ---------------------------------------------------------------------

  private closePop(): void {
    this.popCleanup?.();
    this.popCleanup = undefined;
    this.pop?.remove();
    this.pop = undefined;
    this.popAnchor = undefined;
  }

  /**
   * One popover at a time, anchored to a control. Clicking the same anchor
   * again closes it; outside pointer-down and Esc close it; `up` opens above
   * the anchor (for the dock).
   */
  private openPop(anchor: HTMLElement, build: (pop: HTMLElement) => void, up = false): void {
    if (this.pop && this.popAnchor === anchor) {
      this.closePop();
      return;
    }
    this.closePop();
    const pop = document.createElement('div');
    pop.className = 'rd-pop';
    pop.setAttribute('role', 'menu');
    build(pop);
    this.el.root.appendChild(pop);
    const a = anchor.getBoundingClientRect();
    const r = pop.getBoundingClientRect();
    const placement = placePopover(
      a,
      { width: r.width, height: Math.max(r.height, pop.scrollHeight) },
      { width: window.innerWidth, height: window.innerHeight },
      up,
    );
    pop.style.left = `${placement.left}px`;
    pop.style.top = `${placement.top}px`;
    pop.style.maxHeight = `${placement.maxHeight}px`;
    this.pop = pop;
    this.popAnchor = anchor;

    const onDown = (e: Event): void => {
      const t = e.target as Node;
      if (!pop.contains(t) && !anchor.contains(t)) this.closePop();
    };
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') this.closePop();
    };
    document.addEventListener('pointerdown', onDown, true);
    document.addEventListener('keydown', onKey, true);
    this.popCleanup = () => {
      document.removeEventListener('pointerdown', onDown, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }

  private menuItem(
    icon: IconName | null,
    label: string,
    checked = false,
    action?: string,
    securityId?: string,
  ): string {
    const security = securityId ? ` data-security="${escapeHtml(securityId)}"` : '';
    const advanced = action ? ` data-action="${escapeHtml(action)}"` : '';
    return (
      `<button type="button" class="rd-mi${checked ? ' rd-checked' : ''}" role="menuitem"${security}${advanced}>` +
      `${icon ? iconHtml(icon) : '<span class="rd-mi-pad"></span>'}` +
      `<span class="rd-mi-label">${escapeHtml(label)}</span>` +
      `${checked ? iconHtml('check') : ''}</button>`
    );
  }

  private openMonitorPop(): void {
    this.openPop(this.el.btnMonitors, (pop) => {
      const currentDisplay = this.displays[this.current];
      const virtual = parseVirtualDisplayCapability(this.peerPlatform, this.platformAdditions);
      const isVirtualDisplay = !!virtual && currentDisplay?.originalResolution?.width === 0 && currentDisplay.originalResolution.height === 0;
      const fit = { width: Math.round(this.el.viewport.clientWidth), height: Math.round(this.el.viewport.clientHeight) };
      const resolutions = currentDisplay ? buildResolutionChoices({
        supported: currentDisplay.resolutions,
        original: currentDisplay.originalResolution,
        fit,
        isVirtual: isVirtualDisplay,
      }) : [];
      const resolutionHtml = currentDisplay && resolutions.length
        ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Resolution</div>' + resolutions.map((resolution) =>
            `<button type="button" class="rd-mi rd-resolution" data-width="${resolution.width}" data-height="${resolution.height}"><span class="rd-mi-pad"></span><span class="rd-mi-label">${escapeHtml(resolution.label)}</span></button>`,
          ).join('') +
          (isVirtualDisplay ? '<button type="button" class="rd-mi" data-custom-resolution><span class="rd-mi-pad"></span><span class="rd-mi-label">Custom resolution…</span></button>' : '')
        : '';
      const virtualHtml = virtual
        ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Virtual displays</div>' +
          (virtual.impl === 'rustdesk_idd'
            ? [1, 2, 3, 4].map((id) => this.menuItem(null, `Virtual display ${id}`, virtual.rustdeskIds.includes(id), `virtual:${id}`)).join('') +
              this.menuItem(null, 'Unplug all virtual displays', false, 'virtual:all')
            : this.menuItem(null, 'Add virtual display', false, 'virtual:add') +
              this.menuItem(null, 'Remove virtual display', false, 'virtual:remove') +
              this.menuItem(null, 'Unplug all virtual displays', false, 'virtual:all'))
        : '';
      pop.innerHTML =
        '<div class="rd-pop-title">Select monitor</div>' +
        this.displays.map((display, index) => {
          const label = display.name?.trim() || `Monitor ${index + 1}`;
          return (
            `<button type="button" class="rd-mon-tile${index === this.current ? ' rd-checked' : ''}" data-idx="${index}" role="menuitem"${display.online ? '' : ' disabled'}>` +
            `<span class="rd-mon-num">${index + 1}</span>` +
            `<span class="rd-mon-meta"><span class="rd-mon-name">${escapeHtml(label)}</span>` +
            `<span class="rd-mon-res">${display.width}×${display.height}${display.online ? '' : ' · offline'}</span></span>` +
            `${index === this.current ? iconHtml('check') : ''}</button>`
          );
        }).join('') + resolutionHtml + virtualHtml;
      for (const button of pop.querySelectorAll<HTMLButtonElement>('.rd-mon-tile')) {
        button.addEventListener('click', () => {
          this.post({ c: 'switchDisplay', index: Number(button.dataset.idx) });
          // Wait for Misc.switch_display before changing geometry/input mapping.
          this.closePop();
        });
      }
      for (const button of pop.querySelectorAll<HTMLButtonElement>('.rd-resolution')) {
        button.addEventListener('click', () => {
          this.post({
            c: 'displayResolution', display: this.current,
            width: Number(button.dataset.width), height: Number(button.dataset.height),
          });
          this.toast('Resolution change requested');
          this.closePop();
        });
      }
      pop.querySelector<HTMLButtonElement>('[data-custom-resolution]')?.addEventListener('click', () => {
        const value = window.prompt('Custom resolution (width×height)', `${currentDisplay?.width ?? 1920}×${currentDisplay?.height ?? 1080}`);
        const match = value?.trim().match(/^(\d{2,5})\s*[x×]\s*(\d{2,5})$/i);
        if (!match) return;
        const width = Number(match[1]);
        const height = Number(match[2]);
        if (width < 1 || height < 1 || width > 16384 || height > 16384) return;
        this.post({ c: 'displayResolution', display: this.current, width, height });
        this.closePop();
      });
      for (const button of pop.querySelectorAll<HTMLButtonElement>('[data-action^="virtual:"]')) {
        button.addEventListener('click', () => {
          const action = button.dataset.action?.slice('virtual:'.length);
          if (!virtual || !action) return;
          if (action === 'all') this.post({ c: 'virtualDisplay', display: -1, on: false });
          else if (action === 'add') this.post({ c: 'virtualDisplay', display: 0, on: true });
          else if (action === 'remove') this.post({ c: 'virtualDisplay', display: 0, on: false });
          else {
            const display = Number(action);
            this.post({ c: 'virtualDisplay', display, on: !virtual.rustdeskIds.includes(display) });
          }
          this.toast('Virtual-display change requested');
          this.closePop();
        });
      }
    });
  }

  private openMorePop(anchor: HTMLElement): void {
    this.openPop(anchor, (pop) => {
      const fs = !!document.fullscreenElement;
      const security = buildSecurityControlMenu({
        platform: this.peerPlatform,
        permissions: this.permissions,
        privacyModeSupported: this.privacyModeSupported,
        privacyModeImpls: this.privacyModeImpls,
        privacyModeOn: this.privacyModeOn,
        activePrivacyImplKey: this.activePrivacyImplKey,
        blockInputOn: this.blockInputOn,
        lockAfterSessionEnd: this.lockAfterSessionEnd,
        viewOnly: this.viewOnly,
      });
      const securityHtml = security.length
        ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Remote controls</div>'
          + security.map((item) => this.menuItem(null, item.label, item.checked, undefined, item.id)).join('')
        : '';
      const canTerminal = this.terminalSupported && remoteInputAllowed(this.viewOnly, 'terminal');
      const canCamera = this.cameraSupported;
      const tools =
        canTerminal || canCamera
          ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Tools</div>' +
            (canTerminal ? this.menuItem(null, 'Remote terminal', false, 'terminal') : '') +
            (canCamera ? this.menuItem(null, 'View camera', false, 'camera') : '')
          : '';
      const canRemoteCursor = canUseRemoteCursor(this.peerPlatform, this.displays[this.current]);
      const codecHtml = this.codecSupport.map((codec) =>
        this.menuItem(null, codec === 'auto' ? 'Automatic codec' : codec.toUpperCase(), this.preferredCodec === codec, `codec:${codec}`),
      ).join('');
      const canAudio = !!this.audioPlayback && this.permissions.Audio !== false;
      const canClipboard = this.permissions.Clipboard !== false;
      const canSendClipboard = canClipboard && remoteInputAllowed(this.viewOnly, 'clipboard');
      const canRecord = this.permissions.Recording !== false && typeof MediaRecorder !== 'undefined';
      const volumePercent = Math.round(this.audioVolume * 100);
      const mediaHtml =
        canAudio || canClipboard || canRecord
          ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Session media</div>' +
            (canAudio
              ? this.menuItem(null, this.audioStarted ? 'Remote audio ready' : 'Start remote audio', this.audioStarted, 'audioResume') +
                this.menuItem(null, 'Receive remote audio', this.remoteAudioEnabled, 'audioToggle') +
                this.menuItem(null, 'Mute playback', this.audioMuted, 'audioMute') +
                `<label class="rd-media-volume"><span>Volume</span><input data-action="audioVolume" type="range" min="0" max="100" value="${volumePercent}"><output>${volumePercent}%</output></label>`
              : '') +
            (canClipboard
              ? this.menuItem(null, 'Text clipboard', this.clipboardEnabled, 'clipboardToggle') +
                (canSendClipboard ? this.menuItem(null, 'Sync local clipboard now', false, 'clipboardSync') : '')
              : '') +
            (canRecord
              ? this.menuItem(null, this.recording ? 'Stop local recording' : 'Start local recording', this.recording, 'recording')
              : '')
          : '';
      pop.innerHTML =
        this.menuItem('refresh', 'Refresh video', false, 'refresh') +
        this.menuItem(fs ? 'fullscreenExit' : 'fullscreen', fs ? 'Exit fullscreen' : 'Fullscreen', false, 'fullscreen') +
        '<div class="rd-pop-sep"></div><div class="rd-pop-title">Image quality</div>' +
        this.menuItem(null, 'Best', this.quality === QUALITY.best, 'quality:best') +
        this.menuItem(null, 'Balanced', this.quality === QUALITY.balanced, 'quality:balanced') +
        this.menuItem(null, 'Speed', this.quality === QUALITY.speed, 'quality:speed') +
        `<label class="rd-display-slider"><span>Custom quality</span><input data-action="customQuality" type="range" min="10" max="100" value="${this.customQuality}"><output>${this.customQuality}</output></label>` +
        '<div class="rd-pop-sep"></div><div class="rd-pop-title">Frame rate and codec</div>' +
        this.menuItem(null, 'Adaptive FPS', this.adaptiveFps, 'adaptiveFps') +
        `<label class="rd-display-slider"><span>${this.adaptiveFps ? 'Maximum FPS' : 'Custom FPS'}</span><input data-action="customFps" type="range" min="5" max="120" step="5" value="${this.customFps}"><output>${this.customFps}</output></label>` +
        codecHtml +
        (canRemoteCursor
          ? '<div class="rd-pop-sep"></div><div class="rd-pop-title">Remote cursor</div>' +
            this.menuItem(null, 'Show remote cursor', this.showRemoteCursor, 'showRemoteCursor') +
            this.menuItem(null, 'Follow remote cursor', this.followRemoteCursor, 'followRemoteCursor') +
            (this.displays.length > 1 ? this.menuItem(null, 'Follow remote window focus', this.followRemoteWindow, 'followRemoteWindow') : '') +
            this.menuItem(null, 'Enlarge remote cursor', this.cursorScale > 1, 'cursorScale')
          : '') +
        securityHtml +
        tools +
        mediaHtml;
      pop.querySelector<HTMLButtonElement>('[data-action="refresh"]')?.addEventListener('click', () => {
        this.post({ c: 'refresh' });
        this.closePop();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="fullscreen"]')?.addEventListener('click', () => {
        void this.toggleFullscreen();
        this.closePop();
      });
      const qualityValues: Record<string, number> = { best: QUALITY.best, balanced: QUALITY.balanced, speed: QUALITY.speed };
      for (const button of pop.querySelectorAll<HTMLButtonElement>('[data-action^="quality:"]')) {
        button.addEventListener('click', () => {
          const key = button.dataset.action?.slice('quality:'.length) ?? '';
          this.quality = qualityValues[key] ?? QUALITY.balanced;
          this.post({ c: 'quality', imageQuality: this.quality });
          this.closePop();
        });
      }
      const customQuality = pop.querySelector<HTMLInputElement>('[data-action="customQuality"]');
      customQuality?.addEventListener('input', () => {
        const output = customQuality.parentElement?.querySelector('output');
        if (output) output.textContent = customQuality.value;
      });
      customQuality?.addEventListener('change', () => {
        this.customQuality = Number(customQuality.value);
        this.post({ c: 'customQuality', quality: this.customQuality });
      });
      pop.querySelector<HTMLButtonElement>('[data-action="adaptiveFps"]')?.addEventListener('click', () => {
        this.adaptiveFps = !this.adaptiveFps;
        this.adaptiveFpsTarget = this.customFps;
        this.adaptiveStableSamples = 0;
        this.lastDroppedFrames = this.stats?.framesDropped ?? 0;
        this.post({ c: 'customFps', fps: this.adaptiveFpsTarget });
        this.toast(this.adaptiveFps ? 'Adaptive FPS enabled; bitrate remains host-managed' : 'Fixed FPS enabled');
        this.closePop();
      });
      const customFps = pop.querySelector<HTMLInputElement>('[data-action="customFps"]');
      customFps?.addEventListener('input', () => {
        const output = customFps.parentElement?.querySelector('output');
        if (output) output.textContent = customFps.value;
      });
      customFps?.addEventListener('change', () => {
        this.customFps = Number(customFps.value);
        this.adaptiveFpsTarget = this.customFps;
        this.adaptiveStableSamples = 0;
        this.post({ c: 'customFps', fps: this.adaptiveFpsTarget });
      });
      for (const button of pop.querySelectorAll<HTMLButtonElement>('[data-action^="codec:"]')) {
        button.addEventListener('click', () => {
          const codec = button.dataset.action?.slice('codec:'.length) ?? '';
          const prefer = codecPreferenceValue(codec);
          if (prefer === null || !this.codecSupport.includes(codec as never)) return;
          this.preferredCodec = codec;
          this.post({ c: 'preferredCodec', prefer });
          this.closePop();
        });
      }
      const toggleDisplayOption = (action: 'showRemoteCursor'|'followRemoteCursor'|'followRemoteWindow'): void => {
        const enabled = !this[action];
        this[action] = enabled;
        if (action === 'followRemoteCursor' && enabled && !this.showRemoteCursor) {
          this.showRemoteCursor = true;
          this.post({ c: 'displayOption', option: 'showRemoteCursor', enabled: true });
        }
        this.post({ c: 'displayOption', option: action, enabled });
        if (action === 'showRemoteCursor' && !enabled) this.el.remoteCursor.hidden = true;
        this.closePop();
      };
      for (const action of ['showRemoteCursor', 'followRemoteCursor', 'followRemoteWindow'] as const) {
        pop.querySelector<HTMLButtonElement>(`[data-action="${action}"]`)?.addEventListener('click', () => toggleDisplayOption(action));
      }
      pop.querySelector<HTMLButtonElement>('[data-action="cursorScale"]')?.addEventListener('click', () => {
        this.cursorScale = this.cursorScale > 1 ? 1 : 2;
        this.updateRemoteCursorTransform();
        this.closePop();
      });
      for (const button of pop.querySelectorAll<HTMLButtonElement>('[data-security]')) {
        button.addEventListener('click', () => this.activateSecurityControl(button.dataset.security!));
      }
      pop.querySelector<HTMLButtonElement>('[data-action="terminal"]')?.addEventListener('click', () => {
        this.closePop();
        this.openTerminalPanel();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="camera"]')?.addEventListener('click', () => {
        this.closePop();
        this.openCameraPanel();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="audioResume"]')?.addEventListener('click', () => {
        void this.resumeAudioFromUserGesture();
        this.closePop();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="audioToggle"]')?.addEventListener('click', () => {
        this.remoteAudioEnabled = !this.remoteAudioEnabled;
        this.post({ c: 'remoteAudio', enabled: this.remoteAudioEnabled });
        if (this.remoteAudioEnabled) void this.resumeAudioFromUserGesture();
        else this.audioPlayback?.reset();
        this.toast(this.remoteAudioEnabled ? 'Remote audio enabled' : 'Remote audio disabled');
        this.closePop();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="audioMute"]')?.addEventListener('click', () => {
        this.audioMuted = !this.audioMuted;
        this.audioPlayback?.setMuted(this.audioMuted);
        this.toast(this.audioMuted ? 'Remote audio muted' : 'Remote audio unmuted');
        this.closePop();
      });
      const volume = pop.querySelector<HTMLInputElement>('[data-action="audioVolume"]');
      volume?.addEventListener('input', () => {
        this.audioVolume = Number(volume.value) / 100;
        this.audioPlayback?.setVolume(this.audioVolume);
        const output = volume.parentElement?.querySelector('output');
        if (output) output.textContent = `${volume.value}%`;
      });
      pop.querySelector<HTMLButtonElement>('[data-action="clipboardToggle"]')?.addEventListener('click', () => {
        this.clipboardEnabled = !this.clipboardEnabled;
        this.post({ c: 'clipboardEnabled', enabled: this.clipboardEnabled });
        if (!this.clipboardEnabled) this.removeClipboardSyncOffer();
        this.toast(this.clipboardEnabled ? 'Text clipboard enabled' : 'Text clipboard disabled');
        this.closePop();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="clipboardSync"]')?.addEventListener('click', () => {
        this.removeClipboardSyncOffer();
        void this.sendClipboard();
        this.closePop();
      });
      pop.querySelector<HTMLButtonElement>('[data-action="recording"]')?.addEventListener('click', () => {
        void this.toggleRecording();
        this.closePop();
      });
    });
  }

  private activateSecurityControl(id: string): void {
    if (id === 'restart') {
      this.closePop();
      this.beginRemoteRestart();
      return;
    }
    if (id === 'elevation') {
      if (!window.confirm('Ask the remote Windows user to approve elevation?')) return;
      this.post({ c: 'requestElevation' });
      this.toast('Elevation requested — waiting for approval on the remote device', true);
      this.closePop();
      return;
    }
    if (id.startsWith('privacy:')) {
      if (this.privacyModePending) return;
      const implKey = id.slice('privacy:'.length);
      const on = !(this.privacyModeOn && this.activePrivacyImplKey === implKey);
      if (on && !window.confirm('Enable privacy mode and hide the remote screen from the local user?')) return;
      this.privacyModePending = true;
      this.activePrivacyImplKey = implKey;
      this.post({ c: 'privacyMode', implKey, on });
      this.toast(`${on ? 'Enabling' : 'Disabling'} privacy mode…`, true);
      this.closePop();
      return;
    }
    if (id === 'blockInput') {
      if (this.blockInputPending) return;
      const on = !this.blockInputOn;
      if (on && !window.confirm('Block the remote computer’s local keyboard and mouse?')) return;
      this.blockInputPending = true;
      this.post({ c: 'blockInput', on });
      this.toast(`${on ? 'Blocking' : 'Restoring'} remote keyboard and mouse…`, true);
      this.closePop();
      return;
    }
    if (id === 'lockScreen') {
      const sent = this.post(buildLockScreenKeyCommand());
      this.toast(sent ? 'Lock command sent' : 'Lock command not sent');
      this.closePop();
      return;
    }
    if (id === 'lockAfterSessionEnd') {
      this.lockAfterSessionEnd = !this.lockAfterSessionEnd;
      this.post({ c: 'lockAfterSessionEnd', on: this.lockAfterSessionEnd });
      this.toast(
        this.lockAfterSessionEnd
          ? 'Lock-after-disconnect request sent (best effort; the remote device does not acknowledge this setting)'
          : 'Lock-after-disconnect disable request sent (best effort; the remote device does not acknowledge this setting)',
      );
      this.closePop();
    }
  }

  private openFitPop(): void {
    this.openPop(this.el.btnFit, (pop) => {
      pop.innerHTML =
        this.menuItem(null, 'Fit to screen', this.fitMode === 'fit') +
        this.menuItem(null, 'Actual size', this.fitMode === 'actual');
      const items = pop.querySelectorAll<HTMLButtonElement>('.rd-mi');
      const set = (mode: FitMode, label: string): void => {
        this.fitMode = mode;
        this.el.viewport.dataset.fit = mode;
        q(this.el.toolbar, '#rd-fit-label').textContent = label;
        this.closePop();
      };
      items[0]?.addEventListener('click', () => set('fit', 'Fit to screen'));
      items[1]?.addEventListener('click', () => set('actual', 'Actual size'));
    });
  }

  private openKeysPop(anchor: HTMLElement): void {
    this.openPop(
      anchor,
      (pop) => {
        pop.innerHTML =
          '<div class="rd-pop-title">Send to remote</div>' +
          this.menuItem('keyboard', 'Ctrl+Alt+Del') +
          this.menuItem('keyboard', 'Windows key') +
          this.menuItem('keyboard', 'PrintScreen') +
          this.menuItem('keyboard', 'Escape') +
          this.menuItem('keyboard', 'Tab');
        const acts: [string, () => void][] = [
          ['Ctrl+Alt+Del sent', () => this.post({ c: 'ctrlAltDel' })],
          ['Windows key sent', () => this.pressControl(ControlKey.Meta)],
          ['PrintScreen sent', () => this.pressControl(ControlKey.Snapshot)],
          ['Escape sent', () => this.pressControl(ControlKey.Escape)],
          ['Tab sent', () => this.pressControl(ControlKey.Tab)],
        ];
        pop.querySelectorAll<HTMLButtonElement>('.rd-mi').forEach((b, i) => {
          b.addEventListener('click', () => {
            acts[i]![1]();
            this.toast(acts[i]![0]);
            this.closePop();
          });
        });
      },
      true,
    );
  }

  private openTypePop(anchor: HTMLElement): void {
    this.openPop(
      anchor,
      (pop) => {
        pop.classList.add('rd-pop-type');
        pop.innerHTML = `
          <div class="rd-pop-title">Type on the remote device</div>
          <textarea id="rd-type-text" rows="4" placeholder="Sent as keystrokes — works where the remote clipboard does not."></textarea>
          <div class="rd-pop-actions">
            <button type="button" class="rd-chip rd-chip-solid" id="rd-type-send">${iconHtml('send')}<span>Send keystrokes</span></button>
          </div>`;
        const ta = q<HTMLTextAreaElement>(pop, '#rd-type-text');
        setTimeout(() => ta.focus(), 0);
        q<HTMLButtonElement>(pop, '#rd-type-send').addEventListener('click', () => {
          const text = ta.value;
          if (!text) return;
          for (const cmd of buildTypeCommands(text)) this.post(cmd);
          this.toast(`Typed ${text.length} character${text.length === 1 ? '' : 's'}`);
          this.closePop();
        });
      },
      true,
    );
  }

  // --- connect overlay ------------------------------------------------------------

  private renderOverlay(): void {
    const svg = (paths: string, size = 20): string =>
      `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" ` +
      `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
    const lock = svg('<rect x="4.5" y="10.5" width="15" height="10.5" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>');
    const device = svg('<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>');
    const arrow = svg('<path d="M5 12h13M12 5.5 18.5 12 12 18.5"/>', 24);
    this.el.overlay.innerHTML = `
      <div class="rd-card">
        <div class="rd-brand">
          <img class="rd-logo" src="/rdclient/logo.png" alt="CortenDesk" width="60" height="60">
          <span class="rd-wordmark">Corten<span>Desk</span></span>
        </div>
        <div class="rd-tagline">Web-Based Client ${overlayVersion(this.cfg)}</div>
        <div class="rd-divider" aria-hidden="true"></div>
        <p class="rd-help">Enter the client's temporary or permanent password assigned in the RustDesk client.</p>
        <div class="rd-target" id="rd-target" hidden>
          <span class="rd-target-ic">${device}</span>
          <span class="rd-target-cap">Client ID</span>
          <span class="rd-target-id" id="rd-overlay-peer"></span>
        </div>
        <label class="rd-field" id="rd-field-id" hidden>
          <span class="rd-input">
            <span class="rd-input-ic">${device}</span>
            <input id="rd-peer-id" type="text" inputmode="numeric" autocomplete="off" spellcheck="false" placeholder="Device ID">
          </span>
        </label>
        <div class="rd-connect-row">
          <span class="rd-input">
            <span class="rd-input-ic">${lock}</span>
            <input id="rd-password" type="password" autocomplete="new-password" placeholder="Enter password">
          </span>
          <button type="button" class="rd-go" id="rd-connect" aria-label="Connect">${arrow}</button>
        </div>
        <label class="rd-save">
          <input type="checkbox" id="rd-save-pw">
          <span>Save password on this device</span>
        </label>
        <div class="rd-os-login" id="rd-os-login-area" hidden>
          <label class="rd-save rd-os-login-toggle">
            <input type="checkbox" id="rd-os-login" aria-controls="rd-os-login-fields rd-os-login-auth-fields" aria-expanded="false">
            <span>Automatically log into the remote OS</span>
          </label>
          <div id="rd-os-login-fields" hidden>
            <label class="rd-field rd-os-password-field">
              <span class="rd-input">
                <span class="rd-input-ic">${lock}</span>
                <input id="rd-os-password" type="password" autocomplete="off" maxlength="1024" aria-label="Remote OS password" aria-describedby="rd-os-login-note" placeholder="Remote OS password">
              </span>
            </label>
          </div>
          <div id="rd-os-login-auth-fields" hidden>
            <label class="rd-field rd-os-password-field">
              <span class="rd-input">
                <span class="rd-input-ic">${lock}</span>
                <input id="rd-os-current-password" type="password" autocomplete="current-password" maxlength="1024" aria-label="Current CortenDesk password" aria-describedby="rd-os-login-note" placeholder="Current CortenDesk password">
              </span>
            </label>
            <p class="rd-os-login-note" id="rd-os-login-note">The remote password is encrypted at rest, but anyone with database access and APP_KEY can decrypt it. Your local CortenDesk password is required before each use; SSO-only accounts cannot use stored OS passwords. Auto-login does not change the remote lock-after-session setting.</p>
          </div>
        </div>
        <div class="rd-msg" id="rd-msg">
          <div class="rd-overlay-status" id="rd-overlay-status" hidden>
            <span class="rd-spinner" aria-hidden="true"></span><span id="rd-overlay-status-text"></span>
          </div>
          <div class="rd-overlay-error" id="rd-overlay-error" role="alert" hidden></div>
        </div>
        <button type="button" class="rd-chip" id="rd-restart-cancel" hidden>Cancel reconnect</button>
      </div>`;
    const o = this.el.overlay;
    this.el.overlayPeer = q(o, '#rd-overlay-peer');
    this.el.overlayTarget = q(o, '#rd-target');
    this.el.fieldId = q(o, '#rd-field-id');
    this.el.peerIdInput = q(o, '#rd-peer-id');
    this.el.passwordInput = q(o, '#rd-password');
    this.el.saveCheckbox = q(o, '#rd-save-pw');
    this.el.osLoginArea = q(o, '#rd-os-login-area');
    this.el.osLoginCheckbox = q(o, '#rd-os-login');
    this.el.osLoginFields = q(o, '#rd-os-login-fields');
    this.el.osLoginAuthFields = q(o, '#rd-os-login-auth-fields');
    this.el.osPasswordInput = q(o, '#rd-os-password');
    this.el.osCurrentPasswordInput = q(o, '#rd-os-current-password');
    this.el.connectBtn = q(o, '#rd-connect');
    this.el.overlayStatus = q(o, '#rd-overlay-status');
    this.el.overlayStatusText = q(o, '#rd-overlay-status-text');
    this.el.overlayError = q(o, '#rd-overlay-error');
    this.el.reconnectCancel = q(o, '#rd-restart-cancel');

    this.el.connectBtn.addEventListener('click', () => { void this.onConnectClick(); });
    this.el.reconnectCancel.addEventListener('click', () => this.cancelRestartReconnect());
    this.el.osLoginCheckbox.addEventListener('change', () => {
      this.updateOsLoginFields();
      if (this.el.osLoginCheckbox.checked && !this.osLoginHasSavedPassword) {
        this.el.osPasswordInput.focus();
      } else {
        this.el.osCurrentPasswordInput.focus();
      }
    });
    const enter = (e: KeyboardEvent): void => {
      if (e.key === 'Enter') void this.onConnectClick();
    };
    this.el.passwordInput.addEventListener('keydown', enter);
    this.el.osPasswordInput.addEventListener('keydown', enter);
    this.el.osCurrentPasswordInput.addEventListener('keydown', enter);
    this.el.peerIdInput.addEventListener('keydown', enter);
  }

  // --- session lifecycle -----------------------------------------------------

  // Reflect any stored credential for this device: tick the box and show a saved
  // placeholder instead of an empty field. A real value never leaves storage.
  private hydrateSavedPassword(peerId: string): void {
    const has = !!peerId && loadSavedHash(peerId) !== null;
    this.el.saveCheckbox.checked = has;
    this.el.passwordInput.value = '';
    this.el.passwordInput.placeholder = has ? 'Saved password — click to change' : 'Enter password';
  }

  private updateOsLoginFields(): void {
    const enabled = this.el.osLoginCheckbox.checked;
    this.el.osLoginFields.hidden = !enabled;
    this.el.osLoginAuthFields.hidden = !enabled && !this.osLoginHasSavedPassword;
    this.el.osLoginCheckbox.setAttribute('aria-expanded', String(enabled));
  }

  private async hydrateOsLogin(peerId: string): Promise<void> {
    this.osLoginHydratedPeer = '';
    this.osLoginHasSavedPassword = false;
    this.el.osLoginCheckbox.checked = false;
    this.el.osLoginCheckbox.disabled = !peerId || !this.osLoginFlow;
    this.el.osPasswordInput.value = '';
    this.el.osCurrentPasswordInput.value = '';
    this.el.osPasswordInput.placeholder = 'Remote OS password';
    this.updateOsLoginFields();
    if (!peerId || !this.osLoginFlow) return;

    try {
      const view = await this.osLoginFlow.hydrate(peerId);
      const currentPeer = normalizePeerId(this.el.peerIdInput.value || this.fixedPeerId);
      if (!view || currentPeer !== peerId) return;
      this.osLoginHydratedPeer = peerId;
      this.osLoginHasSavedPassword = view.hasSavedPassword;
      this.el.osLoginCheckbox.checked = view.enabled;
      this.el.osPasswordInput.placeholder = view.hasSavedPassword
        ? 'Saved OS password — enter to replace'
        : 'Remote OS password';
      this.updateOsLoginFields();
    } catch {
      // Optional setting failure must not expose response details or block a
      // normal remote-control connection. Enabling it still retries on Connect.
    } finally {
      const currentPeer = normalizePeerId(this.el.peerIdInput.value || this.fixedPeerId);
      if (currentPeer === peerId) this.el.osLoginCheckbox.disabled = false;
    }
  }

  /**
   * Why the browser will refuse to run this page's session, or null if it won't.
   *
   * Two things used to make an insecure origin fatal: `crypto.subtle` for the
   * login hash, and WebCodecs' `VideoDecoder` for video. The hash no longer
   * uses Web Crypto (see core/sha256.ts), and video falls back to Media Source
   * Extensions, which is not secure-context gated. So plain http:// now works
   * where MSE can play H.264 — degraded, and the operator is told so.
   *
   * What remains fatal is an origin with neither WebCodecs nor MSE H.264: there
   * is no third way to show the remote screen. Saying so plainly beats the old
   * failure, `Cannot read properties of undefined (reading 'digest')`, which
   * sent operators hunting through their config (#3).
   */
  private secureContextProblem(): string | null {
    if (typeof VideoDecoder !== 'undefined') return null; // full-quality path
    if (mseH264Available()) return null; // degraded path — see fallbackNotice()

    if (typeof isSecureContext !== 'undefined' && !isSecureContext) {
      return 'This page is served over plain HTTP and this browser cannot fall '
        + 'back to Media Source playback. Serve the console over HTTPS, or open '
        + 'it as http://localhost, which browsers treat as secure.';
    }
    return 'This browser has no WebCodecs video decoder and no Media Source '
      + 'support for H.264. Chrome or Edge is required for the remote screen.';
  }

  private consumePasswordInput(): string {
    const password = this.el.passwordInput.value;
    this.el.passwordInput.value = '';
    return password;
  }

  private async onConnectClick(): Promise<void> {
    if (this.worker && this.state !== 'error' && this.state !== 'closed') return;
    const peerId = normalizePeerId(this.el.peerIdInput.value || this.fixedPeerId);
    if (!peerId) {
      this.setOverlayError('Enter a device ID');
      return;
    }
    if (!this.cfg) {
      this.setOverlayError('Missing window.__RD__ configuration');
      return;
    }
    const blocked = this.secureContextProblem();
    if (blocked) {
      this.setOverlayError(blocked);
      return;
    }
    this.peerId = peerId;
    this.setOverlayError(null);
    this.setLoggedOutFlag(false); // connecting again clears the logout marker

    let osPassword: string | undefined;
    if (this.osLoginFlow) {
      this.setOverlayBusy(true);
      this.setOverlayStatusText('Preparing secure OS auto-login settings…');
      if (this.osLoginHydratedPeer !== peerId) {
        this.osLoginHydration = this.hydrateOsLogin(peerId);
      }
      await this.osLoginHydration;
      const typedOsPassword = this.el.osPasswordInput.value;
      const currentPassword = this.el.osCurrentPasswordInput.value;
      try {
        osPassword = await this.osLoginFlow.prepare(
          peerId,
          this.el.osLoginCheckbox.checked,
          typedOsPassword,
          currentPassword,
        );
        this.osLoginHasSavedPassword = this.el.osLoginCheckbox.checked;
        if (osPassword) this.osLoginHydratedPeer = '';
        this.el.osPasswordInput.value = '';
        this.el.osCurrentPasswordInput.value = '';
      } catch {
        this.el.osPasswordInput.value = '';
        this.el.osCurrentPasswordInput.value = '';
        this.setOverlayBusy(false);
        this.setOverlayError('Could not prepare OS auto-login. Check both passwords and try again.');
        return;
      }
    }

    const typed = this.consumePasswordInput();
    const saved = loadSavedHash(peerId);
    // If the user left the field blank and we have a stored hash, reuse it.
    // If the "save" box is off, forget any stored credential for this device.
    if (!this.el.saveCheckbox.checked) clearSavedHash(peerId);
    this.pendingHashHex = undefined;
    this.connectedWithSavedHash = false;

    let config: SessionConfig;
    if (!typed && saved) {
      this.connectedWithSavedHash = true;
      config = buildSessionConfig(this.cfg, peerId, '', saved, undefined, osPassword);
    } else {
      config = buildSessionConfig(this.cfg, peerId, typed, undefined, undefined, osPassword);
    }
    this.startSession(config);
  }

  private beginRemoteRestart(): void {
    if (this.state !== 'streaming' || !this.reconnectConfig) {
      this.toast('Restart is unavailable until the session is connected');
      return;
    }
    if (!window.confirm('Restart the remote device and reconnect automatically?')) return;

    const config: SessionConfig = {
      ...this.reconnectConfig,
      password: '',
      savedHashHex: this.sessionHashHex ?? this.reconnectConfig.savedHashHex,
    };
    this.clearRestartFlow();
    this.el.reconnectCancel.hidden = false;
    this.post({ c: 'restartRemoteDevice' });
    const flow: RestartFlow = { startedAt: Date.now(), attempt: 0, config, reconnecting: false };
    flow.deadlineTimer = setTimeout(() => this.expireRestartFlow(flow), RESTART_RECONNECT_TIMEOUT_MS);
    this.restartFlow = flow;
    this.toast('Restart command sent — waiting for the remote device', true);
  }

  private scheduleRestartReconnect(detail?: string): void {
    const flow = this.restartFlow;
    if (!flow || flow.timer) return;
    clearTimeout(flow.stableTimer);
    flow.stableTimer = undefined;
    this.teardown();
    this.clearSecurityState();
    this.filePanel?.destroy();
    this.filePanel = undefined;
    this.closeSide();
    this.closePop();

    const elapsed = Date.now() - flow.startedAt;
    const delay = nextRestartReconnectDelay(flow.attempt, elapsed);
    if (delay === null) {
      this.clearRestartFlow();
      this.setState('error', `The remote device did not return after restarting${detail ? ` — ${detail}` : ''}`);
      return;
    }

    flow.attempt += 1;
    this.state = 'connecting';
    this.el.root.dataset.state = 'connecting';
    this.showOverlay();
    this.setOverlayBusy(true);
    this.el.reconnectCancel.hidden = false;
    this.setOverlayStatusText(`Waiting for restarted device — retry ${flow.attempt} in ${Math.ceil(delay / 1000)}s`);
    flow.timer = setTimeout(() => {
      if (this.restartFlow !== flow) return;
      flow.timer = undefined;
      if (Date.now() - flow.startedAt >= RESTART_RECONNECT_TIMEOUT_MS) {
        this.expireRestartFlow(flow);
        return;
      }
      flow.reconnecting = true;
      this.startSession({ ...flow.config, password: '' });
    }, delay);
  }

  private cancelRestartReconnect(): void {
    const wasWaiting = this.state !== 'streaming';
    if (!this.restartFlow) return;
    this.clearRestartFlow();
    if (wasWaiting) this.setState('closed');
    this.toast('Automatic reconnect cancelled');
  }

  private expireRestartFlow(flow: RestartFlow): void {
    if (this.restartFlow !== flow) return;
    const sessionStillStreaming = this.state === 'streaming';
    const reconnecting = flow.reconnecting;
    this.clearRestartFlow();
    if (reconnecting) {
      this.teardown(true);
      this.clearSecurityState();
      this.setState('error', 'The remote device did not return within 120 seconds after the restart request');
    } else if (sessionStillStreaming) {
      this.toast('No remote restart was detected within 120 seconds');
    } else {
      this.setState('error', 'The remote device did not return within 120 seconds after the restart request');
    }
  }

  private clearRestartFlow(): void {
    if (this.restartFlow) {
      clearTimeout(this.restartFlow.timer);
      clearTimeout(this.restartFlow.stableTimer);
      clearTimeout(this.restartFlow.deadlineTimer);
    }
    this.restartFlow = undefined;
    if (this.el?.reconnectCancel) this.el.reconnectCancel.hidden = true;
  }

  private startSession(config: SessionConfig): void {
    this.teardown();
    // Retain only the challenge-independent password hash for reconnects. Plaintext
    // connection and OS-login passwords are transferred to the worker and never
    // kept in UI reconnect state.
    this.reconnectConfig = { ...config, password: '', osPassword: undefined };
    // A fresh connection may be a different peer/credential — retire the panel
    // and everything else that belonged to the previous session.
    this.filePanel?.destroy();
    this.filePanel = undefined;
    this.closeSide();
    this.closePop();
    this.chatLog = [];
    this.chatUnread = 0;
    this.renderChatBadges();
    this.viewOnly = false;
    this.el.btnViewOnly.classList.remove('rd-on');
    this.el.btnViewOnly.setAttribute('aria-pressed', 'false');
    this.setLatches(false, false);
    this.clearSecurityState();
    this.peerWho = '';
    this.peerPlatform = '';
    this.terminalSupported = false;
    this.cameraSupported = false;
    this.platformAdditions = '';
    this.codecSupport = ['auto'];
    this.preferredCodec = 'auto';
    this.showRemoteCursor = false;
    this.followRemoteCursor = false;
    this.followRemoteWindow = false;
    this.adaptiveFps = false;
    this.adaptiveFpsTarget = this.customFps;
    this.adaptiveStableSamples = 0;
    this.lastDroppedFrames = 0;
    this.el.remoteCursor.hidden = true;
    this.sessionHashHex = config.savedHashHex;
    this.stats = undefined;
    this.streamStartMs = 0;
    this.displays = [];
    this.current = 0;
    this.remoteAudioEnabled = true;
    this.audioStarted = false;
    this.audioMuted = false;
    this.audioVolume = 1;
    this.clipboardEnabled = true;
    this.removeClipboardSyncOffer();
    this.createAudioPlayback();
    const canvas = this.freshCanvas();
    const offscreen = canvas.transferControlToOffscreen();
    const worker = new Worker(this.workerUrl, { type: 'module' });
    this.worker = worker;
    worker.onmessage = (e: MessageEvent<UiWorkerEvent>) => this.onEvent(e.data);
    worker.onerror = (e: ErrorEvent) => this.setState('error', e.message || 'session worker failed');
    const cmd: Extract<UiCommand, { c: 'connect' }> = { c: 'connect', config, canvas: offscreen };
    postConnectWithSecretScrub(worker, cmd, [offscreen]);
    this.detach = attachInput(canvas, (c) => this.post(c), () => this.currentRect(), {
      isTouchMode: () => this.inputMode === 'touch',
    });
    this.el.peerLabel.textContent = this.peerId;
    this.el.statDevice.textContent = this.peerId;
    this.resetPermissions();
    this.setState('connecting');
  }

  // A canvas can be transferred to OffscreenCanvas only once; reconnects need a fresh node.
  private freshCanvas(): HTMLCanvasElement {
    if (this.canvasTransferred) {
      const fresh = this.canvas.cloneNode(false) as HTMLCanvasElement;
      this.canvas.replaceWith(fresh);
      this.canvas = fresh;
    }
    this.canvasTransferred = true;
    return this.canvas;
  }

  private teardown(immediateWorkerTermination = false): void {
    this.sessionEpoch += 1;
    this.endVoiceCall(false);
    this.releaseRemoteSecurityState();
    if (this.recording && this.permissions.Recording !== false) {
      this.post({ c: 'clientRecording', recording: false });
    }
    this.recording = false;
    this.recordingStartedMs = 0;
    this.el.recordingIndicator.hidden = true;
    this.recorder?.close();
    this.recorder = undefined;
    this.audioPlayback?.close();
    this.audioPlayback = undefined;
    this.removeClipboardSyncOffer();
    this.detach?.();
    this.detach = undefined;
    this.teardownMse();
    const w = this.worker;
    this.worker = undefined;
    if (w) {
      w.onmessage = null;
      w.onerror = null;
      if (immediateWorkerTermination) w.terminate();
      else setTimeout(() => w.terminate(), 250); // let a pending 'disconnect' flush first
    }
    this.resetPermissions();
  }

  /**
   * Single choke point to the worker. View-only swallows everything that would
   * act on the remote device. The Ctrl/Alt latches merge into the modifiers of
   * key and mouse traffic — a pure merge, no synthetic key down/up, so a
   * dropped session can never leave a modifier stuck on the peer.
   */
  /**
   * Diagnostic log, off unless ?debug=1 or window.__rdDebug is set.
   *
   * Read live rather than cached at mount so the flag can be flipped mid
   * session from the console — the interesting failures only exist once a
   * session is running, and a reload throws them away.
   */
  private dbg(tag: string, data: unknown): void {
    if (!debugEnabled(location.search, window)) return;
    console.log(`[rd:${tag}]`, data);
  }

  /** Throttle for the per-event input log, which would otherwise flood. */
  private lastDbgMouseMs = 0;

  /** Returns true when the command reached the worker. */
  private post(cmd: UiCommand): boolean {
    // Lock screen is a one-shot control key: the peer handles it whether or
    // not a display is online, and latched Ctrl/Alt must not ride along.
    const lockScreen = cmd.c === 'key' && cmd.keyKind === 'control' && cmd.value === ControlKey.LockScreen;
    const inputChannel: RemoteInputChannel | null = cmd.c === 'mouse'
      ? 'pointer'
      : cmd.c === 'key' || cmd.c === 'ctrlAltDel'
        ? 'keyboard'
        : cmd.c === 'clipboardText'
          ? 'clipboard'
          : null;
    const sendsRemoteInput = cmd.c === 'mouse' || cmd.c === 'key' || cmd.c === 'ctrlAltDel';
    if (sendsRemoteInput && !lockScreen && this.displays[this.current]?.online !== true) return false;
    if (cmd.c === 'mouse') {
      const now = Date.now();
      if (now - this.lastDbgMouseMs > 1000) {
        this.lastDbgMouseMs = now;
        // The whole question in one line: the coordinate actually leaving the
        // client, the display index it was mapped against, and that display's
        // origin. If x/y sit inside display 0 while current is 1, the mapping
        // is using the wrong rect; if current is still 0, the switch never
        // reached us.
        this.dbg('mouse', {
          sent: { x: cmd.x, y: cmd.y },
          current: this.current,
          rect: this.currentRect(),
          displays: this.displays.map((d) => ({ x: d.x, y: d.y, w: d.width, h: d.height })),
        });
      }
    }
    if (inputChannel && !remoteInputAllowed(this.viewOnly, inputChannel)) return false;
    if ((this.latchCtrl || this.latchAlt) && !lockScreen && (cmd.c === 'mouse' || cmd.c === 'key')) {
      const extra: number[] = [];
      if (this.latchCtrl) extra.push(ControlKey.Control);
      if (this.latchAlt) extra.push(ControlKey.Alt);
      cmd = { ...cmd, modifiers: [...new Set([...cmd.modifiers, ...extra])] };
    }
    if (!this.worker) return false;
    this.worker.postMessage(cmd);
    return true;
  }

  private currentRect(): DisplayRect {
    const d = this.displays[this.current];
    if (d) return displayToRect(d);
    if (this.stats?.width && this.stats.height) {
      return { x: 0, y: 0, width: this.stats.width, height: this.stats.height };
    }
    return { x: 0, y: 0, width: 1280, height: 720 };
  }

  // --- worker events ---------------------------------------------------------

  private onEvent(ev: UiWorkerEvent): void {
    switch (ev.t) {
      case 'state':
        if (this.restartFlow && ev.peerInitiated) {
          this.clearRestartFlow();
          this.setState(ev.state, ev.detail);
        } else if (this.restartFlow && (ev.state === 'error' || ev.state === 'closed')) {
          this.scheduleRestartReconnect(ev.detail);
        } else {
          this.setState(ev.state, ev.detail);
        }
        break;
      case 'peerInfo': {
        this.dbg('peerInfo', { current: ev.current, displays: ev.displays });
        this.displays = ev.current === undefined
          ? mergeDisplayRefresh(this.displays, ev.displays, this.current)
          : ev.displays;
        if (ev.current !== undefined) this.current = ev.current;
        this.peerWho = ev.username ? `${ev.username}@${ev.hostname}` : ev.hostname;
        this.peerPlatform = ev.platform || '';
        this.privacyModeSupported = ev.privacyModeSupported;
        this.privacyModeImpls = ev.privacyModeImpls;
        this.terminalSupported = ev.terminalSupported;
        this.cameraSupported = ev.viewCameraSupported;
        this.platformAdditions = mergePlatformAdditions(this.platformAdditions, ev.platformAdditions);
        if (!canUseRemoteCursor(this.peerPlatform, this.displays[this.current])) {
          this.el.remoteCursor.hidden = true;
        }
        this.el.peerLabel.textContent = this.peerWho || this.peerId;
        this.refreshPeerSub();
        this.el.statVersion.textContent = ev.version || '—';
        this.el.statUser.textContent = this.peerWho || '—';
        this.el.statPlatform.textContent = this.peerPlatform || '—';
        const currentDisplay = this.displays[this.current];
        const hasDisplayControls = !!currentDisplay?.resolutions.length || !!currentDisplay?.originalResolution ||
          !!parseVirtualDisplayCapability(this.peerPlatform, this.platformAdditions);
        this.el.btnMonitors.hidden = this.displays.length < 2 && !hasDisplayControls;
        document.title = `${this.peerId} — CortenDesk`;
        break;
      }
      case 'switchDisplay': {
        // Authoritative: the host telling us what it is now capturing. Trust
        // its geometry over the PeerInfo snapshot, which can be stale by the
        // time a switch happens (resolution changed, monitor re-arranged, a
        // display that was offline at login).
        this.dbg('switchDisplay', ev);
        this.current = ev.index;
        applySwitchDisplay(this.displays, ev);
        if (ev.cursorEmbedded) this.el.remoteCursor.hidden = true;
        // On the MSE fallback the muxer is built around the stream it was
        // started with, so a new frame size has to start a new one. The worker
        // holds the forwarded stream until the next key frame, which is what
        // rebuilds this on the following push.
        this.teardownMse();
        this.refreshPeerSub();
        break;
      }
      case 'followDisplay':
        if ((this.followRemoteWindow || this.followRemoteCursor) && ev.index !== this.current && this.displays[ev.index]?.online) {
          this.post({ c: 'switchDisplay', index: ev.index });
        }
        break;
      case 'codecSupport':
        this.codecSupport = ev.codecs;
        if (!this.codecSupport.includes(this.preferredCodec as never)) this.preferredCodec = 'auto';
        break;
      case 'stats':
        this.onStats(ev.stats);
        break;
      case 'audioPcm':
        if (this.remoteAudioEnabled && this.permissions.Audio !== false) {
          this.audioPlayback?.enqueue(ev.pcm, ev.sampleRate, ev.channels);
        }
        break;
      case 'cursor': {
        const css = cursorCss(ev.pngDataUrl, ev.hotx, ev.hoty);
        this.canvas.style.cursor = css;
        this.videoEl.style.cursor = css;
        this.el.remoteCursor.src = ev.pngDataUrl;
        this.remoteCursorHot = { x: ev.hotx, y: ev.hoty };
        this.updateRemoteCursorTransform();
        break;
      }
      case 'cursorPos':
        this.positionRemoteCursor(ev.x, ev.y);
        break;
      case 'clipboard': {
        if (!this.clipboardEnabled || this.permissions.Clipboard === false) break;
        const sessionEpoch = this.sessionEpoch;
        // A successful sync is silent, like the native client; only the
        // fallback needs the user's attention.
        void navigator.clipboard
          ?.writeText(ev.text)
          .catch(() => {
            if (sessionEpoch === this.sessionEpoch) {
              this.toast('Remote clipboard received (press Ctrl+V on this page to sync)');
            }
          });
        break;
      }
      case 'chat':
        this.onChat(ev.text);
        break;
      case 'voiceCall':
        this.onVoiceCall(ev.state, ev.detail);
        break;
      case 'h264':
        this.pushMseFrame(ev.data, ev.key);
        break;
      case 'permission':
        this.applyPermission(ev.kind, ev.enabled);
        break;
      case 'privacyMode':
        this.applyPrivacyModeState(ev.state, ev.details, ev.implKey);
        break;
      case 'blockInput':
        this.applyBlockInputState(ev.state, ev.details);
        break;
      case 'elevation':
        if (ev.state === 'succeeded') this.toast('Elevation approved');
        else if (ev.state === 'failed') this.toast(`Elevation failed${ev.detail ? `: ${ev.detail}` : ''}`);
        else this.toast('Elevation approved — waiting for the elevated service', true);
        break;
      case 'credentials':
        this.pendingHashHex = ev.hashHex; // persisted only once the session streams
        this.sessionHashHex = ev.hashHex; // in-memory: reused by the file panel
        if (this.reconnectConfig) this.reconnectConfig.savedHashHex = ev.hashHex;
        break;
      case 'uac':
        if (ev.on) {
          this.toast('UAC prompt is open on the remote screen — approve or cancel it there', true);
        } else {
          this.hideToast();
          this.toast('Remote UAC dialog closed');
        }
        break;
      case 'msgbox': {
        // "wait-*" types (e.g. wait-uac) stay up until the situation resolves;
        // everything else is a normal transient notification.
        const text = ev.text || ev.title;
        if (!text) break;
        this.toast(ev.title && ev.text ? `${ev.title}: ${ev.text}` : text, /^wait/i.test(ev.msgtype));
        break;
      }
      case 'loginError':
        this.clearRestartFlow();
        this.teardown();
        if (this.connectedWithSavedHash) {
          // The stored credential no longer works (password changed) — drop it.
          clearSavedHash(this.peerId);
          this.el.saveCheckbox.checked = false;
          this.el.passwordInput.placeholder = 'Enter password';
          this.connectedWithSavedHash = false;
        }
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayError(ev.message || 'Login failed');
        this.el.passwordInput.focus();
        this.el.passwordInput.select();
        break;
    }
  }

  private persistCredentialIfWanted(): void {
    if (!this.el.saveCheckbox.checked) {
      clearSavedHash(this.peerId);
      return;
    }
    // A freshly-typed password emits a new hash; a reused saved hash keeps the stored one.
    if (this.pendingHashHex) saveSavedHash(this.peerId, this.pendingHashHex);
  }

  /** The line under the peer name: state while in flight, identity once live. */
  private refreshPeerSub(): void {
    if (this.state === 'streaming') {
      const bits = ['Online'];
      if (this.peerPlatform) bits.push(this.peerPlatform);
      this.el.peerSub.textContent = bits.join(' · ');
    } else {
      this.el.peerSub.textContent = STATE_LABEL[this.state];
    }
  }

  private setState(state: SessionState, detail?: string): void {
    this.state = state;
    this.renderVoiceCall();
    this.el.root.dataset.state = state;
    this.refreshPeerSub();
    switch (state) {
      case 'streaming':
        if (!this.streamStartMs) this.streamStartMs = Date.now();
        this.persistCredentialIfWanted();
        this.hideOverlay();
        this.canvas.focus();
        if (this.restartFlow) {
          const flow = this.restartFlow;
          this.el.reconnectCancel.hidden = true;
          clearTimeout(flow.stableTimer);
          this.toast('Connection restored — checking stability after the restart request', true);
          flow.stableTimer = setTimeout(() => {
            if (this.restartFlow !== flow || this.state !== 'streaming') return;
            this.clearRestartFlow();
            this.toast('Connection restored after the restart request');
          }, 15_000);
        }
        this.showClipboardSyncOffer();
        break;
      case 'error':
        this.teardown();
        this.clearSecurityState();
        this.destroyAdvancedPanels();
        this.filePanel?.destroy();
        this.filePanel = undefined;
        this.closeSide();
        this.closePop();
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayError(detail || 'Connection failed');
        break;
      case 'closed':
        this.teardown();
        this.clearSecurityState();
        this.destroyAdvancedPanels();
        this.filePanel?.destroy();
        this.filePanel = undefined;
        this.closeSide();
        this.closePop();
        this.showOverlay();
        this.setOverlayBusy(false);
        this.setOverlayStatusText('Disconnected');
        break;
      default:
        this.showOverlay();
        this.setOverlayBusy(true);
        this.setOverlayStatusText(STATE_LABEL[state] + (detail ? ` — ${detail}` : '') + '…');
    }
  }

  private onStats(s: SessionStats): void {
    this.stats = s;
    this.el.statCodec.textContent = s.codec || '—';
    this.el.statRes.textContent = s.width && s.height ? `${s.width}×${s.height}` : '—';
    this.el.statFps.textContent = String(Math.round(s.fps));
    this.el.statBitrate.textContent = formatMbps(s.mbps);
    this.el.statDropped.textContent = String(s.framesDropped);
    if (this.adaptiveFps) {
      const next = adaptFps({
        target: this.adaptiveFpsTarget,
        droppedDelta: Math.max(0, s.framesDropped - this.lastDroppedFrames),
        stableSamples: this.adaptiveStableSamples,
        cap: this.customFps,
      });
      this.adaptiveStableSamples = next.stableSamples;
      if (next.target !== this.adaptiveFpsTarget) {
        this.adaptiveFpsTarget = next.target;
        this.post({ c: 'customFps', fps: this.adaptiveFpsTarget });
      }
      this.lastDroppedFrames = s.framesDropped;
    }
  }

  private positionRemoteCursor(x: number, y: number): void {
    const display = this.displays[this.current];
    if (!this.showRemoteCursor || !display || !canUseRemoteCursor(this.peerPlatform, display)) {
      this.el.remoteCursor.hidden = true;
      return;
    }
    const surface = this.videoEl.hidden ? this.canvas : this.videoEl;
    const canvasRect = surface.getBoundingClientRect();
    const viewportRect = this.el.viewport.getBoundingClientRect();
    const point = mapRemoteCursorToCanvas(
      { x, y },
      display,
      {
        left: canvasRect.left - viewportRect.left,
        top: canvasRect.top - viewportRect.top,
        width: canvasRect.width,
        height: canvasRect.height,
      },
    );
    if (!point) {
      this.el.remoteCursor.hidden = true;
      return;
    }
    this.el.remoteCursor.style.left = `${point.x}px`;
    this.el.remoteCursor.style.top = `${point.y}px`;
    this.el.remoteCursor.hidden = false;
    this.updateRemoteCursorTransform();
  }

  private updateRemoteCursorTransform(): void {
    const x = -this.remoteCursorHot.x * this.cursorScale;
    const y = -this.remoteCursorHot.y * this.cursorScale;
    this.el.remoteCursor.style.transform = `translate(${x}px, ${y}px) scale(${this.cursorScale})`;
  }

  private applyPrivacyModeState(state: number, details: string, implKey: string): void {
    this.privacyModePending = false;
    let failed = false;
    switch (state) {
      case BackNotification_PrivacyModeState.PrvOnByOther:
      case BackNotification_PrivacyModeState.PrvOnSucceeded:
        this.privacyModeOn = true;
        this.activePrivacyImplKey = implKey || this.activePrivacyImplKey;
        break;
      case BackNotification_PrivacyModeState.PrvOffSucceeded:
      case BackNotification_PrivacyModeState.PrvOffByPeer:
        this.privacyModeOn = false;
        this.activePrivacyImplKey = '';
        break;
      case BackNotification_PrivacyModeState.PrvNotSupported:
        this.privacyModeOn = false;
        this.privacyModeSupported = false;
        failed = true;
        break;
      case BackNotification_PrivacyModeState.PrvOnFailedDenied:
      case BackNotification_PrivacyModeState.PrvOnFailedPlugin:
      case BackNotification_PrivacyModeState.PrvOnFailed:
        this.privacyModeOn = false;
        failed = true;
        break;
      case BackNotification_PrivacyModeState.PrvOffFailed:
        this.privacyModeOn = true;
        failed = true;
        break;
      default:
        failed = true;
    }
    if (failed) this.toast(`Privacy mode failed${details ? `: ${details}` : ''}`);
    else this.refreshSecurityToast();
  }

  private applyBlockInputState(state: number, details: string): void {
    this.blockInputPending = false;
    let failed = false;
    switch (state) {
      case BackNotification_BlockInputState.BlkOnSucceeded:
        this.blockInputOn = true;
        break;
      case BackNotification_BlockInputState.BlkOffSucceeded:
        this.blockInputOn = false;
        break;
      case BackNotification_BlockInputState.BlkOnFailed:
        this.blockInputOn = false;
        failed = true;
        break;
      case BackNotification_BlockInputState.BlkOffFailed:
        this.blockInputOn = true;
        failed = true;
        break;
      default:
        failed = true;
    }
    if (failed) this.toast(`Remote input control failed${details ? `: ${details}` : ''}`);
    else this.refreshSecurityToast();
  }

  private refreshSecurityToast(): void {
    if (this.blockInputOn) this.toast('Remote keyboard and mouse are blocked', true);
    else if (this.privacyModeOn) this.toast('Privacy mode is active', true);
    else this.hideToast();
  }

  private releaseRemoteSecurityState(): void {
    // A disconnect can race the peer's acknowledgement of an enable request.
    // Compensate for both confirmed and pending intrusive state before closing.
    if (this.blockInputOn || this.blockInputPending) this.post({ c: 'blockInput', on: false });
    if ((this.privacyModeOn || this.privacyModePending) && this.activePrivacyImplKey) {
      this.post({ c: 'privacyMode', implKey: this.activePrivacyImplKey, on: false });
    }
    this.blockInputOn = false;
    this.blockInputPending = false;
    this.privacyModeOn = false;
    this.privacyModePending = false;
  }

  private clearSecurityState(): void {
    this.privacyModeSupported = false;
    this.privacyModeImpls = [];
    this.privacyModeOn = false;
    this.privacyModePending = false;
    this.activePrivacyImplKey = '';
    this.blockInputOn = false;
    this.blockInputPending = false;
    this.lockAfterSessionEnd = false;
    this.hideToast();
  }

  // --- permissions / misc ------------------------------------------------------

  // Keep ?lo=1 in the URL in sync with "the user logged out on purpose".
  private setLoggedOutFlag(on: boolean): void {
    try {
      const url = new URL(location.href);
      if (on) url.searchParams.set('lo', '1');
      else if (url.searchParams.has('lo')) url.searchParams.delete('lo');
      else return;
      history.replaceState(null, '', url);
    } catch {
      /* history unavailable (odd embed) — auto-login still gated per page load */
    }
  }

  /**
   * Apply a peer-advertised permission to the chrome.
   *
   * The peer is the only thing that ENFORCES these — a server policy (a
   * CortenDesk strategy, or the user's own settings) is applied on the
   * controlled machine, and it will refuse the capability whatever this client
   * does. Gating here is purely so the operator sees a disabled control instead
   * of clicking one that silently fails.
   *
   * Permissions are assumed granted until the peer says otherwise: it reports
   * the restricted ones after login, and anything it never mentions is allowed.
   */
  private applyPermission(kind: string, enabled: boolean): void {
    this.permissions[kind] = enabled;
    if (kind === 'Restart' && !enabled && this.restartFlow) {
      const sessionStillStreaming = this.state === 'streaming';
      this.clearRestartFlow();
      if (sessionStillStreaming) this.toast('Restart permission was denied by the peer');
      else this.setState('error', 'Automatic reconnect stopped because restart permission was denied');
    }

    if (kind === 'Audio') {
      if (!enabled) {
        this.audioPlayback?.reset();
        if (this.voiceCallState !== 'idle') this.endVoiceCall(true);
      }
      this.renderVoiceCall();
    }
    if (kind === 'Clipboard' && !enabled) this.removeClipboardSyncOffer();
    if (kind === 'Recording' && !enabled && this.recording) this.recorder?.stop();

    const target = PERMISSION_CONTROLS[kind];
    if (!target) {
      if (kind === 'Audio' || kind === 'Recording') {
        this.toast(`Peer ${enabled ? 'enabled' : 'disabled'} ${kind.toLowerCase()}`);
      }
      return; // nothing in the chrome maps to it
    }

    const ids = kind === 'Keyboard' ? [target.id, ...KEYBOARD_EXTRA_IDS] : [target.id];
    for (const id of ids) {
      const el = this.el.root.querySelector<HTMLButtonElement>(`#${id}`);
      if (!el) continue;
      el.disabled = !enabled;
      if (id === target.id) {
        el.title = enabled ? target.title : `${target.title} — not permitted by this device`;
        el.setAttribute('aria-label', el.title);
      }
    }

    // A capability withdrawn mid-session has to close what it opened.
    if (kind === 'File') {
      this.el.edge
        .querySelector<HTMLButtonElement>('[data-open="files"]')
        ?.toggleAttribute('disabled', !enabled);
      if (!enabled) {
        this.filePanel?.destroy();
        this.filePanel = undefined;
        if (this.sideOpen && this.sideTab === 'files') this.closeSide();
      }
    }

    this.toast(`Peer ${enabled ? 'enabled' : 'disabled'} ${target.title.toLowerCase()}`);
  }

  /** Forget peer permissions — they belong to one session, not to the client. */
  private resetPermissions(): void {
    this.permissions = {};
    const all = new Set<string>(KEYBOARD_EXTRA_IDS);
    for (const { id } of Object.values(PERMISSION_CONTROLS)) all.add(id);
    for (const id of all) {
      const el = this.el.root.querySelector<HTMLButtonElement>(`#${id}`);
      if (el) el.disabled = false;
    }
    for (const { id, title } of Object.values(PERMISSION_CONTROLS)) {
      const el = this.el.root.querySelector<HTMLButtonElement>(`#${id}`);
      if (el) {
        el.title = title;
        el.setAttribute('aria-label', title);
      }
    }
    this.el.edge
      .querySelector<HTMLButtonElement>('[data-open="files"]')
      ?.toggleAttribute('disabled', false);
  }

  /**
   * Feed a forwarded H.264 frame to the MSE player, creating it on first use.
   *
   * Reaching here at all means the worker found no WebCodecs and chose the
   * forwarding pipeline, so the <video> takes over from the canvas: it becomes
   * the visible surface AND the input target, since clicks must map against
   * whatever is actually showing the remote screen.
   */
  private pushMseFrame(data: Uint8Array, key: boolean): void {
    if (!this.msePlayer) {
      this.msePlayer = new MseVideoPlayer(this.videoEl, (msg) => {
        this.toast(msg);
        this.post({ c: 'refresh' });
      });
      this.canvas.hidden = true;
      this.videoEl.hidden = false;
      // Re-point input at the element the operator can actually see.
      this.detach?.();
      this.detach = attachInput(this.videoEl, (c) => this.post(c), () => this.currentRect(), {
        isTouchMode: () => this.inputMode === 'touch',
      });
      this.el.viewport.dataset.mse = '1';
    }
    this.msePlayer.push(data, key);
  }

  /** Put the canvas back in charge; called whenever a session ends. */
  private teardownMse(): void {
    if (!this.msePlayer) return;
    this.msePlayer.close();
    this.msePlayer = undefined;
    this.videoEl.hidden = true;
    this.videoEl.removeAttribute('src');
    this.canvas.hidden = false;
    delete this.el.viewport.dataset.mse;
  }

  private createAudioPlayback(): void {
    const AudioContextCtor = (window as unknown as {
      AudioContext?: new () => AudioContext;
      webkitAudioContext?: new () => AudioContext;
    }).AudioContext ?? (window as unknown as { webkitAudioContext?: new () => AudioContext }).webkitAudioContext;
    if (!AudioContextCtor) return;
    try {
      const context = new AudioContextCtor();
      this.audioPlayback = new RemoteAudioPlayback(context as unknown as RemoteAudioContext);
      this.audioPlayback.setVolume(this.audioVolume);
      this.audioPlayback.setMuted(this.audioMuted);
    } catch {
      this.audioPlayback = undefined;
    }
  }

  private async resumeAudioFromUserGesture(): Promise<void> {
    const sessionEpoch = this.sessionEpoch;
    if (!this.audioPlayback || this.permissions.Audio === false) {
      this.toast('Remote audio is unavailable');
      return;
    }
    const resumed = await this.audioPlayback.resumeFromUserGesture();
    const currentPermissions = this.permissions as Record<string, boolean | undefined>;
    if (sessionEpoch !== this.sessionEpoch || currentPermissions.Audio === false) return;
    this.audioStarted = resumed;
    this.toast(resumed ? 'Remote audio ready' : 'Browser blocked remote audio playback');
  }

  private showClipboardSyncOffer(): void {
    this.removeClipboardSyncOffer();
    if (!this.clipboardEnabled || this.permissions.Clipboard === false || this.state !== 'streaming') return;
    const prompt = document.createElement('div');
    prompt.className = 'rd-clipboard-sync-offer';
    const text = document.createElement('span');
    text.textContent = 'Sync your current clipboard to the remote device?';
    const sync = document.createElement('button');
    sync.type = 'button';
    sync.textContent = 'Sync now';
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.textContent = 'Not now';
    dismiss.className = 'rd-quiet';
    sync.addEventListener('click', () => {
      this.removeClipboardSyncOffer();
      void this.sendClipboard();
    });
    dismiss.addEventListener('click', () => this.removeClipboardSyncOffer());
    prompt.append(text, sync, dismiss);
    this.el.viewport.appendChild(prompt);
    this.clipboardSyncPrompt = prompt;
  }

  private removeClipboardSyncOffer(): void {
    this.clipboardSyncPrompt?.remove();
    this.clipboardSyncPrompt = undefined;
  }

  private canRecordSession(): boolean {
    return this.state === 'streaming' && this.permissions.Recording !== false;
  }

  private canSendClipboard(): boolean {
    return remoteInputAllowed(this.viewOnly, 'clipboard')
      && this.clipboardEnabled
      && this.permissions.Clipboard !== false;
  }

  private async toggleRecording(): Promise<void> {
    if (this.recording) {
      this.recorder?.stop();
      return;
    }
    if (!this.canRecordSession()) {
      this.toast('Session recording is not permitted by this device');
      return;
    }
    const sessionEpoch = this.sessionEpoch;
    if (this.remoteAudioEnabled && this.permissions.Audio !== false && this.audioPlayback) {
      const resumed = await this.audioPlayback.resumeFromUserGesture();
      if (sessionEpoch !== this.sessionEpoch) return;
      if (!this.canRecordSession()) {
        this.toast('Session recording is not permitted by this device');
        return;
      }
      this.audioStarted = resumed;
    }
    if (sessionEpoch !== this.sessionEpoch) return;
    if (!this.canRecordSession()) {
      this.toast('Session recording is not permitted by this device');
      return;
    }
    const surface = (!this.videoEl.hidden ? this.videoEl : this.canvas) as unknown as RecordingSurface;
    const recorder = new LocalSessionRecorder(
      surface,
      () => this.audioPlayback?.createRecordingTap() ?? null,
      (active, startedAtMs) => {
        if (sessionEpoch === this.sessionEpoch) this.onRecordingState(active, startedAtMs);
      },
    );
    const result = recorder.start();
    if (!result.ok) {
      recorder.close();
      this.toast(result.reason);
      return;
    }
    this.recorder = recorder;
  }

  private onRecordingState(active: boolean, startedAtMs?: number): void {
    const changed = this.recording !== active;
    const wasRecording = this.recording;
    this.recording = active;
    this.recordingStartedMs = active ? (startedAtMs ?? Date.now()) : 0;
    this.el.recordingIndicator.hidden = !active;
    const time = this.el.recordingIndicator.querySelector('span');
    if (time) time.textContent = '00:00';
    if (changed && this.permissions.Recording !== false) {
      this.post({ c: 'clientRecording', recording: active });
    }
    if (!active) {
      this.recorder = undefined;
      if (wasRecording) this.toast('Recording saved locally');
    } else {
      this.toast('Recording locally — nothing is uploaded', true);
    }
  }

  private async sendClipboard(): Promise<void> {
    if (!this.canSendClipboard()) {
      this.toast('Text clipboard is disabled for this session');
      return;
    }
    const sessionEpoch = this.sessionEpoch;
    const text = await readLocalClipboardText();
    if (sessionEpoch !== this.sessionEpoch) return;
    if (!this.canSendClipboard()) {
      this.toast('Text clipboard is disabled for this session');
      return;
    }
    if (text === null) {
      this.toast('Clipboard unavailable (permission denied?)');
      return;
    }
    this.post({ c: 'clipboardText', text });
    this.toast('Clipboard sent');
  }

  private async toggleFullscreen(): Promise<void> {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await this.el.root.requestFullscreen();
    } catch {
      this.toast('Fullscreen unavailable');
    }
  }

  // --- overlay / toast -------------------------------------------------------

  private showOverlay(): void {
    this.el.overlay.classList.remove('rd-hidden');
  }

  private hideOverlay(): void {
    this.el.overlay.classList.add('rd-hidden');
    this.setOverlayBusy(false);
  }

  private setOverlayBusy(busy: boolean): void {
    this.el.connectBtn.disabled = busy;
    this.el.peerIdInput.disabled = busy;
    this.el.passwordInput.disabled = busy;
    this.el.osLoginCheckbox.disabled = busy || !this.osLoginFlow;
    this.el.osPasswordInput.disabled = busy;
    this.el.osCurrentPasswordInput.disabled = busy;
    if (busy) this.setOverlayError(null);
    else if (!this.el.overlayStatusText.textContent) this.el.overlayStatus.hidden = true;
    this.el.overlayStatus.classList.toggle('rd-busy', busy);
  }

  private setOverlayStatusText(text: string): void {
    this.el.overlayStatus.hidden = false;
    this.el.overlayStatusText.textContent = text;
  }

  private setOverlayError(message: string | null): void {
    this.el.overlayError.hidden = !message;
    this.el.overlayError.textContent = message ?? '';
    if (message) this.el.overlayStatus.hidden = true;
  }

  private toast(msg: string, sticky = false): void {
    const t = this.el.toast;
    t.textContent = msg;
    t.classList.add('rd-show');
    clearTimeout(this.toastTimer);
    if (!sticky) this.toastTimer = setTimeout(() => t.classList.remove('rd-show'), 2600);
  }

  private hideToast(): void {
    clearTimeout(this.toastTimer);
    this.el.toast.classList.remove('rd-show');
  }

  private destroyAdvancedPanels(): void {
    this.terminalPanel?.destroy();
    this.terminalPanel = undefined;
    this.cameraPanel?.destroy();
    this.cameraPanel = undefined;
  }

  dispose(): void {
    this.clearRestartFlow();
    this.teardown();
    this.filePanel?.destroy();
    this.filePanel = undefined;
    this.destroyAdvancedPanels();
    this.closePop();
    if (this.ticker) clearInterval(this.ticker);
  }
}

if (typeof document !== 'undefined' && typeof Worker !== 'undefined') {
  const start = (): void => new RdApp().mount();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}
