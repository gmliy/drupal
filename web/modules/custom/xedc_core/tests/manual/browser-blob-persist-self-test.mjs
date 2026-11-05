/**
 * Chromium IndexedDB Blob persistence self-test for upload-manager.
 *
 * Verifies that after full-file SHA-256 (arrayBuffer), storing a slice() clone
 * into IndexedDB still works, and that enqueue() persists recoverable Blobs.
 */
import { createRequire } from 'module';
import fs from 'fs';
import http from 'http';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const managerSrc = fs.readFileSync(path.join(__dirname, '../../js/upload-manager.js'), 'utf8');

const html = `<!doctype html><html><body>
<script>
window.Drupal = { behaviors: {} };
window.drupalSettings = {
  xedcUpload: {
    entry: 'admin', chunkBytes: 1024, headBytes: 64, concurrency: 1,
    sessionsUrl: '/mock/sessions', sessionUrl: '/mock/session', sessionBase: '/mock/session/',
    probeUrl: '/mock/probe', draftUrl: '', closeUrl: '', progressBase: '/mock/', syncUrl: '/mock/sync',
    maxArchive: 209715200, maxImage: 52428800
  }
};
window.fetch = async (url, options = {}) => {
  const path = String(url);
  if (path.includes('/session/token')) {
    return new Response('test-csrf-token', { status: 200 });
  }
  if (path.includes('/mock/sessions') || path.includes('/mock/sync')) {
    return new Response(JSON.stringify({ sessions: [], tasks: [] }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  if (path.includes('/mock/probe')) {
    return new Response(JSON.stringify({ reusable: false, upload: true }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  if (path.includes('/mock/session') && (options.method || 'GET') === 'POST' && !path.includes('/chunk')) {
    return new Response(JSON.stringify({
      session_id: 'sess-' + Math.random().toString(16).slice(2),
      status: 'open',
      received_bytes: 0,
      total_bytes: 0,
      missing_chunks: [],
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }
  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
};
</script>
<script>${managerSrc}</script>
<script>
(async () => {
  const out = { ok: false, steps: [] };
  try {
    indexedDB.deleteDatabase('xedc-upload-v2');
    await new Promise((r) => setTimeout(r, 50));
    const manager = Drupal.xedcUpload;
    await manager.start();

    function makeFile(bytes, name, type) {
      const data = new Uint8Array(bytes);
      for (let i = 0; i < data.length; i++) data[i] = (i * 17 + 3) & 255;
      return new File([data], name, { type });
    }
    async function sha256(blob) {
      const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
      return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    }

    const image = makeFile(220000, 'gallery-shot.jpg', 'image/jpeg');
    const zip = makeFile(1100000, 'pack.zip', 'application/zip');

    // Poison path: hash first (full arrayBuffer), then try to IDB-put the same File.
    await sha256(image);
    let directPutError = '';
    try {
      await new Promise((resolve, reject) => {
        const req = indexedDB.open('xedc-upload-direct-probe', 1);
        req.onupgradeneeded = () => req.result.createObjectStore('jobs', { keyPath: 'id' });
        req.onerror = () => reject(req.error);
        req.onsuccess = () => {
          const db = req.result;
          const tx = db.transaction('jobs', 'readwrite');
          tx.objectStore('jobs').put({ id: 'direct', blob: image });
          tx.oncomplete = () => { db.close(); resolve(); };
          tx.onerror = () => { const err = tx.error; db.close(); reject(err || new Error('direct put failed')); };
        };
      });
      out.steps.push({ directPutAfterHash: 'ok' });
    } catch (e) {
      directPutError = String((e && e.message) || e);
      out.steps.push({ directPutAfterHash: 'failed', error: directPutError });
    }

    // Manager path: enqueue clones before/after hashing internally.
    const imgs = await manager.enqueue([image], { kind: 'image', requireTitle: false, entry: 'admin', nid: 1, title: 'persist-img' });
    const zips = await manager.enqueue([zip], { kind: 'archive', requireTitle: false, entry: 'admin', nid: 1, title: 'persist-zip' });
    out.steps.push({
      enqueue: imgs.length + zips.length,
      persistOk: manager.persistOk(),
      persistError: manager.persistError() || '',
      warned: manager.list().filter((j) => j.persistWarned).length,
    });

    const db = await new Promise((resolve, reject) => {
      const req = indexedDB.open('xedc-upload-v2', 2);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    const rows = await new Promise((resolve, reject) => {
      const tx = db.transaction('jobs', 'readonly');
      const r = tx.objectStore('jobs').getAll();
      r.onsuccess = () => resolve(r.result || []);
      r.onerror = () => reject(r.error);
    });
    db.close();
    const withBlob = rows.filter((row) => (row.blob && row.blob.size) || (row.blobEncoding === 'arraybuffer' && row.blobBuffer));
    out.steps.push({ idbRows: rows.length, withBlob: withBlob.length, sampleEncoding: withBlob[0] && withBlob[0].blobEncoding });

    out.ok = withBlob.length >= 1 && manager.persistOk() !== false && (manager.persistError() || '') === '';
    out.directPutError = directPutError;
  } catch (e) {
    out.error = String((e && e.stack) || e);
  }
  document.body.dataset.result = JSON.stringify(out);
})();
</script>
</body></html>`;

const server = http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(html);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const { port } = server.address();

const browser = await chromium.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});
try {
  const page = await browser.newPage();
  await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle', timeout: 60000 });
  await page.waitForFunction(() => document.body.dataset.result, { timeout: 60000 });
  const result = JSON.parse(await page.evaluate(() => document.body.dataset.result));
  console.log(JSON.stringify(result, null, 2));
  if (!result.ok) process.exitCode = 1;
}
finally {
  await browser.close();
  server.close();
}
