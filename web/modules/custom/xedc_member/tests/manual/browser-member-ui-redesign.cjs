/**
 * Member center + public profile UI redesign browser audit.
 * Read-only: no form submits that mutate production data.
 */
const { chromium } = require('/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.XEDC_BASE || 'https://gitxx.xyz';
const LOGIN = process.env.XEDC_LOGIN_URL;
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.XEDC_OUT || '/tmp/xedc-member-ui-audit';
const SLUG = process.env.XEDC_SLUG || 'dcxe';

if (!LOGIN) {
  console.error('XEDC_LOGIN_URL required');
  process.exit(1);
}

fs.mkdirSync(OUT, { recursive: true });

const MEMBER_PATHS = [
  { id: 'overview', path: `/member` },
  { id: 'favorites', path: `/member/favorites` },
  { id: 'likes', path: `/member/likes` },
  { id: 'downloads', path: `/member/downloads` },
  { id: 'membership', path: `/member/membership` },
  { id: 'history', path: `/member/history` },
  { id: 'comments', path: `/member/comments` },
  { id: 'submissions', path: `/member/submissions` },
  { id: 'profile', path: `/member/profile` },
  { id: 'security', path: `/member/security` },
  { id: 'messages', path: `/member/messages` },
  { id: 'notifications', path: `/member/notifications` },
  { id: 'notifications-social', path: `/member/notifications/social` },
  { id: 'notifications-vip', path: `/member/notifications/vip` },
  { id: 'notifications-official', path: `/member/notifications/official` },
];

const PUBLIC_PATHS = [
  { id: 'public-anon', path: `/r/${SLUG}`, auth: false },
  { id: 'public-auth', path: `/r/${SLUG}`, auth: true },
];

function privacyHits(html) {
  const hits = [];
  const lower = html.toLowerCase();
  // Public page must not dump private account fields into hero/body text blocks.
  if (/type=["']password["']/.test(lower)) hits.push('password-field');
  if (/账号安全|浏览历史|我的下载|我的收藏|我的点赞/.test(html) && /xp-hero|class="xp"/.test(html)) {
    // These labels in public profile would be a leak; nav sitewide is ok.
    if (/xp-hero[\s\S]{0,1200}(账号安全|浏览历史|我的下载)/.test(html)) hits.push('private-label-in-hero');
  }
  return hits;
}

async function auditPage(page, label, url, opts = {}) {
  const result = {
    id: label,
    url,
    status: null,
    ok: false,
    title: '',
    hasXm: false,
    hasXp: false,
    cssMember: false,
    cssNotify: false,
    cssPublic: false,
    jsErrors: [],
    overflowX: false,
    stickyTopbars: 0,
    privacy: [],
    notes: [],
  };

  const jsErrors = [];
  const onError = (err) => jsErrors.push(String(err));
  page.on('pageerror', onError);

  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  result.status = response ? response.status() : null;
  await page.waitForTimeout(500);

  result.title = await page.title();
  result.jsErrors = jsErrors;
  page.off('pageerror', onError);

  const probe = await page.evaluate(() => {
    const html = document.documentElement.outerHTML;
    const styles = [...document.styleSheets].map((s) => s.href || '').filter(Boolean);
    const sticky = [...document.querySelectorAll('*')].filter((el) => {
      const st = getComputedStyle(el);
      if (st.position !== 'fixed' && st.position !== 'sticky') return false;
      const top = parseFloat(st.top || '0');
      return top <= 4 && el.getBoundingClientRect().height > 20 && el.getBoundingClientRect().top < 120;
    });
    return {
      hasXm: !!document.querySelector('.xm,[data-xm-root]'),
      hasXp: !!document.querySelector('.xp'),
      cssMember: styles.some((h) => h.includes('member.css') || h.includes('/xedc_member/css/')),
      cssNotify: styles.some((h) => h.includes('notify.css')),
      cssPublic: styles.some((h) => h.includes('public-profile.css')),
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
      stickyTopbars: sticky.length,
      bodyClass: document.body.className,
      welcome: !!document.querySelector('.xm-welcome'),
      vipStrip: !!document.querySelector('.xm-vip-strip'),
      side: !!document.querySelector('.xm-side'),
      drawerClose: !!document.querySelector('[data-xm-close]'),
      htmlSnippet: html.slice(0, 200000),
    };
  });

  Object.assign(result, {
    hasXm: probe.hasXm,
    hasXp: probe.hasXp,
    cssMember: probe.cssMember,
    cssNotify: probe.cssNotify,
    cssPublic: probe.cssPublic,
    overflowX: probe.overflowX,
    stickyTopbars: probe.stickyTopbars,
    welcome: probe.welcome,
    vipStrip: probe.vipStrip,
    side: probe.side,
    drawerClose: probe.drawerClose,
    bodyClass: probe.bodyClass,
  });

  if (opts.privacy) {
    result.privacy = privacyHits(probe.htmlSnippet);
  }

  if (opts.screenshot) {
    const shot = path.join(OUT, `${label}${opts.suffix || ''}.png`);
    await page.screenshot({ path: shot, fullPage: false });
    result.screenshot = shot;
  }

  result.ok = result.status === 200 && result.jsErrors.length === 0 && !result.overflowX;
  if (opts.expectXm && !result.hasXm) {
    result.ok = false;
    result.notes.push('missing .xm shell');
  }
  if (opts.expectXp && !result.hasXp) {
    result.ok = false;
    result.notes.push('missing .xp profile');
  }
  if (opts.privacy && result.privacy.length) {
    result.ok = false;
    result.notes.push('privacy:' + result.privacy.join(','));
  }
  return result;
}

(async () => {
  const browser = await chromium.launch({
    executablePath: fs.existsSync(CHROME) ? CHROME : undefined,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
  });
  await context.addInitScript(() => {
    try {
      if (!window.__xedcThemeLock) {
        const cur = localStorage.getItem('theme');
        if (cur !== 'dark' && cur !== 'light') localStorage.setItem('theme', 'light');
      }
    } catch (e) {}
  });
  const page = await context.newPage();
  const report = { started: new Date().toISOString(), results: [], mobile: [], dark: [], submissions: [], notes: [] };

  // Login
  await page.goto(LOGIN, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(800);
  const logged = await page.evaluate(() => document.body.textContent.includes('退出') || !!document.querySelector('a[href*="user/logout"]'));
  report.notes.push(logged ? 'login-ok' : 'login-uncertain');

  // Resolve vanity /member redirect
  for (const item of MEMBER_PATHS) {
    const r = await auditPage(page, item.id, BASE + item.path, {
      expectXm: true,
      screenshot: ['overview', 'membership', 'favorites', 'messages', 'notifications', 'submissions', 'profile'].includes(item.id),
    });
    report.results.push(r);
    console.log(`[member] ${item.id} ${r.status} ok=${r.ok} xm=${r.hasXm} css=${r.cssMember} overflow=${r.overflowX}`);
  }

  // Submission create pages (GET only)
  for (const type of ['photo', 'video', 'article']) {
    const url = `${BASE}/node/add/${type}`;
    const r = await auditPage(page, `submit-${type}`, url, { screenshot: type === 'photo' });
    r.notes.push('submission-form-route');
    report.submissions.push(r);
    console.log(`[submit] ${type} ${r.status} ok=${r.ok}`);
  }

  // Try open first editable submission if list has edit link
  await page.goto(BASE + '/member/submissions', { waitUntil: 'domcontentloaded' });
  const editHref = await page.evaluate(() => {
    const a = document.querySelector('.xm-submission-card__actions a.xm-btn--primary, .xm-submission-card a[href*="/edit"]');
    return a ? a.href : '';
  });
  if (editHref) {
    const r = await auditPage(page, 'submission-edit', editHref, { screenshot: true });
    report.submissions.push(r);
    console.log(`[submit] edit ${r.status} ok=${r.ok}`);
  }
  else {
    report.submissions.push({ id: 'submission-edit', ok: null, notes: ['NOT TESTED: no editable submission for uid'] });
    console.log('[submit] edit NOT TESTED');
  }

  // Message thread if any
  await page.goto(BASE + '/member/messages', { waitUntil: 'domcontentloaded' });
  const threadHref = await page.evaluate(() => {
    const a = document.querySelector('.xn-msg__thread-link, a[href*="/member/messages/"]');
    return a && a.href.includes('/member/messages/') ? a.href : '';
  });
  if (threadHref) {
    const r = await auditPage(page, 'message-thread', threadHref, { expectXm: true, screenshot: true });
    report.results.push(r);
    console.log(`[member] message-thread ${r.status} ok=${r.ok}`);
  }
  else {
    report.results.push({ id: 'message-thread', ok: null, notes: ['NOT TESTED: no existing thread'] });
    console.log('[member] message-thread NOT TESTED');
  }

  // Public profile authenticated
  for (const item of PUBLIC_PATHS.filter((p) => p.auth)) {
    const r = await auditPage(page, item.id, BASE + item.path, {
      expectXp: true,
      privacy: true,
      screenshot: true,
    });
    report.results.push(r);
    console.log(`[public] ${item.id} ${r.status} ok=${r.ok} privacy=${r.privacy.join('|') || 'clean'}`);
  }

  // Dark mode via site theme key (xedc-navbar.js reads localStorage.theme)
  await context.addInitScript(() => {
    window.__xedcThemeLock = true;
    try { localStorage.setItem('theme', 'dark'); } catch (e) {}
  });
  await page.evaluate(() => {
    window.__xedcThemeLock = true;
    try { localStorage.setItem('theme', 'dark'); } catch (e) {}
  });
  for (const p of ['/member', `/r/${SLUG}`, '/member/notifications']) {
    const id = 'dark-' + p.replace(/\W+/g, '_');
    await page.goto(BASE + p, { waitUntil: 'networkidle', timeout: 45000 });
    await page.waitForTimeout(300);
    const contrast = await page.evaluate(() => {
      const el = document.querySelector('.xm-welcome .xm-title, .xm-title, .xp-name, .xn-ntf__head h1');
      const hero = document.querySelector('.xp-hero, .xm-welcome, .xn-ntf');
      return {
        theme: document.documentElement.getAttribute('data-bs-theme'),
        fg: el ? getComputedStyle(el).color : null,
        panelBg: hero ? getComputedStyle(hero).backgroundColor : null,
        bodyBg: getComputedStyle(document.body).backgroundColor,
        bodyClass: document.body.className,
      };
    });
    const shot = path.join(OUT, `${id}-dark.png`);
    await page.screenshot({ path: shot, fullPage: false });
    const r = {
      id,
      url: BASE + p,
      status: 200,
      themeProbe: contrast,
      screenshot: shot,
      ok: contrast.theme === 'dark'
        && !!contrast.bodyBg
        && (contrast.bodyBg.includes('9, 9, 11') || contrast.panelBg.includes('24, 24, 27') || (contrast.fg || '').includes('250, 250, 250')),
    };
    report.dark.push(r);
    console.log(`[dark] ${p} ok=${r.ok} theme=${contrast.theme} fg=${contrast.fg} panel=${contrast.panelBg} body=${contrast.bodyBg} class=${contrast.bodyClass}`);
  }

  // Mobile drawer
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => {
    document.documentElement.removeAttribute('data-bs-theme');
    document.documentElement.removeAttribute('data-theme');
  });
  await page.goto(BASE + '/member', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);
  const mobile = { id: 'mobile-overview', drawer: {}, ok: false };
  mobile.before = await page.evaluate(() => ({
    topbar: !!document.querySelector('.xm-topbar'),
    topbarDisplay: document.querySelector('.xm-topbar') ? getComputedStyle(document.querySelector('.xm-topbar')).display : null,
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
  }));
  const openBtn = await page.$('[data-xm-open]');
  if (openBtn) {
    await openBtn.click();
    await page.waitForTimeout(350);
  }
  mobile.drawer = await page.evaluate(() => {
    const side = document.querySelector('.xm-side,[data-xm-drawer]');
    const close = document.querySelector('[data-xm-close]');
    const open = document.documentElement.classList.contains('xm-nav-open');
    const rect = side ? side.getBoundingClientRect() : null;
    return {
      open,
      closeVisible: !!(close && getComputedStyle(close).display !== 'none'),
      sideVisible: !!(side && getComputedStyle(side).visibility !== 'hidden'),
      sideLeft: rect ? rect.left : null,
    };
  });
  await page.screenshot({ path: path.join(OUT, 'mobile-drawer.png') });
  if (mobile.drawer.open && mobile.drawer.closeVisible) {
    await page.click('[data-xm-close]');
    await page.waitForTimeout(300);
    mobile.afterClose = await page.evaluate(() => document.documentElement.classList.contains('xm-nav-open'));
  }
  mobile.ok = mobile.before.topbarDisplay === 'flex' && mobile.drawer.open === true && mobile.drawer.closeVisible === true && mobile.afterClose === false && !mobile.before.overflowX;
  report.mobile.push(mobile);
  console.log(`[mobile] drawer ok=${mobile.ok}`, JSON.stringify(mobile.drawer));

  // Public mobile
  const pubMobile = await auditPage(page, 'public-mobile', BASE + `/r/${SLUG}`, {
    expectXp: true,
    privacy: true,
    screenshot: true,
  });
  report.mobile.push(pubMobile);

  // Anonymous public
  const anonCtx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const anonPage = await anonCtx.newPage();
  const anon = await auditPage(anonPage, 'public-anon', BASE + `/r/${SLUG}`, {
    expectXp: true,
    privacy: true,
    screenshot: true,
  });
  report.results.push(anon);
  await anonCtx.close();
  console.log(`[public] anon ${anon.status} ok=${anon.ok}`);

  // Empty public profile probe: find a user with no published works if cheap; else note.
  report.notes.push('empty-public-profile: NOT TESTED unless fixture exists');

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.log('WROTE', path.join(OUT, 'report.json'));
  await browser.close();

  const fails = [...report.results, ...report.submissions, ...report.dark]
    .filter((r) => r && r.ok === false);
  if (fails.length) {
    console.log('FAILS', fails.map((f) => f.id + ':' + (f.notes || []).join(';')).join(', '));
    process.exitCode = 2;
  }
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
