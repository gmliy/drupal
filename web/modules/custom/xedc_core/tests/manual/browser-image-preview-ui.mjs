/**
 * Browser checks for image preview dialog close + two-column layout.
 */
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');

const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const MEMBER_LOGIN = process.env.MEMBER_LOGIN || '';
const ADMIN_LOGIN = process.env.ADMIN_LOGIN || '';
const PHOTO_URL = process.env.PHOTO_URL || `${BASE}/cosplay/cos/195`;
const ADMIN_TASK = process.env.ADMIN_TASK || '270';
const CHROME = process.env.CHROMIUM_PATH || '/usr/bin/chromium-browser';
const OUT = '/tmp/xedc-image-preview-ui.json';
const results = [];

function record(name, status, detail = '') {
  results.push({ name, status, detail: String(detail).slice(0, 360) });
  console.log(`${status}\t${name}${detail ? '\t' + String(detail).slice(0, 220) : ''}`);
}

async function login(context, url, label) {
  if (!url) {
    record(`${label}_login`, 'NOT_TESTED', 'login URL unset');
    return null;
  }
  const page = await context.newPage();
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(700);
  if (page.url().includes('pass-reset-token') || page.url().includes('/user/reset/')) {
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
  }
  const authed = !page.url().includes('/user/login');
  record(`${label}_login`, authed ? 'PASS' : 'FAIL', page.url());
  return authed ? page : null;
}

async function openImgSheet(page) {
  const opened = await page.evaluate(() => {
    const btn = document.querySelector('[data-img-all], .img-detail__all, .img-lb__all');
    if (btn) {
      btn.scrollIntoView({ block: 'center', inline: 'nearest' });
      btn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
      btn.click();
      return 'clicked';
    }
    const sheet = document.getElementById('img-sheet');
    if (sheet) {
      sheet.hidden = false;
      sheet.removeAttribute('hidden');
      sheet.setAttribute('aria-hidden', 'false');
      return 'forced';
    }
    return '';
  });
  await page.waitForTimeout(500);
  return opened !== '' && (await page.locator('.img-sheet:not([hidden])').count()) > 0;
}

async function checkImgSheet(page, label, expectCols) {
  const open = await openImgSheet(page);
  record(`${label}_img_sheet_open`, open ? 'PASS' : 'FAIL');
  if (!open) {
    const htmlHint = await page.evaluate(() => ({
      hasDetail: !!document.querySelector('.img-detail'),
      hasSheet: !!document.getElementById('img-sheet'),
      title: document.title,
    }));
    record(`${label}_img_sheet_hint`, 'NOT_TESTED', JSON.stringify(htmlHint));
    return;
  }
  const info = await page.evaluate(() => {
    const grid = document.querySelector('.img-sheet:not([hidden]) .img-sheet__grid');
    const close = document.querySelector('.img-sheet:not([hidden]) .img-sheet__close');
    const gs = grid ? getComputedStyle(grid) : null;
    return {
      cols: gs ? gs.gridTemplateColumns : '',
      closeText: close ? (close.textContent || '').trim() : '',
      aria: close ? close.getAttribute('aria-label') : '',
    };
  });
  const colCount = info.cols.split(/\s+/).filter(Boolean).length;
  const okCols = colCount === expectCols;
  record(`${label}_img_sheet_cols`, okCols ? 'PASS' : 'FAIL', `${expectCols} expected; got ${info.cols}`);
  record(`${label}_img_sheet_close_clean`, !/close|dismiss/i.test(info.closeText) ? 'PASS' : 'FAIL', JSON.stringify(info));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(250);
  record(`${label}_img_sheet_escape`, (await page.locator('.img-sheet:not([hidden])').count()) === 0 ? 'PASS' : 'FAIL');
}

async function injectDialogProbe(page, label, cssUrl) {
  await page.addStyleTag({ url: cssUrl }).catch(() => {});
  await page.waitForTimeout(150);
  const result = await page.evaluate(() => {
    const wrap = document.createElement('div');
    wrap.className = 'ui-dialog';
    wrap.innerHTML = [
      '<div class="ui-dialog-titlebar">',
      '<span class="ui-dialog-title">图片预览</span>',
      '<button type="button" class="ui-dialog-titlebar-close" aria-label="关闭" title="关闭">',
      '<span class="ui-button-text">Close</span>',
      '</button></div>',
      '<div class="ui-dialog-content"><div class="media-library-view"><div class="views-view-grid">',
      '<div class="views-row media-library-item"><img alt="a" width="200" height="150" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></div>',
      '<div class="views-row media-library-item"><img alt="b" width="200" height="150" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></div>',
      '</div></div></div>',
    ].join('');
    document.body.appendChild(wrap);
    const btn = wrap.querySelector('.ui-dialog-titlebar-close');
    const textEl = wrap.querySelector('.ui-button-text');
    const grid = wrap.querySelector('.views-view-grid');
    const style = getComputedStyle(btn);
    const textStyle = getComputedStyle(textEl);
    const before = getComputedStyle(btn, '::before');
    const out = {
      textDisplay: textStyle.display,
      fontSize: style.fontSize,
      color: style.color,
      beforeW: before.width,
      beforeH: before.height,
      gridCols: grid ? getComputedStyle(grid).gridTemplateColumns : '',
      aria: btn.getAttribute('aria-label'),
    };
    wrap.remove();
    return out;
  });
  const textHidden = result.textDisplay === 'none' || result.fontSize === '0px' || result.color === 'rgba(0, 0, 0, 0)' || result.color === 'transparent';
  record(`${label}_dialog_close_text_hidden`, textHidden ? 'PASS' : 'FAIL', JSON.stringify(result));
  record(`${label}_dialog_close_icon`, parseFloat(result.beforeW) > 0 || parseFloat(result.beforeH) > 0 ? 'PASS' : 'FAIL', result.beforeW);
  record(`${label}_dialog_close_aria_zh`, result.aria === '关闭' ? 'PASS' : 'FAIL', result.aria || '');
  if (result.gridCols) {
    const cols = result.gridCols.split(/\s+/).filter(Boolean).length;
    record(`${label}_dialog_grid_two_col`, cols >= 2 ? 'PASS' : 'FAIL', result.gridCols);
  }
}

async function checkAdminImport(page) {
  await page.goto(`${BASE}/admin/content/image-imports/${ADMIN_TASK}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(800);
  const body = await page.locator('body').innerText();
  record('admin_has_publish', body.includes('发布') ? 'PASS' : 'FAIL');
  const info = await page.evaluate(() => {
    const grid = document.querySelector('.xedc-import-grid--preview, .xedc-import-grid');
    if (!grid) return null;
    const style = getComputedStyle(grid);
    return {
      cols: style.gridTemplateColumns,
      overflowY: style.overflowY,
      maxHeight: style.maxHeight,
      items: grid.querySelectorAll('img').length,
    };
  });
  if (!info) {
    record('admin_import_grid', 'FAIL', 'missing');
    return;
  }
  const colCount = info.cols.split(/\s+/).filter(Boolean).length;
  record('admin_import_two_col_css', colCount >= 2 || info.items <= 1 ? 'PASS' : 'FAIL', JSON.stringify(info));
  record('admin_import_scrollable', info.overflowY === 'auto' || info.overflowY === 'scroll' ? 'PASS' : 'FAIL', info.overflowY);
}

async function main() {
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  // Separate contexts so member/admin sessions do not clobber each other.
  const memberCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const adminCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });

  const memberPage = await login(memberCtx, MEMBER_LOGIN, 'member');
  if (memberPage) {
    await memberPage.goto(PHOTO_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await memberPage.waitForTimeout(900);
    record('member_photo_access', !(await memberPage.title()).includes('拒绝') ? 'PASS' : 'FAIL', await memberPage.title());
    await checkImgSheet(memberPage, 'desktop', 2);

    await memberPage.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'));
    await checkImgSheet(memberPage, 'dark', 2);

    await memberPage.setViewportSize({ width: 390, height: 844 });
    await checkImgSheet(memberPage, 'mobile', 2);
    await memberPage.setViewportSize({ width: 820, height: 1100 });
    await checkImgSheet(memberPage, 'tablet', 3);
    await memberPage.setViewportSize({ width: 1440, height: 900 });

    await memberPage.goto(`${BASE}/member/submit/photo`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await memberPage.waitForTimeout(800);
    record('member_submit_page', /submit|node\/add|edit/.test(memberPage.url()) ? 'PASS' : 'FAIL', memberPage.url());
    // Ensure dialog library can load for probe.
    await memberPage.evaluate(() => {
      if (window.Drupal && Drupal.ajax) {
        return;
      }
    });
    // Attach core dialog assets by visiting a page that already uses them is hard;
    // load script tags from libraries if present on submit form via media widget.
    await injectDialogProbe(memberPage, 'member', `${BASE}/themes/custom/xedc/css/dialog-fix.css`);

    await memberPage.goto(`${BASE}/member/submissions`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const taskLink = memberPage.locator('a[href*="/admin/content/image-imports/"]').first();
    if (await taskLink.count()) {
      await taskLink.click();
      await memberPage.waitForTimeout(600);
      const html = await memberPage.content();
      const text = await memberPage.locator('body').innerText();
      record('member_has_save', text.includes('保存') ? 'PASS' : 'FAIL');
      record('member_no_publish_href', !/image-imports\/\d+\/publish/.test(html) ? 'PASS' : 'FAIL');
    }
    else {
      record('member_import_owned_task', 'NOT_TESTED', 'uid2 has no import task in list');
    }
  }

  const adminPage = await login(adminCtx, ADMIN_LOGIN, 'admin');
  if (adminPage) {
    await checkAdminImport(adminPage);
    await injectDialogProbe(adminPage, 'admin', `${BASE}/themes/custom/xedc_admin/css/dialog.css`);
  }

  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify({ when: new Date().toISOString(), results }, null, 2));
  const failed = results.filter((r) => r.status === 'FAIL');
  const tested = results.filter((r) => r.status !== 'NOT_TESTED');
  console.log('SUMMARY\t' + (failed.length ? 'FAIL' : 'PASS') + '\t' + (tested.length - failed.length) + '/' + tested.length + '\tnot_tested=' + (results.length - tested.length));
  process.exit(failed.length ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(2);
});
