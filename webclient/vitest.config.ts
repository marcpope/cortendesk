import { defineConfig } from 'vitest/config';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Unit tests run under Node. libsodium-wrappers' ESM build imports a sibling
// package Node cannot resolve; use the CJS build, as esbuild.mjs does.
export default defineConfig({
  resolve: {
    alias: {
      'libsodium-wrappers': require.resolve('libsodium-wrappers'),
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
