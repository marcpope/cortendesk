import { describe, expect, it, vi } from 'vitest';
import { VoiceCallAttemptOwner } from './voice-call-attempt';

type Deferred<T> = { promise: Promise<T>; resolve(value: T): void };
function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  return { promise: new Promise<T>((r) => { resolve = r; }), resolve };
}

it('does not let an old preparation completion mutate a replacement call', async () => {
  const owner = new VoiceCallAttemptOwner();
  const oldPreparation = deferred<{ ok: boolean }>();
  const replacementPreparation = deferred<{ ok: boolean }>();
  const oldContinuation = vi.fn();
  const replacementContinuation = vi.fn();

  const oldToken = owner.begin();
  const oldResult = owner.wait(oldToken, () => oldPreparation.promise).then((result) => {
    if (result.owned) oldContinuation(result.value);
  });

  owner.invalidate();
  const replacementToken = owner.begin();
  const replacementResult = owner.wait(replacementToken, () => replacementPreparation.promise).then((result) => {
    if (result.owned) replacementContinuation(result.value);
  });

  replacementPreparation.resolve({ ok: true });
  await replacementResult;
  oldPreparation.resolve({ ok: false });
  await oldResult;

  expect(replacementContinuation).toHaveBeenCalledWith({ ok: true });
  expect(oldContinuation).not.toHaveBeenCalled();
});
