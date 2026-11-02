// CortenDesk web client — in-session file-transfer panel.
//
// Slides over the live desktop viewport (no separate window, no second login):
// the panel opens its own FILE_TRANSFER relay connection to the same peer,
// authenticating silently with the h1 credential the desktop session already
// proved. Dual-pane manager in the CortenDesk style: local computer left,
// remote computer right, Send/Receive between them, transfer queue + event
// log at the bottom, close (×) returns to the desktop.
//
// Local side is the browser, so "local" means:
//   - Chromium: a real directory opened with showDirectoryPicker() — browse,
//     upload from and download into it (writes are atomic: data commits on
//     close, so a cancelled download never clobbers an existing file).
//   - Fallback: stage files with a picker/drag-drop for upload; downloads go
//     to the browser's Downloads folder.
//
// Wire protocol (second relay connection, ConnType.FILE_TRANSFER):
//   browse    read_dir -> dir(id=0)
//   download  send{id} -> dir(id) file list -> per file: digest -> our
//             send_confirm(offset) -> blocks (empty block = EOF) -> done(id)
//   upload    receive{id, files} -> per file: our digest -> peer confirm
//             (send_confirm, or digest{is_upload} asking us to decide) ->
//             blocks -> done(id, fileCount) -> peer echoes done
//   ops       create/remove_file/remove_dir/rename -> done/error ack
import './file-panel.css';
import type { FtDirectory, FtEntry, SessionConfig, SessionEvent, SessionState, UiCommand } from '../core/contracts';
import {
  FT_BLOCK_SIZE,
  formatBytes,
  isDirKind,
  joinRemote,
  parentRemote,
} from '../core/file-transfer';
import { escapeHtml, iconHtml } from './common';
import { htl, tl, tn } from '../i18n';
import { isRtcEvent, webRtcFor, type WebRtcHost } from './webrtc-host';

type PickerWindow = Window & {
  showDirectoryPicker?(opts?: { mode?: 'read' | 'readwrite' }): Promise<FileSystemDirectoryHandle>;
};

// queryPermission/requestPermission are WICG additions not yet in lib.dom.
type PermissionedHandle = FileSystemDirectoryHandle & {
  queryPermission?(desc: { mode: 'read' | 'readwrite' }): Promise<PermissionState>;
  requestPermission?(desc: { mode: 'read' | 'readwrite' }): Promise<PermissionState>;
};

const MEM_DOWNLOAD_WARN = 512 * 1024 * 1024; // fallback downloads buffer in RAM
const UPLOAD_BACKLOG_LIMIT = 8 * 1024 * 1024; // pause while ws send buffer exceeds this

type ConflictDecision = { action: 'overwrite' | 'skip' | 'cancel'; applyAll: boolean };
type ConflictPolicy = 'ask' | 'overwrite' | 'skip';

type LocalEntry = {
  kind: 'dir' | 'file';
  name: string;
  size: number;
  modifiedMs: number;
  handle?: FileSystemDirectoryHandle | FileSystemFileHandle;
  file?: File; // fallback staged file
};

type UploadFile = { rel: string; file: File };

type Job = {
  id: number;
  kind: 'download' | 'upload';
  label: string;
  status: 'starting' | 'running' | 'done' | 'error' | 'cancelled';
  error?: string;
  totalSize: number;
  doneBytes: number;
  fileCount: number;
  currentFile: string;
  policy: ConflictPolicy | null; // sticky per-job override from "apply to all"
  startMs: number;
  lastBytes: number; // for speed sampling
  lastSampleMs: number;
  speedBps: number;
  // download state
  remoteFiles?: FtEntry[];
  destDir?: FileSystemDirectoryHandle | null; // null = memory/anchor fallback
  curFileNum?: number;
  writable?: FileSystemWritableFileStream;
  memBuf?: Uint8Array[];
  writeChain?: Promise<void>;
  // upload state
  files?: UploadFile[];
  confirmWaiter?: (r: { skip: boolean; offsetBytes: number } | 'cancelled') => void;
  sentWaiter?: (buffered: number) => void;
  peerDone?: boolean;
};

type PendingConflict = {
  jobId: number;
  name: string;
  detail: string;
  resolve: (d: ConflictDecision) => void;
};

export type FilePanelOpts = {
  viewport: HTMLElement;
  workerUrl: string;
  toast: (msg: string) => void;
  // Built fresh on every (re)connect — must carry connType:'fileTransfer' and
  // the session's h1 credential; null when the desktop session isn't ready.
  getConfig: () => SessionConfig | null;
};

type Els = {
  panel: HTMLElement;
  status: HTMLElement;
  btnHidden: HTMLButtonElement;
  localSub: HTMLElement;
  localPath: HTMLInputElement;
  localBody: HTMLElement;
  localFoot: HTMLElement;
  localEmpty: HTMLElement;
  remoteSub: HTMLElement;
  remotePath: HTMLInputElement;
  remoteBody: HTMLElement;
  remoteFoot: HTMLElement;
  remoteEmpty: HTMLElement;
  btnSend: HTMLButtonElement;
  btnRecv: HTMLButtonElement;
  tabJobs: HTMLButtonElement;
  tabLog: HTMLButtonElement;
  jobsWrap: HTMLElement;
  logWrap: HTMLElement;
  dialog: HTMLElement;
};

function q<T extends Element>(scope: ParentNode, sel: string): T {
  const el = scope.querySelector<T>(sel);
  if (!el) throw new Error(`rdclient: missing element ${sel}`);
  return el;
}

function nowMs(): number {
  return Date.now();
}

export class FilePanel {
  private readonly opts: FilePanelOpts;
  private el!: Els;
  private worker: Worker | undefined;
  private rtc: WebRtcHost | undefined;
  private state: SessionState = 'closed';
  private peerLabel = '';
  private sep: '\\' | '/' = '/';
  private showHidden = false;
  private nextId = 1;
  private jobs: Job[] = [];
  private conflicts: PendingConflict[] = [];
  private conflictOpen = false;
  private ops = new Map<number, { desc: string; refresh: boolean }>();
  private ticker: ReturnType<typeof setInterval> | undefined;
  private beforeUnload = (): void => this.post({ c: 'disconnect' });

  // remote pane
  private remotePathValue = '';
  private remoteEntries: FtEntry[] = [];
  private remoteSel = new Set<number>();
  private remoteAnchor = -1;

  // local pane
  private fsMode = false;
  private rootHandle: FileSystemDirectoryHandle | undefined;
  private dirStack: FileSystemDirectoryHandle[] = []; // [root, ..., cwd]
  private localEntries: LocalEntry[] = [];
  private staged: File[] = []; // fallback mode
  private localSel = new Set<number>();
  private localAnchor = -1;
  private localListGen = 0;

  constructor(opts: FilePanelOpts) {
    this.opts = opts;
    this.fsMode = typeof (window as PickerWindow).showDirectoryPicker === 'function';
    this.render();
    this.ticker = setInterval(() => this.tickJobs(), 500);
    window.addEventListener('beforeunload', this.beforeUnload);
    this.log(tl('file_transfer_panel_ready'));
    if (!this.fsMode) {
      this.log(tl('this_browser_lacks_file_system'));
    }
  }

  get isOpen(): boolean {
    return !this.el.panel.classList.contains('rd-ft-closed');
  }

  toggle(): void {
    if (this.isOpen) this.close();
    else this.open();
  }

  open(): void {
    this.el.panel.classList.remove('rd-ft-closed');
    this.connectIfNeeded();
  }

  close(): void {
    // Hide only — the file connection stays warm (the session core echoes the
    // peer's TestDelay keepalive) so reopening is instant and running
    // transfers continue in the background.
    this.el.panel.classList.add('rd-ft-closed');
  }

  destroy(): void {
    this.post({ c: 'disconnect' });
    this.teardown();
    if (this.ticker) clearInterval(this.ticker);
    window.removeEventListener('beforeunload', this.beforeUnload);
    this.el.panel.remove();
  }

  // --- connection ------------------------------------------------------------

  private connectIfNeeded(): void {
    if (this.worker && this.state !== 'error' && this.state !== 'closed') return;
    const config = this.opts.getConfig();
    if (!config) {
      this.setStatus('error', tl('desktop_session_is_not_connected'));
      return;
    }
    this.peerLabel = config.peerId;
    this.teardown();
    const worker = new Worker(this.opts.workerUrl, { type: 'module' });
    this.worker = worker;
    const rtc = webRtcFor(worker);
    this.rtc = rtc;
    worker.onmessage = (e: MessageEvent) => {
      if (!isRtcEvent(rtc, e.data)) this.onEvent(e.data as SessionEvent);
    };
    worker.onerror = (e: ErrorEvent) => {
      this.state = 'error';
      this.setStatus('error', e.message || 'session worker failed');
    };
    this.post({ c: 'connectFile', config });
    this.state = 'connecting';
    this.setStatus('busy', tl('opening_file_channel'));
  }

  private teardown(): void {
    const w = this.worker;
    this.worker = undefined;
    if (w) setTimeout(() => w.terminate(), 250);
    this.rtc?.close();
    this.rtc = undefined;
    for (const job of this.jobs) {
      if (job.status === 'running' || job.status === 'starting') {
        job.status = 'error';
        job.error = 'connection closed';
        job.confirmWaiter?.('cancelled');
        job.sentWaiter?.(0);
        void this.closeJobFile(job, false);
      }
    }
    this.renderJobs();
  }

  private post(cmd: UiCommand, transfer?: Transferable[]): void {
    if (!this.worker) return;
    if (transfer) this.worker.postMessage(cmd, transfer);
    else this.worker.postMessage(cmd);
  }

  // --- DOM -------------------------------------------------------------------

  private render(): void {
    const pane = (side: 'local' | 'remote', title: string, headBtns: string, pathBtns: string): string => `
      <section class="rd-ft-pane" id="rd-ft-${side}">
        <header class="rd-ft-pane-head">
          <span class="rd-ft-pane-ic">${iconHtml(side === 'local' ? 'monitor' : 'folderTransfer')}</span>
          <div class="rd-ft-pane-title">
            <strong>${escapeHtml(title)}</strong>
            <small id="rd-ft-${side}-sub"></small>
          </div>
          <div class="rd-ft-pane-actions">${headBtns}</div>
        </header>
        <div class="rd-ft-pathrow">
          ${pathBtns}
          <input id="rd-ft-${side}-path" class="rd-ft-path" type="text" spellcheck="false" autocomplete="off"
                 placeholder="${side === 'remote' ? 'Remote path' : ''}" ${side === 'local' ? 'readonly' : ''}>
          <button type="button" class="rd-btn" id="rd-ft-${side}-refresh" title="${htl('refresh')}">${iconHtml('refresh')}</button>
        </div>
        <div class="rd-ft-listwrap">
          <table class="rd-ft-table">
            <thead><tr><th>${htl('name')}</th><th class="rd-ft-col-size">${htl('size')}</th><th class="rd-ft-col-mod">${htl('modified')}</th></tr></thead>
            <tbody id="rd-ft-${side}-body"></tbody>
          </table>
          <div class="rd-ft-empty" id="rd-ft-${side}-empty" hidden></div>
        </div>
        <footer class="rd-ft-foot" id="rd-ft-${side}-foot"></footer>
      </section>`;

    const sBtn = (id: string, icon: string, title: string): string =>
      `<button type="button" class="rd-btn" id="${id}" title="${escapeHtml(title)}">${icon}</button>`;

    const panel = document.createElement('div');
    panel.id = 'rd-ft-overlay';
    panel.className = 'rd-ft-closed';
    panel.innerHTML = `
      <header class="rd-ft-head">
        <span class="rd-ft-title">${iconHtml('folderTransfer')}<span>${htl('file_transfer_2')}</span></span>
        <span class="rd-ft-status" id="rd-ft-status"></span>
        <span class="rd-ft-head-spacer"></span>
        ${sBtn('rd-ft-hidden', iconHtml('eyeOff'), tl('show_hidden_files'))}
        ${sBtn('rd-ft-close', iconHtml('close'), tl('close_file_transfer'))}
      </header>
      <div class="rd-ft-panes">
        ${pane(
          'local',
          tl('this_computer'),
          sBtn('rd-ft-local-send', iconHtml('fileUpload'), tl('send_files_pick_files_from')) +
            (this.fsMode
              ? sBtn('rd-ft-local-open', iconHtml('folderOpen'), tl('open_local_folder'))
              : sBtn('rd-ft-local-add', iconHtml('folderOpen'), tl('add_files_stage'))),
          sBtn('rd-ft-local-up', iconHtml('arrowUp'), tl('up_one_level')),
        )}
        <div class="rd-ft-mid">
          <button type="button" class="rd-ft-go" id="rd-ft-send" title="${htl('send_selected_remote_computer')}" disabled>
            <span>${htl('send')}</span>${iconHtml('sendRight')}
          </button>
          <button type="button" class="rd-ft-go rd-ft-go-recv" id="rd-ft-recv" title="${htl('receive_selected_from_remote_computer')}" disabled>
            ${iconHtml('sendLeft')}<span>${htl('receive')}</span>
          </button>
        </div>
        ${pane(
          'remote',
          tl('remote_computer'),
          [
            sBtn('rd-ft-remote-new', iconHtml('newFolder'), tl('new_folder')),
            sBtn('rd-ft-remote-rename', iconHtml('rename'), tl('rename_selected')),
            sBtn('rd-ft-remote-del', iconHtml('trash'), tl('delete_selected')),
          ].join(''),
          sBtn('rd-ft-remote-up', iconHtml('arrowUp'), tl('up_one_level')) +
            sBtn('rd-ft-remote-home', iconHtml('home'), tl('home_directory')),
        )}
      </div>
      <div class="rd-ft-bottom">
        <div class="rd-ft-tabs">
          <button type="button" class="rd-ft-tab rd-active" id="rd-ft-tab-jobs">${htl('transfers')}</button>
          <button type="button" class="rd-ft-tab" id="rd-ft-tab-log">${htl('event_log')}</button>
        </div>
        <div class="rd-ft-jobs" id="rd-ft-jobs"><div class="rd-ft-none">${htl('no_transfers_yet')}</div></div>
        <div class="rd-ft-log" id="rd-ft-log" hidden></div>
      </div>
      <div id="rd-ft-dialog" hidden></div>`;
    this.opts.viewport.appendChild(panel);

    const m = panel;
    this.el = {
      panel,
      status: q(m, '#rd-ft-status'),
      btnHidden: q(m, '#rd-ft-hidden'),
      localSub: q(m, '#rd-ft-local-sub'),
      localPath: q(m, '#rd-ft-local-path'),
      localBody: q(m, '#rd-ft-local-body'),
      localFoot: q(m, '#rd-ft-local-foot'),
      localEmpty: q(m, '#rd-ft-local-empty'),
      remoteSub: q(m, '#rd-ft-remote-sub'),
      remotePath: q(m, '#rd-ft-remote-path'),
      remoteBody: q(m, '#rd-ft-remote-body'),
      remoteFoot: q(m, '#rd-ft-remote-foot'),
      remoteEmpty: q(m, '#rd-ft-remote-empty'),
      btnSend: q(m, '#rd-ft-send'),
      btnRecv: q(m, '#rd-ft-recv'),
      tabJobs: q(m, '#rd-ft-tab-jobs'),
      tabLog: q(m, '#rd-ft-tab-log'),
      jobsWrap: q(m, '#rd-ft-jobs'),
      logWrap: q(m, '#rd-ft-log'),
      dialog: q(m, '#rd-ft-dialog'),
    };

    this.el.localSub.textContent = this.fsMode ? tl('browser_no_folder_opened') : tl('browser_staged_files');

    q<HTMLButtonElement>(m, '#rd-ft-close').addEventListener('click', () => this.close());
    this.el.btnHidden.addEventListener('click', () => {
      this.showHidden = !this.showHidden;
      this.el.btnHidden.innerHTML = iconHtml(this.showHidden ? 'eye' : 'eyeOff');
      this.el.btnHidden.title = this.showHidden ? tl('hide_hidden_files') : tl('show_hidden_files');
      this.readRemote(this.remotePathValue);
      void this.refreshLocal();
    });

    q<HTMLButtonElement>(m, '#rd-ft-local-send').addEventListener('click', () => this.pickAndSendFiles());
    if (this.fsMode) {
      q<HTMLButtonElement>(m, '#rd-ft-local-open').addEventListener('click', () => void this.openLocalFolder());
    } else {
      q<HTMLButtonElement>(m, '#rd-ft-local-add').addEventListener('click', () => this.pickStagedFiles());
    }
    q<HTMLButtonElement>(m, '#rd-ft-local-up').addEventListener('click', () => void this.localUp());
    q<HTMLButtonElement>(m, '#rd-ft-local-refresh').addEventListener('click', () => void this.refreshLocal());
    q<HTMLButtonElement>(m, '#rd-ft-remote-up').addEventListener('click', () => this.remoteUp());
    q<HTMLButtonElement>(m, '#rd-ft-remote-home').addEventListener('click', () => this.readRemote(''));
    q<HTMLButtonElement>(m, '#rd-ft-remote-refresh').addEventListener('click', () => this.readRemote(this.remotePathValue));
    q<HTMLButtonElement>(m, '#rd-ft-remote-new').addEventListener('click', () => this.newRemoteFolder());
    q<HTMLButtonElement>(m, '#rd-ft-remote-rename').addEventListener('click', () => this.renameRemote());
    q<HTMLButtonElement>(m, '#rd-ft-remote-del').addEventListener('click', () => this.deleteRemote());
    this.el.remotePath.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') this.readRemote(this.el.remotePath.value.trim());
    });

    this.el.btnSend.addEventListener('click', () => void this.startUpload());
    this.el.btnRecv.addEventListener('click', () => this.startDownloads());

    this.wireList(this.el.localBody, 'local');
    this.wireList(this.el.remoteBody, 'remote');

    const setTab = (tab: 'jobs' | 'log'): void => {
      this.el.tabJobs.classList.toggle('rd-active', tab === 'jobs');
      this.el.tabLog.classList.toggle('rd-active', tab === 'log');
      this.el.jobsWrap.hidden = tab !== 'jobs';
      this.el.logWrap.hidden = tab !== 'log';
    };
    this.el.tabJobs.addEventListener('click', () => setTab('jobs'));
    this.el.tabLog.addEventListener('click', () => setTab('log'));

    // drag & drop upload (files only) onto the remote pane; onto the local pane
    // in fallback mode it stages them.
    const remotePane = q<HTMLElement>(m, '#rd-ft-remote');
    const localPane = q<HTMLElement>(m, '#rd-ft-local');
    for (const [paneEl, handler] of [
      [remotePane, (files: File[]) => void this.uploadFiles(files.map((f) => ({ rel: f.name, file: f })))],
      [localPane, (files: File[]) => this.stageFiles(files)],
    ] as Array<[HTMLElement, (files: File[]) => void]>) {
      paneEl.addEventListener('dragover', (e) => {
        e.preventDefault();
        paneEl.classList.add('rd-ft-drop');
      });
      paneEl.addEventListener('dragleave', () => paneEl.classList.remove('rd-ft-drop'));
      paneEl.addEventListener('drop', (e) => {
        e.preventDefault();
        paneEl.classList.remove('rd-ft-drop');
        const files = Array.from(e.dataTransfer?.files ?? []);
        if (files.length) handler(files);
      });
    }

    this.updateLocalEmpty();
    this.renderLocal();
    this.renderRemote();
    this.updateButtons();
  }

  private setStatus(kind: 'busy' | 'ok' | 'error', text: string): void {
    const s = this.el.status;
    s.className = `rd-ft-status rd-ft-status-${kind}`;
    s.innerHTML =
      (kind === 'busy' ? '<span class="rd-spinner" aria-hidden="true"></span>' : '') +
      `<span>${escapeHtml(text)}</span>` +
      (kind === 'error' ? '<button type="button" class="rd-ft-retry" id="rd-ft-retry">' + htl('retry') + '</button>' : '');
    s.querySelector('#rd-ft-retry')?.addEventListener('click', () => this.connectIfNeeded());
  }

  private wireList(tbody: HTMLElement, side: 'local' | 'remote'): void {
    tbody.addEventListener('click', (e) => {
      const tr = (e.target as HTMLElement).closest('tr[data-idx]');
      if (!tr) return;
      const idx = Number(tr.getAttribute('data-idx'));
      const sel = side === 'local' ? this.localSel : this.remoteSel;
      const anchor = side === 'local' ? this.localAnchor : this.remoteAnchor;
      if (e.shiftKey && anchor >= 0) {
        sel.clear();
        const [a, b] = [Math.min(anchor, idx), Math.max(anchor, idx)];
        for (let i = a; i <= b; i++) sel.add(i);
      } else if (e.metaKey || e.ctrlKey) {
        if (sel.has(idx)) sel.delete(idx);
        else sel.add(idx);
        if (side === 'local') this.localAnchor = idx;
        else this.remoteAnchor = idx;
      } else {
        sel.clear();
        sel.add(idx);
        if (side === 'local') this.localAnchor = idx;
        else this.remoteAnchor = idx;
      }
      if (side === 'local') this.renderLocal();
      else this.renderRemote();
      this.updateButtons();
    });
    tbody.addEventListener('dblclick', (e) => {
      const tr = (e.target as HTMLElement).closest('tr[data-idx]');
      if (!tr) return;
      const idx = Number(tr.getAttribute('data-idx'));
      if (side === 'remote') {
        const entry = this.remoteEntries[idx];
        if (!entry) return;
        if (isDirKind(entry.kind)) this.readRemote(joinRemote(this.remotePathValue, entry.name, this.sep));
        else void this.startDownloadOf([entry]);
      } else {
        const entry = this.localEntries[idx];
        if (!entry) return;
        if (entry.kind === 'dir' && this.fsMode) void this.localEnter(entry);
      }
    });
  }

  // --- session events --------------------------------------------------------

  private onEvent(ev: SessionEvent): void {
    switch (ev.t) {
      case 'state':
        this.state = ev.state;
        switch (ev.state) {
          case 'streaming':
            this.setStatus('ok', `Connected · ${this.peerLabel}`);
            this.log(tl('file_channel_established', { peer: this.peerLabel }));
            if (!this.remotePathValue) this.readRemote('');
            break;
          case 'error':
            this.teardown();
            this.setStatus('error', ev.detail || tl('connection_failed'));
            this.log(tl('file_channel_error', { detail: ev.detail || tl('unknown_error') }));
            break;
          case 'closed':
            this.teardown();
            this.setStatus('error', tl('file_channel_closed'));
            this.log(tl('file_channel_closed_2'));
            break;
          case 'needAccept':
            this.setStatus('busy', tl('waiting_for_remote_user_accept_3'));
            break;
          default:
            this.setStatus('busy', tl('opening_file_channel'));
        }
        this.updateButtons();
        this.renderRemote();
        break;
      case 'peerInfo':
        this.el.remoteSub.textContent = ev.username ? `${ev.username}@${ev.hostname}` : ev.hostname;
        break;
      case 'loginError':
        // Shouldn't happen (same credential as the live desktop session), but
        // surface it honestly if the peer rejects the file channel.
        this.teardown();
        this.state = 'error';
        this.setStatus('error', ev.message || tl('file_channel_login_failed_2'));
        this.log(tl('file_channel_login_failed', { message: ev.message }));
        this.updateButtons();
        break;
      case 'msgbox': {
        const text = ev.text || ev.title;
        if (text) this.opts.toast(ev.title && ev.text ? `${ev.title}: ${ev.text}` : text);
        break;
      }
      case 'ftDir':
        this.onFtDir(ev.dir);
        break;
      case 'ftDigest':
        void this.onFtDigest(ev);
        break;
      case 'ftSendConfirm': {
        const job = this.jobById(ev.id);
        job?.confirmWaiter?.({ skip: ev.skip, offsetBytes: ev.offsetBytes });
        break;
      }
      case 'ftSent': {
        const job = this.jobById(ev.id);
        job?.sentWaiter?.(ev.buffered);
        break;
      }
      case 'ftBlock':
        this.onFtBlock(ev);
        break;
      case 'ftDone':
        this.onFtDone(ev.id, ev.fileNum);
        break;
      case 'ftError':
        this.onFtError(ev.id, ev.fileNum, ev.error);
        break;
      default:
        break;
    }
  }

  // --- remote pane -----------------------------------------------------------

  private readRemote(path: string): void {
    if (this.state !== 'streaming') return;
    this.post({ c: 'ftReadDir', path, includeHidden: this.showHidden });
  }

  private remoteUp(): void {
    const parent = parentRemote(this.remotePathValue, this.sep);
    // '' from a Windows drive root means "list the drives" (path "/").
    this.readRemote(parent === '' && this.sep === '\\' ? '/' : parent);
  }

  private onFtDir(dir: FtDirectory): void {
    // Directory listings for the browse pane come back with id=0 (and the
    // unsolicited post-login home listing). id>0 = a transfer job's file list.
    if (dir.id !== 0) {
      const job = this.jobById(dir.id);
      if (job && job.kind === 'download') {
        job.remoteFiles = dir.entries.map((e) => ({
          ...e,
          name: this.sep === '\\' ? e.name.replaceAll('\\', '/') : e.name,
        }));
        job.fileCount = job.remoteFiles.length;
        job.totalSize = job.remoteFiles.reduce((s, e) => s + e.size, 0);
        job.status = job.fileCount === 0 ? job.status : 'running';
        this.renderJobs();
      }
      return;
    }
    if (dir.path) {
      this.sep = dir.path.includes('\\') || /^[a-zA-Z]:/.test(dir.path) ? '\\' : '/';
    }
    this.remotePathValue = dir.path;
    this.el.remotePath.value = dir.path;
    const entries = dir.entries.filter((e) => this.showHidden || !e.isHidden);
    entries.sort((a, b) => {
      const da = isDirKind(a.kind) ? 0 : 1;
      const db = isDirKind(b.kind) ? 0 : 1;
      if (da !== db) return da - db;
      return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
    });
    this.remoteEntries = entries;
    this.remoteSel.clear();
    this.remoteAnchor = -1;
    this.renderRemote();
    this.updateButtons();
  }

  private renderRemote(): void {
    const rows = this.remoteEntries.map((e, i) => {
      const icon = isDirKind(e.kind) ? (e.kind === 'drive' ? 'drive' : 'folder') : 'file';
      const size = isDirKind(e.kind) ? '—' : formatBytes(e.size);
      const mod = e.modifiedSec ? new Date(e.modifiedSec * 1000).toLocaleString() : '—';
      return `<tr data-idx="${i}" class="${this.remoteSel.has(i) ? 'rd-sel' : ''}${e.isHidden ? ' rd-hiddenfile' : ''}">
        <td><span class="rd-ft-ic">${iconHtml(icon)}</span>${escapeHtml(e.name)}</td>
        <td class="rd-ft-col-size">${size}</td><td class="rd-ft-col-mod">${mod}</td></tr>`;
    });
    this.el.remoteBody.innerHTML = rows.join('');
    this.el.remoteEmpty.hidden = this.remoteEntries.length > 0;
    this.el.remoteEmpty.textContent = this.state === 'streaming' ? tl('empty_folder') : tl('not_connected');
    const selSize = [...this.remoteSel].reduce((s, i) => s + (this.remoteEntries[i]?.size ?? 0), 0);
    this.el.remoteFoot.textContent = tl('n_of_m_selected', { n: this.remoteSel.size, m: this.remoteEntries.length }) +
      (selSize > 0 ? ` · ${formatBytes(selSize)}` : '');
  }

  // --- remote ops ------------------------------------------------------------

  private op(desc: string, refresh: boolean): number {
    const id = this.nextId++;
    this.ops.set(id, { desc, refresh });
    return id;
  }

  private newRemoteFolder(): void {
    if (this.state !== 'streaming') return;
    this.promptDialog(tl('new_folder'), tl('folder_name'), '', (name) => {
      if (!name) return;
      const id = this.op(tl('create_folder', { name }), true);
      this.post({ c: 'ftCreateDir', id, path: joinRemote(this.remotePathValue, name, this.sep) });
    });
  }

  private renameRemote(): void {
    const idx = [...this.remoteSel][0];
    const entry = idx !== undefined ? this.remoteEntries[idx] : undefined;
    if (!entry || this.state !== 'streaming') return;
    this.promptDialog(tl('rename_title', { name: entry.name }), tl('new_name'), entry.name, (name) => {
      if (!name || name === entry.name) return;
      const id = this.op(tl('rename_op', { old: entry.name, name }), true);
      this.post({ c: 'ftRename', id, path: joinRemote(this.remotePathValue, entry.name, this.sep), newName: name });
    });
  }

  private deleteRemote(): void {
    const entries = [...this.remoteSel].map((i) => this.remoteEntries[i]).filter((e): e is FtEntry => !!e);
    if (!entries.length || this.state !== 'streaming') return;
    const what = entries.length === 1 ? `"${entries[0]!.name}"` : tn('n_items', entries.length);
    this.confirmDialog(tl('delete_remote_confirm', { what }), () => {
      for (const e of entries) {
        const path = joinRemote(this.remotePathValue, e.name, this.sep);
        const id = this.op(tl('delete_op', { name: e.name }), true);
        if (isDirKind(e.kind)) this.post({ c: 'ftRemoveDir', id, path });
        else this.post({ c: 'ftRemoveFile', id, path, fileNum: 0 });
      }
    });
  }

  // --- local pane (File System Access mode) ----------------------------------

  private async openLocalFolder(): Promise<void> {
    const picker = (window as PickerWindow).showDirectoryPicker;
    if (!picker) return;
    try {
      // 'read' mode: Chrome's blocklist refuses readwrite grants on Desktop/
      // Documents etc. ("contains system files"). Reading is allowed almost
      // everywhere; write permission is requested per-folder when receiving.
      const handle = await picker.call(window, { mode: 'read' });
      this.rootHandle = handle;
      this.dirStack = [handle];
      this.localSel.clear();
      this.localAnchor = -1;
      await this.refreshLocal();
    } catch {
      return; // user cancelled the picker
    }
  }

  // True when Chrome grants (or the user approves) write access to `dir`.
  // Blocklisted folders auto-deny — callers fall back to Downloads saving.
  private async ensureWritable(dir: FileSystemDirectoryHandle): Promise<boolean> {
    const h = dir as PermissionedHandle;
    try {
      if (!h.queryPermission || !h.requestPermission) return true; // old impl: try and see
      if ((await h.queryPermission({ mode: 'readwrite' })) === 'granted') return true;
      return (await h.requestPermission({ mode: 'readwrite' })) === 'granted';
    } catch {
      return false;
    }
  }

  private localCwd(): FileSystemDirectoryHandle | undefined {
    return this.dirStack[this.dirStack.length - 1];
  }

  private async localEnter(entry: LocalEntry): Promise<void> {
    if (entry.kind !== 'dir' || !entry.handle) return;
    this.dirStack.push(entry.handle as FileSystemDirectoryHandle);
    this.localSel.clear();
    this.localAnchor = -1;
    await this.refreshLocal();
  }

  private async localUp(): Promise<void> {
    if (!this.fsMode || this.dirStack.length <= 1) return;
    this.dirStack.pop();
    this.localSel.clear();
    this.localAnchor = -1;
    await this.refreshLocal();
  }

  private async refreshLocal(): Promise<void> {
    if (!this.fsMode) {
      this.renderLocal();
      return;
    }
    const cwd = this.localCwd();
    if (!cwd) {
      this.renderLocal();
      return;
    }
    const gen = ++this.localListGen;
    const dirs: LocalEntry[] = [];
    const files: LocalEntry[] = [];
    try {
      for await (const handle of cwd.values()) {
        if (!this.showHidden && handle.name.startsWith('.')) continue;
        if (handle.kind === 'directory') {
          dirs.push({ kind: 'dir', name: handle.name, size: 0, modifiedMs: 0, handle: handle as FileSystemDirectoryHandle });
        } else {
          files.push({ kind: 'file', name: handle.name, size: 0, modifiedMs: 0, handle: handle as FileSystemFileHandle });
        }
      }
      await Promise.all(
        files.map(async (f) => {
          try {
            const file = await (f.handle as FileSystemFileHandle).getFile();
            f.size = file.size;
            f.modifiedMs = file.lastModified;
            f.file = file;
          } catch {
            /* unreadable entry — keep name only */
          }
        }),
      );
    } catch (e) {
      this.opts.toast(tl('folder_read_failed', { message: (e as Error).message }));
    }
    if (gen !== this.localListGen) return; // superseded by a newer navigation
    const cmp = (a: LocalEntry, b: LocalEntry): number =>
      a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
    dirs.sort(cmp);
    files.sort(cmp);
    this.localEntries = [...dirs, ...files];
    this.el.localPath.value = this.dirStack.map((d) => d.name).join('/');
    this.el.localSub.textContent = `browser · ${this.rootHandle?.name ?? ''}`;
    this.renderLocal();
    this.updateButtons();
  }

  // --- local pane (fallback staging mode) -------------------------------------

  private pickStagedFiles(): void {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.addEventListener('change', () => this.stageFiles(Array.from(input.files ?? [])));
    input.click();
  }

  // "Send files…": pick files from anywhere (Desktop included — the file
  // picker has none of the directory blocklist restrictions) and upload them
  // straight to the current remote folder.
  private pickAndSendFiles(): void {
    if (this.state !== 'streaming') {
      this.opts.toast(tl('not_connected'));
      return;
    }
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.addEventListener('change', () => {
      const files = Array.from(input.files ?? []);
      if (files.length) void this.uploadFiles(files.map((f) => ({ rel: f.name, file: f })));
    });
    input.click();
  }

  private stageFiles(files: File[]): void {
    if (this.fsMode) return; // FS mode: local pane is a real folder, drops go via Send
    if (!files.length) return;
    this.staged.push(...files);
    this.renderLocal();
    this.updateButtons();
  }

  private renderLocal(): void {
    if (!this.fsMode) {
      this.localEntries = this.staged.map((f) => ({
        kind: 'file' as const,
        name: f.name,
        size: f.size,
        modifiedMs: f.lastModified,
        file: f,
      }));
    }
    const rows = this.localEntries.map((e, i) => {
      const icon = e.kind === 'dir' ? 'folder' : 'file';
      const size = e.kind === 'dir' ? '—' : formatBytes(e.size);
      const mod = e.modifiedMs ? new Date(e.modifiedMs).toLocaleString() : '—';
      return `<tr data-idx="${i}" class="${this.localSel.has(i) ? 'rd-sel' : ''}${e.name.startsWith('.') ? ' rd-hiddenfile' : ''}">
        <td><span class="rd-ft-ic">${iconHtml(icon)}</span>${escapeHtml(e.name)}</td>
        <td class="rd-ft-col-size">${size}</td><td class="rd-ft-col-mod">${mod}</td></tr>`;
    });
    this.el.localBody.innerHTML = rows.join('');
    this.updateLocalEmpty();
    const selSize = [...this.localSel].reduce((s, i) => s + (this.localEntries[i]?.size ?? 0), 0);
    this.el.localFoot.textContent = tl('n_of_m_selected', { n: this.localSel.size, m: this.localEntries.length }) +
      (selSize > 0 ? ` · ${formatBytes(selSize)}` : '');
  }

  private updateLocalEmpty(): void {
    const empty = this.el.localEmpty;
    if (this.localEntries.length > 0) {
      empty.hidden = true;
      return;
    }
    empty.hidden = false;
    if (this.fsMode) {
      empty.innerHTML = this.rootHandle
        ? htl('empty_folder')
        : `<div class="rd-ft-cta">${iconHtml('folderOpen')}<p>${htl('open_local_folder_or_use')}
           <b>${htl('send_files')}</b> ${htl('above_or_drag_files_onto')}</p>
           <button type="button" class="rd-ft-cta-btn" id="rd-ft-cta-open">${htl('open_folder')}</button></div>`;
      empty.querySelector('#rd-ft-cta-open')?.addEventListener('click', () => void this.openLocalFolder());
    } else {
      empty.innerHTML = `<div class="rd-ft-cta">${iconHtml('folderOpen')}<p>${htl('drop_files_here_or_add')}<br>
        ${htl('received_files_saved_to_downloads')}</p>
        <button type="button" class="rd-ft-cta-btn" id="rd-ft-cta-add">${htl('add_files')}</button></div>`;
      empty.querySelector('#rd-ft-cta-add')?.addEventListener('click', () => this.pickStagedFiles());
    }
  }

  // --- transfers: download ---------------------------------------------------

  private startDownloads(): void {
    const entries = [...this.remoteSel].map((i) => this.remoteEntries[i]).filter((e): e is FtEntry => !!e);
    void this.startDownloadOf(entries);
  }

  private async startDownloadOf(entries: FtEntry[]): Promise<void> {
    if (this.state !== 'streaming' || !entries.length) return;
    let destDir = this.fsMode ? this.localCwd() ?? null : null;
    if (destDir && !(await this.ensureWritable(destDir))) {
      destDir = null;
      this.log(tl('chrome_refused_write_access_this'));
      this.opts.toast(tl('saving_your_downloads_folder_folder'));
    } else if (this.fsMode && !destDir) {
      this.log(tl('no_local_folder_open_received'));
    }
    for (const entry of entries) {
      const id = this.nextId++;
      const job: Job = {
        id,
        kind: 'download',
        label: entry.name,
        status: 'starting',
        totalSize: isDirKind(entry.kind) ? 0 : entry.size,
        doneBytes: 0,
        fileCount: isDirKind(entry.kind) ? 0 : 1,
        currentFile: entry.name,
        policy: null,
        startMs: nowMs(),
        lastBytes: 0,
        lastSampleMs: nowMs(),
        speedBps: 0,
        destDir,
        curFileNum: -1,
        memBuf: [],
        writeChain: Promise.resolve(),
      };
      this.jobs.unshift(job);
      this.post({
        c: 'ftSend',
        id,
        path: joinRemote(this.remotePathValue, entry.name, this.sep),
        includeHidden: this.showHidden,
        fileNum: 0,
      });
      this.log(`Receiving "${entry.name}"…`);
      if (!destDir && entry.size > MEM_DOWNLOAD_WARN) {
        this.log(tl('buffered_warning', { name: entry.name, size: formatBytes(entry.size) }));
      }
    }
    this.renderJobs();
  }

  // The peer announces each file with a digest and waits for our confirm.
  private async onFtDigest(ev: Extract<SessionEvent, { t: 'ftDigest' }>): Promise<void> {
    const job = this.jobById(ev.id);
    if (!job) return;
    if (job.kind === 'upload') {
      // Upload: the target exists on the remote side — our call.
      const decision = await this.resolveConflict(job, this.uploadFileName(job, ev.fileNum), ev);
      if (decision === 'cancel') {
        this.cancelJob(job);
        job.confirmWaiter?.('cancelled');
        return;
      }
      this.post({ c: 'ftConfirm', id: job.id, fileNum: ev.fileNum, skip: decision === 'skip', offsetBlk: 0 });
      job.confirmWaiter?.(decision === 'skip' ? { skip: true, offsetBytes: 0 } : { skip: false, offsetBytes: 0 });
      return;
    }
    // Download: digest describes the remote source file about to be sent.
    job.status = 'running';
    const rel = job.remoteFiles?.[ev.fileNum]?.name ?? job.label;
    job.currentFile = rel;
    let decision: 'overwrite' | 'skip' | 'cancel' = 'overwrite';
    if (job.destDir && (await this.localTargetExists(job.destDir, rel))) {
      decision = await this.resolveConflict(job, rel, ev);
    }
    if (decision === 'cancel') {
      this.cancelJob(job);
      return;
    }
    this.post({ c: 'ftConfirm', id: job.id, fileNum: ev.fileNum, skip: decision === 'skip', offsetBlk: 0 });
    if (decision === 'skip') this.log(tl('skipped_exists', { name: rel }));
    this.renderJobs();
  }

  private async localTargetExists(dir: FileSystemDirectoryHandle, rel: string): Promise<boolean> {
    try {
      const parts = rel.split('/').filter(Boolean);
      let d = dir;
      for (const part of parts.slice(0, -1)) d = await d.getDirectoryHandle(part);
      await d.getFileHandle(parts[parts.length - 1]!);
      return true;
    } catch {
      return false;
    }
  }

  private onFtBlock(ev: Extract<SessionEvent, { t: 'ftBlock' }>): void {
    const job = this.jobById(ev.id);
    if (!job || job.kind !== 'download' || job.status === 'cancelled' || job.status === 'error') return;
    job.writeChain = (job.writeChain ?? Promise.resolve()).then(async () => {
      try {
        if (job.curFileNum !== ev.fileNum) {
          await this.closeJobFile(job, true);
          job.curFileNum = ev.fileNum;
          const rel = job.remoteFiles?.[ev.fileNum]?.name ?? job.label;
          job.currentFile = rel;
          if (job.destDir) {
            job.writable = await this.createLocalWritable(job.destDir, rel);
          } else {
            job.memBuf = [];
          }
        }
        if (ev.data.length === 0) {
          await this.closeJobFile(job, true);
          job.curFileNum = -1;
          return;
        }
        if (job.writable) await job.writable.write(ev.data as Uint8Array<ArrayBuffer>);
        else job.memBuf?.push(ev.data);
        job.doneBytes += ev.data.length;
      } catch (e) {
        job.status = 'error';
        job.error = `write failed: ${(e as Error).message}`;
        this.post({ c: 'ftCancel', id: job.id });
        this.log(tl('receive_failed', { label: job.label, error: job.error }));
        this.renderJobs();
      }
    });
  }

  private async createLocalWritable(
    dir: FileSystemDirectoryHandle,
    rel: string,
  ): Promise<FileSystemWritableFileStream> {
    const parts = rel.split('/').filter(Boolean);
    let d = dir;
    for (const part of parts.slice(0, -1)) d = await d.getDirectoryHandle(part, { create: true });
    const fh = await d.getFileHandle(parts[parts.length - 1]!, { create: true });
    return fh.createWritable(); // data commits atomically on close()
  }

  // Finalize the file currently streaming into `job` (if any).
  private async closeJobFile(job: Job, commit: boolean): Promise<void> {
    const w = job.writable;
    job.writable = undefined;
    if (w) {
      try {
        if (commit) await w.close();
        else await w.abort();
      } catch {
        /* already closed */
      }
    }
    if (!job.destDir && commit && job.memBuf && job.curFileNum !== undefined && job.curFileNum >= 0) {
      const rel = job.remoteFiles?.[job.curFileNum]?.name ?? job.label;
      this.saveBlobToDownloads(rel.split('/').join('_'), job.memBuf);
    }
    job.memBuf = [];
  }

  private saveBlobToDownloads(name: string, chunks: Uint8Array[]): void {
    const blob = new Blob(chunks as BlobPart[]);
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }

  private onFtDone(id: number, fileNum: number): void {
    const opInfo = this.ops.get(id);
    if (opInfo) {
      this.ops.delete(id);
      this.log(tl('op_done', { desc: opInfo.desc }));
      if (opInfo.refresh) this.readRemote(this.remotePathValue);
      return;
    }
    const job = this.jobById(id);
    if (!job) return;
    if (job.kind === 'download') {
      job.writeChain = (job.writeChain ?? Promise.resolve()).then(async () => {
        await this.closeJobFile(job, true);
        job.curFileNum = -1;
        if (job.status !== 'error' && job.status !== 'cancelled') {
          job.status = 'done';
          job.doneBytes = Math.max(job.doneBytes, job.totalSize);
          this.log(`Received "${job.label}" (${formatBytes(job.doneBytes)}).`);
          this.opts.toast(tl('received_label', { label: job.label }));
          if (this.fsMode) void this.refreshLocal();
        }
        this.renderJobs();
      });
    } else {
      // Peer's write side echoed done — upload fully flushed on the far end.
      job.peerDone = true;
      if (job.status === 'running' || job.status === 'starting') {
        job.status = 'done';
        this.log(`Sent "${job.label}" (${formatBytes(job.doneBytes)}).`);
        this.opts.toast(tl('sent_label', { label: job.label }));
        this.readRemote(this.remotePathValue);
      }
      this.renderJobs();
    }
    void fileNum;
  }

  private onFtError(id: number, fileNum: number, error: string): void {
    const opInfo = this.ops.get(id);
    if (opInfo) {
      this.ops.delete(id);
      this.log(tl('op_failed_dash', { desc: opInfo.desc, error }));
      this.opts.toast(tl('op_failed', { desc: opInfo.desc, error }));
      if (opInfo.refresh) this.readRemote(this.remotePathValue);
      return;
    }
    const job = this.jobById(id);
    if (!job) return;
    // "skipped" for a single-file job is the peer acknowledging our skip.
    if (/^skipped$/i.test(error)) {
      job.status = 'done';
      this.renderJobs();
      return;
    }
    job.status = 'error';
    job.error = error;
    job.confirmWaiter?.('cancelled');
    job.sentWaiter?.(0);
    void this.closeJobFile(job, false);
    this.log(`Transfer "${job.label}" failed${fileNum >= 0 ? ` (file ${fileNum + 1})` : ''}: ${error}`);
    this.renderJobs();
  }

  // --- transfers: upload -----------------------------------------------------

  private async startUpload(): Promise<void> {
    if (this.state !== 'streaming') return;
    const files: UploadFile[] = [];
    const selected = [...this.localSel].map((i) => this.localEntries[i]).filter((e): e is LocalEntry => !!e);
    for (const entry of selected) {
      if (entry.kind === 'file') {
        const f = entry.file ?? (entry.handle ? await (entry.handle as FileSystemFileHandle).getFile() : undefined);
        if (f) files.push({ rel: entry.name, file: f });
      } else if (entry.handle) {
        await this.collectDir(entry.handle as FileSystemDirectoryHandle, entry.name, files);
      }
    }
    await this.uploadFiles(files);
  }

  private async collectDir(dir: FileSystemDirectoryHandle, prefix: string, out: UploadFile[]): Promise<void> {
    for await (const handle of dir.values()) {
      if (!this.showHidden && handle.name.startsWith('.')) continue;
      if (handle.kind === 'directory') {
        await this.collectDir(handle as FileSystemDirectoryHandle, `${prefix}/${handle.name}`, out);
      } else {
        try {
          out.push({ rel: `${prefix}/${handle.name}`, file: await (handle as FileSystemFileHandle).getFile() });
        } catch {
          this.log(tl('skipping_unreadable', { name: `${prefix}/${handle.name}` }));
        }
      }
    }
  }

  private async uploadFiles(files: UploadFile[]): Promise<void> {
    if (this.state !== 'streaming' || !files.length) return;
    const id = this.nextId++;
    const totalSize = files.reduce((s, f) => s + f.file.size, 0);
    const label = files.length === 1 ? files[0]!.rel : tn('n_files', files.length);
    const job: Job = {
      id,
      kind: 'upload',
      label,
      status: 'running',
      totalSize,
      doneBytes: 0,
      fileCount: files.length,
      currentFile: files[0]!.rel,
      policy: null,
      startMs: nowMs(),
      lastBytes: 0,
      lastSampleMs: nowMs(),
      speedBps: 0,
      files,
    };
    this.jobs.unshift(job);
    this.renderJobs();
    this.log(tl('sending_to', { label, path: this.remotePathValue || tl('home') }));

    this.post({
      c: 'ftReceive',
      id,
      path: this.remotePathValue,
      files: files.map((f) => ({
        name: f.rel,
        size: f.file.size,
        modifiedSec: Math.floor(f.file.lastModified / 1000),
      })),
      totalSize,
      fileNum: 0,
    });

    try {
      for (let i = 0; i < files.length; i++) {
        if (this.jobStopped(job)) return;
        const { rel, file } = files[i]!;
        job.currentFile = rel;
        this.renderJobs();
        this.post({
          c: 'ftDigest',
          id,
          fileNum: i,
          fileSize: file.size,
          lastModifiedSec: Math.floor(file.lastModified / 1000),
        });
        // Wait for the go-ahead: either the peer's direct send_confirm (target
        // absent) or our own decision after its digest (handled in onFtDigest,
        // which resolves this same waiter).
        const confirm = await new Promise<{ skip: boolean; offsetBytes: number } | 'cancelled'>((resolve) => {
          job.confirmWaiter = resolve;
        });
        job.confirmWaiter = undefined;
        if (confirm === 'cancelled' || this.jobStopped(job)) return;
        if (confirm.skip) {
          job.doneBytes += file.size;
          this.log(tl('skipped_exists_remote', { name: rel }));
          continue;
        }
        let offset = confirm.offsetBytes;
        job.doneBytes += Math.min(offset, file.size);
        while (offset < file.size) {
          if (this.jobStopped(job)) return;
          const end = Math.min(offset + FT_BLOCK_SIZE, file.size);
          const buf = new Uint8Array(await file.slice(offset, end).arrayBuffer());
          const buffered = await new Promise<number>((resolve) => {
            job.sentWaiter = resolve;
            this.post({ c: 'ftBlock', id, fileNum: i, data: buf, blkId: 0 }, [buf.buffer]);
          });
          job.sentWaiter = undefined;
          job.doneBytes += end - offset;
          offset = end;
          // Self-clock: if the socket backlog is deep, let TCP drain.
          if (buffered > UPLOAD_BACKLOG_LIMIT) {
            await new Promise((r) => setTimeout(r, 120));
          }
        }
        // Empty block = end-of-file marker.
        this.post({ c: 'ftBlock', id, fileNum: i, data: new Uint8Array(0), blkId: 0 });
      }
      if (this.jobStopped(job)) return;
      this.post({ c: 'ftDone', id, fileNum: files.length });
      // Completion is reported when the peer echoes done (onFtDone).
    } catch (e) {
      if (!this.jobStopped(job)) {
        job.status = 'error';
        job.error = (e as Error).message;
        this.post({ c: 'ftError', id, fileNum: -1, error: job.error ?? 'read failed' });
        this.log(tl('send_failed', { label: job.label, error: job.error }));
        this.renderJobs();
      }
    }
  }

  private uploadFileName(job: Job, fileNum: number): string {
    return job.files?.[fileNum]?.rel ?? job.label;
  }

  private jobStopped(job: Job): boolean {
    return job.status === 'cancelled' || job.status === 'error' || this.state !== 'streaming';
  }

  // --- conflicts -------------------------------------------------------------

  private async resolveConflict(
    job: Job,
    name: string,
    ev: Extract<SessionEvent, { t: 'ftDigest' }>,
  ): Promise<'overwrite' | 'skip' | 'cancel'> {
    if (job.policy === 'overwrite') return 'overwrite';
    if (job.policy === 'skip') return 'skip';
    const detail =
      `${ev.fileSize ? formatBytes(ev.fileSize) : '—'} · ${tl('modified_lower')} ` +
      (ev.lastModifiedSec ? new Date(ev.lastModifiedSec * 1000).toLocaleString() : '—') +
      ' ' + (job.kind === 'upload' ? tl('on_remote') : tl('incoming'));
    const decision = await new Promise<ConflictDecision>((resolve) => {
      this.conflicts.push({ jobId: job.id, name, detail, resolve });
      this.pumpConflicts();
    });
    if (decision.applyAll && decision.action !== 'cancel') job.policy = decision.action;
    return decision.action;
  }

  private pumpConflicts(): void {
    if (this.conflictOpen) return;
    const next = this.conflicts.shift();
    if (!next) return;
    this.conflictOpen = true;
    const d = this.el.dialog;
    d.hidden = false;
    d.innerHTML = `
      <div class="rd-ft-dlg">
        <h3>${htl('file_already_exists')}</h3>
        <p class="rd-ft-dlg-file">${iconHtml('file')}<span>${escapeHtml(next.name)}</span></p>
        <p class="rd-ft-dlg-detail">${escapeHtml(next.detail)}</p>
        <label class="rd-save rd-ft-dlg-all"><input type="checkbox" id="rd-ft-dlg-applyall"><span>${htl('apply_all_files_in_this')}</span></label>
        <div class="rd-ft-dlg-btns">
          <button type="button" class="rd-ft-dlg-btn rd-ft-dlg-primary" data-act="overwrite">${htl('overwrite')}</button>
          <button type="button" class="rd-ft-dlg-btn" data-act="skip">${htl('skip')}</button>
          <button type="button" class="rd-ft-dlg-btn rd-ft-dlg-danger" data-act="cancel">${htl('cancel_transfer')}</button>
        </div>
      </div>`;
    const finish = (action: ConflictDecision['action']): void => {
      const applyAll = (d.querySelector('#rd-ft-dlg-applyall') as HTMLInputElement | null)?.checked ?? false;
      d.hidden = true;
      d.innerHTML = '';
      this.conflictOpen = false;
      next.resolve({ action, applyAll });
      this.pumpConflicts();
    };
    d.querySelectorAll<HTMLButtonElement>('[data-act]').forEach((b) =>
      b.addEventListener('click', () => finish(b.dataset.act as ConflictDecision['action'])),
    );
  }

  // --- small dialogs ---------------------------------------------------------

  private promptDialog(title: string, placeholder: string, initial: string, cb: (v: string | null) => void): void {
    const d = this.el.dialog;
    d.hidden = false;
    d.innerHTML = `
      <div class="rd-ft-dlg">
        <h3>${escapeHtml(title)}</h3>
        <input type="text" class="rd-ft-dlg-input" id="rd-ft-dlg-input" placeholder="${escapeHtml(placeholder)}"
               value="${escapeHtml(initial)}" spellcheck="false" autocomplete="off">
        <div class="rd-ft-dlg-btns">
          <button type="button" class="rd-ft-dlg-btn rd-ft-dlg-primary" data-act="ok">${htl('ok')}</button>
          <button type="button" class="rd-ft-dlg-btn" data-act="no">${htl('cancel')}</button>
        </div>
      </div>`;
    const input = q<HTMLInputElement>(d, '#rd-ft-dlg-input');
    input.focus();
    input.select();
    const finish = (ok: boolean): void => {
      const v = ok ? input.value.trim() : null;
      d.hidden = true;
      d.innerHTML = '';
      cb(v);
    };
    d.querySelector('[data-act="ok"]')!.addEventListener('click', () => finish(true));
    d.querySelector('[data-act="no"]')!.addEventListener('click', () => finish(false));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') finish(true);
      if (e.key === 'Escape') finish(false);
    });
  }

  private confirmDialog(text: string, cb: () => void): void {
    const d = this.el.dialog;
    d.hidden = false;
    d.innerHTML = `
      <div class="rd-ft-dlg">
        <h3>${htl('are_you_sure')}</h3>
        <p class="rd-ft-dlg-detail">${escapeHtml(text)}</p>
        <div class="rd-ft-dlg-btns">
          <button type="button" class="rd-ft-dlg-btn rd-ft-dlg-danger" data-act="ok">${htl('delete')}</button>
          <button type="button" class="rd-ft-dlg-btn" data-act="no">${htl('cancel')}</button>
        </div>
      </div>`;
    const finish = (ok: boolean): void => {
      d.hidden = true;
      d.innerHTML = '';
      if (ok) cb();
    };
    d.querySelector('[data-act="ok"]')!.addEventListener('click', () => finish(true));
    d.querySelector('[data-act="no"]')!.addEventListener('click', () => finish(false));
  }

  // --- job list / log --------------------------------------------------------

  private jobById(id: number): Job | undefined {
    return this.jobs.find((j) => j.id === id);
  }

  private cancelJob(job: Job): void {
    if (job.status === 'done' || job.status === 'cancelled') return;
    job.status = 'cancelled';
    this.post({ c: 'ftCancel', id: job.id });
    job.confirmWaiter?.('cancelled');
    job.sentWaiter?.(0);
    void this.closeJobFile(job, false);
    this.log(`Cancelled "${job.label}".`);
    this.renderJobs();
  }

  private tickJobs(): void {
    let active = false;
    const t = nowMs();
    for (const job of this.jobs) {
      if (job.status !== 'running' && job.status !== 'starting') continue;
      active = true;
      const dt = t - job.lastSampleMs;
      if (dt >= 900) {
        const inst = ((job.doneBytes - job.lastBytes) / dt) * 1000;
        job.speedBps = job.speedBps === 0 ? inst : job.speedBps * 0.5 + inst * 0.5;
        job.lastBytes = job.doneBytes;
        job.lastSampleMs = t;
      }
    }
    if (active) this.renderJobs();
  }

  private renderJobs(): void {
    const wrap = this.el.jobsWrap;
    if (!this.jobs.length) {
      wrap.innerHTML = '<div class="rd-ft-none">' + htl('no_transfers_yet') + '</div>';
      return;
    }
    wrap.innerHTML = this.jobs
      .map((j) => {
        const pct = j.totalSize > 0 ? Math.min(100, Math.round((j.doneBytes / j.totalSize) * 100)) : j.status === 'done' ? 100 : 0;
        const dirIcon = j.kind === 'download' ? iconHtml('sendLeft') : iconHtml('sendRight');
        const status =
          j.status === 'running' || j.status === 'starting'
            ? tl('bytes_of', { done: formatBytes(j.doneBytes), total: j.totalSize ? formatBytes(j.totalSize) : '?' }) +
              (j.speedBps > 0 ? ` · ${formatBytes(j.speedBps)}/s` : '')
            : j.status === 'done'
              ? tl('completed_size', { size: formatBytes(Math.max(j.doneBytes, j.totalSize)) })
              : j.status === 'cancelled'
                ? tl('cancelled')
                : tl('failed_error', { error: j.error ?? tl('unknown_error') });
        const cancellable = j.status === 'running' || j.status === 'starting';
        return `<div class="rd-ft-job rd-ft-job-${j.status}">
          <span class="rd-ft-job-dir">${dirIcon}</span>
          <div class="rd-ft-job-main">
            <div class="rd-ft-job-top"><span class="rd-ft-job-name">${escapeHtml(j.label)}</span>
              <span class="rd-ft-job-status">${escapeHtml(status)}</span></div>
            <div class="rd-ft-bar"><div class="rd-ft-bar-fill" style="width:${pct}%"></div></div>
          </div>
          ${cancellable ? `<button type="button" class="rd-btn rd-ft-job-cancel" data-job="${j.id}" title="${htl('cancel')}">${iconHtml('close')}</button>` : ''}
        </div>`;
      })
      .join('');
    wrap.querySelectorAll<HTMLButtonElement>('[data-job]').forEach((b) =>
      b.addEventListener('click', () => {
        const job = this.jobById(Number(b.dataset.job));
        if (job) this.cancelJob(job);
      }),
    );
  }

  private log(message: string): void {
    const line = document.createElement('div');
    line.className = 'rd-ft-logline';
    const ts = new Date().toLocaleTimeString();
    line.textContent = `${ts}  ${message}`;
    this.el.logWrap.appendChild(line);
    this.el.logWrap.scrollTop = this.el.logWrap.scrollHeight;
  }

  private updateButtons(): void {
    const connected = this.state === 'streaming';
    this.el.btnSend.disabled = !connected || this.localSel.size === 0;
    this.el.btnRecv.disabled = !connected || this.remoteSel.size === 0;
  }
}
