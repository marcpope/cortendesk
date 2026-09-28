import { buttonMask, MouseButton, MouseType } from '../input/mouse-keyboard';

export type OsAutoLoginResult = 'sent' | 'blocked' | 'cancelled' | 'already-attempted';

export interface OsAutoLoginSinks {
  eligible(): boolean;
  sendMouse(mask: number, x: number, y: number): void;
  sendPassword(password: string): boolean;
}

export interface OsAutoLoginDeps {
  sleep(ms: number): Promise<void>;
}

const POINTER_MOVE = 0;
const PRIMARY_BUTTON_DOWN = buttonMask(MouseType.DOWN, MouseButton.LEFT);
const PRIMARY_BUTTON_UP = buttonMask(MouseType.UP, MouseButton.LEFT);
const WAKE_ORIGIN = { x: 0, y: 0 } as const;
const WAKE_NUDGE = { x: 3, y: 3 } as const;
const WAKE_STEP_DELAY_MS = 50;
const LOGIN_SURFACE_SETTLE_MS = 1200;

const defaultDeps: OsAutoLoginDeps = {
  sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
};

export class OsAutoLoginAttempt {
  private started = false;
  private epoch = 0;

  constructor(
    private readonly sinks: OsAutoLoginSinks,
    private readonly deps: OsAutoLoginDeps = defaultDeps,
  ) {}

  cancel(): void {
    this.epoch += 1;
  }

  async start(password: string): Promise<OsAutoLoginResult> {
    if (this.started) return 'already-attempted';
    this.started = true;
    const epoch = ++this.epoch;
    if (!this.sinks.eligible()) return 'blocked';

    // A newly streamed lock screen can still be dormant. Use a short, bounded
    // pointer gesture to wake its password field, then allow the surface to
    // settle before sending the one-shot password sequence. Release first in
    // case an earlier browser session disconnected mid-click; each later press
    // is paired with its release before any cancellation point.
    this.sinks.sendMouse(PRIMARY_BUTTON_UP, WAKE_ORIGIN.x, WAKE_ORIGIN.y);
    await this.deps.sleep(WAKE_STEP_DELAY_MS);
    if (epoch !== this.epoch) return 'cancelled';

    this.sinks.sendMouse(POINTER_MOVE, WAKE_ORIGIN.x, WAKE_ORIGIN.y);
    await this.deps.sleep(WAKE_STEP_DELAY_MS);
    if (epoch !== this.epoch) return 'cancelled';

    this.sinks.sendMouse(POINTER_MOVE, WAKE_NUDGE.x, WAKE_NUDGE.y);
    await this.deps.sleep(WAKE_STEP_DELAY_MS);
    if (epoch !== this.epoch) return 'cancelled';

    this.sinks.sendMouse(PRIMARY_BUTTON_DOWN, WAKE_ORIGIN.x, WAKE_ORIGIN.y);
    this.sinks.sendMouse(PRIMARY_BUTTON_UP, WAKE_ORIGIN.x, WAKE_ORIGIN.y);
    await this.deps.sleep(LOGIN_SURFACE_SETTLE_MS);
    if (epoch !== this.epoch) return 'cancelled';

    return this.sinks.sendPassword(password) ? 'sent' : 'blocked';
  }
}
