/**
 * Public creator profile redesign acceptance (read-only).
 * Does not send messages or mutate production data beyond session login.
 */
import { chromium } from '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const BASE = process.env.XEDC_BASE || 'https://gitxx.xyz';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.XEDC_OUT || '/var/www/drupal/docs/tasks/screenshots/xedc-public-profile';
const DRUSH = process.env.XEDC_DRUSH || '/var/www/drupal/vendor/bin/drush';

fs.mkdirSync(OUT, { recursive: true });

function uli(uid) {
  const out = execSync(`${DRUSH} uli --uid=${uid} --uri=${BASE}`, { encoding: 'utf8' }).trim();
  const line = out.split('\n').map((s) => s.trim()).find((s) => s.startsWith('http'));
  if (!line) throw new Error(`uli failed for uid ${uid}: ${out}`);
  return line;
}

async function shot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function measure(page) {
  return page.evaluate(() => {
    const xp = document.querySelector('.xp');
    const primary = document.querySelector('.xp-action--primary');
    const actions = document.querySelector('.xn-profile-actions, .xp-actions');
    const hero = document.querySelector('.xp-profile, .xp-hero');
    const cs = primary ? getComputedStyle(primary) : null;
    const body = document.body;
    return {
      hasXp: !!xp,
      hasProfile: !!hero,
      hasActions: !!actions,
      actionCount: actions ? actions.querySelectorAll('a').length : 0,
      primaryText: primary ? (primary.textContent || '').trim() : '',
      primaryHref: primary ? primary.getAttribute('href') || '' : '',
      primaryColor: cs ? cs.color : null,
      primaryBg: cs ? cs.backgroundColor : null,
      primaryVisible: primary ? (primary.getBoundingClientRect().height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none') : false,
      contrastOk: !!(cs && cs.color && cs.backgroundColor && cs.color !== cs.backgroundColor),
      name: document.querySelector('.xp-name')?.textContent?.trim() || '',
      handle: document.querySelector('.xp-handle')?.textContent?.trim() || '',
      bio: document.querySelector('.xp-bio')?.textContent?.trim() || '',
      filterCount: document.querySelectorAll('.xp-filter').length,
      cardCount: document.querySelectorAll('.xp-card').length,
      empty: document.querySelector('.xp-empty')?.textContent?.trim() || '',
      overflowX: body.scrollWidth > body.clientWidth + 1,
      privateHits: (() => {
        const html = xp ? xp.innerHTML : '';
        const hits = [];
        if (/账号安全|浏览历史|我的下载|我的收藏|我的点赞|邮箱|password/i.test(html)) hits.push('private-label');
        if (/type=["']password["']/i.test(html)) hits.push('password-field');
        return hits;
      })(),
      cssPublic: [...document.styleSheets].some((s) => (s.href || '').includes('public-profile.css')),
    };
  });
}

async function setTheme(page, theme) {
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-bs-theme', t);
    document.documentElement.setAttribute('data-theme', t);
  }, theme);
}

async function loginWithUli(page, uid) {
  const url = uli(uid);
  const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  return res ? res.status() : null;
}

async function gotoProfile(page, slug, query = '') {
  const url = `${BASE}/r/${slug}${query}`;
  const errors = [];
  const onErr = (e) => errors.push(String(e));
  page.on('pageerror', onErr);
  const res = await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
  page.off('pageerror', onErr);
  return { url, status: res ? res.status() : null, errors };
}

const report = {
  started: new Date().toISOString(),
  base: BASE,
  results: [],
  notes: [],
};

async function run() {
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  const viewports = [
    { id: 'desktop', width: 1440, height: 900 },
    { id: 'tablet', width: 768, height: 1024 },
    { id: 'mobile', width: 390, height: 844 },
  ];

  // 1) Anonymous: profile with works
  {
    const context = await browser.newContext({ viewport: viewports[0] });
    const page = await context.newPage();
    const nav = await gotoProfile(page, 'aa');
    const m = await measure(page);
    const shotPath = await shot(page, 'anon-aa-light-1440');
    report.results.push({
      id: 'anon-with-works',
      ...nav,
      ...m,
      shot: shotPath,
      expect: {
        status200: nav.status === 200,
        hasPrimaryGuest: m.primaryText.includes('登录') || m.primaryText.includes('私信'),
        loginHref: /user\/login/.test(m.primaryHref),
        contrastOk: m.contrastOk,
        noPrivate: m.privateHits.length === 0,
      },
    });

    // type filters
    for (const type of ['photo', 'video', 'article']) {
      const n = await gotoProfile(page, 'aa', `?type=${type}`);
      const mm = await measure(page);
      await shot(page, `anon-aa-${type}-1440`);
      report.results.push({
        id: `anon-filter-${type}`,
        status: n.status,
        errors: n.errors,
        cardCount: mm.cardCount,
        empty: mm.empty,
        activeFilter: await page.locator('.xp-filter.is-active').first().textContent().catch(() => ''),
      });
    }

    // dark
    await gotoProfile(page, 'aa');
    await setTheme(page, 'dark');
    await page.waitForTimeout(200);
    const dark = await measure(page);
    await shot(page, 'anon-aa-dark-1440');
    report.results.push({ id: 'anon-dark', ...dark, contrastOk: dark.contrastOk });

    // empty profile
    const emptyNav = await gotoProfile(page, '12shh');
    const emptyM = await measure(page);
    await shot(page, 'anon-empty-12shh-1440');
    report.results.push({
      id: 'anon-empty',
      ...emptyNav,
      ...emptyM,
      expectEmpty: emptyM.cardCount === 0 && !!emptyM.empty,
    });

    // long-ish bio profile (admin)
    report.notes.push('long-bio: admin bio ~70 chars tested via /r/aa');

    await context.close();
  }

  // 2) Responsive viewports anon
  for (const vp of viewports) {
    const context = await browser.newContext({ viewport: vp });
    const page = await context.newPage();
    const nav = await gotoProfile(page, 'aa');
    const m = await measure(page);
    await shot(page, `anon-aa-light-${vp.id}-${vp.width}`);
    report.results.push({
      id: `responsive-${vp.id}`,
      status: nav.status,
      overflowX: m.overflowX,
      primaryVisible: m.primaryVisible,
      contrastOk: m.contrastOk,
      cardCount: m.cardCount,
      errors: nav.errors,
    });
    await context.close();
  }

  // 3) Logged-in as uid 3 visiting /r/aa — PM should target uid 1
  {
    const context = await browser.newContext({ viewport: viewports[0] });
    const page = await context.newPage();
    await loginWithUli(page, 3);
    const nav = await gotoProfile(page, 'aa');
    const m = await measure(page);
    await shot(page, 'auth-uid3-on-aa-1440');

    let pm = { clicked: false, finalUrl: '', status: null, recipientOk: false };
    if (m.primaryHref && !/user\/login/.test(m.primaryHref)) {
      const res = await page.goto(new URL(m.primaryHref, BASE).toString(), {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      pm.clicked = true;
      pm.status = res ? res.status() : null;
      pm.finalUrl = page.url();
      // message_start should land on /member/messages/{thread} without 403/404
      // Member vanity aliases rewrite /member/* → /{slug}/* for the viewer.
      pm.okFlow = pm.status === 200 && /\/(?:member|[^/]+)\/messages(?:\/\d+)?(?:$|\?)/.test(pm.finalUrl);
      await shot(page, 'auth-uid3-pm-after-click');
      // Do not send a message body — only verify route entry.
    }

    report.results.push({
      id: 'auth-other-pm',
      ...nav,
      primaryText: m.primaryText,
      primaryHref: m.primaryHref,
      contrastOk: m.contrastOk,
      actionCount: m.actionCount,
      pm,
      expectTarget: /\/messages\/to\/1(?:$|\?)/.test(m.primaryHref) || /user[=/]1/.test(m.primaryHref),
    });
    await context.close();
  }

  // 4) Own profile as uid 1 — no self-PM
  {
    const context = await browser.newContext({ viewport: viewports[0] });
    const page = await context.newPage();
    await loginWithUli(page, 1);
    const nav = await gotoProfile(page, 'aa');
    const m = await measure(page);
    await shot(page, 'auth-own-aa-1440');
    report.results.push({
      id: 'auth-own-no-pm',
      ...nav,
      actionCount: m.actionCount,
      primaryText: m.primaryText,
      hasPrimary: !!m.primaryText,
      privateHits: m.privateHits,
      expectNoSelfPm: m.actionCount === 0 && !m.primaryText,
    });

    // dark own
    await setTheme(page, 'dark');
    await page.waitForTimeout(200);
    await shot(page, 'auth-own-aa-dark-1440');
    await context.close();
  }

  // 5) Logged-in visiting empty other profile
  {
    const context = await browser.newContext({ viewport: viewports[2] });
    const page = await context.newPage();
    await loginWithUli(page, 3);
    const nav = await gotoProfile(page, '12shh');
    const m = await measure(page);
    await shot(page, 'auth-uid3-empty-12shh-mobile');
    report.results.push({
      id: 'auth-other-empty-mobile',
      ...nav,
      ...m,
      expectPm: !!m.primaryText && m.contrastOk && !m.overflowX,
    });
    await context.close();
  }

  await browser.close();
  report.finished = new Date().toISOString();
  const outJson = path.join(OUT, 'results.json');
  fs.writeFileSync(outJson, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  console.log('WROTE', outJson);
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
