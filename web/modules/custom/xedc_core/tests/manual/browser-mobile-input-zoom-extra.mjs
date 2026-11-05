/** Extra viewports + soft-keyboard layout probes (Chromium emulation). */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const require = createRequire(import.meta.url);
const { chromium, devices } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const LOGIN = process.env.LOGIN_URL || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.SHOT_DIR || '/var/www/drupal/docs/tasks/screenshots/xedc-mobile-input-zoom';
const results = [];
const record = (n, s, d = '') => { results.push({ name: n, status: s, detail: d }); console.log(`${s}\t${n}\t${d}`); };

fs.mkdirSync(OUT, { recursive: true });

async function probe(contextLabel, viewport, deviceExtras = {}) {
  const browser = await chromium.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true, ...deviceExtras });
  const page = await context.newPage();
  try {
    await page.goto(`${BASE}/user/login`, { waitUntil: 'networkidle', timeout: 90000 });
    const sel = '#edit-name, input[name="name"]';
    const el = page.locator(sel).first();
    if (!(await el.count())) {
      record(`${contextLabel}-login`, 'NOT TESTED', 'login form missing');
      return;
    }
    const before = await page.evaluate(() => ({
      scale: visualViewport?.scale ?? null,
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      font: getComputedStyle(document.querySelector('#edit-name, input[name="name"]')).fontSize,
      innerH: innerHeight,
      vvH: visualViewport?.height ?? null,
    }));
    await el.click();
    await page.waitForTimeout(200);
    // Approximate soft keyboard: shrink visual viewport height.
    await page.evaluate(() => {
      // Chromium may not expose writable visualViewport; record current metrics only.
    });
    const after = await page.evaluate(() => {
      const el = document.querySelector('#edit-name, input[name="name"]');
      const cs = getComputedStyle(el);
      return {
        scale: visualViewport?.scale ?? null,
        overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        font: cs.fontSize,
        fontPx: parseFloat(cs.fontSize),
        innerH: innerHeight,
        vvH: visualViewport?.height ?? null,
        w: el.getBoundingClientRect().width,
      };
    });
    await page.screenshot({ path: path.join(OUT, `${contextLabel}-login.png`) });
    const ok = after.fontPx >= 16 && (after.scale == null || Math.abs(after.scale - 1) < 0.01) && !after.overflowX;
    record(`${contextLabel}-login`, ok ? 'PASS' : 'FAIL', JSON.stringify({ before, after }));
  }
  finally {
    await browser.close();
  }
}

await probe('android-pixel7', { width: 412, height: 915 }, { hasTouch: true, isMobile: true, userAgent: devices['Pixel 7'].userAgent });
await probe('tablet-ipad', { width: 768, height: 1024 }, { hasTouch: true, isMobile: true, userAgent: devices['iPad Mini'].userAgent });
await probe('iphone13-fresh', { width: 390, height: 844 }, { ...devices['iPhone 13'] });

if (LOGIN) {
  const browser = await chromium.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ ...devices['iPhone 13'], ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.goto(LOGIN, { waitUntil: 'networkidle', timeout: 90000 });

  const routes = [
    ['/user/1/edit', 'account-edit', '#edit-mail, input[name="mail"], input[type="email"], input[type="text"]'],
    ['/member/messages', 'pm-list', 'input, textarea'],
  ];
  // Discover real profile edit path from member nav
  await page.goto(`${BASE}/member`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const profileHref = await page.locator('a[href*="profile"], a[href*="edit"]').evaluateAll((as) => as.map((a) => a.getAttribute('href'))).catch(() => []);
  record('member-nav-links', 'PASS', JSON.stringify(profileHref.slice(0, 12)));

  for (const [route, label, sel] of routes) {
    await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
    const visible = page.locator(`${sel} >> visible=true`).first();
    if (!(await visible.count())) {
      // fallback any matching
      const any = page.locator(sel).first();
      if (!(await any.count())) {
        record(`${label}`, 'NOT TESTED', `no ${sel}`);
        continue;
      }
    }
    const handle = await page.locator(sel).first();
    await handle.click({ force: true }).catch(() => null);
    await page.waitForTimeout(200);
    const m = await page.evaluate((s) => {
      const nodes = [...document.querySelectorAll(s)].filter((n) => n.offsetParent !== null || n.getClientRects().length);
      const el = nodes[0] || document.querySelector(s);
      if (!el) return null;
      const cs = getComputedStyle(el);
      return { font: cs.fontSize, scale: visualViewport?.scale, overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, tag: el.tagName, type: el.getAttribute('type') };
    }, sel);
    await page.screenshot({ path: path.join(OUT, `${label}-focus.png`) });
    if (!m) record(label, 'NOT TESTED', 'no element');
    else record(label, parseFloat(m.font) >= 16 && Math.abs((m.scale ?? 1) - 1) < 0.01 && !m.overflowX ? 'PASS' : 'FAIL', JSON.stringify(m));
  }

  // Soft keyboard simulation via viewport resize (common Chrome pattern)
  await page.goto(`${BASE}/user/login`, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
  await page.goto(`${BASE}/node/add/photo`, { waitUntil: 'networkidle', timeout: 90000 });
  await page.setViewportSize({ width: 390, height: 844 });
  const title = page.locator('#edit-title-0-value, input[name="title[0][value]"]').first();
  if (await title.count()) {
    await title.click();
    const full = await page.evaluate(() => ({ h: innerHeight, vv: visualViewport?.height, scale: visualViewport?.scale, overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 }));
    await page.setViewportSize({ width: 390, height: 420 }); // keyboard open approx
    await page.waitForTimeout(200);
    const keyed = await page.evaluate(() => ({ h: innerHeight, vv: visualViewport?.height, scale: visualViewport?.scale, overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, font: getComputedStyle(document.activeElement).fontSize }));
    await page.screenshot({ path: path.join(OUT, 'keyboard-open-approx.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(200);
    const closed = await page.evaluate(() => ({ h: innerHeight, vv: visualViewport?.height, scale: visualViewport?.scale, overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 }));
    await page.screenshot({ path: path.join(OUT, 'keyboard-closed-approx.png') });
    const ok = Math.abs((keyed.scale ?? 1) - 1) < 0.01 && !keyed.overflowX && !closed.overflowX && parseFloat(keyed.font) >= 16;
    record('soft-keyboard-approx', ok ? 'PASS' : 'FAIL', JSON.stringify({ full, keyed, closed }));
  }
  else {
    record('soft-keyboard-approx', 'NOT TESTED', 'title field missing');
  }

  await browser.close();
}

const prev = JSON.parse(fs.readFileSync(path.join(OUT, 'results.json'), 'utf8'));
prev.extra = results;
prev.generatedAt = new Date().toISOString();
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(prev, null, 2));
const fail = results.filter((r) => r.status === 'FAIL').length;
console.log(`\nEXTRA fail=${fail}`);
process.exit(fail ? 1 : 0);
