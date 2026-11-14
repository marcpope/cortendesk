import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  recorderStart: vi.fn(() => ({ ok: true as const })),
  recorderClose: vi.fn(),
  recorderCtor: vi.fn(),
  readLocalClipboardText: vi.fn<() => Promise<string | null>>(),
}));
const { recorderStart, recorderClose, recorderCtor, readLocalClipboardText } = mocks;

vi.mock('../media/session-recorder', () => ({
  LocalSessionRecorder: class {
    constructor(...args: unknown[]) { mocks.recorderCtor(...args); }
    start = mocks.recorderStart;
    stop = vi.fn();
    close = mocks.recorderClose;
  },
}));

vi.mock('../input/clipboard-cursor', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../input/clipboard-cursor')>();
  return { ...actual, readLocalClipboardText: mocks.readLocalClipboardText };
});

import { RdApp } from './app';

function deferred<T>(): { promise: Promise<T>; resolve(value: T): void } {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => { resolve = done; });
  return { promise, resolve };
}

describe('RdApp asynchronous media permission gates', () => {
  afterEach(() => vi.unstubAllGlobals());

  beforeEach(() => {
    recorderStart.mockClear();
    recorderClose.mockClear();
    recorderCtor.mockClear();
    readLocalClipboardText.mockReset();
  });

  it('does not start recording if permission is revoked while audio resume is pending', async () => {
    const resume = deferred<boolean>();
    const app = Object.create(RdApp.prototype) as any;
    app.recording = false;
    app.state = 'streaming';
    app.permissions = { Recording: true, Audio: true };
    app.remoteAudioEnabled = true;
    app.audioStarted = false;
    app.audioPlayback = {
      resumeFromUserGesture: () => resume.promise,
      createRecordingTap: () => null,
    };
    app.toast = vi.fn();
    app.videoEl = { hidden: true };
    app.canvas = {};

    const pending = app.toggleRecording();
    app.permissions.Recording = false;
    resume.resolve(true);
    await pending;

    expect(app.audioStarted).toBe(false);
    expect(recorderCtor).not.toHaveBeenCalled();
    expect(recorderStart).not.toHaveBeenCalled();
  });

  it('does not let a recording request from one session resume in its replacement', async () => {
    const resume = deferred<boolean>();
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.recording = false;
    app.state = 'streaming';
    app.permissions = { Recording: true, Audio: true };
    app.remoteAudioEnabled = true;
    app.audioPlayback = {
      resumeFromUserGesture: () => resume.promise,
      createRecordingTap: () => null,
    };
    app.toast = vi.fn();
    app.videoEl = { hidden: true };
    app.canvas = {};

    const pending = app.toggleRecording();
    app.sessionEpoch = 2;
    app.state = 'streaming';
    app.permissions = { Recording: true, Audio: true };
    resume.resolve(true);
    await pending;

    expect(recorderCtor).not.toHaveBeenCalled();
    expect(recorderStart).not.toHaveBeenCalled();
  });

  it('does not mutate replacement-session audio state after recording audio resume', async () => {
    const resume = deferred<boolean>();
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.recording = false;
    app.state = 'streaming';
    app.permissions = { Recording: true, Audio: true };
    app.remoteAudioEnabled = true;
    app.audioStarted = false;
    app.audioPlayback = {
      resumeFromUserGesture: () => resume.promise,
      createRecordingTap: () => null,
      close: vi.fn(),
    };
    app.toast = vi.fn();
    app.videoEl = { hidden: true };
    app.canvas = {};
    app.el = { recordingIndicator: { hidden: false } };
    app.removeClipboardSyncOffer = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();

    const pending = app.toggleRecording();
    app.teardown();
    app.state = 'streaming';
    app.permissions = { Recording: true, Audio: true };
    app.audioStarted = false;
    resume.resolve(true);
    await pending;

    expect(app.audioStarted).toBe(false);
    expect(recorderCtor).not.toHaveBeenCalled();
  });

  it('does not mutate or toast in a replacement session after audio resume', async () => {
    const resume = deferred<boolean>();
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.recording = false;
    app.audioStarted = false;
    app.permissions = { Audio: true };
    app.audioPlayback = {
      resumeFromUserGesture: () => resume.promise,
      close: vi.fn(),
    };
    app.toast = vi.fn();
    app.el = { recordingIndicator: { hidden: false } };
    app.removeClipboardSyncOffer = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();

    const pending = app.resumeAudioFromUserGesture();
    app.teardown();
    app.state = 'streaming';
    app.permissions = { Audio: true };
    app.audioStarted = false;
    app.toast.mockClear();
    resume.resolve(true);
    await pending;

    expect(app.audioStarted).toBe(false);
    expect(app.toast).not.toHaveBeenCalled();
  });

  it('does not mark audio ready if permission is revoked while resume is pending', async () => {
    const resume = deferred<boolean>();
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.audioStarted = false;
    app.permissions = { Audio: true };
    app.audioPlayback = { resumeFromUserGesture: () => resume.promise };
    app.toast = vi.fn();

    const pending = app.resumeAudioFromUserGesture();
    app.permissions.Audio = false;
    resume.resolve(true);
    await pending;

    expect(app.audioStarted).toBe(false);
    expect(app.toast).not.toHaveBeenCalled();
  });

  it('ignores delayed recorder state callbacks from a replaced session', async () => {
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.recording = false;
    app.state = 'streaming';
    app.permissions = { Recording: true };
    app.remoteAudioEnabled = false;
    app.toast = vi.fn();
    app.post = vi.fn();
    app.videoEl = { hidden: true };
    app.canvas = {};
    app.el = { recordingIndicator: { hidden: true, querySelector: () => null } };
    app.removeClipboardSyncOffer = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();

    await app.toggleRecording();
    const staleCallback = recorderCtor.mock.calls[0]?.[2] as ((active: boolean, startedAtMs?: number) => void);
    staleCallback(true, 100);
    app.teardown();

    const replacementRecorder = { close: vi.fn() };
    app.state = 'streaming';
    app.permissions = { Recording: true };
    app.recording = true;
    app.recorder = replacementRecorder;
    app.el.recordingIndicator.hidden = false;
    app.post.mockClear();
    app.toast.mockClear();

    staleCallback(false);

    expect(app.recording).toBe(true);
    expect(app.recorder).toBe(replacementRecorder);
    expect(app.el.recordingIndicator.hidden).toBe(false);
    expect(app.post).not.toHaveBeenCalled();
    expect(app.toast).not.toHaveBeenCalled();
  });

  it('does not toast in a replacement session after an incoming clipboard write completes', async () => {
    const write = deferred<void>();
    vi.stubGlobal('navigator', { clipboard: { writeText: () => write.promise } });
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.recording = false;
    app.permissions = { Clipboard: true };
    app.clipboardEnabled = true;
    app.toast = vi.fn();
    app.el = { recordingIndicator: { hidden: false } };
    app.removeClipboardSyncOffer = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();

    app.onEvent({ t: 'clipboard', text: 'session A' });
    app.teardown();
    app.toast.mockClear();
    write.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(app.toast).not.toHaveBeenCalled();
  });

  it('does not send clipboard text if clipboard is disabled while the browser read is pending', async () => {
    const read = deferred<string | null>();
    readLocalClipboardText.mockReturnValueOnce(read.promise);
    const app = Object.create(RdApp.prototype) as any;
    app.clipboardEnabled = true;
    app.permissions = { Clipboard: true };
    app.toast = vi.fn();
    app.post = vi.fn();

    const pending = app.sendClipboard();
    app.clipboardEnabled = false;
    read.resolve('do not send');
    await pending;

    expect(app.post).not.toHaveBeenCalled();
  });

  it('does not let a clipboard read from one session post into its replacement', async () => {
    const read = deferred<string | null>();
    readLocalClipboardText.mockReturnValueOnce(read.promise);
    const app = Object.create(RdApp.prototype) as any;
    app.sessionEpoch = 1;
    app.clipboardEnabled = true;
    app.permissions = { Clipboard: true };
    app.toast = vi.fn();
    app.post = vi.fn();

    const pending = app.sendClipboard();
    app.sessionEpoch = 2;
    app.clipboardEnabled = true;
    app.permissions = { Clipboard: true };
    read.resolve('must not cross peers');
    await pending;

    expect(app.post).not.toHaveBeenCalled();
  });

  it('resets peer permissions during disconnect teardown', () => {
    const app = Object.create(RdApp.prototype) as any;
    app.recording = false;
    app.sessionEpoch = 7;
    app.permissions = { Clipboard: false };
    app.el = { recordingIndicator: { hidden: false } };
    app.removeClipboardSyncOffer = vi.fn();
    app.teardownMse = vi.fn();
    app.resetPermissions = vi.fn();

    app.teardown();

    expect(app.resetPermissions).toHaveBeenCalledOnce();
    expect(app.sessionEpoch).toBe(8);
  });
});
