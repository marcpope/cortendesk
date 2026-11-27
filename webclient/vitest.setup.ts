import { beforeAll } from 'vitest';
import { sodiumReady } from './src/core/crypto';

// Session decodes the server key in its constructor, so libsodium has to be
// loaded before any test builds one. Without this a test file that never
// awaits it passes or fails on timing.
beforeAll(() => sodiumReady());
