/**
 * Measure search input vs dropdown alignment (getBoundingClientRect).
 * Run: node web/modules/custom/xedc_search/tests/manual/measure-search-layout.mjs [baseUrl]
 */
import { chromium, devices } from 'playwright';

const base = process.argv[2] || process.env.XEDC_BASE_URL || 'http://127.0.0.1';
const hostHeader = process.env.XEDC_HOST || 'gitxx.xyz';

const pages = [
  { name: 'global-nav', path: '/', selector: 'form.xn-search input[type="search"]' },
  { name: 'search-page', path: '/search', selector: 'form.sr-form input.sr-input' },
  { name: 'video', path: '/video', selector: 'form.xv-search input[type="search"]' },
  { name: 'article', path: '/articles', selector: 'form.xa-search input[type="search"]' },
  { name: 'models', path: '/models', selector: '.xm-search input[type="search"]' },
  { name: 'tags', path: '/tags', selector: 'form.xt-toolbar input[type="search"]' },
  { name: 'photo', path: '/meitu', selector: '.photo-drop__search input[type="search"]', openDrop: true },
];

async function measure(page, label, selector) {
  const input = page.locator(selector).first();
  if ((await input.count()) === 0) {
    return { label, status: 'BLOCKED', detail: 'input not found' };
  }
  await input.focus();
  await page.waitForTimeout(600);
  const panel = page.locator('.xs-mount.is-open > .xs-panel:not([hidden]), .xs-wrap.is-open > .xs-panel:not([hidden])').first();
  if ((await panel.count()) === 0) {
    return { label, status: 'NOT TESTED', detail: 'empty input panel closed (no history/hot) or not open' };
  }
  const box = await page.evaluate(() => {
    const inputEl = document.querySelector('.xs-mount.is-open input[aria-expanded="true"], .xs-wrap.is-open input[aria-expanded="true"]');
    const mountEl = inputEl && (inputEl.closest('.xs-mount.is-open') || inputEl.closest('.xs-wrap.is-open'));
    const panelEl = mountEl && mountEl.querySelector(':scope > .xs-panel:not([hidden])');
    if (!inputEl || !panelEl || !mountEl) {
      return null;
    }
    const anchor = mountEl.classList.contains('sr-shell') || mountEl.classList.contains('sr-field') ? mountEl : inputEl;
    const i = anchor.getBoundingClientRect();
    const p = panelEl.getBoundingClientRect();
    return {
      gapY: Math.round((p.top - i.bottom) * 100) / 100,
      deltaLeft: Math.round((p.left - i.left) * 100) / 100,
      deltaRight: Math.round((p.right - i.right) * 100) / 100,
      deltaWidth: Math.round((p.width - i.width) * 100) / 100,
      inputW: Math.round(i.width * 100) / 100,
      panelW: Math.round(p.width * 100) / 100,
      fixed: panelEl.classList.contains('xs-panel--fixed'),
    };
  });
  if (!box) {
    return { label, status: 'FAIL', detail: 'no rects' };
  }
  const ok = box.gapY === 0 && box.deltaLeft === 0 && box.deltaRight === 0 && box.deltaWidth === 0;
  return {
    label,
    status: ok ? 'PASS' : 'FAIL',
    ...box,
  };
}

const viewports = [
  { tag: 'desktop', width: 1280, height: 800 },
  { tag: 'iphone', device: devices['iPhone 13'] },
];

const results = [];
const browser = await chromium.launch({ headless: true });
for (const vp of viewports) {
  const hostOverride = base.includes('127.0.0.1') || base.includes('localhost');
  const common = hostOverride ? { extraHTTPHeaders: { Host: hostHeader } } : {};
  const context = vp.device
    ? await browser.newContext({ ...vp.device, ...common })
    : await browser.newContext({ viewport: { width: vp.width, height: vp.height }, ...common });
  const page = await context.newPage();
  for (const entry of pages) {
    const url = base.replace(/\/$/, '') + entry.path;
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
      if (entry.openDrop) {
        const trigger = page.locator('[data-photo-drop], .photo-drop button, .photo-toolbar button').first();
        if (await trigger.count()) {
          await trigger.click().catch(() => {});
          await page.waitForTimeout(300);
        }
      }
      const row = await measure(page, `${vp.tag}/${entry.name}`, entry.selector);
      results.push(row);
    }
    catch (error) {
      results.push({ label: `${vp.tag}/${entry.name}`, status: 'BLOCKED', detail: String(error.message || error) });
    }
  }
  await context.close();
}
await browser.close();

console.log('XEDC search layout measurement');
console.log('base=' + base);
for (const row of results) {
  console.log(JSON.stringify(row));
}
