/**
 * Browser check: VIP hub vs engagement hub separation.
 * ADMIN_LOGIN='...' node web/modules/custom/xedc_core/tests/manual/browser-vip-engagement-dedup.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const results = [];
const record = (name, status, detail = '') => {
  results.push({ name, status, detail });
  console.log(`${status}\t${name}${detail ? '\t' + detail : ''}`);
};

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
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto(ADMIN_LOGIN, { waitUntil: 'domcontentloaded', timeout: 60000 });
  record('ADMIN_login', page.url().includes('user/login') ? 'FAIL' : 'PASS', page.url());

  await page.goto(BASE + '/admin/people/xedc-vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const vipH1 = await page.locator('.xedc-vip-hub h1, h1').first().innerText().catch(() => '');
  record('VIP_page_title', vipH1.includes('VIP') ? 'PASS' : 'FAIL', vipH1);
  const tabs = await page.locator('.xedc-vip-hub__tabs a').allTextContents();
  record('VIP_tabs', tabs.join('|').includes('开通') && tabs.join('|').includes('卡密') ? 'PASS' : 'FAIL', tabs.join('|'));
  const stats = await page.locator('.xedc-vip-hub__stat').count();
  record('VIP_header_stats', stats >= 4 ? 'PASS' : 'FAIL', String(stats));

  await page.goto(BASE + '/admin/people/xedc-vip?tab=grant', { waitUntil: 'domcontentloaded', timeout: 60000 });
  record('VIP_grant_tab', page.url().includes('tab=grant') ? 'PASS' : 'FAIL', page.url());
  const grantForm = await page.locator('form[id*="vip-grant"], form.xedc-vip-grant, input[name="name"]').count();
  record('VIP_grant_form', grantForm > 0 ? 'PASS' : 'FAIL');

  await page.goto(BASE + '/admin/xedc/engagement', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const engText = await page.locator('.xedc-ops, .region-content, body').first().innerText().catch(() => '');
  record('ENG_page_loaded', page.url().includes('/admin/xedc/engagement') ? 'PASS' : 'FAIL', page.url());
  record('ENG_no_vip_grant_button', !/开通 VIP/.test(engText) ? 'PASS' : 'FAIL');
  record('ENG_no_vip_hub_tabs', (await page.locator('.xedc-vip-hub__tabs').count()) === 0 ? 'PASS' : 'FAIL');
  const localTabs = await page.locator('.tabs a, nav.tabs a, .gin-local-tasks a, .tabs__link').allTextContents().catch(() => []);
  const tabStr = localTabs.join('|');
  record('ENG_local_tasks_no_vip', !/VIP/.test(tabStr) ? 'PASS' : 'FAIL', tabStr.slice(0, 200));

  await page.goto(BASE + '/admin/xedc/vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
  record('LEGACY_vip_redirect', page.url().includes('xedc-vip') && page.url().includes('tab=grant') ? 'PASS' : 'FAIL', page.url());

  // Nav pane HTML
  const usersPane = await page.locator('#xedc-nav-users, [data-xedc-nav-pane="users"]').innerHTML().catch(() => '');
  record('NAV_has_both', usersPane.includes('VIP 管理') && usersPane.includes('前台统计') ? 'PASS' : 'FAIL');
  record('NAV_no_old_vip_menus', (!usersPane.includes('VIP 通知记录') && !usersPane.includes('VIP 提醒设置') && !usersPane.includes('VIP 卡密管理')) ? 'PASS' : 'FAIL');

  // Mobile viewport smoke
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/admin/people/xedc-vip?tab=codes', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 8);
  record('VIP_mobile_no_overflow', !overflow ? 'PASS' : 'FAIL');

  await browser.close();
  const pass = results.filter((r) => r.status === 'PASS').length;
  const fail = results.filter((r) => r.status === 'FAIL').length;
  const skip = results.filter((r) => r.status === 'NOT_TESTED').length;
  fs.writeFileSync('/tmp/xedc-vip-engagement-dedup-browser.json', JSON.stringify({ pass, fail, skip, results }, null, 2));
  console.log(`SUMMARY pass=${pass} fail=${fail} skip=${skip}`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(2);
});
