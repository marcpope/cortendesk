import type { SupportedDecoding_PreferCodec } from '../gen/message';

// CortenDesk web client — CROSS-MODULE CONTRACT.
//
// SCAFFOLD-OWNED. Every module imports from this file; NO ONE else edits it.
// The shapes below are the frozen boundary between the UI (main thread) and the
// session worker, and between the sans-IO protocol core and its callers.

// Remembered display/media options for a desktop connection, sent in the
// LoginRequest so the peer starts the session with them (issue #92). Absent
// fields leave the peer's own default in place.
export type InitialSessionOptions = { imageQuality?:number; customImageQuality?:number; customFps?:number; preferCodec?:SupportedDecoding_PreferCodec; showRemoteCursor?:boolean; followRemoteCursor?:boolean; followRemoteWindow?:boolean; disableClipboard?:boolean; disableAudio?:boolean };
export type SessionConfig = { peerId:string; serverKeyB64:string; wsIdUrl:string; wsRelayUrl:string; password:string; myId:string; myName:string; signalToken?:string; savedHashHex?:string; connType?:'default'|'fileTransfer'|'viewCamera'|'terminal'; terminalServiceId?:string; terminalPersistent?:boolean; initialOptions?:InitialSessionOptions };
export type ResolutionInfo = { width:number; height:number };
export type DisplayInfo = { index:number; x:number; y:number; width:number; height:number; name:string; scale:number; online:boolean; cursorEmbedded:boolean; originalResolution?:ResolutionInfo; resolutions:ResolutionInfo[] };
export type SessionStats = { codec:string; width:number; height:number; fps:number; mbps:number; framesDropped:number; startedAtMs:number };
export type SessionState = 'connecting'|'rendezvous'|'relay'|'handshake'|'login'|'streaming'|'error'|'closed'|'needAccept';
// File transfer: plain-object mirrors of the protobuf FileEntry/FileDirectory
// (bigints down-converted to number — file sizes/mtimes fit in 2^53).
export type FtEntryKind = 'dir'|'file'|'drive'|'dirLink'|'fileLink';
export type FtEntry = { kind:FtEntryKind; name:string; size:number; modifiedSec:number; isHidden:boolean };
export type FtDirectory = { id:number; path:string; entries:FtEntry[] };
export type SessionEvent =                       // worker -> main
  | { t:'state'; state:SessionState; detail?:string; peerInitiated?:boolean }
  | { t:'peerInfo'; displays:DisplayInfo[]; username:string; hostname:string; platform:string; platformAdditions:string; version:string; current?:number; privacyModeSupported:boolean; privacyModeImpls:{key:string; label:string}[]; terminalSupported:boolean; viewCameraSupported:boolean }
  // The peer's authoritative answer to a display switch. It is the ONLY reply
  // the host sends (server/video_service.rs make_display_changed_msg), and it
  // carries the real geometry of what is now being captured — which is what
  // input coordinates must be mapped against. A locally-assumed index is not
  // enough: the host can refuse the switch, or report different geometry.
  | { t:'switchDisplay'; index:number; x:number; y:number; width:number; height:number; cursorEmbedded:boolean; originalResolution?:ResolutionInfo; resolutions:ResolutionInfo[] }
  | { t:'followDisplay'; index:number }
  | { t:'codecSupport'; codecs:Array<'auto'|'vp9'|'h264'|'h265'|'vp8'|'av1'> }
  | { t:'stats'; stats:SessionStats }
  | { t:'cursor'; pngDataUrl:string; hotx:number; hoty:number } | { t:'cursorPos'; x:number; y:number }
  | { t:'clipboard'; text:string } | { t:'permission'; kind:string; enabled:boolean }
  | { t:'privacyMode'; state:number; details:string; implKey:string }
  | { t:'blockInput'; state:number; details:string }
  | { t:'elevation'; state:'pending'|'succeeded'|'failed'; detail:string }
  | { t:'chat'; text:string }             // inbound message from the remote peer
  | { t:'voiceCall'; state:'waiting'|'accepted'|'rejected'|'closed'; detail?:string }
  // Fallback video: raw H.264 Annex B forwarded for main-thread MSE playback.
  // Only emitted when WebCodecs is unavailable (insecure origin).
  | { t:'h264'; data:Uint8Array; key:boolean }
  | { t:'credentials'; hashHex:string }   // h1 = SHA256(pw||salt); UI may persist for "save password"
  | { t:'loginError'; message:string }
  | { t:'uac'; on:boolean }               // remote UAC prompt opened/closed (capture restarts around it)
  | { t:'msgbox'; msgtype:string; title:string; text:string; link:string }
  // terminal connections only. Data stays bytes; the UI must append text nodes,
  // never interpret remote terminal output as markup.
  | { t:'terminalOpened'; terminalId:number; success:boolean; message:string; pid:number; serviceId:string; persistentSessions:number[]; replayTerminalOutput:boolean }
  | { t:'terminalData'; terminalId:number; data:Uint8Array; compressed:boolean }
  | { t:'terminalClosed'; terminalId:number; exitCode:number }
  | { t:'terminalError'; terminalId:number; message:string }
  // file transfer connections only (block data arrives already zstd-decompressed):
  | { t:'ftDir'; dir:FtDirectory }
  | { t:'ftBlock'; id:number; fileNum:number; data:Uint8Array; blkId:number }
  | { t:'ftDone'; id:number; fileNum:number }
  | { t:'ftError'; id:number; fileNum:number; error:string }
  | { t:'ftDigest'; id:number; fileNum:number; lastModifiedSec:number; fileSize:number; isUpload:boolean; isIdentical:boolean; transferredSize:number; isResume:boolean }
  | { t:'ftSendConfirm'; id:number; fileNum:number; skip:boolean; offsetBytes:number } // peer confirmed our upload file (offset_blk is BYTES)
  | { t:'ftSent'; id:number; fileNum:number; buffered:number };                       // ack for an uploaded ftBlock (buffered = ws bytes queued)
export type UiCommand =                           // main -> worker
  | { c:'connect'; config:SessionConfig; canvas:OffscreenCanvas }
  | { c:'mouse'; mask:number; x:number; y:number; modifiers:number[] }
  | { c:'key'; down:boolean; press:boolean; keyKind:'chr'|'control'|'unicode'; value:number; modifiers:number[] }
  | { c:'switchDisplay'; index:number } | { c:'ctrlAltDel' } | { c:'refresh' }
  | { c:'quality'; imageQuality:number } | { c:'clipboardText'; text:string } | { c:'disconnect' }
  | { c:'restartRemoteDevice' } | { c:'requestElevation' }
  | { c:'privacyMode'; implKey:string; on:boolean }
  | { c:'blockInput'; on:boolean } | { c:'lockAfterSessionEnd'; on:boolean }
  | { c:'displayResolution'; display:number; width:number; height:number }
  | { c:'virtualDisplay'; display:number; on:boolean }
  | { c:'customQuality'; quality:number } | { c:'customFps'; fps:number }
  | { c:'preferredCodec'; prefer:SupportedDecoding_PreferCodec }
  | { c:'displayOption'; option:'showRemoteCursor'|'followRemoteCursor'|'followRemoteWindow'; enabled:boolean }
  | { c:'remoteAudio'; enabled:boolean }
  | { c:'clipboardEnabled'; enabled:boolean }
  | { c:'clientRecording'; recording:boolean }
  | { c:'chat'; text:string }             // outbound message to the remote peer
  | { c:'voiceCallStart' } | { c:'voiceCallClose' }
  | { c:'voiceAudioFormat'; sampleRate:number; channels:number } | { c:'voiceAudioFrame'; data:Uint8Array }
  // terminal connections only (no canvas):
  | { c:'connectTerminal'; config:SessionConfig }
  | { c:'terminalOpen'; terminalId:number; rows:number; cols:number }
  | { c:'terminalData'; terminalId:number; data:Uint8Array }
  | { c:'terminalResize'; terminalId:number; rows:number; cols:number }
  | { c:'terminalClose'; terminalId:number }
  // file transfer connections only (no canvas; connect with config.connType='fileTransfer'):
  | { c:'connectFile'; config:SessionConfig }
  | { c:'ftReadDir'; path:string; includeHidden:boolean }
  | { c:'ftSend'; id:number; path:string; includeHidden:boolean; fileNum:number }   // ask peer to send (download)
  | { c:'ftReceive'; id:number; path:string; files:{ name:string; size:number; modifiedSec:number }[]; totalSize:number; fileNum:number } // announce upload
  | { c:'ftDigest'; id:number; fileNum:number; fileSize:number; lastModifiedSec:number } // upload: announce source file, wait for confirm
  | { c:'ftBlock'; id:number; fileNum:number; data:Uint8Array; blkId:number }       // upload payload chunk (empty data = EOF marker)
  | { c:'ftDone'; id:number; fileNum:number }                                       // upload: whole job complete (fileNum = file count)
  | { c:'ftError'; id:number; fileNum:number; error:string }
  | { c:'ftConfirm'; id:number; fileNum:number; skip:boolean; offsetBlk:number }    // reply to ftDigest
  | { c:'ftCancel'; id:number }
  | { c:'ftCreateDir'; id:number; path:string }
  | { c:'ftRemoveFile'; id:number; path:string; fileNum:number }
  | { c:'ftRemoveDir'; id:number; path:string }
  | { c:'ftRename'; id:number; path:string; newName:string };
export interface Transport { send(bytes:Uint8Array):void; onMessage(cb:(b:Uint8Array)=>void):void; onClose(cb:()=>void):void; close():void; buffered?():number; }
export interface Encryptor { seal(pt:Uint8Array):Uint8Array; open(ct:Uint8Array):Uint8Array; }
