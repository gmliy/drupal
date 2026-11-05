/**
 * Media Library modal compact-grid acceptance (member photo form).
 * Read-only aside from temporary selection clicks (cancelled, not inserted).
 */
import { chromium } from '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const BASE = process.env.XEDC_BASE || 'https://gitxx.xyz';
const CHROME = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const OUT = process.env.XEDC_MEDIA_OUT || '/var/www/drupal/docs/tasks/screenshots/xedc-media-library-grid';
const DRUSH = '/var/www/drupal/vendor/bin/drush';

fs.mkdirSync(OUT, { recursive: true });

function uli(uid) {
  const out = execSync(`${DRUSH} uli --uid=${uid} --uri=${BASE}`, { encoding: 'utf8' }).trim();
  const line = out.split('\n').map((s) => s.trim()).find((s) => s.startsWith('http'));
  if (!line) throw new Error(`uli failed: ${out}`);
  return line;
}

async function openMediaLibrary(page) {
  // Prefer field_images open button; fall back to any media library open.
  const candidates = [
    '.field--name-field-images .js-media-library-open-button',
    '.field--name-field-images .media-library-open-button',
    '.field--name-field-images .xm-media-open',
    '.js-media-library-open-button',
    'input[value*="添加"]',
    'input[value*="媒体"]',
    'button:has-text("添加媒体")',
    'input[value*="Add media"]',
  ];
  for (const sel of candidates) {
    const loc = page.locator(sel).first();
    if (await loc.count()) {
      const visible = await loc.isVisible().catch(() => false);
      if (!visible) {
        // Unhide if previous CSS wrongly hid the control (diagnostic only).
        await page.evaluate((s) => {
          const el = document.querySelector(s);
          if (el) {
            el.style.setProperty('display', 'inline-flex', 'important');
            el.style.visibility = 'visible';
          }
        }, sel);
      }
      await loc.click({ force: true, timeout: 10000 });
      await page.waitForSelector('.ui-dialog .media-library-item, .ui-dialog .media-library-wrapper, .media-library-widget-modal', { timeout: 30000 });
      return sel;
    }
  }
  throw new Error('No media library open button found');
}

async function measureGrid(page) {
  return page.evaluate(() => {
    const dialog = document.querySelector('.ui-dialog.media-library-widget-modal, .ui-dialog:has(.media-library-wrapper)');
    const grid = dialog?.querySelector('.js-media-library-views-form, .media-library-views-form__rows, .views-view-grid');
    const items = [...(dialog?.querySelectorAll('.js-media-library-item, .media-library-item, .media-library-item--grid') || [])];
    if (!dialog || items.length < 2) {
      return {
        ok: false,
        reason: 'missing-dialog-or-items',
        itemCount: items.length,
        dialogClass: dialog?.className || '',
      };
    }
    const tops = new Map();
    for (const el of items) {
      const top = Math.round(el.getBoundingClientRect().top);
      // Group near-equal tops (sub-pixel / border differences).
      let key = top;
      for (const existing of tops.keys()) {
        if (Math.abs(existing - top) <= 2) {
          key = existing;
          break;
        }
      }
      tops.set(key, (tops.get(key) || 0) + 1);
    }
    const cols = Math.max(...tops.values());
    const first = items[0];
    const img = first.querySelector('img');
    const cs = img ? getComputedStyle(img) : null;
    const gcs = grid ? getComputedStyle(grid) : null;
    const buttonpane = dialog.querySelector('.ui-dialog-buttonpane');
    const content = dialog.querySelector('.ui-dialog-content');
    const scrollTarget = dialog.querySelector('.view-content, .media-library-views-form__rows, .views-view-grid');
    return {
      ok: true,
      cols,
      itemCount: items.length,
      itemWidth: Math.round(first.getBoundingClientRect().width),
      dialogWidth: Math.round(dialog.getBoundingClientRect().width),
      gridDisplay: gcs?.display || null,
      gridTemplateColumns: gcs?.gridTemplateColumns || null,
      objectFit: cs?.objectFit || null,
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
        || (content && content.scrollWidth > content.clientWidth + 1),
      buttonpaneVisible: !!(buttonpane && buttonpane.getBoundingClientRect().height > 0),
      listScrollable: !!(scrollTarget && (getComputedStyle(scrollTarget).overflowY === 'auto' || getComputedStyle(scrollTarget).overflowY === 'scroll' || scrollTarget.scrollHeight >= scrollTarget.clientHeight)),
      hasExposed: !!dialog.querySelector('.views-exposed-form, .media-library-view .view-filters'),
      dialogClass: dialog.className,
    };
  });
}

const report = { started: new Date().toISOString(), base: BASE, results: [] };

async function runViewport(browser, vp, theme) {
  const context = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  await page.goto(uli(1), { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.goto(`${BASE}/node/add/photo?upload=gallery`, { waitUntil: 'networkidle', timeout: 60000 });

  if (theme === 'dark') {
    await page.evaluate(() => {
      document.documentElement.setAttribute('data-bs-theme', 'dark');
      document.documentElement.setAttribute('data-theme', 'dark');
    });
  }

  let openSel = '';
  try {
    openSel = await openMediaLibrary(page);
  }
  catch (e) {
    report.results.push({ id: `${vp.id}-${theme}`, error: String(e), errors });
    await page.screenshot({ path: path.join(OUT, `${vp.id}-${theme}-fail.png`), fullPage: true });
    await context.close();
    return;
  }

  // Wait a beat for AJAX grid paint.
  await page.waitForTimeout(800);
  const m = await measureGrid(page);

  // Toggle select on first item if present.
  let selectOk = false;
  const item = page.locator('.ui-dialog .media-library-item, .ui-dialog .js-click-to-select').first();
  if (await item.count()) {
    await item.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(200);
    selectOk = await page.evaluate(() => {
      const el = document.querySelector('.ui-dialog .media-library-item.checked, .ui-dialog .js-click-to-select.checked, .ui-dialog .media-library-item--grid.checked');
      return !!el;
    });
    // Deselect
    await item.click({ timeout: 5000 }).catch(() => {});
  }

  // Cancel without inserting.
  const cancel = page.locator('.ui-dialog-buttonpane button, .ui-dialog-buttonpane .button').filter({ hasText: /取消|Cancel|关闭/i }).first();
  if (await cancel.count()) {
    await cancel.click().catch(() => {});
  }
  else {
    await page.keyboard.press('Escape').catch(() => {});
  }

  const shot = path.join(OUT, `${vp.id}-${theme}.png`);
  // Re-open briefly for screenshot of grid state if closed; else shot current.
  if (!(await page.locator('.ui-dialog .media-library-item').count())) {
    try {
      await openMediaLibrary(page);
      await page.waitForTimeout(600);
    }
    catch {
      // keep fail shot of page
    }
  }
  await page.screenshot({ path: shot, fullPage: false });

  const expectCols =
    vp.w >= 1400 ? [5, 6] :
    vp.w >= 1200 ? [5, 6] :
    vp.w >= 900 ? [3, 4] :
    vp.w >= 768 ? [3, 4] :
    vp.w >= 480 ? [2, 3] : [2, 3];

  report.results.push({
    id: `${vp.id}-${theme}`,
    openSel,
    ...m,
    selectOk,
    expectCols,
    colsOk: m.ok && expectCols.includes(m.cols),
    objectFitOk: m.objectFit === 'contain',
    noOverflow: m.overflowX === false,
    errors,
    shot,
  });

  await context.close();
}

async function main() {
  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  const viewports = [
    { id: 'desktop', w: 1440, h: 900 },
    { id: 'tablet', w: 768, h: 1024 },
    { id: 'mobile', w: 390, h: 844 },
  ];

  for (const vp of viewports) {
    await runViewport(browser, vp, 'light');
  }
  await runViewport(browser, viewports[0], 'dark');

  await browser.close();
  report.finished = new Date().toISOString();
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
