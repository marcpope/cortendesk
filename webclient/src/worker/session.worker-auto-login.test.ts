import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../core/crypto', () => ({
  sodiumReady: async () => {},
  decodeB64: () => new Uint8Array(32),
  StreamCipher: class {},
}));

import type { SessionConfig, SessionEvent, SessionState, Transport } from '../core/contracts';
import type { SessionSinks } from '../core/session';
import { WorkerHost } from './session.worker';

afterEach(() => {
  vi.useRealTimers();
});

function fakeTransport(): Transport {
  return {
    send: () => {},
    onMessage: () => {},
    onClose: () => {},
    close: () => {},
    buffered: () => 0,
  };
}

async function connectedWorker() {
  const sendMouse = vi.fn();
  const sendOsPassword = vi.fn(() => true);
  let sinks: SessionSinks | undefined;
  const session = {
    currentState: 'connecting' as SessionState,
    start: vi.fn(),
    setSupportedDecoding: vi.fn(),
    sendMouse,
    sendOsPassword,
    disconnect: vi.fn(),
  };
  const config: SessionConfig = {
    peerId: '123456',
    serverKeyB64: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    wsIdUrl: 'wss://id.example.test',
    wsRelayUrl: 'wss://relay.example.test',
    password: '',
    myId: 'controller',
    myName: 'Controller',
    osPassword: 'remote-os-secret',
  };
  const host = new WorkerHost({
    post: (_event: SessionEvent) => {},
    ready: async () => {},
    probeDecoding: async () => ({}) as never,
    openWs: async () => fakeTransport(),
    createSession: (_config, captured) => {
      sinks = captured;
      return session as never;
    },
    createVideoPipeline: () => ({
      onNeedReadvertise: null,
      disabledCodecs: () => [],
      pushFrame: () => {},
      reset: () => {},
      close: () => {},
    }),
    createAudioPipeline: () => ({
      onPcm: null,
      setFormat: () => {},
      pushFrame: () => {},
      close: () => {},
    }),
  });

  host.handle({ c: 'connect', config, canvas: {} as OffscreenCanvas });
  await vi.waitFor(() => expect(sinks).toBeDefined());

  return { host, config, session, sinks: sinks!, sendMouse, sendOsPassword };
}

describe('WorkerHost OS auto-login', () => {
  it('starts on streaming when Keyboard permission is absent and starts only once', async () => {
    const { host, config, session, sinks, sendMouse } = await connectedWorker();
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });

    expect(sendMouse).toHaveBeenCalledTimes(1);
    expect(sendMouse).toHaveBeenCalledWith(10, 0, 0, []);
    expect(config.osPassword).toBeUndefined();

    sinks.emit({ t: 'state', state: 'streaming' });
    expect(sendMouse).toHaveBeenCalledTimes(1);
    host.handle({ c: 'disconnect' });
  });

  it('cancels immediately when confirmed Keyboard permission is revoked', async () => {
    const { host, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    vi.useFakeTimers();
    sinks.emit({ t: 'permission', kind: 'Keyboard', enabled: true });
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });
    expect(sendMouse).toHaveBeenCalledTimes(1);

    sinks.emit({ t: 'permission', kind: 'Keyboard', enabled: false });
    await vi.runAllTimersAsync();

    expect(sendMouse).toHaveBeenCalledTimes(1);
    expect(sendOsPassword).not.toHaveBeenCalled();
    host.handle({ c: 'disconnect' });
  });

  it('scrubs immediately when Keyboard is denied before streaming', async () => {
    const { host, config, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    sinks.emit({ t: 'permission', kind: 'Keyboard', enabled: false });
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });

    expect(config.osPassword).toBeUndefined();
    expect(sendMouse).not.toHaveBeenCalled();
    expect(sendOsPassword).not.toHaveBeenCalled();
    host.handle({ c: 'disconnect' });
  });

  it('cannot revive auto-login after a login error', async () => {
    const { host, config, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    session.currentState = 'login';
    sinks.emit({ t: 'loginError', message: 'Wrong password' });
    session.currentState = 'streaming';
    sinks.emit({ t: 'permission', kind: 'Keyboard', enabled: true });
    sinks.emit({ t: 'state', state: 'streaming' });

    expect(config.osPassword).toBeUndefined();
    expect(sendMouse).not.toHaveBeenCalled();
    expect(sendOsPassword).not.toHaveBeenCalled();
    host.handle({ c: 'disconnect' });
  });

  it('scrubs without input when View only is enabled before streaming', async () => {
    const { host, config, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    host.handle({ c: 'viewOnly', enabled: true });
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });

    expect(config.osPassword).toBeUndefined();
    expect(sendMouse).not.toHaveBeenCalled();
    expect(sendOsPassword).not.toHaveBeenCalled();
    host.handle({ c: 'disconnect' });
  });

  it('cancels an in-flight attempt immediately when View only is enabled', async () => {
    const { host, config, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    vi.useFakeTimers();
    sinks.emit({ t: 'permission', kind: 'Keyboard', enabled: true });
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });
    expect(sendMouse).toHaveBeenCalledTimes(1);

    host.handle({ c: 'viewOnly', enabled: true });
    await vi.runAllTimersAsync();

    expect(config.osPassword).toBeUndefined();
    expect(sendMouse).toHaveBeenCalledTimes(1);
    expect(sendOsPassword).not.toHaveBeenCalled();
    host.handle({ c: 'disconnect' });
  });

  it('submits the password when Keyboard permission is never reported', async () => {
    const { host, config, session, sinks, sendMouse, sendOsPassword } = await connectedWorker();
    vi.useFakeTimers();
    session.currentState = 'streaming';
    sinks.emit({ t: 'state', state: 'streaming' });

    expect(sendMouse).toHaveBeenCalledTimes(1);
    await vi.runAllTimersAsync();

    expect(config.osPassword).toBeUndefined();
    expect(sendOsPassword).toHaveBeenCalledOnce();
    expect(sendOsPassword).toHaveBeenCalledWith('remote-os-secret');
    host.handle({ c: 'disconnect' });
  });
});
