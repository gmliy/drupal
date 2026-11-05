/**
 * Visual smoke for XEDC tags admin UI (light/dark + viewports).
 * ADMIN_LOGIN='...' node web/modules/custom/xedc_tags/tests/manual/browser-tags-admin-ui.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.SHOT_DIR || '/var/www/drupal/docs/tasks/screenshots/xedc-tags-ui';
const results = [];
const record = (name, status, detail = '') => {
  results.push({ name, status, detail });
  console.log(`${status}\t${name}${detail ? '\t' + detail : ''}`);
};

const routes = [
  ['overview', '/admin/content/tags'],
  ['list', '/admin/content/tags/list'],
  ['health', '/admin/content/tags/health'],
  ['duplicates', '/admin/content/tags/duplicates'],
  ['merge', '/admin/content/tags/merge'],
  ['aliases', '/admin/content/tags/aliases'],
  ['recommend', '/admin/content/tags/recommend'],
  ['relations', '/admin/content/tags/relations'],
  ['seo', '/admin/content/tags/seo'],
  ['ai', '/admin/content/tags/ai'],
  ['tasks', '/admin/content/tags/tasks'],
  ['unused', '/admin/content/tags/unused'],
  ['history', '/admin/content/tags/history'],
];

async function shot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function checkPage(page, key, urlPath) {
  await page.goto(BASE + urlPath, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const okRoute = page.url().includes(urlPath.split('?')[0]);
  record(`${key}_route`, okRoute ? 'PASS' : 'FAIL', page.url());
  const hasChrome = await page.locator('.xt-admin, .xt-admin__title').count();
  record(`${key}_chrome`, hasChrome > 0 ? 'PASS' : 'FAIL', String(hasChrome));
  const title = await page.locator('.xt-admin__title').first().innerText().catch(() => '');
  record(`${key}_title`, title.trim() !== '' ? 'PASS' : 'FAIL', title.slice(0, 80));
  const unstyledTables = await page.evaluate(() => {
    const tables = [...document.querySelectorAll('.region-content table')];
    return tables.filter((t) => !t.closest('.xt-admin') && !t.classList.contains('xt-table')).length;
  });
  record(`${key}_tables_scoped`, unstyledTables === 0 ? 'PASS' : 'WARN', String(unstyledTables));
  await shot(page, `light-1440-${key}`);
}

async function main() {
  if (!ADMIN_LOGIN) {
    record('ADMIN_login', 'NOT_TESTED', 'ADMIN_LOGIN unset');
    process.exit(0);
  }
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto(ADMIN_LOGIN, { waitUntil: 'domcontentloaded', timeout: 60000 });
  record('ADMIN_login', page.url().includes('user/login') ? 'FAIL' : 'PASS', page.url());

  for (const [key, route] of routes) {
    await checkPage(page, key, route);
  }

  // Dark mode via Gin cookie / class toggle if available.
  await page.goto(BASE + '/admin/content/tags', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.evaluate(() => {
    document.documentElement.classList.add('gin--dark-mode');
  });
  const darkBg = await page.evaluate(() => {
    const el = document.querySelector('.xt-admin__header, .xt-stat, .xt-card');
    if (!el) return '';
    return getComputedStyle(el).backgroundColor;
  });
  record('dark_mode_surface', darkBg !== '' ? 'PASS' : 'FAIL', darkBg);
  await shot(page, 'dark-1440-overview');

  // Contrast smoke: badge text vs background not identical.
  const badgeOk = await page.evaluate(() => {
    const badge = document.querySelector('.xt-badge');
    if (!badge) return true;
    const cs = getComputedStyle(badge);
    return cs.color !== cs.backgroundColor;
  });
  record('dark_badge_contrast', badgeOk ? 'PASS' : 'FAIL');

  // Mobile overflow checks.
  for (const width of [768, 430, 375]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(BASE + '/admin/content/tags/list', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 8);
    record(`list_no_overflow_${width}`, !overflow ? 'PASS' : 'FAIL');
    await shot(page, `light-${width}-list`);
  }

  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(BASE + '/admin/content/tags/merge', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const mergeSteps = await page.locator('.xt-merge-steps, .xt-merge-panel').count();
  record('merge_wizard_ui', mergeSteps > 0 ? 'PASS' : 'FAIL', String(mergeSteps));
  await shot(page, 'light-1280-merge');

  await page.goto(BASE + '/admin/content/tags/ai', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const aiText = await page.locator('.xt-admin').innerText();
  record('ai_human_confirm_copy', /建议/.test(aiText) && !/api[_-]?key/i.test(aiText) ? 'PASS' : 'FAIL');
  await shot(page, 'light-1280-ai');

  await browser.close();
  const pass = results.filter((r) => r.status === 'PASS').length;
  const fail = results.filter((r) => r.status === 'FAIL').length;
  const warn = results.filter((r) => r.status === 'WARN').length;
  const skip = results.filter((r) => r.status === 'NOT_TESTED').length;
  fs.writeFileSync('/tmp/xedc-tags-admin-ui-browser.json', JSON.stringify({ pass, fail, warn, skip, results, out: OUT }, null, 2));
  console.log(`SUMMARY pass=${pass} fail=${fail} warn=${warn} skip=${skip} shots=${OUT}`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(2);
});
