/**
 * Member submission UI smoke (frontend theme isolation + hub layout).
 *
 * LOGIN_URL='https://gitxx.xyz/user/reset/...' node .../browser-member-submission-ui.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const LOGIN = process.env.LOGIN_URL || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.SHOT_DIR || '/var/www/drupal/docs/tasks/screenshots/xedc-member-submission';
const results = [];
const record = (name, status, detail = '') => {
  results.push({ name, status, detail });
  console.log(`${status}\t${name}${detail ? '\t' + detail : ''}`);
};

fs.mkdirSync(OUT, { recursive: true });

async function shot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

const browser = await chromium.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});
const context = await browser.newContext({
  viewport: { width: 1440, height: 1100 },
  ignoreHTTPSErrors: true,
});
const page = await context.newPage();

try {
  if (!LOGIN) {
    record('login', 'NOT TESTED', 'LOGIN_URL missing');
  }
  else {
    await page.goto(LOGIN, { waitUntil: 'networkidle', timeout: 90000 });
    record('login', page.url().includes('/user/reset') && (await page.locator('text=Log in').count()) ? 'FAIL' : 'PASS', page.url());
  }

  await page.goto(`${BASE}/member/submissions`, { waitUntil: 'networkidle', timeout: 90000 });
  const hub = await page.locator('.xm-submit-hub .xm-submit-card').count();
  const adminShell = await page.locator('.xedc-admin-shell, .xedc-admin-header').count();
  const title = await page.locator('.xm-title').first().innerText().catch(() => '');
  await shot(page, 'submissions-hub-desktop');
  const hubText = await page.locator('.xm-submit-hub').innerText().catch(() => '');
  record('hub-cards', hub >= 4 ? 'PASS' : 'FAIL', `cards=${hub} title=${title}`);
  record('hub-no-gallery-entry', !hubText.includes('图片集投稿') ? 'PASS' : 'FAIL', hubText.slice(0, 160));
  record('hub-has-photo-and-archive', hubText.includes('图片投稿') && hubText.includes('压缩包投稿') ? 'PASS' : 'FAIL');
  record('hub-no-admin-shell', adminShell === 0 ? 'PASS' : 'FAIL', `adminShell=${adminShell}`);

  await page.setViewportSize({ width: 390, height: 844 });
  await shot(page, 'submissions-hub-mobile');
  record('hub-mobile', 'PASS', 'screenshot saved');

  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.goto(`${BASE}/node/add/photo`, { waitUntil: 'networkidle', timeout: 90000 });
  const bodyClass = await page.evaluate(() => document.body.className);
  const hasMemberForm = await page.locator('.xm-submission-form, .xm-submit').count();
  const hasFeatured = await page.locator('.field--name-field-featured, #edit-field-featured-value').count();
  const hasSlideshow = await page.locator('.field--name-field-slideshow, #edit-field-slideshow-value').count();
  const hasStaffStatus = await page.locator('text=压缩率').count();
  const hasTaskTable = await page.locator('text=已有导入任务').count();
  const hasUpload = await page.locator('[data-xedc-asset-upload], .field--name-field-images').count();
  const hasAdminNav = await page.locator('.xedc-admin-shell').count();
  const hasImportNavText = await page.getByText('图片导入', { exact: true }).count();
  await shot(page, 'photo-submit-desktop');
  record('photo-frontend-theme', /xm-submission-page/.test(bodyClass) ? 'PASS' : 'FAIL', bodyClass.slice(0, 120));
  record('photo-member-form', hasMemberForm > 0 ? 'PASS' : 'FAIL', `form=${hasMemberForm}`);
  record('photo-hide-featured', hasFeatured === 0 ? 'PASS' : 'FAIL', `featured=${hasFeatured}`);
  record('photo-hide-slideshow', hasSlideshow === 0 ? 'PASS' : 'FAIL', `slideshow=${hasSlideshow}`);
  record('photo-hide-staff-metrics', hasStaffStatus === 0 ? 'PASS' : 'FAIL', `ratioText=${hasStaffStatus}`);
  record('photo-hide-task-table', hasTaskTable === 0 ? 'PASS' : 'FAIL', `tasks=${hasTaskTable}`);
  record('photo-upload-present', hasUpload > 0 ? 'PASS' : 'FAIL', `upload=${hasUpload}`);
  record('photo-no-admin-chrome', (hasAdminNav === 0 && hasImportNavText === 0) ? 'PASS' : 'FAIL', `admin=${hasAdminNav} importText=${hasImportNavText}`);
  const photoImageDrop = await page.locator('.xedc-upload--images [data-xedc-drop="image"] input[data-xedc-images][multiple]').count();
  const photoArchiveDrop = await page.locator('[data-xedc-archive]').count();
  record('photo-has-multi-image', photoImageDrop > 0 ? 'PASS' : 'FAIL', `multi=${photoImageDrop}`);
  record('photo-no-zip-control', photoArchiveDrop === 0 ? 'PASS' : 'FAIL', `zip=${photoArchiveDrop}`);

  await page.goto(`${BASE}/node/add/photo?upload=archive`, { waitUntil: 'networkidle', timeout: 90000 });
  const archiveDrop = await page.locator('.xedc-upload--archive [data-xedc-drop="archive"] input[data-xedc-archive]').count();
  const archiveImageDrop = await page.locator('[data-xedc-images]').count();
  const archiveTitle = await page.locator('.xm-submit__title').innerText().catch(() => '');
  await shot(page, 'photo-archive-mode');
  record('archive-has-zip', archiveDrop > 0 ? 'PASS' : 'FAIL', `zip=${archiveDrop}`);
  record('archive-no-batch-image', archiveImageDrop === 0 ? 'PASS' : 'FAIL', `images=${archiveImageDrop}`);
  record('archive-title', archiveTitle.includes('压缩包') ? 'PASS' : 'FAIL', archiveTitle);

  await page.goto(`${BASE}/node/add/video`, { waitUntil: 'networkidle', timeout: 90000 });
  const videoForm = await page.locator('.xm-submission-form--video, .xm-submit__body--video').count();
  await shot(page, 'video-submit-desktop');
  record('video-member-form', videoForm > 0 ? 'PASS' : 'FAIL', `form=${videoForm}`);

  await page.goto(`${BASE}/node/add/article`, { waitUntil: 'networkidle', timeout: 90000 });
  const articleForm = await page.locator('.xm-submission-form--article, .xm-submit__body--article').count();
  await shot(page, 'article-submit-desktop');
  record('article-member-form', articleForm > 0 ? 'PASS' : 'FAIL', `form=${articleForm}`);

  // Dark mode
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-bs-theme', 'dark');
    document.documentElement.setAttribute('data-theme', 'dark');
  });
  await page.goto(`${BASE}/member/submissions`, { waitUntil: 'networkidle', timeout: 90000 });
  await shot(page, 'submissions-hub-dark');
  record('hub-dark', 'PASS', 'screenshot saved');
}
catch (e) {
  record('suite', 'FAIL', String(e && e.stack || e));
}
finally {
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  await browser.close();
}

const failed = results.filter((r) => r.status === 'FAIL');
process.exitCode = failed.length ? 1 : 0;
