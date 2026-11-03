import type { SessionState } from './contracts';

/**
 * Automatic reconnect after a connection that drops by itself, like the
 * official RustDesk client's auto-reconnect.
 *
 * The usual cause on Windows: someone signs in at the login or lock screen
 * (or logs off, or a UAC / RDP session switch moves the console). The
 * RustDesk service then restarts its server process in the new session,
 * and the open connection dies without a close reason. A moment later the
 * device is back and a new connection works.
 *
 * What is NOT retried, as in RustDesk (client.rs check_if_retry and
 * connection.rs send_close_reason_no_retry): a close the host announced
 * with a close_reason (closed manually by the peer, inactivity, not
 * allowed), a refused login (handled as loginError), and the user's own
 * Disconnect. Failures during the retries themselves (device offline for a
 * few seconds, relay refused) keep retrying until the deadline.
 */

/** 1 s, 2 s, 4 s ... capped at 60 s between attempts. */
export const DROP_RECONNECT_DELAYS_MS = [1_000, 2_000, 4_000, 8_000, 16_000, 32_000, 60_000] as const;
/** Give up after five minutes without a working session. */
export const DROP_RECONNECT_TIMEOUT_MS = 5 * 60_000;

/** Texts RustDesk itself never retries on (check_if_retry), for details that carry them. */
const NO_RETRY = /offline|not exist|mismatch|manually|restricted|incoming only|not allowed|inactivity|password/i;

export type SessionEnd = { state: SessionState; detail?: string; peerInitiated?: boolean };

export function shouldAutoReconnect(
  end: SessionEnd,
  ctx: { wasStreaming: boolean; userClosed: boolean },
): boolean {
  if (end.state !== 'error' && end.state !== 'closed') return false;
  if (ctx.userClosed || !ctx.wasStreaming) return false;
  if (end.peerInitiated) return false;
  return !NO_RETRY.test(end.detail ?? '');
}

/** Delay before attempt `attempt` (0-based), or null once the deadline has passed. */
export function nextReconnectDelay(
  attempt: number,
  elapsedMs: number,
  delays: readonly number[] = DROP_RECONNECT_DELAYS_MS,
  timeoutMs = DROP_RECONNECT_TIMEOUT_MS,
): number | null {
  if (elapsedMs >= timeoutMs) return null;
  const delay = delays[attempt] ?? delays[delays.length - 1]!;
  return Math.min(delay, timeoutMs - elapsedMs);
}
