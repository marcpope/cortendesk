import type { DisplayInfo, SessionConfig, SessionEvent, UiCommand } from '../core/contracts';
import { disconnectIndependentWorker } from './advanced-worker-lifecycle';
import { MseVideoPlayer } from '../media/mse-video';
import { htl, tl } from '../i18n';
import { isRtcEvent, webRtcFor, type WebRtcHost } from './webrtc-host';

export function buildCameraConfig(source: SessionConfig): SessionConfig {
  return { ...source, connType: 'viewCamera' };
}

type CameraPanelDeps = {
  root: HTMLElement;
  workerUrl: string;
  getConfig(): SessionConfig | null;
  toast(message: string): void;
};

type CameraWorkerEvent = SessionEvent | { t: 'h264'; data: Uint8Array; key: boolean };

export class CameraPanel {
  private shell: HTMLElement | undefined;
  private worker: Worker | undefined;
  private rtc: WebRtcHost | undefined;
  private canvas: HTMLCanvasElement | undefined;
  private video: HTMLVideoElement | undefined;
  private status: HTMLElement | undefined;
  private selector: HTMLElement | undefined;
  private mse: MseVideoPlayer | undefined;
  private connected = false;
  private currentSource = 0;

  constructor(private readonly deps: CameraPanelDeps) {}

  open(): void {
    if (this.shell) return;
    const config = this.deps.getConfig();
    if (!config) {
      this.deps.toast(tl('connect_device_before_viewing_its'));
      return;
    }

    const shell = document.createElement('section');
    shell.className = 'rd-camera-modal';
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');
    shell.setAttribute('aria-label', tl('remote_camera'));
    shell.innerHTML = `
      <div class="rd-camera-card">
        <header class="rd-camera-head">
          <div><strong>${htl('remote_camera')}</strong><span data-camera-status>${htl('connecting_2')}</span></div>
          <div data-camera-selector aria-label="${htl('camera_source')}"></div>
          <button type="button" data-camera-close aria-label="${htl('close_remote_camera')}">×</button>
        </header>
        <div class="rd-camera-viewport">
          <canvas aria-label="${htl('remote_camera_video')}"></canvas>
          <video autoplay playsinline muted hidden aria-label="${htl('remote_camera_fallback_video')}"></video>
        </div>
      </div>`;
    this.deps.root.appendChild(shell);
    this.shell = shell;
    this.canvas = shell.querySelector('canvas')!;
    this.video = shell.querySelector('video')!;
    this.status = shell.querySelector<HTMLElement>('[data-camera-status]')!;
    this.selector = shell.querySelector<HTMLElement>('[data-camera-selector]')!;
    shell.querySelector<HTMLButtonElement>('[data-camera-close]')!.addEventListener('click', () => this.destroy());

    const offscreen = this.canvas.transferControlToOffscreen();
    this.worker = new Worker(this.deps.workerUrl, { type: 'module' });
    const rtc = webRtcFor(this.worker);
    this.rtc = rtc;
    this.worker.onmessage = (event: MessageEvent<CameraWorkerEvent>) => {
      if (!isRtcEvent(rtc, event.data)) this.onEvent(event.data);
    };
    this.worker.onerror = () => this.fail(tl('camera_worker_failed'));
    this.worker.postMessage(
      { c: 'connect', config: buildCameraConfig(config), canvas: offscreen } satisfies UiCommand,
      [offscreen],
    );
  }

  private onEvent(event: CameraWorkerEvent): void {
    switch (event.t) {
      case 'state':
        this.connected = event.state === 'streaming';
        if (event.state === 'error' || event.state === 'closed') {
          this.fail(event.detail || (event.state === 'closed' ? tl('camera_disconnected') : tl('camera_connection_failed')));
        } else {
          this.setStatus(event.detail || (this.connected ? tl('live') : event.state));
        }
        break;
      case 'loginError':
        this.fail(event.message);
        break;
      case 'peerInfo':
        if (event.current !== undefined) this.currentSource = event.current;
        this.renderSources(event.displays, this.currentSource);
        break;
      case 'switchDisplay':
        this.currentSource = event.index;
        this.markSource(event.index);
        break;
      case 'h264':
        if (!this.video || !this.canvas) return;
        if (!this.mse) {
          this.mse = new MseVideoPlayer(this.video, () => {
            this.worker?.postMessage({ c: 'refresh' } satisfies UiCommand);
          });
          this.canvas.hidden = true;
          this.video.hidden = false;
        }
        this.mse.push(event.data, event.key);
        break;
    }
  }

  private renderSources(displays: DisplayInfo[], current: number): void {
    if (!this.selector) return;
    this.selector.replaceChildren();
    if (displays.length <= 1) return;
    displays.forEach((display, index) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = display.name?.trim() || tl('camera_n', { n: index + 1 });
      button.dataset.cameraIndex = String(index);
      button.classList.toggle('rd-active', index === current);
      button.addEventListener('click', () => {
        if (!this.connected) return;
        this.worker?.postMessage({ c: 'switchDisplay', index } satisfies UiCommand);
      });
      this.selector?.appendChild(button);
    });
  }

  private markSource(current: number): void {
    this.selector?.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
      button.classList.toggle('rd-active', Number(button.dataset.cameraIndex) === current);
    });
  }

  private stopWorker(): void {
    const worker = this.worker;
    this.worker = undefined;
    if (worker) disconnectIndependentWorker(worker);
    this.rtc?.close();
    this.rtc = undefined;
    this.mse?.close();
    this.mse = undefined;
    this.connected = false;
  }

  private fail(message: string): void {
    this.setStatus(message);
    this.stopWorker();
  }

  private setStatus(message: string): void {
    if (this.status) this.status.textContent = message;
  }

  destroy(): void {
    this.stopWorker();
    this.shell?.remove();
    this.shell = undefined;
    this.canvas = undefined;
    this.video = undefined;
    this.status = undefined;
    this.selector = undefined;
    this.connected = false;
  }
}
