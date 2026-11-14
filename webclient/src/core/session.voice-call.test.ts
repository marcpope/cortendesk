import { describe, expect, it } from 'vitest';
import { Message } from '../gen/message';
import type { SessionConfig, SessionEvent } from './contracts';
import { Session, VOICE_RELAY_BUFFER_LIMIT } from './session';

const config: SessionConfig = {
  peerId: '123456789',
  serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  wsIdUrl: 'wss://id.example.test',
  wsRelayUrl: 'wss://relay.example.test',
  password: '',
  myId: 'controller',
  myName: 'Controller',
};

function connectedSession() {
  const sent: Uint8Array[] = [];
  const events: SessionEvent[] = [];
  let relayBuffered = 0;
  const session = new Session(config, {
    sendSignaling: () => {},
    sendRelay: (bytes) => sent.push(bytes),
    relayBuffered: () => relayBuffered,
    emit: (event) => events.push(event),
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
  });
  (session as unknown as { cipher: { seal(bytes: Uint8Array): Uint8Array } }).cipher = { seal: (bytes) => bytes };
  return { session, sent, events, setRelayBuffered: (bytes: number) => { relayBuffered = bytes; } };
}

describe('Session outgoing voice calls', () => {
  it('sends a nonzero connect request and waits for its matching response', async () => {
    const { session, sent, events } = connectedSession();

    session.startVoiceCall();

    expect(sent).toHaveLength(1);
    const request = Message.decode(sent[0]!);
    expect(request.union).toMatchObject({
      $case: 'voice_call_request',
      voice_call_request: { is_connect: true },
    });
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp
      : 0n;
    expect(timestamp).toBeGreaterThan(0n);
    expect(events).toContainEqual({ t: 'voiceCall', state: 'waiting' });

    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp, ack_timestamp: timestamp + 1n } } }),
    );

    expect(events).toContainEqual({ t: 'voiceCall', state: 'accepted' });
  });

  it('ignores mismatched responses and sends a close request for the pending call', async () => {
    const { session, sent, events } = connectedSession();
    session.startVoiceCall();
    const request = Message.decode(sent[0]!);
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp : 0n;

    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp + 1n, ack_timestamp: timestamp + 2n } } }),
    );
    expect(events).not.toContainEqual({ t: 'voiceCall', state: 'accepted' });
    expect(events).toContainEqual({
      t: 'voiceCall',
      state: 'closed',
      detail: 'Voice call response could not be verified',
    });

    // RustDesk consumes the pending request on any response. A later packet that
    // guesses the original timestamp must not revive a request after a mismatch.
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp } } }),
    );
    expect(events).not.toContainEqual({ t: 'voiceCall', state: 'accepted' });

    session.closeVoiceCall();
    expect(Message.decode(sent[1]!).union).toMatchObject({
      $case: 'voice_call_request',
      voice_call_request: { is_connect: false },
    });
    expect(events).toContainEqual({ t: 'voiceCall', state: 'closed' });
  });

  it('sends audio format and frames only after an exact accepted response', async () => {
    const { session, sent } = connectedSession();
    session.sendVoiceAudioFormat(48000, 1);
    session.sendVoiceAudioFrame(new Uint8Array([1]));
    expect(sent).toHaveLength(0);

    session.startVoiceCall();
    const request = Message.decode(sent[0]!);
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp : 0n;
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp, ack_timestamp: timestamp + 1n } } }),
    );

    session.sendVoiceAudioFormat(44100, 2);
    session.sendVoiceAudioFrame(new Uint8Array([9, 8, 7]));
    expect(Message.decode(sent[1]!).union).toMatchObject({
      $case: 'misc',
      misc: { union: { $case: 'audio_format', audio_format: { sample_rate: 44100, channels: 2 } } },
    });
    expect(Message.decode(sent[2]!).union).toMatchObject({
      $case: 'audio_frame',
      audio_frame: { data: new Uint8Array([9, 8, 7]) },
    });

    session.closeVoiceCall();
    session.sendVoiceAudioFrame(new Uint8Array([6]));
    expect(sent).toHaveLength(4);
  });

  it('ignores an unsolicited incoming connect request', async () => {
    const { session, sent, events } = connectedSession();
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_request', voice_call_request: { is_connect: true, req_timestamp: 42n } } }),
    );
    expect(sent).toHaveLength(0);
    expect(events).not.toContainEqual(expect.objectContaining({ t: 'voiceCall' }));
  });

  it('drops voice frames while the relay send buffer is congested and resumes after drain', async () => {
    const { session, sent, setRelayBuffered } = connectedSession();
    session.startVoiceCall();
    const request = Message.decode(sent[0]!);
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp
      : 0n;
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp, ack_timestamp: 0n } } }),
    );
    sent.length = 0;

    setRelayBuffered(VOICE_RELAY_BUFFER_LIMIT);
    session.sendVoiceAudioFrame(new Uint8Array([1, 2, 3]));
    expect(sent).toHaveLength(0);

    setRelayBuffered(0);
    session.sendVoiceAudioFrame(new Uint8Array([4, 5, 6]));
    expect(sent).toHaveLength(1);
    const resumed = Message.decode(sent[0]!);
    expect(resumed.union?.$case).toBe('audio_frame');
  });

  it('stops accepting microphone packets when the session disconnects', async () => {
    const { session, sent } = connectedSession();
    session.startVoiceCall();
    const request = Message.decode(sent[0]!);
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp : 0n;
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp } } }),
    );

    session.disconnect();
    session.sendVoiceAudioFrame(new Uint8Array([7]));
    expect(sent).toHaveLength(1);
  });

  it('closes an accepted call when the remote sends a disconnect request', async () => {
    const { session, sent, events } = connectedSession();
    session.startVoiceCall();
    const request = Message.decode(sent[0]!);
    const timestamp = request.union?.$case === 'voice_call_request'
      ? request.union.voice_call_request.req_timestamp : 0n;
    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_response', voice_call_response: { accepted: true, req_timestamp: timestamp, ack_timestamp: timestamp + 1n } } }),
    );

    await (session as unknown as { dispatch(message: Message): Promise<void> }).dispatch(
      Message.fromPartial({ union: { $case: 'voice_call_request', voice_call_request: { is_connect: false, req_timestamp: timestamp + 3n } } }),
    );
    expect(events).toContainEqual({ t: 'voiceCall', state: 'closed', detail: 'Remote user ended the call' });
  });
});
