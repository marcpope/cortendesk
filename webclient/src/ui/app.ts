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
import { ControlKey } from '../gen/message';
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
  resolveWorkerUrl,
  saveSavedHash,
  type IconName,
  type RdGlobalConfig,
} from './common';
import { FilePanel } from './file-panel';
import { MseVideoPlayer } from '../media/mse-video';
import { mseH264Available } from '../media/video';

// Back-compat: everything that used to live here is re-exported for tests and
// external importers.
export * from './common';

type RdWindow = Window & { __RD__?: RdGlobalConfig };

type SideTab = 'files' | 'chat' | 'details';
type InputMode = 'pointer' | 'touch';
type FitMode = 'fit' | 'actual';

type ChatEntry = { who: 'me' | 'peer'; text: string; at: number };

type Els = {
  root: HTMLElement;
  toolbar: HTMLElement;
  viewport: HTMLElement;
  dock: HTMLElement;
  side: HTMLElement;
  edge: HTMLElement;
  overlay: HTMLElement;
  toast: HTMLElement;
  peerLabel: HTMLElement;
  peerSub: HTMLElement;
  btnMonitors: HTMLButtonElement;
  btnFit: HTMLButtonElement;
  btnViewOnly: HTMLButtonElement;
  chatList: HTMLElement;
  chatInput: HTMLInputElement;
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
  connectBtn: HTMLButtonElement;
  overlayStatus: HTMLElement;
  overlayStatusText: HTMLElement;
  overlayError: HTMLElement;
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

  private filePanel: FilePanel | undefined;
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
  private sideTab: SideTab = 'files';
  private chatLog: ChatEntry[] = [];
  private chatUnread = 0;
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
    });

    // Saved password + fixed peer -> sign straight in. Skipped when ?lo=1
    // (the user logged out on purpose) so the connect screen stays put.
    if (this.fixedPeerId && !loggedOutFromSearch(location.search) && loadSavedHash(this.fixedPeerId)) {
      this.onConnectClick();
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
    }, 1000);

    window.addEventListener('beforeunload', () => this.post({ c: 'disconnect' }));
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
    } as Els; // remaining refs filled by render*()

    // --- INTEGRATION: FULLSCREEN INTERACTIVE ARROW CONTROLS WITH SIDEBAR ADAPTATION ---
    const rootEl = document.getElementById('rd-root');
    const dockEl = document.getElementById('rd-dock');
    const toolbarEl = document.getElementById('rd-toolbar');
    const edgeEl = document.getElementById('rd-edge');
    const sideEl = document.getElementById('rd-side'); // Added target layout sidebar

    if (rootEl) {
      // Local tracking variables to maintain layout visibility memory
      let isBottomOpen = false;
      let isTopOpen = false;

      // Function to dynamically shrink/move #rd-side when bars are toggled in fullscreen
      const updateSideLayout = () => {
        if (!sideEl) return;

        if (document.fullscreenElement) {
          const topOffset = isTopOpen && toolbarEl ? toolbarEl.offsetHeight : 0;
          const bottomOffset = isBottomOpen && dockEl ? dockEl.offsetHeight : 0;

          sideEl.style.top = `${topOffset}px`;
          sideEl.style.height = `calc(100vh - ${topOffset}px - ${bottomOffset}px)`;
        } else {
          // Reset to default styles when not in fullscreen
          sideEl.style.top = '';
          sideEl.style.height = '';
        }
      };

      // 1. Initialize and append the Bottom Dock Arrow
      let bottomArrow = document.getElementById('rd-dock-arrow') as HTMLButtonElement | null;
      if (dockEl && !bottomArrow) {
        bottomArrow = document.createElement('button');
        bottomArrow.id = 'rd-dock-arrow';
        bottomArrow.type = 'button';
        bottomArrow.className = 'rd-arrow-btn';
        bottomArrow.innerHTML = '▲';
        rootEl.appendChild(bottomArrow);

        bottomArrow.addEventListener('click', () => {
          isBottomOpen = !isBottomOpen;
          if (isBottomOpen) {
            dockEl.style.transform = 'translateY(0)';
            bottomArrow!.innerHTML = '▼';
            bottomArrow!.style.bottom = `${dockEl.offsetHeight}px`;
          } else {
            dockEl.style.transform = 'translateY(100%)';
            bottomArrow!.innerHTML = '▲';
            bottomArrow!.style.bottom = '0px';
          }
          updateSideLayout(); // Recalculate #rd-side space
        });
      }

      // 2. Initialize and append the Top Toolbar Arrow
      let topArrow = document.getElementById('rd-toolbar-arrow') as HTMLButtonElement | null;
      if (toolbarEl && !topArrow) {
        topArrow = document.createElement('button');
        topArrow.id = 'rd-toolbar-arrow';
        topArrow.type = 'button';
        topArrow.className = 'rd-arrow-btn';
        topArrow.innerHTML = '▼';
        rootEl.appendChild(topArrow);

        topArrow.addEventListener('click', () => {
          isTopOpen = !isTopOpen;
          if (isTopOpen) {
            toolbarEl.style.transform = 'translateY(0)';
            topArrow!.innerHTML = '▲';
            topArrow!.style.top = `${toolbarEl.offsetHeight}px`;
          } else {
            toolbarEl.style.transform = 'translateY(-100%)';
            topArrow!.innerHTML = '▼';
            topArrow!.style.top = '0px';
          }
          updateSideLayout(); // Recalculate #rd-side space
        });
      }

      // 3. Setup dynamic hover opacity on the edge sidebar
      if (edgeEl) {
        edgeEl.addEventListener('mouseenter', () => { edgeEl.style.opacity = '1'; });
        edgeEl.addEventListener('mouseleave', () => {
          if (document.fullscreenElement) edgeEl.style.opacity = '0.30';
        });
      }

      // 4. Manage layout transformations on entering/exiting fullscreen mode
      const onFullscreenChange = () => {
        const isFS = !!document.fullscreenElement;

        if (isFS) {
          // Reset visibility tracking states on initial layout expansion
          isBottomOpen = false;
          isTopOpen = false;

          document.body.classList.add('rd-fullscreen-active');
          if (edgeEl) edgeEl.style.opacity = '0.30';
          if (bottomArrow) { bottomArrow.innerHTML = '▲'; bottomArrow.style.bottom = '0px'; }
          if (topArrow) { topArrow.innerHTML = '▼'; topArrow.style.top = '0px'; }
        } else {
          // Clean custom modifications immediately on windowed mode restore
          document.body.classList.remove('rd-fullscreen-active');

          if (dockEl) dockEl.style.transform = '';
          if (toolbarEl) toolbarEl.style.transform = '';
          if (edgeEl) edgeEl.style.opacity = '';
          if (bottomArrow) bottomArrow.style.bottom = '';
          if (topArrow) topArrow.style.top = '';
        }
        updateSideLayout(); // Apply fullscreen or windowed default sizing to #rd-side
      };

      document.removeEventListener('fullscreenchange', onFullscreenChange);
      document.addEventListener('fullscreenchange', onFullscreenChange);
      onFullscreenChange();
    }
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
      this.post({ c: 'disconnect' });
      this.setState('closed');
    });
  }

  private toggleViewOnly(): void {
    this.viewOnly = !this.viewOnly;
    this.el.btnViewOnly.setAttribute('aria-pressed', String(this.viewOnly));
    this.el.btnViewOnly.classList.toggle('rd-on', this.viewOnly);
    // Latched modifiers make no sense with input off; drop them quietly.
    if (this.viewOnly) this.setLatches(false, false);
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
    let left = a.left + a.width / 2 - r.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - r.width - 8));
    const top = up ? a.top - r.height - 10 : a.bottom + 10;
    pop.style.left = `${Math.round(left)}px`;
    pop.style.top = `${Math.round(Math.max(8, top))}px`;
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

  private menuItem(icon: IconName | null, label: string, checked = false): string {
    return (
      `<button type="button" class="rd-mi${checked ? ' rd-checked' : ''}" role="menuitem">` +
      `${icon ? iconHtml(icon) : '<span class="rd-mi-pad"></span>'}` +
      `<span class="rd-mi-label">${escapeHtml(label)}</span>` +
      `${checked ? iconHtml('check') : ''}</button>`
    );
  }

  private openMonitorPop(): void {
    this.openPop(this.el.btnMonitors, (pop) => {
      // DisplayInfo carries index/geometry/name only — the protocol sends no
      // per-monitor preview, so these are honest tiles, not fake thumbnails.
      pop.innerHTML =
        '<div class="rd-pop-title">Select monitor</div>' +
        this.displays
          .map((d, i) => {
            const label = d.name?.trim() || `Monitor ${i + 1}`;
            return (
              `<button type="button" class="rd-mon-tile${i === this.current ? ' rd-checked' : ''}" data-idx="${i}" role="menuitem">` +
              `<span class="rd-mon-num">${i + 1}</span>` +
              `<span class="rd-mon-meta"><span class="rd-mon-name">${escapeHtml(label)}</span>` +
              `<span class="rd-mon-res">${d.width}×${d.height}</span></span>` +
              `${i === this.current ? iconHtml('check') : ''}</button>`
            );
          })
          .join('');
      for (const b of pop.querySelectorAll<HTMLButtonElement>('.rd-mon-tile')) {
        b.addEventListener('click', () => {
          const i = Number(b.dataset.idx);
          this.post({ c: 'switchDisplay', index: i });
          // Deliberately NOT setting this.current here. The host decides which
          // display is captured and answers with Misc.switch_display; assuming
          // success locally meant input started mapping to the new monitor's
          // origin while the video still showed the old one — a switch the host
          // declines never corrects itself. The confirmation arrives in
          // milliseconds and updates both together.
          this.closePop();
        });
      }
    });
  }

  private openMorePop(anchor: HTMLElement): void {
    this.openPop(anchor, (pop) => {
      const fs = !!document.fullscreenElement;
      pop.innerHTML =
        this.menuItem('refresh', 'Refresh video') +
        this.menuItem(fs ? 'fullscreenExit' : 'fullscreen', fs ? 'Exit fullscreen' : 'Fullscreen') +
        '<div class="rd-pop-sep"></div><div class="rd-pop-title">Image quality</div>' +
        this.menuItem(null, 'Best', this.quality === QUALITY.best) +
        this.menuItem(null, 'Balanced', this.quality === QUALITY.balanced) +
        this.menuItem(null, 'Speed', this.quality === QUALITY.speed);
      const items = pop.querySelectorAll<HTMLButtonElement>('.rd-mi');
      items[0]?.addEventListener('click', () => {
        this.post({ c: 'refresh' });
        this.closePop();
      });
      items[1]?.addEventListener('click', () => {
        void this.toggleFullscreen();
        this.closePop();
      });
      const qv = [QUALITY.best, QUALITY.balanced, QUALITY.speed];
      [items[2], items[3], items[4]].forEach((b, i) => {
        b?.addEventListener('click', () => {
          this.quality = qv[i]!;
          this.post({ c: 'quality', imageQuality: this.quality });
          this.closePop();
        });
      });
    });
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
        <div class="rd-msg" id="rd-msg">
          <div class="rd-overlay-status" id="rd-overlay-status" hidden>
            <span class="rd-spinner" aria-hidden="true"></span><span id="rd-overlay-status-text"></span>
          </div>
          <div class="rd-overlay-error" id="rd-overlay-error" role="alert" hidden></div>
        </div>
      </div>`;
    const o = this.el.overlay;
    this.el.overlayPeer = q(o, '#rd-overlay-peer');
    this.el.overlayTarget = q(o, '#rd-target');
    this.el.fieldId = q(o, '#rd-field-id');
    this.el.peerIdInput = q(o, '#rd-peer-id');
    this.el.passwordInput = q(o, '#rd-password');
    this.el.saveCheckbox = q(o, '#rd-save-pw');
    this.el.connectBtn = q(o, '#rd-connect');
    this.el.overlayStatus = q(o, '#rd-overlay-status');
    this.el.overlayStatusText = q(o, '#rd-overlay-status-text');
    this.el.overlayError = q(o, '#rd-overlay-error');

    this.el.connectBtn.addEventListener('click', () => this.onConnectClick());
    const enter = (e: KeyboardEvent): void => {
      if (e.key === 'Enter') this.onConnectClick();
    };
    this.el.passwordInput.addEventListener('keydown', enter);
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

  private onConnectClick(): void {
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

    const typed = this.el.passwordInput.value;
    const saved = loadSavedHash(peerId);
    // If the user left the field blank and we have a stored hash, reuse it.
    // If the "save" box is off, forget any stored credential for this device.
    if (!this.el.saveCheckbox.checked) clearSavedHash(peerId);
    this.pendingHashHex = undefined;
    this.connectedWithSavedHash = false;

    let config: SessionConfig;
    if (!typed && saved) {
      this.connectedWithSavedHash = true;
      config = buildSessionConfig(this.cfg, peerId, '', saved);
    } else {
      config = buildSessionConfig(this.cfg, peerId, typed);
    }
    this.startSession(config);
  }

  private startSession(config: SessionConfig): void {
    this.teardown();
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
    this.peerWho = '';
    this.peerPlatform = '';
    this.sessionHashHex = config.savedHashHex;
    this.stats = undefined;
    this.streamStartMs = 0;
    this.displays = [];
    this.current = 0;
    const canvas = this.freshCanvas();
    const offscreen = canvas.transferControlToOffscreen();
    const worker = new Worker(this.workerUrl, { type: 'module' });
    this.worker = worker;
    worker.onmessage = (e: MessageEvent) => this.onEvent(e.data as SessionEvent);
    worker.onerror = (e: ErrorEvent) => this.setState('error', e.message || 'session worker failed');
    const cmd: UiCommand = { c: 'connect', config, canvas: offscreen };
    worker.postMessage(cmd, [offscreen]);
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

  private teardown(): void {
    this.detach?.();
    this.detach = undefined;
    this.teardownMse();
    const w = this.worker;
    this.worker = undefined;
    if (w) setTimeout(() => w.terminate(), 250); // let a pending 'disconnect' flush first
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

  private post(cmd: UiCommand): void {
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
    if (this.viewOnly && (cmd.c === 'mouse' || cmd.c === 'key' || cmd.c === 'ctrlAltDel')) return;
    if ((this.latchCtrl || this.latchAlt) && (cmd.c === 'mouse' || cmd.c === 'key')) {
      const extra: number[] = [];
      if (this.latchCtrl) extra.push(ControlKey.Control);
      if (this.latchAlt) extra.push(ControlKey.Alt);
      cmd = { ...cmd, modifiers: [...new Set([...cmd.modifiers, ...extra])] };
    }
    this.worker?.postMessage(cmd);
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

  private onEvent(ev: SessionEvent): void {
    switch (ev.t) {
      case 'state':
        this.setState(ev.state, ev.detail);
        break;
      case 'peerInfo': {
        this.dbg('peerInfo', { current: ev.current, displays: ev.displays });
        this.displays = ev.displays;
        this.current = ev.current;
        this.peerWho = ev.username ? `${ev.username}@${ev.hostname}` : ev.hostname;
        this.peerPlatform = ev.platform || '';
        this.el.peerLabel.textContent = this.peerWho || this.peerId;
        this.refreshPeerSub();
        this.el.statVersion.textContent = ev.version || '—';
        this.el.statUser.textContent = this.peerWho || '—';
        this.el.statPlatform.textContent = this.peerPlatform || '—';
        this.el.btnMonitors.hidden = this.displays.length < 2;
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
        // On the MSE fallback the muxer is built around the stream it was
        // started with, so a new frame size has to start a new one. The worker
        // holds the forwarded stream until the next key frame, which is what
        // rebuilds this on the following push.
        this.teardownMse();
        this.refreshPeerSub();
        break;
      }
      case 'stats':
        this.onStats(ev.stats);
        break;
      case 'cursor':
        this.canvas.style.cursor = cursorCss(ev.pngDataUrl, ev.hotx, ev.hoty);
        break;
      case 'cursorPos':
        break; // remote pointer position; local pointer is authoritative here
      case 'clipboard':
        void navigator.clipboard
          ?.writeText(ev.text)
          .then(() => this.toast('Remote clipboard received'))
          .catch(() => this.toast('Remote clipboard received (press Ctrl+V on this page to sync)'));
        break;
      case 'chat':
        this.onChat(ev.text);
        break;
      case 'h264':
        this.pushMseFrame(ev.data, ev.key);
        break;
      case 'permission':
        this.applyPermission(ev.kind, ev.enabled);
        break;
      case 'credentials':
        this.pendingHashHex = ev.hashHex; // persisted only once the session streams
        this.sessionHashHex = ev.hashHex; // in-memory: reused by the file panel
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
    this.el.root.dataset.state = state;
    this.refreshPeerSub();
    switch (state) {
      case 'streaming':
        if (!this.streamStartMs) this.streamStartMs = Date.now();
        this.persistCredentialIfWanted();
        this.hideOverlay();
        this.canvas.focus();
        break;
      case 'error':
        this.teardown();
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

    const target = PERMISSION_CONTROLS[kind];
    if (!target) {
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

  private async sendClipboard(): Promise<void> {
    const text = await readLocalClipboardText();
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

  dispose(): void {
    this.teardown();
    this.filePanel?.destroy();
    this.filePanel = undefined;
    this.closePop();
    if (this.ticker) clearInterval(this.ticker);
  }
}

if (typeof document !== 'undefined' && typeof Worker !== 'undefined') {
  const start = (): void => new RdApp().mount();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}
