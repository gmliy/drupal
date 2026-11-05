/**
 * Full UI audit: VIP hub + 前台统计 pages (light/dark, desktop/mobile).
 *
 * ADMIN_LOGIN='https://gitxx.xyz/user/reset/...' \
 * node web/modules/custom/xedc_core/tests/manual/browser-vip-front-stats-ui.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = '/tmp/xedc-vip-front-stats-ui.json';
const results = [];

function record(name, status, detail = '') {
  results.push({ name, status, detail: String(detail).slice(0, 240) });
  console.log(`${status}\t${name}${detail ? '\t' + String(detail).slice(0, 160) : ''}`);
}

async function auditPage(page, name, path, checks = {}) {
  const url = path.startsWith('http') ? path : BASE + path;
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(400);
  const status = resp ? resp.status() : 0;
  record(`${name}_http`, status >= 200 && status < 400 ? 'PASS' : 'FAIL', String(status));
  const body = await page.locator('body').innerText().catch(() => '');
  if (checks.titleIncludes) {
    const h1 = await page.locator('h1').first().innerText().catch(() => '');
    record(`${name}_title`, h1.includes(checks.titleIncludes) || body.includes(checks.titleIncludes) ? 'PASS' : 'FAIL', h1);
  }
  if (checks.mustInclude) {
    for (const s of checks.mustInclude) {
      record(`${name}_has_${s.slice(0, 12)}`, body.includes(s) ? 'PASS' : 'FAIL', s);
    }
  }
  if (checks.mustNotInclude) {
    for (const s of checks.mustNotInclude) {
      record(`${name}_no_${s.slice(0, 12)}`, !body.includes(s) ? 'PASS' : 'FAIL', s);
    }
  }
  if (checks.noCharts) {
    const charts = await page.locator('.xedc-ops__bars, .xedc-ops__chart, .xedc-ops__split').count();
    record(`${name}_no_charts`, charts === 0 ? 'PASS' : 'FAIL', String(charts));
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 12);
  record(`${name}_no_overflow`, !overflow ? 'PASS' : 'FAIL');
  const jsErrors = await page.evaluate(() => window.__xedcUiErrors || []);
  record(`${name}_no_js_errors`, jsErrors.length === 0 ? 'PASS' : 'FAIL', JSON.stringify(jsErrors).slice(0, 120));
}

async function main() {
  if (!ADMIN_LOGIN) {
    record('ADMIN_login', 'NOT_TESTED', 'ADMIN_LOGIN unset');
    process.exit(0);
  }
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (err) => {
    page.evaluate((m) => {
      window.__xedcUiErrors = window.__xedcUiErrors || [];
      window.__xedcUiErrors.push(m);
    }, err.message).catch(() => {});
  });

  await page.goto(ADMIN_LOGIN, { waitUntil: 'domcontentloaded', timeout: 60000 });
  record('ADMIN_login', page.url().includes('/user/login') ? 'FAIL' : 'PASS', page.url());

  // Light desktop
  await page.evaluate(() => {
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.classList.add('xedc-admin-active');
  });

  await auditPage(page, 'VIP_overview', '/admin/people/xedc-vip', {
    titleIncludes: 'VIP 管理',
    mustInclude: ['概览', '当前有效'],
  });
  await auditPage(page, 'VIP_grant', '/admin/people/xedc-vip?tab=grant', {
    mustInclude: ['开通'],
  });
  await auditPage(page, 'VIP_codes', '/admin/people/xedc-vip?tab=codes', {
    mustInclude: ['卡密'],
  });
  await auditPage(page, 'VIP_reminders', '/admin/people/xedc-vip?tab=reminders', {
    mustInclude: ['提醒'],
  });
  await auditPage(page, 'VIP_records', '/admin/people/xedc-vip?tab=records', {});

  await auditPage(page, 'STATS_overview', '/admin/xedc/engagement', {
    titleIncludes: '前台统计',
    mustNotInclude: ['会员与互动'],
    noCharts: true,
  });
  await auditPage(page, 'STATS_likes', '/admin/xedc/engagement/likes', {
    mustInclude: ['点赞'],
  });
  await auditPage(page, 'STATS_downloads', '/admin/xedc/engagement/downloads', {
    mustInclude: ['下载'],
  });
  await auditPage(page, 'STATS_shares', '/admin/xedc/engagement/shares', {
    mustInclude: ['分享'],
  });
  await auditPage(page, 'STATS_members', '/admin/xedc/engagement/members', {});
  await auditPage(page, 'STATS_data', '/admin/xedc/engagement/statistics', {
    noCharts: true,
  });
  await auditPage(page, 'STATS_zips', '/admin/xedc/zip', {
    mustInclude: ['ZIP'],
  });
  await auditPage(page, 'STATS_download_res', '/admin/xedc/downloads', {});

  // Nav rename
  const pane = await page.locator('#xedc-nav-users, [data-xedc-nav-pane="users"]').innerHTML().catch(() => '');
  record('NAV_front_stats', pane.includes('前台统计') ? 'PASS' : 'FAIL');
  record('NAV_no_old_name', !pane.includes('会员与互动') ? 'PASS' : 'FAIL');
  record('NAV_vip_present', pane.includes('VIP 管理') ? 'PASS' : 'FAIL');

  // Dark mode via project localStorage key (survives navigation).
  await page.evaluate(() => {
    localStorage.setItem('xedcAdmin.theme', 'dark');
  });
  await page.goto(BASE + '/admin/people/xedc-vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(300);
  const darkVip = await page.evaluate(() => {
    const el = document.querySelector('.xedc-vip-hub') || document.body;
    const cs = getComputedStyle(el);
    const bg = getComputedStyle(document.documentElement).getPropertyValue('--xedc-admin-background').trim();
    const fgToken = getComputedStyle(document.documentElement).getPropertyValue('--xedc-admin-foreground').trim();
    return { fg: cs.color, bg, fgToken, theme: document.documentElement.getAttribute('data-theme') };
  });
  record('DARK_vip_theme_set', darkVip.theme === 'dark' ? 'PASS' : 'FAIL', JSON.stringify(darkVip));
  record('DARK_vip_tokens', darkVip.bg && darkVip.fgToken && darkVip.bg !== darkVip.fgToken ? 'PASS' : 'FAIL', JSON.stringify(darkVip));

  await page.goto(BASE + '/admin/xedc/engagement', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(300);
  const darkStats = await page.evaluate(() => {
    const el = document.querySelector('.xedc-ops') || document.body;
    const cs = getComputedStyle(el);
    const card = document.querySelector('.xedc-ops__card');
    const cardBg = card ? getComputedStyle(card).backgroundColor : '';
    const cardFg = card ? getComputedStyle(card).color : '';
    return { fg: cs.color, cardBg, cardFg, theme: document.documentElement.getAttribute('data-theme') };
  });
  record('DARK_stats_theme', darkStats.theme === 'dark' ? 'PASS' : 'FAIL', JSON.stringify(darkStats));
  record('DARK_stats_card_readable', darkStats.cardBg && darkStats.cardFg && darkStats.cardBg !== darkStats.cardFg ? 'PASS' : 'FAIL', JSON.stringify(darkStats));

  // Mobile light
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => localStorage.setItem('xedcAdmin.theme', 'light'));
  await auditPage(page, 'MOBILE_VIP', '/admin/people/xedc-vip?tab=codes', { mustInclude: ['卡密'] });
  await auditPage(page, 'MOBILE_STATS', '/admin/xedc/engagement', { titleIncludes: '前台统计', noCharts: true });

  // Mobile dark
  await page.evaluate(() => localStorage.setItem('xedcAdmin.theme', 'dark'));
  await page.goto(BASE + '/admin/xedc/engagement/likes', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(300);
  const mobDark = await page.evaluate(() => ({
    theme: document.documentElement.getAttribute('data-theme'),
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 12,
  }));
  record('MOBILE_DARK_likes_theme', mobDark.theme === 'dark' ? 'PASS' : 'FAIL', JSON.stringify(mobDark));
  record('MOBILE_DARK_likes_overflow', !mobDark.overflow ? 'PASS' : 'FAIL');

  await browser.close();
  const pass = results.filter((r) => r.status === 'PASS').length;
  const fail = results.filter((r) => r.status === 'FAIL').length;
  const skip = results.filter((r) => r.status === 'NOT_TESTED').length;
  fs.writeFileSync(OUT, JSON.stringify({ pass, fail, skip, results }, null, 2));
  console.log(`SUMMARY pass=${pass} fail=${fail} skip=${skip}`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(2);
});
