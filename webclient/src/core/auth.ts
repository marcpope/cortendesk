import {
  LoginRequest,
  Message,
  OptionMessage,
  OptionMessage_BoolOption,
  SupportedDecoding,
} from '../gen/message';
import { sha256 as sha256sync } from './sha256';

const utf8 = new TextEncoder();

function cat(a: Uint8Array, b: Uint8Array): Uint8Array<ArrayBuffer> {
  const out = new Uint8Array(a.length + b.length);
  out.set(a, 0);
  out.set(b, a.length);
  return out;
}

// Deliberately not crypto.subtle: that is gated behind a secure context, and
// this hash is the only reason the login path ever needed one. The vendored
// implementation behaves identically everywhere, including over plain http://.
// Kept async so every caller's signature is unchanged.
async function sha256(data: Uint8Array<ArrayBuffer>): Promise<Uint8Array> {
  return sha256sync(data);
}

// h1 = SHA256(utf8(pw) || utf8(salt)) as RAW 32 bytes (never hex). This is the
// stable per-install credential RustDesk itself persists as "remembered password".
export async function computeLoginH1(password: string, salt: string): Promise<Uint8Array> {
  return sha256(cat(utf8.encode(password), utf8.encode(salt)));
}

// Final login value = SHA256(h1_raw || utf8(challenge)), sent as raw bytes.
export async function loginHashFromH1(h1: Uint8Array, challenge: string): Promise<Uint8Array> {
  return sha256(cat(h1 as Uint8Array<ArrayBuffer>, utf8.encode(challenge)));
}

export async function loginPasswordHash(
  password: string,
  salt: string,
  challenge: string,
): Promise<Uint8Array> {
  if (password.length === 0) return new Uint8Array(0);
  return loginHashFromH1(await computeLoginH1(password, salt), challenge);
}

export function buildLoginRequest(opts: {
  peerId: string;
  passwordHash: Uint8Array;
  myId: string;
  myName: string;
  sessionId: bigint;
  version: string;
  supportedDecoding: SupportedDecoding;
  videoAckRequired?: boolean;
  lockAfterSessionEnd?: boolean;
  // File-transfer connection: LoginRequest carries the file_transfer union and
  // no video options (there is no video stream on this connection type).
  fileTransfer?: { dir: string; showHidden: boolean };
  viewCamera?: boolean;
  terminal?: { serviceId: string; persistent: boolean };
}): Uint8Array {
  const base = {
    username: opts.peerId,
    password: opts.passwordHash,
    my_id: opts.myId,
    my_name: opts.myName,
    session_id: opts.sessionId,
    version: opts.version,
  };
  return Message.encode({
    union: {
      $case: 'login_request',
      login_request: LoginRequest.fromPartial(
        opts.fileTransfer
          ? {
              ...base,
              union: {
                $case: 'file_transfer',
                file_transfer: { dir: opts.fileTransfer.dir, show_hidden: opts.fileTransfer.showHidden },
              },
            }
          : opts.terminal
            ? {
                ...base,
                union: {
                  $case: 'terminal',
                  terminal: { service_id: opts.terminal.serviceId },
                },
                option: OptionMessage.fromPartial({
                  terminal_persistent: opts.terminal.persistent
                    ? OptionMessage_BoolOption.Yes
                    : OptionMessage_BoolOption.No,
                }),
              }
            : {
                ...base,
                video_ack_required: opts.videoAckRequired ?? true,
                option: OptionMessage.fromPartial({
                  supported_decoding: opts.supportedDecoding,
                  ...(opts.lockAfterSessionEnd
                    ? { lock_after_session_end: OptionMessage_BoolOption.Yes }
                    : {}),
                }),
                union: opts.viewCamera ? { $case: 'view_camera', view_camera: {} } : undefined,
              },
      ),
    },
  }).finish();
}
