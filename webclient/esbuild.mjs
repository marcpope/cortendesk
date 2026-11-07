// Build the CortenDesk web client into ../public/rdclient/ (committed output,
// honoring the CortenDesk no-build-step rule for the app itself: built once on
// the dev machine, the bundle is served statically by Laravel).
//
//   npm run build            one-shot production bundle
//   npm run build -- --watch rebuild on change (dev only)
//
// Two independent entry points:
//   src/ui/app.ts             -> ../public/rdclient/app.js       (main thread)
//   src/worker/session.worker -> ../public/rdclient/session.worker.js (worker)
//
// WASM (libsodium-wrappers ships base64-inlined already; zstddec ships a .wasm)
// is inlined as base64 data URIs via the `binary` loader so the output stays a
// self-contained static asset tree with no extra fetches.

import { build, context } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';
import { rmSync } from 'node:fs';

const require = createRequire(import.meta.url);
const __dirname = dirname(fileURLToPath(import.meta.url));
const outdir = resolve(__dirname, '../public/rdclient');
const watch = process.argv.includes('--watch');
const dev = watch || process.argv.includes('--dev');

/** @type {import('esbuild').BuildOptions} */
const shared = {
  bundle: true,
  format: 'esm',
  // safari16: iPhone/iPad Safari (and every iOS browser, all WebKit).
  target: ['chrome110', 'edge110', 'safari16'],
  platform: 'browser',
  sourcemap: true,
  minify: !dev,
  legalComments: 'linked',
  logLevel: 'info',
  loader: {
    '.wasm': 'binary',
    '.css': 'css',
  },
  alias: {
    // libsodium-wrappers' ESM build imports './libsodium.mjs' from the sibling
    // `libsodium` package — unresolvable. Bundle the CJS build instead (same
    // alias as vitest.config.ts).
    'libsodium-wrappers': require.resolve('libsodium-wrappers'),
    // jMuxer imports node's `stream` for its optional createStream() wrapper,
    // which this client never calls. Stub it rather than bundling a polyfill
    // for an unreachable path.
    stream: resolve(__dirname, 'src/shims/node-stream.ts'),
  },
  define: {
    'process.env.NODE_ENV': dev ? '"development"' : '"production"',
  },
};

// The app is split so code it loads on demand (xterm, for the terminal) lands
// in chunks/ instead of app.js. Chunk names carry a content hash, so a cached
// app.js never pairs with a newer chunk. The worker stays a single file.
const builds = [
  { ...shared, entryPoints: { app: resolve(__dirname, 'src/ui/app.ts') }, outdir, splitting: true, chunkNames: 'chunks/[name]-[hash]' },
  { ...shared, entryPoints: { 'session.worker': resolve(__dirname, 'src/worker/session.worker.ts') }, outdir },
];

// Chunks from earlier builds are never referenced again.
rmSync(resolve(outdir, 'chunks'), { recursive: true, force: true });

if (watch) {
  for (const options of builds) await (await context(options)).watch();
  console.log(`[esbuild] watching -> ${outdir}`);
} else {
  await Promise.all(builds.map((options) => build(options)));
  console.log(`[esbuild] built -> ${outdir}`);
}
