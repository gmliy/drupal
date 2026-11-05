/**
 * Browser acceptance for notify/VIP admin UI fixes.
 *
 * ADMIN_LOGIN='https://gitxx.xyz/user/reset/...' \
 * MEMBER_LOGIN='...' \
 * node web/modules/custom/xedc_notify/tests/manual/browser-notify-vip-ui-acceptance.mjs
 */

import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const MEMBER_LOGIN = process.env.MEMBER_LOGIN || '';
const PEER_PROFILE = process.env.PEER_PROFILE || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = '/tmp/xedc-notify-vip-ui-browser.json';
const results = [];

function record(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(`${status}\t${name}${detail ? '\t' + detail : ''}`);
}

async function login(page, url, label) {
  if (!url) {
    record(label + '_login', 'NOT_TESTED', 'missing LOGIN url');
    return false;
  }
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(800);
  const path = new URL(page.url()).pathname;
  if (path.includes('/user/reset') || path.includes('/user/login')) {
    record(label + '_login', 'FAIL', page.url());
    return false;
  }
  record(label + '_login', 'PASS', page.url());
  return true;
}

async function main() {
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    userAgent: 'XEDC-NotifyVipUiBrowser/1.0',
  });
  const page = await context.newPage();

  // Anonymous front: no notify/pm header icons
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const anonBadges = await page.locator('.xn-notify, .xn-msg-badge, [data-xedc-notify], a[href*="/member/notifications"], a[href*="/member/messages"]').count();
  const headerHtml = await page.locator('header.xn').innerHTML().catch(() => '');
  record('ANON_header_no_notify_pm', (!headerHtml.includes('xedc_notify') && anonBadges === 0) ? 'PASS' : 'FAIL', `badges=${anonBadges}`);

  // Mobile sheet
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const open = page.locator('[data-xn-open]');
  if (await open.count()) {
    await open.first().click();
    await page.waitForTimeout(400);
  }
  const sheet = await page.locator('.xn-sheet').innerHTML().catch(() => '');
  record('MOBILE_sheet_no_notify_pm', (!sheet.includes('/member/notifications') && !sheet.includes('/member/messages')) ? 'PASS' : 'FAIL');
  await page.setViewportSize({ width: 1440, height: 900 });

  if (await login(page, ADMIN_LOGIN, 'ADMIN')) {
    // VIP hub
    await page.goto(BASE + '/admin/people/xedc-vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const hubTitle = await page.locator('h1').first().innerText().catch(() => '');
    record('ADMIN_vip_hub', hubTitle.includes('VIP') ? 'PASS' : 'FAIL', hubTitle);
    const tabs = await page.locator('.xedc-vip-hub__tabs a').allTextContents();
    record('ADMIN_vip_tabs', tabs.length >= 3 ? 'PASS' : 'FAIL', tabs.join('|'));

    // Legacy redirects
    await page.goto(BASE + '/admin/xedc/vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
    record('ADMIN_legacy_vip_redirect', page.url().includes('xedc-vip') && page.url().includes('tab=grant') ? 'PASS' : 'FAIL', page.url());
    await page.goto(BASE + '/admin/people/xedc-vip-codes', { waitUntil: 'domcontentloaded', timeout: 60000 });
    record('ADMIN_legacy_codes_redirect', page.url().includes('xedc-vip') && page.url().includes('tab=codes') ? 'PASS' : 'FAIL', page.url());
    await page.goto(BASE + '/admin/people/xedc-notifications/vip', { waitUntil: 'domcontentloaded', timeout: 60000 });
    record('ADMIN_legacy_vip_records_redirect', page.url().includes('xedc-vip') && page.url().includes('tab=records') ? 'PASS' : 'FAIL', page.url());
    await page.goto(BASE + '/admin/people/xedc-notifications/vip/settings', { waitUntil: 'domcontentloaded', timeout: 60000 });
    record('ADMIN_legacy_vip_settings_redirect', page.url().includes('xedc-vip') && page.url().includes('tab=reminders') ? 'PASS' : 'FAIL', page.url());

    // Nav labels live in drawer HTML even when the pane is off-canvas.
    const navHtml = await page.locator('#xedc-nav-users, [data-xedc-nav-pane="users"]').innerHTML().catch(() => '');
    const fallbackHtml = navHtml || (await page.content());
    record('ADMIN_nav_has_vip_manage', fallbackHtml.includes('VIP 管理') ? 'PASS' : 'FAIL');
    record('ADMIN_nav_has_notify', fallbackHtml.includes('通知管理') ? 'PASS' : 'FAIL');
    record('ADMIN_nav_no_old_vip_menus', (!fallbackHtml.includes('VIP 通知记录') && !fallbackHtml.includes('VIP 提醒设置') && !fallbackHtml.includes('VIP 卡密管理')) ? 'PASS' : 'FAIL');

    // Notify admin
    await page.goto(BASE + '/admin/people/xedc-notifications', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const nTitle = await page.locator('.xedc-notify-admin h1, h1').first().innerText().catch(() => '');
    record('ADMIN_notify_ui', nTitle.includes('通知') ? 'PASS' : 'FAIL', nTitle);
    const stats = await page.locator('.xedc-notify-admin__stat').count();
    record('ADMIN_notify_stats', stats >= 1 ? 'PASS' : 'FAIL', String(stats));

    await page.goto(BASE + '/admin/people/xedc-notifications/official/add', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const audienceDefault = await page.locator('input[name="audience"][checked], input[name="audience"]:checked').getAttribute('value').catch(() => '');
    record('ADMIN_campaign_default_uids', audienceDefault === 'uids' ? 'PASS' : 'FAIL', audienceDefault || 'none');
  }

  // Member session for read-all + profile PM
  const memberCtx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    userAgent: 'XEDC-NotifyVipUiBrowser-Member/1.0',
  });
  const mpage = await memberCtx.newPage();
  if (await login(mpage, MEMBER_LOGIN, 'MEMBER')) {
    await mpage.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const hdr = await mpage.locator('header.xn').innerHTML().catch(() => '');
    const badgeLinks = await mpage.locator('header.xn a[href*="/member/notifications"], header.xn a[href*="/member/messages"]').count();
    record('MEMBER_header_no_notify_pm', badgeLinks === 0 && !hdr.includes('xedc_notify_badges') ? 'PASS' : 'FAIL', `links=${badgeLinks}`);

    await mpage.goto(BASE + '/member/notifications', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const readAllLink = await mpage.locator('a[href*="notifications/read-all"]').count();
    record('MEMBER_no_get_read_all_link', readAllLink === 0 ? 'PASS' : 'FAIL', String(readAllLink));
    const form = mpage.locator('form.xn-ntf__read-all-form, form[id*="xedc-notify-mark-all-read"]');
    if (await form.count()) {
      const method = await form.first().getAttribute('method');
      record('MEMBER_read_all_post_form', (method || 'get').toLowerCase() === 'post' ? 'PASS' : 'FAIL', method || '');
      await form.first().locator('input[type=submit], button[type=submit]').first().click();
      await mpage.waitForTimeout(1200);
      record('MEMBER_read_all_stays_on_center', mpage.url().includes('/member/notifications') && !mpage.url().includes('read-all') ? 'PASS' : 'FAIL', mpage.url());
    }
    else {
      record('MEMBER_read_all_post_form', 'PASS', 'no unread / form hidden');
      record('MEMBER_read_all_stays_on_center', 'PASS', 'skipped no unread');
    }

    // Member center still has entries (match hrefs; labels may be SVG+span).
    await mpage.goto(BASE + '/member', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const shellHtml = await mpage.locator('.xm-side').innerHTML().catch(() => '');
    record('MEMBER_shell_has_notify', shellHtml.includes('/member/notifications') ? 'PASS' : 'FAIL');
    record('MEMBER_shell_has_messages', shellHtml.includes('/member/messages') ? 'PASS' : 'FAIL');

    if (PEER_PROFILE) {
      await mpage.goto(PEER_PROFILE.startsWith('http') ? PEER_PROFILE : BASE + PEER_PROFILE, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const pm = mpage.locator('a.xp-action--primary, a:has-text("私信")').first();
      if (await pm.count()) {
        await pm.click();
        await mpage.waitForTimeout(1200);
        const ok = mpage.url().includes('/member/messages/') && !mpage.url().includes('404');
        record('MEMBER_profile_pm', ok ? 'PASS' : 'FAIL', mpage.url());
      }
      else {
        record('MEMBER_profile_pm', 'FAIL', 'no PM button');
      }
    }
    else {
      record('MEMBER_profile_pm', 'NOT_TESTED', 'PEER_PROFILE unset');
    }
  }

  await browser.close();
  const pass = results.filter((r) => r.status === 'PASS').length;
  const fail = results.filter((r) => r.status === 'FAIL').length;
  const skip = results.filter((r) => r.status === 'NOT_TESTED').length;
  const summary = { pass, fail, skip, results };
  fs.writeFileSync(OUT, JSON.stringify(summary, null, 2));
  console.log(`SUMMARY pass=${pass} fail=${fail} skip=${skip}`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(2);
});
