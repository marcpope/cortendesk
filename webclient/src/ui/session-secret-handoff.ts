import type { UiCommand } from '../core/contracts';

type ConnectCommand = Extract<UiCommand, { c: 'connect' }>;

type CommandPoster = {
  postMessage(message: ConnectCommand, transfer: Transferable[]): void;
};

/**
 * Structured-clone the transient OS password to the session worker, then scrub
 * the main-thread command/config copy even when cloning throws.
 */
export function postConnectWithSecretScrub(
  poster: CommandPoster,
  command: ConnectCommand,
  transfer: Transferable[],
): void {
  try {
    poster.postMessage(command, transfer);
  } finally {
    command.config.osPassword = undefined;
  }
}
