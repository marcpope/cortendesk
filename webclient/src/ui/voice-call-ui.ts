import type { SessionState } from '../core/contracts';
import { tl } from '../i18n';

export type VoiceCallUiState = 'idle' | 'preparing' | 'waiting' | 'connected';

export type VoiceCallUiModel = {
  disabled: boolean;
  label: string;
  ariaLabel: string;
  status: string;
};

/**
 * Voice is independent of keyboard/mouse control, so View only is deliberately
 * absent from this model. RustDesk permits calls in view-only remote sessions.
 */
export function voiceCallUiModel(
  sessionState: SessionState,
  audioAllowed: boolean,
  callState: VoiceCallUiState,
  browserSupported = true,
): VoiceCallUiModel {
  const active = callState === 'waiting' || callState === 'connected';
  if (!browserSupported && !active) {
    return {
      disabled: true,
      label: tl('voice_call'),
      ariaLabel: tl('start_voice_call'),
      status: tl('voice_calls_need_chrome_or'),
    };
  }
  if (callState === 'preparing') {
    return {
      disabled: true,
      label: tl('voice_call'),
      ariaLabel: tl('start_voice_call'),
      status: tl('requesting_microphone'),
    };
  }
  const unavailable = sessionState !== 'streaming' || !audioAllowed;
  const status = callState === 'waiting'
    ? tl('waiting_for_remote_user')
    : callState === 'connected'
      ? tl('voice_call_connected')
      : !audioAllowed
        ? tl('voice_calls_are_not_permitted')
        : sessionState !== 'streaming'
          ? tl('voice_calls_require_connected_session')
          : tl('voice_calls_are_off');

  return {
    disabled: unavailable && !active,
    label: active ? tl('end_call') : tl('voice_call'),
    ariaLabel: active ? tl('end_voice_call') : tl('start_voice_call'),
    status,
  };
}
