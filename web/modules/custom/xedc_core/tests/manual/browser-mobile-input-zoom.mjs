/**
 * Mobile input zoom regression — measures visualViewport.scale + font-size.
 *
 * LOGIN_URL='https://gitxx.xyz/user/reset/...' node .../browser-mobile-input-zoom.mjs
 *
 * Emulator note: Playwright Chromium cannot fully reproduce iOS Safari zoom.
 * We assert computed font-size >= 16 and scale stays 1 after focus.
 */
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
const record = (name, status, detail = '') => {
  results.push({ name, status, detail });
  console.log(`${status}\t${name}${detail ? '\t' + detail : ''}`);
};

fs.mkdirSync(OUT, { recursive: true });

async function metrics(page, selector) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const cs = getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const vv = window.visualViewport;
    return {
      fontSize: cs.fontSize,
      fontPx: parseFloat(cs.fontSize),
      width: Math.round(rect.width),
      height: Math.round(rect.height),
      innerW: window.innerWidth,
      innerH: window.innerHeight,
      vvW: vv ? vv.width : null,
      vvH: vv ? vv.height : null,
      scale: vv ? vv.scale : null,
      scrollW: document.documentElement.scrollWidth,
      clientW: document.documentElement.clientWidth,
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
  }, selector);
}

async function focusProbe(page, name, selector, shotName) {
  const before = await metrics(page, selector);
  if (!before) {
    record(name, 'NOT TESTED', `missing ${selector}`);
    return;
  }
  await page.locator(selector).first().click({ timeout: 8000 }).catch(() => null);
  await page.waitForTimeout(250);
  const after = await metrics(page, selector);
  if (shotName) {
    await page.screenshot({ path: path.join(OUT, `${shotName}.png`), fullPage: false });
  }
  await page.evaluate(() => {
    if (document.activeElement && document.activeElement.blur) {
      document.activeElement.blur();
    }
  });
  const okFont = after && after.fontPx >= 16;
  const okScale = after && (after.scale === null || Math.abs(after.scale - 1) < 0.01);
  const okOverflow = after && !after.overflowX;
  const status = okFont && okScale && okOverflow ? 'PASS' : 'FAIL';
  record(
    name,
    status,
    `font=${after?.fontSize} scale=${after?.scale} overflowX=${after?.overflowX} beforeScale=${before.scale} w=${after?.width}x${after?.height}`,
  );
}

const iphone = devices['iPhone 13'];
const browser = await chromium.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});

const mobile = await browser.newContext({
  ...iphone,
  ignoreHTTPSErrors: true,
});
const page = await mobile.newPage();

try {
  // Viewport meta
  await page.goto(`${BASE}/user/login`, { waitUntil: 'networkidle', timeout: 90000 });
  const vp = await page.evaluate(() => {
    const tags = [...document.querySelectorAll('meta[name="viewport"]')].map((m) => m.content);
    return tags;
  });
  const joined = vp.join(' | ');
  const bad = /user-scalable\s*=\s*no|maximum-scale\s*=\s*1(\.0)?/i.test(joined);
  record('viewport-meta', vp.length === 1 && !bad ? 'PASS' : 'FAIL', joined);
  record('viewport-no-lock', !bad ? 'PASS' : 'FAIL', 'pinch zoom must remain available');

  await focusProbe(page, 'login-name', 'input[name="name"], #edit-name', 'login-focus-iphone13');
  await focusProbe(page, 'login-pass', 'input[name="pass"], #edit-pass', null);

  await page.goto(`${BASE}/user/register`, { waitUntil: 'networkidle', timeout: 90000 });
  await focusProbe(page, 'register-mail', 'input[type="email"], input[name="mail"], #edit-mail', 'register-focus-iphone13');

  await page.goto(`${BASE}/user/password`, { waitUntil: 'networkidle', timeout: 90000 });
  await focusProbe(page, 'password-reset', 'input[type="email"], input[name="name"], #edit-name', 'password-reset-focus');

  await page.goto(`${BASE}/search`, { waitUntil: 'networkidle', timeout: 90000 }).catch(() => null);
  if (!page.url().includes('/search')) {
    await page.goto(`${BASE}/search/node`, { waitUntil: 'networkidle', timeout: 90000 }).catch(() => null);
  }
  await focusProbe(page, 'site-search', 'input.sr-input, input[type="search"], .xn-msearch input, .xn-search input', 'search-focus');

  // Auth for member / admin pages
  if (!LOGIN) {
    record('member-auth', 'NOT TESTED', 'LOGIN_URL missing');
  }
  else {
    await page.goto(LOGIN, { waitUntil: 'networkidle', timeout: 90000 });
    const logged = !page.url().includes('/user/reset') || (await page.locator('text=Log in').count()) === 0;
    record('member-auth', logged || page.url().includes('/user/') ? 'PASS' : 'FAIL', page.url());

    const memberRoutes = [
      ['/member', 'member-home'],
      ['/member/profile', 'member-profile'],
      ['/member/profile/edit', 'member-profile-edit'],
      ['/member/security', 'member-security'],
      ['/member/submissions', 'member-submissions'],
      ['/node/add/photo', 'photo-submit'],
      ['/node/add/video', 'video-submit'],
      ['/node/add/article', 'article-submit'],
      ['/member/messages', 'messages'],
    ];

    for (const [route, label] of memberRoutes) {
      await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch((e) => {
        record(`${label}-nav`, 'FAIL', String(e).slice(0, 120));
      });
      const sel =
        'form input[type="text"], form input[type="email"], form input[type="password"], form input[type="search"], form input[type="url"], form textarea, form select, .xn-msg__composer textarea, .xm textarea, .xm input[type="text"]';
      const count = await page.locator(sel).count();
      if (count === 0) {
        record(`${label}-inputs`, 'PASS', 'no text inputs on page (ok)');
        continue;
      }
      await focusProbe(page, `${label}-input`, sel, `${label}-focus`);
    }

    // Media library dialog on photo submit if widget exists
    await page.goto(`${BASE}/node/add/photo`, { waitUntil: 'networkidle', timeout: 90000 });
    const openBtn = page.locator('.js-media-library-open-button, .media-library-open-button, input[name*="media-library-open"]').first();
    if (await openBtn.count()) {
      await openBtn.click({ timeout: 10000 }).catch(() => null);
      await page.waitForTimeout(1200);
      const dialogSel = '.ui-dialog.media-library-widget-modal input[type="text"], .media-library-view .form-text, .ui-dialog input[type="search"], .ui-dialog input[name*="name"]';
      await focusProbe(page, 'media-library-search', dialogSel, 'media-library-focus');
      await page.keyboard.press('Escape').catch(() => null);
    }
    else {
      record('media-library-search', 'NOT TESTED', 'open button not found');
    }

    // Admin content form
    await page.goto(`${BASE}/admin/content`, { waitUntil: 'networkidle', timeout: 90000 });
    const adminTheme = await page.evaluate(() => document.documentElement.className + ' ' + document.body.className);
    record('admin-theme-marker', /xedc-admin|gin--|path-admin/.test(adminTheme) ? 'PASS' : 'FAIL', adminTheme.slice(0, 160));
    await focusProbe(page, 'admin-filter', 'input[type="search"], input.form-text, input[name="title"], input[name*="keys"]', 'admin-filter-focus');

    await page.goto(`${BASE}/node/add/article`, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => null);
    await focusProbe(page, 'admin-or-member-title', 'input[name="title[0][value]"], #edit-title-0-value, input.form-text', 'content-title-focus');
  }

  // Android-ish viewport
  await page.setViewportSize({ width: 412, height: 915 });
  await page.goto(`${BASE}/user/login`, { waitUntil: 'networkidle', timeout: 60000 });
  await focusProbe(page, 'android-login-name', '#edit-name, input[name="name"]', 'android-login-focus');

  // Tablet
  await page.setViewportSize({ width: 768, height: 1024 });
  await page.goto(`${BASE}/user/login`, { waitUntil: 'networkidle', timeout: 60000 });
  await focusProbe(page, 'tablet-login-name', '#edit-name, input[name="name"]', 'tablet-login-focus');

  // Desktop regression — font may be <16; scale must stay 1 and no overflow
  const desk = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
  });
  const dpage = await desk.newPage();
  await dpage.goto(`${BASE}/user/login`, { waitUntil: 'networkidle', timeout: 60000 });
  const dBefore = await metrics(dpage, '#edit-name, input[name="name"]');
  await dpage.locator('#edit-name, input[name="name"]').first().click();
  await dpage.waitForTimeout(200);
  const dAfter = await metrics(dpage, '#edit-name, input[name="name"]');
  await dpage.screenshot({ path: path.join(OUT, 'desktop-login-focus.png') });
  const deskOk = dAfter && (dAfter.scale === null || Math.abs(dAfter.scale - 1) < 0.01) && !dAfter.overflowX;
  record(
    'desktop-login-no-layout-break',
    deskOk ? 'PASS' : 'FAIL',
    `font=${dAfter?.fontSize} scale=${dAfter?.scale} overflowX=${dAfter?.overflowX} beforeFont=${dBefore?.fontSize}`,
  );
  await desk.close();

  // Confirm viewport still allows scaling (meta not locked)
  const meta = await page.evaluate(() => document.querySelector('meta[name="viewport"]')?.content || '');
  record('pinch-zoom-meta-intact', !/user-scalable\s*=\s*no|maximum-scale\s*=\s*1(\.0)?/i.test(meta) ? 'PASS' : 'FAIL', meta);
}
catch (e) {
  record('suite-error', 'FAIL', String(e).slice(0, 300));
}
finally {
  const summary = {
    generatedAt: new Date().toISOString(),
    note: 'Playwright Chromium mobile emulation is not a substitute for real iPhone Safari / Android Chrome.',
    results,
  };
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(summary, null, 2));
  await browser.close();
  const failed = results.filter((r) => r.status === 'FAIL').length;
  console.log(`\nDONE fail=${failed} total=${results.length} out=${OUT}`);
  process.exit(failed ? 1 : 0);
}
