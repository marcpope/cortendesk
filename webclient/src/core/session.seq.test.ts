import { beforeAll, describe, expect, it } from 'vitest';
import { KeyboardMode, Message } from '../gen/message';
import { buildTypeCommands } from '../ui/common';
import { PlainChannel, sodiumReady } from './crypto';
import { Session, type SessionSinks } from './session';

function session() {
  const sent: Uint8Array[] = [];
  const sinks: SessionSinks = {
    sendSignaling: () => {},
    sendRelay: (b) => sent.push(b),
    relayBuffered: () => 0,
    emit: () => {},
    onVideo: () => {},
    onAudioFormat: () => {},
    onAudioFrame: () => {},
    openRelay: () => {},
    closeAll: () => {},
  };
  const s = new Session(
    {
      peerId: '123456789', serverKeyB64: 'A'.repeat(43) + '=', wsIdUrl: 'ws://x/id',
      wsRelayUrl: 'ws://x/relay', password: '', myId: 'web-1', myName: 'Tester',
    },
    sinks,
  );
  (s as unknown as { cipher: PlainChannel }).cipher = new PlainChannel();
  return { s, sent };
}

describe('text as KeyEvent.seq', () => {
  beforeAll(async () => {
    await sodiumReady();
  });

  it('encodes like RustDesk input_string(): legacy, seq, neither down nor press', () => {
    const { s, sent } = session();
    s.sendSeq('ދިވެހި 👍 ok');
    expect(sent).toHaveLength(1);
    const msg = Message.decode(sent[0]!);
    expect(msg.union?.$case).toBe('key_event');
    if (msg.union?.$case !== 'key_event') return;
    const k = msg.union.key_event;
    expect(k.union).toEqual({ $case: 'seq', seq: 'ދިވެހި 👍 ok' });
    expect(k.mode).toBe(KeyboardMode.Legacy);
    expect(k.down).toBe(false);
    expect(k.press).toBe(false);
    expect(k.modifiers).toEqual([]);
  });

  it('sends nothing for empty text', () => {
    const { s, sent } = session();
    s.sendSeq('');
    expect(sent).toEqual([]);
  });
});

describe('buildTypeCommands', () => {
  it('runs of text as seq, line breaks and tabs as Return / Tab presses', () => {
    expect(buildTypeCommands('hi ދ\r\nx\ty')).toEqual([
      { c: 'text', text: 'hi ދ' },
      { c: 'key', down: false, press: true, keyKind: 'control', value: 27, modifiers: [] },
      { c: 'text', text: 'x' },
      { c: 'key', down: false, press: true, keyKind: 'control', value: 31, modifiers: [] },
      { c: 'text', text: 'y' },
    ]);
  });
});
