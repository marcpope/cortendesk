// Terminal page: the web client opened with ?mode=terminal. No remote screen;
// the page signs in on a terminal connection of its own (password, saved
// password, 2FA) and shows the terminal full-page.
import type { SessionConfig, SessionState } from '../core/contracts';
import { htl, tl } from '../i18n';
import {
  STATE_LABEL,
  addressBookReady,
  buildSessionConfig,
  clearSavedHash,
  loadSavedHash,
  loggedOutFromSearch,
  newSessionId,
  normalizePeerId,
  overlayVersion,
  peerIdFromSearch,
  resolveWorkerUrl,
  saveSavedHash,
  setLoggedOutParam,
  type RdGlobalConfig,
} from './common';
import {
  consumeTwoFactorCode,
  loginErrorText,
  renderConnectCard,
  showTwoFactor,
  type ConnectCardEls,
} from './connect-card';
import { TerminalPanel, type TerminalPageHooks } from './terminal-panel';

export function terminalModeFromSearch(search: string): boolean {
  return new URLSearchParams(search).get('mode') === 'terminal';
}

/** Same key the terminal panel keeps the persistent service id under. */
function serviceKey(peerId: string): string {
  return `rd:terminal-service:${peerId}`;
}

function hasStoredService(peerId: string): boolean {
  try {
    return !!localStorage.getItem(serviceKey(peerId));
  } catch {
    return false;
  }
}

type Els = ConnectCardEls & {
  root: HTMLElement;
  viewport: HTMLElement;
  overlay: HTMLElement;
  toast: HTMLElement;
  keepCheckbox: HTMLInputElement;
  keepRow: HTMLElement;
};

type PanelLike = Pick<TerminalPanel, 'open' | 'connect' | 'setTitle' | 'submitTwoFactor' | 'destroy' | 'connected'>;

export class TerminalApp {
  private cfg: RdGlobalConfig | undefined;
  private el!: Els;
  private panel!: PanelLike;
  private fixedPeerId = '';
  private peerId = '';
  /** Handed to the panel for one connect, then dropped (it may hold the typed password). */
  private pendingConfig: SessionConfig | null = null;
  private pendingHashHex: string | undefined;
  private connectedWithSavedHash = false;
  private connectedWithAddressBook = false;
  private addressBookRefused = false;
  private toastTimer: ReturnType<typeof setTimeout> | undefined;

  mount(): void {
    this.cfg = (window as Window & { __RD__?: RdGlobalConfig }).__RD__;
    this.ensureDom();
    const attr = document.querySelector('script[data-rd-worker]')?.getAttribute('data-rd-worker');
    const workerUrl = resolveWorkerUrl(this.cfg?.workerUrl, attr, import.meta.url);

    const keep = `
      <label class="rd-save" id="rd-terminal-keep-row">
        <input type="checkbox" id="rd-terminal-keep">
        <span>${htl('keep_terminal_available_after_disconnect')}</span>
      </label>`;
    Object.assign(this.el, renderConnectCard(this.el.overlay, {
      tagline: `${htl('remote_terminal')} ${overlayVersion(this.cfg)}`,
      extra: keep,
    }));
    this.el.keepCheckbox = this.el.overlay.querySelector<HTMLInputElement>('#rd-terminal-keep')!;
    this.el.keepRow = this.el.overlay.querySelector<HTMLElement>('#rd-terminal-keep-row')!;

    this.panel = new TerminalPanel({
      root: this.el.viewport,
      workerUrl,
      getConfig: () => this.pendingConfig,
      toast: (message) => this.toast(message),
      page: this.hooks(),
    });
    this.panel.open();

    this.el.connectBtn.addEventListener('click', () => this.onConnectClick());
    this.el.twoFactorBtn.addEventListener('click', () => this.submitTwoFactor());
    const enter = (fn: () => void) => (e: KeyboardEvent): void => {
      if (e.key === 'Enter') fn();
    };
    this.el.passwordInput.addEventListener('keydown', enter(() => this.onConnectClick()));
    this.el.peerIdInput.addEventListener('keydown', enter(() => this.onConnectClick()));
    this.el.twoFactorInput.addEventListener('keydown', enter(() => this.submitTwoFactor()));
    this.el.peerIdInput.addEventListener('change', () => {
      this.el.peerIdInput.value = normalizePeerId(this.el.peerIdInput.value);
      this.hydrate(this.el.peerIdInput.value);
    });

    this.fixedPeerId = normalizePeerId(this.cfg?.peerId ?? '') || peerIdFromSearch(location.search) || '';
    if (this.fixedPeerId) {
      this.el.fieldId.hidden = true;
      this.el.overlayTarget.hidden = false;
      this.el.overlayPeer.textContent = this.fixedPeerId;
      this.el.peerIdInput.value = this.fixedPeerId;
      this.panel.setTitle(this.fixedPeerId);
      this.hydrate(this.fixedPeerId);
      this.el.passwordInput.focus();
    } else {
      this.el.fieldId.hidden = false;
      this.el.overlayTarget.hidden = true;
      this.el.peerIdInput.focus();
    }

    // Saved password (or an address-book password): sign straight in, as the desktop page does.
    if (
      this.fixedPeerId &&
      !loggedOutFromSearch(location.search) &&
      (loadSavedHash(this.fixedPeerId) || addressBookReady(this.cfg, this.fixedPeerId))
    ) {
      this.onConnectClick();
    }

    window.addEventListener('beforeunload', () => this.panel.destroy());
  }

  private ensureDom(): void {
    let root = document.getElementById('rd-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'rd-root';
      document.body.appendChild(root);
    }
    root.dataset.mode = 'terminal';
    root.dataset.state = 'closed';
    // The desktop page's toolbar, canvas and stats have no use here.
    for (const id of ['rd-toolbar', 'rd-canvas', 'rd-stats']) document.getElementById(id)?.remove();
    let viewport = document.getElementById('rd-viewport');
    if (!viewport) {
      viewport = document.createElement('div');
      viewport.id = 'rd-viewport';
    }
    root.appendChild(viewport);
    const make = (id: string): HTMLElement => {
      let n = document.getElementById(id);
      if (!n) {
        n = document.createElement('div');
        n.id = id;
      }
      viewport.appendChild(n);
      return n;
    };
    const overlay = make('rd-overlay');
    const toast = make('rd-toast');
    this.el = { root, viewport, overlay, toast } as Els;
  }

  private hydrate(peerId: string): void {
    const has = !!peerId && loadSavedHash(peerId) !== null;
    this.el.saveCheckbox.checked = has;
    this.el.passwordInput.value = '';
    this.el.passwordInput.placeholder = has
      ? tl('saved_password_click_change')
      : !this.addressBookRefused && addressBookReady(this.cfg, peerId)
        ? tl('address_book_password_ready')
        : tl('enter_password');
    this.el.keepCheckbox.checked = !!peerId && hasStoredService(peerId);
  }

  private hooks(): TerminalPageHooks {
    return {
      persistent: () => this.el.keepCheckbox.checked,
      onProgress: (state, detail) => this.onProgress(state, detail),
      onLive: () => this.onLive(),
      onTwoFactor: (wrong) => this.onTwoFactor(wrong),
      onCredentials: (hashHex, fromAddressBook) => {
        // An address-book credential is never offered to "Save password".
        if (fromAddressBook) this.connectedWithAddressBook = true;
        else this.pendingHashHex = hashHex;
      },
      onLoginError: (message) => this.onLoginError(message),
      onEnded: (message, byUser) => this.onEnded(message, byUser),
    };
  }

  private onConnectClick(): void {
    if (this.panel.connected) return;
    const peerId = normalizePeerId(this.el.peerIdInput.value || this.fixedPeerId);
    if (!peerId) {
      this.setError(tl('enter_device_id'));
      return;
    }
    if (!this.cfg) {
      this.setError('Missing window.__RD__ configuration');
      return;
    }
    this.peerId = peerId;
    this.setError(null);
    setLoggedOutParam(false);

    const typed = this.el.passwordInput.value;
    this.el.passwordInput.value = '';
    const saved = loadSavedHash(peerId);
    if (!this.el.saveCheckbox.checked) clearSavedHash(peerId);
    this.pendingHashHex = undefined;
    this.connectedWithAddressBook = false;
    this.connectedWithSavedHash = !typed && !!saved;
    const config = this.connectedWithSavedHash
      ? buildSessionConfig(this.cfg, peerId, '', saved!, 'terminal')
      : buildSessionConfig(this.cfg, peerId, typed, undefined, 'terminal', !this.addressBookRefused);

    this.panel.setTitle(peerId);
    document.title = `${peerId} · ${tl('remote_terminal')} · CortenDesk`;
    this.setState('connecting');
    this.setBusy(true);
    this.setStatus(`${STATE_LABEL.connecting}…`);
    this.pendingConfig = { ...config, sessionId: newSessionId() };
    try {
      this.panel.connect();
    } finally {
      this.pendingConfig = null;
    }
  }

  private submitTwoFactor(): void {
    const code = consumeTwoFactorCode(this.el.twoFactorInput);
    if (!code) {
      this.setError(tl('enter_2fa_code'));
      return;
    }
    this.panel.submitTwoFactor(code);
    this.setBusy(true);
    this.setStatus(`${STATE_LABEL.login}…`);
  }

  private onProgress(state: SessionState, detail?: string): void {
    this.setState(state);
    this.showOverlay();
    this.setBusy(true);
    this.setStatus(STATE_LABEL[state] + (detail ? ` (${detail})` : '') + '…');
  }

  /** Code row in, password and options out (the connection is already open), or back. */
  private twoFactorView(on: boolean): void {
    showTwoFactor(this.el, on, !!this.fixedPeerId);
    this.el.keepRow.hidden = on;
  }

  private onLive(): void {
    this.setState('streaming');
    this.twoFactorView(false);
    if (!this.el.saveCheckbox.checked) clearSavedHash(this.peerId);
    else if (this.pendingHashHex) saveSavedHash(this.peerId, this.pendingHashHex);
    this.hideOverlay();
  }

  private onTwoFactor(wrong: boolean): void {
    this.showOverlay();
    this.setBusy(false);
    this.twoFactorView(true);
    this.el.overlayStatus.hidden = true;
    this.setError(wrong ? tl('wrong_2fa_code') : null);
    this.el.twoFactorInput.focus();
  }

  private onLoginError(message: string): void {
    this.setState('error');
    if (this.connectedWithSavedHash) {
      // The stored credential no longer works (password changed): drop it.
      clearSavedHash(this.peerId);
      this.el.saveCheckbox.checked = false;
      this.el.passwordInput.placeholder = tl('enter_password');
      this.connectedWithSavedHash = false;
    }
    let text = loginErrorText(message);
    if (this.connectedWithAddressBook) {
      // Out of date in the address book: the next Connect asks instead.
      this.addressBookRefused = true;
      this.connectedWithAddressBook = false;
      this.el.passwordInput.placeholder = tl('enter_password');
      text = `${tl('address_book_password_refused')} (${text})`;
    }
    this.backToCard();
    this.setError(text);
    this.el.passwordInput.focus();
  }

  private onEnded(message: string, byUser: boolean): void {
    this.setState(byUser ? 'closed' : 'error');
    this.backToCard();
    if (byUser) {
      setLoggedOutParam(true);
      this.setError(null);
      this.setStatus(message);
    } else {
      this.setError(loginErrorText(message));
    }
  }

  private backToCard(): void {
    this.twoFactorView(false);
    this.showOverlay();
    this.setBusy(false);
  }

  // --- card plumbing --------------------------------------------------------

  private setState(state: SessionState): void {
    this.el.root.dataset.state = state;
  }

  private showOverlay(): void {
    this.el.overlay.classList.remove('rd-hidden');
  }

  private hideOverlay(): void {
    this.el.overlay.classList.add('rd-hidden');
    this.setBusy(false);
  }

  private setBusy(busy: boolean): void {
    for (const control of [this.el.connectBtn, this.el.peerIdInput, this.el.passwordInput, this.el.twoFactorInput, this.el.twoFactorBtn]) {
      control.disabled = busy;
    }
    if (busy) this.setError(null);
    else if (!this.el.overlayStatusText.textContent) this.el.overlayStatus.hidden = true;
    this.el.overlayStatus.classList.toggle('rd-busy', busy);
  }

  private setStatus(text: string): void {
    this.el.overlayStatus.hidden = false;
    this.el.overlayStatusText.textContent = text;
  }

  private setError(message: string | null): void {
    this.el.overlayError.hidden = !message;
    this.el.overlayError.textContent = message ?? '';
    if (message) this.el.overlayStatus.hidden = true;
  }

  private toast(message: string): void {
    const t = this.el.toast;
    t.textContent = message;
    t.classList.add('rd-show');
    clearTimeout(this.toastTimer);
    this.toastTimer = setTimeout(() => t.classList.remove('rd-show'), 4000);
  }
}
