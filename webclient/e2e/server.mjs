// Static server for the e2e fixture: the built client from ../../public/rdclient,
// the fixture page and the mock session worker. No Laravel, no RustDesk.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const roots = {
  '/rdclient/': resolve(here, '../../public/rdclient'),
  '/assets/': resolve(here, '../../public/assets'),
  '/': resolve(here, 'fixture'),
};
const types = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png',
  '.map': 'application/json', '.woff2': 'font/woff2', '.woff': 'font/woff', '.webmanifest': 'application/manifest+json', '.txt': 'text/plain',
};
const port = Number(process.env.PORT || 4173);

createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  const prefix = Object.keys(roots).find((p) => p !== '/' && path.startsWith(p)) ?? '/';
  const rel = path === '/' ? 'index.html' : path.slice(prefix.length);
  const file = normalize(join(roots[prefix], rel));
  if (!file.startsWith(roots[prefix])) {
    res.writeHead(403).end();
    return;
  }
  try {
    const body = await readFile(file);
    res.writeHead(200, { 'content-type': types[extname(file)] ?? 'application/octet-stream', 'cache-control': 'no-store' });
    res.end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
}).listen(port, '127.0.0.1', () => console.log(`e2e fixture on http://127.0.0.1:${port}`));
