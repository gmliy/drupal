/**
 * Real-browser acceptance using Chromium + live site JS (Drupal.xedcUpload).
 *
 * LOGIN_URL='https://gitxx.xyz/user/reset/...' node .../browser-upload-cancel-acceptance.mjs
 */

import { createRequire } from 'module';
import fs from 'fs';
import { spawnSync } from 'child_process';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const LOGIN = process.env.LOGIN_URL || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = '/tmp/xedc-browser-cancel-acceptance.json';
const results = [];

function record(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(`${status}  ${name}${detail ? ' :: ' + detail : ''}`);
}

function makeJpeg(filePath, targetBytes) {
  const seed = `${Date.now()}-${Math.random()}`;
  const php = `<?php
  \$path = ${JSON.stringify(filePath)};
  \$want = ${Number(targetBytes)};
  \$seed = ${JSON.stringify(seed)};
  \$im = imagecreatetruecolor(2800, 2800);
  for (\$y = 0; \$y < 2800; \$y += 11) {
    \$c = imagecolorallocate(\$im, (\$y * 7 + strlen(\$seed)) % 255, (\$y * 3) % 200, (\$y * 11) % 255);
    imagefilledrectangle(\$im, 0, \$y, 2799, min(2799, \$y + 10), \$c);
  }
  imagestring(\$im, 5, 20, 20, substr(\$seed, 0, 40), imagecolorallocate(\$im, 255, 255, 0));
  imagejpeg(\$im, \$path, 93);
  imagedestroy(\$im);
  \$n = 0;
  while (filesize(\$path) < \$want && \$n < 10) {
    \$n++;
    \$w = 3000 + \$n * 250;
    \$im = imagecreatetruecolor(\$w, \$w);
    for (\$y = 0; \$y < \$w; \$y += 9) {
      \$c = imagecolorallocate(\$im, random_int(0,255), random_int(0,255), random_int(0,255));
      imagefilledrectangle(\$im, 0, \$y, \$w-1, min(\$w-1, \$y+8), \$c);
    }
    imagestring(\$im, 5, 10, 10, \$seed . "-$n", imagecolorallocate(\$im, 0, 0, 0));
    imagejpeg(\$im, \$path, 96);
    imagedestroy(\$im);
  }
  echo filesize(\$path);
  `;
  fs.writeFileSync('/tmp/xedc_make_jpeg.php', php);
  const out = spawnSync('php', ['/tmp/xedc_make_jpeg.php'], { encoding: 'utf8' });
  if (out.status !== 0) throw new Error(out.stderr || 'jpeg failed');
  return Number(out.stdout.trim());
}

function makeZip(filePath, targetBytes) {
  const php = `<?php
  \$zipPath = ${JSON.stringify(filePath)};
  \$want = ${Number(targetBytes)};
  \$zip = new ZipArchive();
  \$zip->open(\$zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
  \$payloadName = 'payload-' . bin2hex(random_bytes(8)) . '.bin';
  \$payload = random_bytes(max(8*1024*1024, (int)(\$want * 0.85)));
  \$zip->addFromString(\$payloadName, \$payload);
  \$zip->setCompressionName(\$payloadName, ZipArchive::CM_STORE);
  \$img = imagecreatetruecolor(900, 900);
  \$c = imagecolorallocate(\$img, random_int(0,255), random_int(0,255), random_int(0,255));
  imagefilledrectangle(\$img, 0, 0, 899, 899, \$c);
  ob_start(); imagejpeg(\$img, null, 92); \$jpeg = ob_get_clean(); imagedestroy(\$img);
  \$zip->addFromString('a-' . bin2hex(random_bytes(4)) . '.jpg', \$jpeg);
  \$zip->setCompressionIndex(\$zip->numFiles - 1, ZipArchive::CM_STORE);
  \$zip->close();
  echo filesize(\$zipPath);
  `;
  fs.writeFileSync('/tmp/xedc_make_zip.php', php);
  const out = spawnSync('php', ['/tmp/xedc_make_zip.php'], { encoding: 'utf8' });
  if (out.status !== 0) throw new Error(out.stderr || 'zip failed');
  return Number(out.stdout.trim());
}

async function login(page) {
  if (!LOGIN) throw new Error('LOGIN_URL required');
  await page.goto(LOGIN, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.goto(`${BASE}/admin/content/image-imports/image`, { waitUntil: 'networkidle', timeout: 60000 });
  await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload), null, { timeout: 30000 });
}

async function main() {
  const imgPath = '/tmp/xedc_accept_mid.jpg';
  const zipPath = '/tmp/xedc_accept_mid.zip';
  const dualPath = '/tmp/xedc_accept_dual.jpg';
  const smallPath = '/tmp/xedc_accept_small.jpg';
  record('准备测试图片', 'PASS', String(makeJpeg(imgPath, 16 * 1024 * 1024)));
  record('准备测试 ZIP', 'PASS', String(makeZip(zipPath, 20 * 1024 * 1024)));
  record('准备双标签图片', 'PASS', String(makeJpeg(dualPath, 16 * 1024 * 1024)));
  record('准备小图', 'PASS', String(makeJpeg(smallPath, 180000)));

  const browser = await chromium.launch({
    headless: true,
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  page.on('dialog', (d) => d.accept().catch(() => {}));

  try {
    await login(page);
    record('管理员登录并加载 upload manager', 'PASS', page.url());

    // Helper installed in page: enqueue file buffer + poll + cancel.
    async function runMidCancel(kind, filePath, label) {
      const b64 = fs.readFileSync(filePath).toString('base64');
      const name = filePath.split('/').pop();
      const mime = kind === 'archive' ? 'application/zip' : 'image/jpeg';
      const title = `${label}-${Date.now()}`;
      const result = await page.evaluate(async ({ kind, name, mime, title, b64 }) => {
        const manager = Drupal.xedcUpload;
        await manager.start();
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i);
        const file = new File([bytes], name, { type: mime });
        const created = await manager.enqueue([file], {
          kind: kind === 'archive' ? 'archive' : (kind === 'quick' ? 'quick' : 'image'),
          entry: kind === 'quick' ? 'quick' : 'admin',
          title,
        });
        const jobId = created[0] && created[0].id;
        if (!jobId) return { ok: false, error: 'enqueue failed' };
        if (manager.applyTitle) await manager.applyTitle(title);
        const started = Date.now();
        while (Date.now() - started < 60000) {
          const job = manager.list().find((j) => j.id === jobId);
          if (!job) break;
          // Cancel after the first confirmed chunk while complete has not won yet.
          if (job.sessionId && !job.taskId && ['uploading', 'hashing', 'pending', 'retrying', 'open'].includes(job.phase) && job.confirmed > 0 && job.confirmed < job.bytes) {
            const saw = { confirmed: job.confirmed, bytes: job.bytes, phase: job.phase, sessionId: job.sessionId };
            const cancel = await manager.cancel(jobId);
            await new Promise((r) => setTimeout(r, 800));
            const after = manager.list().find((j) => j.id === jobId);
            const cfg = drupalSettings.xedcUpload || {};
            let server = null;
            const res = await fetch((cfg.sessionBase || '/admin/content/image-imports/upload/session/') + saw.sessionId, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            });
            server = { http: res.status, body: await res.json().catch(() => ({})) };
            const sessionsRes = await fetch(cfg.sessionsUrl || '/admin/content/image-imports/upload/sessions', {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            });
            const sessions = await sessionsRes.json().catch(() => ({}));
            const active = (sessions.sessions || []).filter((s) => s.session_id === saw.sessionId && ['open', 'uploading', 'merging'].includes(s.status));
            return {
              ok: true,
              saw,
              cancel,
              afterPhase: after ? after.phase : 'removed',
              server,
              activeCount: active.length,
            };
          }
          if (['completed', 'duplicate', 'queued', 'failed'].includes(job.phase) && job.taskId) {
            return { ok: false, error: 'finished before mid-cancel', phase: job.phase, taskId: job.taskId };
          }
          await new Promise((r) => setTimeout(r, 150));
        }
        const job = manager.list().find((j) => j.id === jobId);
        return { ok: false, error: 'timeout waiting mid progress', phase: job && job.phase, confirmed: job && job.confirmed, bytes: job && job.bytes, sessionId: job && job.sessionId };
      }, { kind, name, mime, title, b64 });

      if (!result.ok) {
        record(`${label} 中途进度`, 'FAIL', JSON.stringify(result));
        return result;
      }
      record(`${label} 中途进度`, 'PASS', `${result.saw.confirmed}/${result.saw.bytes}`);
      record(`${label} cancel 返回`, result.cancel && (result.cancel.ok || result.cancel.outcome === 'cancelled' || result.cancel.serverOk !== false && result.cancel.ok !== false)
        ? (result.cancel.ok ? 'PASS' : (result.cancel.outcome === 'cancelled' || result.cancel.ok ? 'PASS' : 'FAIL'))
        : 'FAIL', JSON.stringify(result.cancel));
      // Treat ok:true or outcome cancelled/already_cancelled as success.
      const cancelOk = !!(result.cancel && (result.cancel.ok === true || ['cancelled', 'already_cancelled'].includes(result.cancel.outcome)));
      if (!cancelOk) {
        // overwrite previous vague line with explicit
        record(`${label} 服务端取消成功`, 'FAIL', JSON.stringify(result.cancel));
      }
      else {
        record(`${label} 服务端取消成功`, 'PASS', result.cancel.outcome || 'ok');
      }
      record(`${label} 本地任务已停止`, ['removed', 'cancelled', 'cancel_pending'].includes(result.afterPhase) || result.afterPhase === 'removed' ? 'PASS' : 'FAIL', String(result.afterPhase));
      record(`${label} 无活动 Session`, result.activeCount === 0 ? 'PASS' : 'FAIL', String(result.activeCount));
      const serverStatus = result.server && result.server.body && result.server.body.status;
      record(`${label} Session 状态 cancelled/410`, (serverStatus === 'cancelled' || result.server?.http === 410 || result.server?.http === 404) ? 'PASS' : 'FAIL', JSON.stringify({ http: result.server?.http, status: serverStatus }));

      // Refresh consistency.
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload), null, { timeout: 20000 });
      const revived = await page.evaluate(async (sessionId) => {
        await Drupal.xedcUpload.start();
        await Drupal.xedcUpload.syncSessions();
        const jobs = Drupal.xedcUpload.list().filter((j) => j.sessionId === sessionId && ['uploading', 'pending', 'retrying', 'open'].includes(j.phase));
        const cfg = drupalSettings.xedcUpload || {};
        const res = await fetch(cfg.sessionsUrl || '/admin/content/image-imports/upload/sessions', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const json = await res.json().catch(() => ({}));
        const sessions = (json.sessions || []).filter((s) => s.session_id === sessionId && ['open', 'uploading'].includes(s.status));
        return { jobs: jobs.length, sessions: sessions.length };
      }, result.saw.sessionId);
      record(`${label} 刷新后不恢复上传`, revived.jobs === 0 && revived.sessions === 0 ? 'PASS' : 'FAIL', JSON.stringify(revived));
      return result;
    }

    // Ensure image page for admin upload settings.
    await page.goto(`${BASE}/admin/content/image-imports/image`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload), null, { timeout: 20000 });
    await runMidCancel('image', imgPath, '普通图片');

    await page.goto(`${BASE}/admin/content/image-imports/archive`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload), null, { timeout: 20000 });
    await runMidCancel('archive', zipPath, 'ZIP');

    await page.goto(`${BASE}/admin/quick-gallery`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload), null, { timeout: 20000 });
    await runMidCancel('quick', imgPath, '快速发布');

    // Dual tabs: start on A, cancel on B.
    const page2 = await context.newPage();
    page2.on('dialog', (d) => d.accept().catch(() => {}));
    await page.goto(`${BASE}/admin/content/image-imports/image`, { waitUntil: 'networkidle' });
    await page2.goto(`${BASE}/admin/content/image-imports/image`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload));
    await page2.waitForFunction(() => !!(window.Drupal && Drupal.xedcUpload));
    const dual = await page.evaluate(async ({ b64, name }) => {
      const manager = Drupal.xedcUpload;
      await manager.start();
      const bin = atob(b64);
      const bytes = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i);
      const file = new File([bytes], name, { type: 'image/jpeg' });
      const title = 'dual-' + Date.now();
      const created = await manager.enqueue([file], { kind: 'image', entry: 'admin', title });
      if (manager.applyTitle) await manager.applyTitle(title);
      const id = created[0].id;
      const started = Date.now();
      while (Date.now() - started < 60000) {
        const job = manager.list().find((j) => j.id === id);
        if (job && job.sessionId && !job.taskId && job.confirmed > 0 && job.confirmed < job.bytes && ['uploading', 'retrying', 'hashing', 'pending', 'open'].includes(job.phase)) {
          return { id, sessionId: job.sessionId, confirmed: job.confirmed, bytes: job.bytes, phase: job.phase };
        }
        await new Promise((r) => setTimeout(r, 150));
      }
      return { error: 'no mid progress', job: manager.list().find((j) => j.id === id) };
    }, { b64: fs.readFileSync(dualPath).toString('base64'), name: 'dual.jpg' });

    if (dual.error) {
      record('双标签中途进度', 'FAIL', JSON.stringify(dual));
    }
    else {
      record('双标签 A 上传中', 'PASS', `${dual.confirmed}/${dual.bytes}`);
      // Start B immediately; if complete already won, handed_off is the correct protection result.
      const cancelB = await page2.evaluate(async (payload) => {
        await Drupal.xedcUpload.start();
        await Drupal.xedcUpload.syncSessions();
        let job = Drupal.xedcUpload.list().find((j) => j.id === payload.id || j.sessionId === payload.sessionId);
        if (!job) {
          return { ok: false, error: 'job not visible in tab B', jobs: Drupal.xedcUpload.list().map((j) => ({ id: j.id, sessionId: j.sessionId, phase: j.phase })) };
        }
        return Object.assign({ seenPhase: job.phase, seenTaskId: job.taskId || 0 }, await Drupal.xedcUpload.cancel(job.id));
      }, { id: dual.id, sessionId: dual.sessionId });

      let finalCancel = cancelB;
      if (cancelB && cancelB.error === 'job not visible in tab B') {
        finalCancel = await page.evaluate(async (id) => Drupal.xedcUpload.cancel(id), dual.id);
        record('双标签 B 同步可见性', 'FAIL', JSON.stringify(cancelB));
        record('双标签回退由 A 取消', finalCancel && (finalCancel.ok || finalCancel.outcome === 'handed_off') ? 'PASS' : 'FAIL', JSON.stringify(finalCancel));
      }
      else {
        record('双标签 B 能看到任务', 'PASS', `phase=${cancelB.seenPhase || ''}`);
        const okCancel = !!(finalCancel && (finalCancel.ok === true || finalCancel.outcome === 'handed_off' || finalCancel.outcome === 'already_cancelled' || finalCancel.outcome === 'completing'));
        record('双标签 B 取消裁决', okCancel ? 'PASS' : 'FAIL', JSON.stringify(finalCancel));
      }

      await page.waitForTimeout(800);
      const aState = await page.evaluate((id) => {
        const job = Drupal.xedcUpload.list().find((j) => j.id === id);
        return job ? { phase: job.phase, taskId: job.taskId || 0 } : { phase: 'removed', taskId: 0 };
      }, dual.id);
      const server = await page.evaluate(async (sessionId) => {
        const cfg = drupalSettings.xedcUpload || {};
        const res = await fetch((cfg.sessionBase || '/admin/content/image-imports/upload/session/') + sessionId, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        return { http: res.status, body: await res.json().catch(() => ({})) };
      }, dual.sessionId);
      const status = server.body && server.body.status;
      if (finalCancel && finalCancel.ok) {
        record('双标签取消胜：A 停止且服务端 cancelled', (['removed', 'cancelled', 'cancel_pending'].includes(aState.phase) && status === 'cancelled') ? 'PASS' : 'FAIL', JSON.stringify({ aState, status }));
      }
      else if (finalCancel && finalCancel.outcome === 'handed_off') {
        record('双标签 complete 胜：任务保留不可删', (aState.taskId > 0 || (server.body && server.body.task_id > 0)) && finalCancel.ok === false ? 'PASS' : 'FAIL', JSON.stringify({ aState, server: { status, task_id: server.body && server.body.task_id } }));
      }
      else {
        record('双标签最终服务端状态可解释', ['cancelled', 'completed'].includes(status) ? 'PASS' : 'FAIL', JSON.stringify({ finalCancel, status, aState }));
      }
    }

    // Hand-off protection with small image.
    const hand = await page.evaluate(async ({ b64, name }) => {
      const manager = Drupal.xedcUpload;
      await manager.start();
      const bin = atob(b64);
      const bytes = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i);
      const file = new File([bytes], name, { type: 'image/jpeg' });
      const title = 'handoff-' + Date.now();
      const created = await manager.enqueue([file], { kind: 'image', entry: 'admin', title });
      if (manager.applyTitle) await manager.applyTitle(title);
      const id = created[0].id;
      const started = Date.now();
      while (Date.now() - started < 90000) {
        const job = manager.list().find((j) => j.id === id);
        if (!job) return { error: 'job vanished' };
        if (job.taskId > 0 || ['queued', 'processing', 'importing', 'completed', 'duplicate', 'review'].includes(job.phase)) {
          const can = manager.canCancel(job);
          const cancel = await manager.cancel(id);
          return { phase: job.phase, taskId: job.taskId, canCancel: can, cancel, still: manager.list().find((j) => j.id === id) };
        }
        await new Promise((r) => setTimeout(r, 250));
      }
      return { error: 'timeout', job: manager.list().find((j) => j.id === id) };
    }, { b64: fs.readFileSync(smallPath).toString('base64'), name: 'small.jpg' });

    if (hand.error) {
      record('交接后取消保护', 'FAIL', JSON.stringify(hand));
    }
    else {
      record('小图已交接得到 taskId/阶段', hand.taskId > 0 || hand.phase ? 'PASS' : 'FAIL', JSON.stringify({ taskId: hand.taskId, phase: hand.phase }));
      record('交接后 canCancel=false', hand.canCancel === false ? 'PASS' : 'FAIL', String(hand.canCancel));
      record('交接后 cancel 不删除任务', hand.cancel && hand.cancel.ok === false ? 'PASS' : 'FAIL', JSON.stringify(hand.cancel));
      record('交接后本地仍可跟踪', !!(hand.still && hand.still.taskId) ? 'PASS' : 'FAIL', JSON.stringify(hand.still && { phase: hand.still.phase, taskId: hand.still.taskId }));
    }
  }
  catch (error) {
    record('浏览器脚本异常', 'FAIL', String(error && error.message ? error.message : error));
  }
  finally {
    await browser.close();
    fs.writeFileSync(OUT, JSON.stringify(results, null, 2));
    const fail = results.filter((r) => r.status === 'FAIL').length;
    const pass = results.filter((r) => r.status === 'PASS').length;
    console.log(`SUMMARY pass=${pass} fail=${fail} out=${OUT}`);
    process.exit(fail > 0 ? 1 : 0);
  }
}

main();
