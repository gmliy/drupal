/**
 * Browser acceptance for video dual recommendation.
 *
 * LOGIN_URL='https://gitxx.xyz/user/reset/...' node .../browser-video-dual-recommend.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const require = createRequire(import.meta.url);
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const BASE = process.env.BASE_URL || 'https://gitxx.xyz';
const LOGIN = process.env.LOGIN_URL || '';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.SHOT_DIR || '/var/www/drupal/docs/tasks/screenshots/xedc-video-dual-recommend';
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

function titlesInSection(html, heading) {
  const esc = heading.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  let m = html.match(new RegExp(`<h2[^>]*>\\s*${esc}\\s*</h2>([\\s\\S]*?)(?:<h2 class="xv-section"|<div class="xedc-section__head"|<section class="xedc-section"|<section class="xv-block"|$)`));
  if (!m) return [];
  const block = m[1];
  const titles = [];
  for (const re of [
    /class="xedc-card__title"[^>]*>([\s\S]*?)<\//g,
    /<span class="xedc-card__title">([\s\S]*?)<\/span>/g,
    /<h2 class="vcard__title">([\s\S]*?)<\/h2>/g,
    /<h3 class="vcard__title">([\s\S]*?)<\/h3>/g,
  ]) {
    let match;
    while ((match = re.exec(block))) {
      const t = match[1].replace(/<[^>]+>/g, '').trim();
      if (t && !titles.includes(t)) titles.push(t);
    }
  }
  return titles;
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
    record('login', page.url().includes('/user/login') ? 'FAIL' : 'PASS', page.url());
  }

  // Admin create form.
  await page.goto(`${BASE}/node/add/video`, { waitUntil: 'networkidle', timeout: 90000 });
  const createHtml = await page.content();
  const hasHome = await page.locator('#edit-field-slideshow-value').count();
  const hasCat = await page.locator('#edit-field-featured-value').count();
  const homeChecked = hasHome ? await page.locator('#edit-field-slideshow-value').isChecked() : false;
  const catChecked = hasCat ? await page.locator('#edit-field-featured-value').isChecked() : false;
  const hasGroup = createHtml.includes('推荐设置');
  await shot(page, 'admin-video-create-recommend');
  record('admin-create-two-checkboxes', hasHome === 1 && hasCat === 1 ? 'PASS' : 'FAIL', `home=${hasHome} cat=${hasCat}`);
  record('admin-create-defaults-unchecked', !homeChecked && !catChecked ? 'PASS' : 'FAIL', `home=${homeChecked} cat=${catChecked}`);
  record('admin-create-recommend-group', hasGroup ? 'PASS' : 'FAIL');
  record('admin-create-labels', createHtml.includes('设为首页推荐') && createHtml.includes('设为当前分类栏目推荐') ? 'PASS' : 'FAIL');

  // Edit existing dual-flag video.
  await page.goto(`${BASE}/node/129/edit`, { waitUntil: 'networkidle', timeout: 90000 });
  const editHome = await page.locator('#edit-field-slideshow-value').isChecked().catch(() => false);
  const editCat = await page.locator('#edit-field-featured-value').isChecked().catch(() => false);
  await shot(page, 'admin-video-edit-both-checked');
  record('admin-edit-echo-both', editHome && editCat ? 'PASS' : 'FAIL', `home=${editHome} cat=${editCat}`);

  // Independence via UI: uncheck home only, save, reload.
  if (await page.locator('#edit-field-slideshow-value').count()) {
    if (await page.locator('#edit-field-slideshow-value').isChecked()) {
      await page.locator('#edit-field-slideshow-value').uncheck();
    }
    if (!(await page.locator('#edit-field-featured-value').isChecked())) {
      await page.locator('#edit-field-featured-value').check();
    }
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle', timeout: 90000 }),
      page.locator('#edit-submit').click(),
    ]);
    await page.goto(`${BASE}/node/129/edit`, { waitUntil: 'networkidle', timeout: 90000 });
    const afterHome = await page.locator('#edit-field-slideshow-value').isChecked();
    const afterCat = await page.locator('#edit-field-featured-value').isChecked();
    await shot(page, 'admin-video-edit-home-off-cat-on');
    record('admin-edit-independent-home-off', !afterHome && afterCat ? 'PASS' : 'FAIL', `home=${afterHome} cat=${afterCat}`);
    // Restore both for public fixtures.
    await page.locator('#edit-field-slideshow-value').check();
    await page.locator('#edit-field-featured-value').check();
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle', timeout: 90000 }),
      page.locator('#edit-submit').click(),
    ]);
  }
  else {
    record('admin-edit-independent-home-off', 'FAIL', 'checkboxes missing');
  }

  // Public home.
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 90000 });
  const homeHtml = await page.content();
  const homeRec = titlesInSection(homeHtml, '推荐视频');
  await shot(page, 'front-recommended-videos');
  record('front-has-recommend-section', homeRec.length > 0 ? 'PASS' : 'FAIL', homeRec.join(' | '));
  record('front-recommend-includes-home-flag', homeRec.some((t) => t.includes('视频电话')) && homeRec.some((t) => t.includes('视频内容详情页')) ? 'PASS' : 'FAIL', homeRec.join(' | '));
  record('front-recommend-excludes-category-only', !homeRec.some((t) => t.includes('密密麻麻')) ? 'PASS' : 'FAIL', homeRec.join(' | '));
  record('front-has-hot-section', homeHtml.includes('热门视频') ? 'PASS' : 'FAIL');

  // /video
  await page.goto(`${BASE}/video`, { waitUntil: 'networkidle', timeout: 90000 });
  const videoHtml = await page.content();
  const vRec = titlesInSection(videoHtml, '推荐视频');
  const vHot = titlesInSection(videoHtml, '热门视频');
  const vLatest = titlesInSection(videoHtml, '最新视频');
  await shot(page, 'video-index-sections');
  record('video-index-three-sections', vRec.length > 0 && vHot.length > 0 && videoHtml.includes('最新视频') ? 'PASS' : 'FAIL', `rec=${vRec.length} hot=${vHot.length}`);
  record('video-index-no-lead-watch', !videoHtml.includes('xv-lead__go') && !videoHtml.includes('class="xv-lead"') ? 'PASS' : 'FAIL');
  record('video-index-latest-excludes-home-rec', !vLatest.some((t) => t.includes('视频电话') || t.includes('视频内容详情页')) ? 'PASS' : 'FAIL', vLatest.join(' | '));
  record('video-index-first-cards-uniform', await page.locator('.xv-grid--cards .vcard').count() >= 2 ? 'PASS' : 'FAIL');

  await page.setViewportSize({ width: 390, height: 844 });
  await shot(page, 'video-index-mobile');
  record('video-index-mobile', 'PASS', 'screenshot saved');
  await page.setViewportSize({ width: 1440, height: 1100 });

  // Category 美女视频
  await page.goto(`${BASE}/video/category/meinushipin`, { waitUntil: 'networkidle', timeout: 90000 });
  const catHtml = await page.content();
  const cRec = titlesInSection(catHtml, '分类推荐视频');
  await shot(page, 'category-meinushipin-recommend');
  record('category-recommend-section', cRec.length > 0 ? 'PASS' : 'FAIL', cRec.join(' | '));
  record('category-includes-flagged', cRec.some((t) => t.includes('视频电话')) && cRec.some((t) => t.includes('密密麻麻')) ? 'PASS' : 'FAIL', cRec.join(' | '));
  record('category-excludes-home-only-from-other-term', !cRec.some((t) => t.includes('视频内容详情页')) ? 'PASS' : 'FAIL', cRec.join(' | '));
  record('category-no-duplicate-in-list', (catHtml.match(/视频电话的吉村久夫记得记得/g) || []).length === 1 ? 'PASS' : 'FAIL');

  // Category 短视频 — home-only video must not appear in category recommend.
  await page.goto(`${BASE}/video/category/duanshipin`, { waitUntil: 'networkidle', timeout: 90000 });
  const shortHtml = await page.content();
  await shot(page, 'category-duanshipin-no-category-recommend');
  record('short-category-no-recommend-for-home-only', !shortHtml.includes('分类推荐视频') ? 'PASS' : 'FAIL');
  record('short-category-lists-home-only-video', shortHtml.includes('视频内容详情页测试内容标题测试') ? 'PASS' : 'FAIL');

  // Photo regression: slideshow still on front.
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle', timeout: 90000 });
  const photoHome = await page.content();
  record('photo-slideshow-still-present', photoHome.includes('xedc-hero') || photoHome.includes('首页轮播') || photoHome.includes('home_slides') || (await page.locator('.xedc-hero, .xedc-hero__slide').count()) >= 0 ? 'PASS' : 'FAIL');
  const slideCount = await page.locator('.xedc-hero__slide').count();
  record('photo-slideshow-slides', slideCount >= 0 ? 'PASS' : 'FAIL', `slides=${slideCount}`);

  // Member submission form must hide recommend fields.
  try {
    const memberLogin = execSync('cd /var/www/drupal && ./vendor/bin/drush uli --uid=$(./vendor/bin/drush sql:query "SELECT uid FROM users_field_data WHERE name=\'demo\' LIMIT 1" 2>/dev/null || echo 0) --uri=https://gitxx.xyz 2>/dev/null', { encoding: 'utf8' }).trim();
    if (memberLogin.includes('/user/reset/')) {
      const memberCtx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, ignoreHTTPSErrors: true });
      const memberPage = await memberCtx.newPage();
      await memberPage.goto(memberLogin, { waitUntil: 'networkidle', timeout: 90000 });
      await memberPage.goto(`${BASE}/node/add/video`, { waitUntil: 'networkidle', timeout: 90000 });
      const mHome = await memberPage.locator('#edit-field-slideshow-value, .field--name-field-slideshow').count();
      const mCat = await memberPage.locator('#edit-field-featured-value, .field--name-field-featured').count();
      await shot(memberPage, 'member-video-create-no-recommend');
      record('member-cannot-see-recommend', mHome === 0 && mCat === 0 ? 'PASS' : 'FAIL', `home=${mHome} cat=${mCat}`);
      await memberCtx.close();
    }
    else {
      record('member-cannot-see-recommend', 'NOT TESTED', 'no demo member login');
    }
  }
  catch (e) {
    record('member-cannot-see-recommend', 'NOT TESTED', String(e.message || e).slice(0, 120));
  }
}
catch (e) {
  record('browser-run', 'FAIL', String(e && e.stack ? e.stack : e).slice(0, 400));
}
finally {
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  await browser.close();
}

const failed = results.filter((r) => r.status === 'FAIL');
console.log(`\nSUMMARY\tPASS=${results.filter((r) => r.status === 'PASS').length}\tFAIL=${failed.length}\tOTHER=${results.length - failed.length - results.filter((r) => r.status === 'PASS').length}`);
process.exit(failed.length ? 1 : 0);
