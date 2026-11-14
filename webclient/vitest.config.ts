import { defineConfig } from 'vitest/config';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Unit tests run under Node (crypto vectors, protobuf round-trips, sans-IO
// session core). No DOM needed for the byte-exact suites; UI/media tests that
// touch WebCodecs/OffscreenCanvas are gated behind their own describe.skipIf.
export default defineConfig({
  resolve: {
    alias: {
      // libsodium-wrappers 0.7.x ESM build imports './libsodium.mjs', which
      // ships in the sibling `libsodium` package — broken under Node ESM
      // resolution. Alias to the CJS build for tests. (esbuild browser
      // bundles need the same alias when the entrypoints land.)
      'libsodium-wrappers': require.resolve('libsodium-wrappers'),
    },
  },
  test: {
    globals: true,
    environment: 'node',
    include: ['src/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/core/**', 'src/media/**'],
    },
  },
});
