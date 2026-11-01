// RustDesk 1.5 WebRTC, page side. Workers cannot create an RTCPeerConnection,
// so the page owns it and the data channel, and the session worker drives it:
// the worker asks for an offer, hands over the device's answer and
// candidates, and sends already-fragmented messages; the page reports local
// candidates, the channel opening, its messages and its end.
import type { UiCommand } from '../core/contracts';
import type { WorkerOutMessage } from '../worker/session.worker';
import { DATA_CHANNEL_LABEL, encodeEndpoint, sdpFingerprint, type CandidateInit, type IceServerConfig } from '../core/webrtc';
import { debugEnabled, webRtcAvailable } from './common';

/** ?debug=1 or window.__rdDebug: log the WebRTC exchange to the console. */
function dbg(what: string, data?: unknown): void {
  if (typeof location === 'undefined' || !debugEnabled(location.search, globalThis)) return;
  console.log(`[rd:webrtc] ${what}`, typeof data === 'object' && data !== null && !(data instanceof Error) ? JSON.stringify(data) : (data ?? ''));
}

type Post = (cmd: UiCommand, transfer?: Transferable[]) => void;

/** Upload flow control: tell the worker when the channel's queue drains to this. */
const BUFFERED_LOW = 256 * 1024;
const BUFFERED_REPORT_MS = 50;
/** How often to look for a direct pair while a TURN connection waits. */
const TURN_RECHECK_MS = 200;

export class WebRtcHost {
  private pc: RTCPeerConnection | undefined;
  private dc: RTCDataChannel | undefined;
  private remote: Promise<void> = Promise.resolve();
  private closed = false;
  private reportTimer: ReturnType<typeof setTimeout> | undefined;
  private traced = 0;
  private lastPair = '';

  constructor(private readonly post: Post) {}

  /** True when the event was ours. */
  handle(ev: WorkerOutMessage): boolean {
    switch (ev.t) {
      case 'rtcOffer':
        void this.offer(ev.iceServers);
        return true;
      case 'rtcAnswer': {
        const pc = this.pc;
        dbg('answer', ev.sdp);
        if (pc) {
          this.remote = pc.setRemoteDescription({ type: 'answer', sdp: ev.sdp }).catch((e: unknown) => {
            dbg('answer refused', e);
            this.end();
          });
        }
        return true;
      }
      case 'rtcCandidate':
        this.addCandidate(ev.candidate);
        return true;
      case 'rtcSend':
        this.send(ev.frags);
        return true;
      case 'rtcClose':
        void this.dumpPairs().finally(() => this.close());
        return true;
      default:
        return false;
    }
  }

  close(): void {
    if (!this.closed && this.pc) dbg('close', this.pc.connectionState);
    this.closed = true;
    if (this.reportTimer !== undefined) clearTimeout(this.reportTimer);
    this.reportTimer = undefined;
    const pc = this.pc;
    this.pc = undefined;
    this.dc = undefined;
    try {
      pc?.close();
    } catch {
      // already closed
    }
  }

  private async offer(iceServers: IceServerConfig[]): Promise<void> {
    if (!webRtcAvailable()) {
      this.post({ c: 'rtcOffer' });
      return;
    }
    try {
      const pc = new RTCPeerConnection({ iceServers });
      this.pc = pc;
      // Created before the offer, so it has a data channel section; the
      // device binds the first ordered, reliable channel it is offered.
      const dc = pc.createDataChannel(DATA_CHANNEL_LABEL);
      dc.binaryType = 'arraybuffer';
      dc.bufferedAmountLowThreshold = BUFFERED_LOW;
      this.dc = dc;
      dc.onopen = () => void this.opened();
      dc.onmessage = (e: MessageEvent<ArrayBuffer>) => {
        const data = e.data;
        if (this.traced++ < 8) dbg('in', { bytes: data.byteLength, head: Array.from(new Uint8Array(data, 0, Math.min(8, data.byteLength))) });
        this.post({ c: 'rtcData', data }, [data]);
      };
      dc.onclose = () => this.end();
      dc.onbufferedamountlow = () => this.reportBuffered();
      pc.onicecandidate = (e) => {
        if (!e.candidate || !e.candidate.candidate) return; // end of gathering is never signalled
        dbg('local candidate', e.candidate.candidate);
        this.post({ c: 'rtcLocalCandidate', json: JSON.stringify(e.candidate.toJSON()) });
      };
      pc.oniceconnectionstatechange = () => dbg('ice', pc.iceConnectionState);
      pc.onconnectionstatechange = () => {
        dbg('connection', pc.connectionState);
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') this.end();
      };
      const offer = await pc.createOffer();
      const sdp = offer.sdp ?? '';
      const sessionKey = sdpFingerprint(sdp);
      if (!sessionKey || this.closed) throw new Error('no offer');
      // The offer goes out before gathering starts, so it carries no
      // candidates; every candidate trickles through the ID server.
      this.post({ c: 'rtcOffer', endpoint: encodeEndpoint({ type: 'offer', sdp, icePolicy: 'all' }), sessionKey });
      await pc.setLocalDescription(offer);
    } catch {
      this.close();
      this.post({ c: 'rtcOffer' });
    }
  }

  private addCandidate(c: CandidateInit): void {
    const pc = this.pc;
    if (!pc) return;
    // After the answer, in order. One bad candidate is no reason to stop.
    dbg('remote candidate', c.candidate);
    this.remote = this.remote.then(() => pc.addIceCandidate(c)).catch((e: unknown) => dbg('remote candidate refused', e));
  }

  private send(frags: ArrayBuffer[]): void {
    const dc = this.dc;
    if (!dc || dc.readyState !== 'open') return;
    if (this.traced++ < 8) dbg('out', frags.map((f) => ({ bytes: f.byteLength, head: Array.from(new Uint8Array(f, 0, Math.min(8, f.byteLength))) })));
    try {
      for (const f of frags) dc.send(f);
    } catch {
      this.end();
      return;
    }
    if (this.reportTimer === undefined) {
      this.reportTimer = setTimeout(() => {
        this.reportTimer = undefined;
        this.reportBuffered();
      }, BUFFERED_REPORT_MS);
    }
  }

  private reportBuffered(): void {
    if (this.dc) this.post({ c: 'rtcBuffered', bytes: this.dc.bufferedAmount });
  }

  private async opened(): Promise<void> {
    const viaTurn = await this.viaTurn();
    dbg('open', { viaTurn });
    this.post({ c: 'rtcOpen', viaTurn });
    if (!viaTurn) return;
    // The browser connects on the first pair that works, often TURN, and
    // moves to a better one when its check succeeds. A TURN connection waits
    // behind the relay, so tell the worker if a direct pair takes over while
    // it waits.
    const until = Date.now() + 15000; // the longest a relay can be held (?rtcwait)
    while (Date.now() < until && !this.closed) {
      await new Promise((r) => setTimeout(r, TURN_RECHECK_MS));
      if (this.closed) return;
      if (!(await this.viaTurn())) {
        dbg('open', { viaTurn: false, upgraded: true });
        this.post({ c: 'rtcOpen', viaTurn: false });
        return;
      }
    }
  }

  /** With ?debug=1: every candidate pair's state, before the connection goes. */
  private async dumpPairs(): Promise<void> {
    const pc = this.pc;
    if (!pc || typeof location === 'undefined' || !debugEnabled(location.search, globalThis)) return;
    try {
      const stats = await pc.getStats();
      const cand = (id?: string) => {
        const c = id ? (stats.get(id) as { candidateType?: string; address?: string; port?: number; protocol?: string } | undefined) : undefined;
        return c ? `${c.candidateType} ${c.address}:${c.port}` : '?';
      };
      stats.forEach((s: { type: string; state?: string; localCandidateId?: string; remoteCandidateId?: string; requestsSent?: number; responsesReceived?: number; requestsReceived?: number; responsesSent?: number }) => {
        if (s.type !== 'candidate-pair') return;
        dbg('pair', `${cand(s.localCandidateId)} -> ${cand(s.remoteCandidateId)} ${s.state} req ${s.requestsSent ?? 0}/${s.responsesReceived ?? 0} in ${s.requestsReceived ?? 0}/${s.responsesSent ?? 0}`);
      });
    } catch {
      // diagnostics only
    }
  }

  /** Is the selected candidate pair relayed by a TURN server? */
  private async viaTurn(): Promise<boolean> {
    const pc = this.pc;
    if (!pc) return false;
    try {
      const stats = await pc.getStats();
      let pairId: string | undefined;
      stats.forEach((s: { type: string; selectedCandidatePairId?: string }) => {
        if (s.type === 'transport' && s.selectedCandidatePairId) pairId = s.selectedCandidatePairId;
      });
      type Pair = { type: string; id: string; nominated?: boolean; state?: string; selected?: boolean; localCandidateId?: string; remoteCandidateId?: string };
      let pair: Pair | undefined;
      stats.forEach((s: Pair) => {
        if (s.type !== 'candidate-pair') return;
        if (pairId ? s.id === pairId : (s.selected || (s.nominated && s.state === 'succeeded'))) pair = s;
      });
      if (!pair) return false;
      const typeOf = (id?: string) => (id ? (stats.get(id) as { candidateType?: string } | undefined)?.candidateType : undefined);
      const label = `${typeOf(pair.localCandidateId)} -> ${typeOf(pair.remoteCandidateId)}`;
      if (label !== this.lastPair) dbg('selected pair', label);
      this.lastPair = label;
      return typeOf(pair.localCandidateId) === 'relay' || typeOf(pair.remoteCandidateId) === 'relay';
    } catch {
      return false;
    }
  }

  private end(): void {
    if (this.closed) return;
    this.close();
    this.post({ c: 'rtcClosed' });
  }
}

/** A WebRtcHost driven by this session worker. */
export function webRtcFor(worker: Worker): WebRtcHost {
  return new WebRtcHost((cmd, transfer) => worker.postMessage(cmd, transfer ?? []));
}

/** Route the worker's WebRTC events to the host; true when the event was one. */
export function isRtcEvent(host: WebRtcHost | undefined, ev: unknown): boolean {
  return host !== undefined && typeof ev === 'object' && ev !== null && host.handle(ev as WorkerOutMessage);
}
