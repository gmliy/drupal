(function (Drupal, drupalSettings, once) {
  'use strict';

  const PREF_NIGHT = 'readerNight';
  const PREF_WIDTH = 'readerImgWidth';

  function readLocal(key) {
    try {
      return JSON.parse(localStorage.getItem(key) || 'null');
    } catch (e) {
      return null;
    }
  }

  function writeLocal(key, data) {
    try {
      localStorage.setItem(key, JSON.stringify(data));
    } catch (e) {}
  }

  function throttleRaf(fn) {
    let queued = false;
    return function throttled() {
      if (queued) {
        return;
      }
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        fn();
      });
    };
  }

  Drupal.behaviors.xedcComicReader = {
    attach(context) {
      const cfg = drupalSettings.xedcComic;
      if (!cfg) {
        return;
      }
      once('xedc-comic-reader-v2', '#reader-stage', context).forEach((stage) => {
        const root = document.getElementById('reader-root');
        const statusEl = document.getElementById('reader-status');
        const progressFill = document.getElementById('reader-progress-fill');
        const endBlock = document.getElementById('reader-end');
        const overlay = document.getElementById('reader-overlay');
        const sheetToc = document.getElementById('reader-sheet-toc');
        const sheetSettings = document.getElementById('reader-sheet-settings');
        let total = 0;
        let offset = 0;
        let loading = false;
        const limit = 8;
        let lastScrollY = window.scrollY;
        let chromeHidden = false;
        let tocReversed = false;

        const applyPrefs = () => {
          if (!root) {
            return;
          }
          const night = localStorage.getItem(PREF_NIGHT) === '1';
          root.classList.toggle('reader--night', night);
          const toggle = document.getElementById('reader-night-toggle');
          if (toggle) {
            toggle.setAttribute('aria-checked', night ? 'true' : 'false');
          }
          const width = localStorage.getItem(PREF_WIDTH) || '800';
          root.classList.remove('reader--width-fit', 'reader--width-full');
          if (width === 'fit') {
            root.classList.add('reader--width-fit');
          }
          else if (width === 'full') {
            root.classList.add('reader--width-full');
          }
          document.querySelectorAll('.reader-segments__btn').forEach((btn) => {
            btn.classList.toggle('is-active', btn.getAttribute('data-width') === width);
          });
        };

        const setChromeHidden = (hidden) => {
          chromeHidden = hidden;
          root?.classList.toggle('reader--chrome-hidden', hidden);
        };

        const toggleChrome = () => setChromeHidden(!chromeHidden);

        const updateScrollProgress = () => {
          const max = document.documentElement.scrollHeight - window.innerHeight;
          const pct = max > 0 ? Math.min(100, (window.scrollY / max) * 100) : 0;
          if (progressFill) {
            progressFill.style.width = pct + '%';
          }
          if (window.scrollY < 24) {
            setChromeHidden(false);
            lastScrollY = window.scrollY;
            return;
          }
          if (window.scrollY > lastScrollY + 8) {
            setChromeHidden(true);
          }
          else if (window.scrollY < lastScrollY - 8) {
            setChromeHidden(false);
          }
          lastScrollY = window.scrollY;
        };

        const saveProgress = () => {
          const payload = {
            comic_id: cfg.comicId,
            chapter_id: cfg.chapterId,
            scrollY: window.scrollY,
            progress: document.documentElement.scrollHeight > 0
              ? window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)
              : 0,
            updated_at: Date.now(),
          };
          writeLocal(cfg.progressKey, payload);
          if (!drupalSettings.user || drupalSettings.user.uid === 0) {
            return;
          }
          fetch(cfg.progressApi, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': cfg.csrfToken || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              ...payload,
              page: 1,
              mode: 'scroll',
            }),
          }).catch(() => {});
        };

        let saveTimer;
        const scheduleSave = () => {
          clearTimeout(saveTimer);
          saveTimer = setTimeout(saveProgress, (cfg.saveInterval || 3) * 1000);
        };

        const openSheet = (sheet) => {
          if (!sheet || !overlay) {
            return;
          }
          overlay.hidden = false;
          overlay.classList.add('is-open');
          sheet.classList.add('is-open');
          sheet.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
          if (sheet === sheetToc) {
            const current = sheet.querySelector('.reader-toc-item.is-current');
            current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
          }
        };

        const closeSheets = () => {
          [sheetToc, sheetSettings].forEach((sheet) => {
            sheet?.classList.remove('is-open');
            sheet?.setAttribute('aria-hidden', 'true');
          });
          overlay?.classList.remove('is-open');
          if (overlay) {
            overlay.hidden = true;
          }
          document.body.style.overflow = '';
        };

        const buildPage = (item) => {
          const wrap = document.createElement('div');
          wrap.className = 'reader-page';
          wrap.dataset.page = String(item.page);
          const w = item.width || 0;
          const h = item.height || 0;
          const skeleton = document.createElement('div');
          skeleton.className = 'reader-skeleton';
          if (w > 0 && h > 0) {
            skeleton.style.aspectRatio = w + ' / ' + h;
          }
          else {
            skeleton.style.minHeight = '60vh';
            skeleton.style.aspectRatio = '2 / 3';
          }
          const img = document.createElement('img');
          img.src = item.url;
          img.alt = Drupal.t('第 @p 页', { '@p': item.page });
          img.loading = 'lazy';
          img.decoding = 'async';
          img.style.visibility = 'hidden';
          const onLoad = () => {
            wrap.classList.add('is-loaded');
            img.style.visibility = 'visible';
          };
          const onError = () => {
            wrap.classList.add('is-error');
            const box = document.createElement('div');
            box.className = 'reader-page__retry';
            const msg = document.createElement('span');
            msg.textContent = Drupal.t('加载失败');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'reader-btn reader-btn--ghost';
            btn.textContent = Drupal.t('重新加载');
            btn.addEventListener('click', () => {
              box.remove();
              wrap.classList.remove('is-error');
              skeleton.style.display = '';
              img.style.visibility = 'hidden';
              img.src = item.url + (item.url.includes('?') ? '&' : '?') + '_r=' + Date.now();
            });
            box.append(msg, btn);
            wrap.appendChild(box);
          };
          img.addEventListener('load', onLoad);
          img.addEventListener('error', onError);
          wrap.append(skeleton, img);
          return wrap;
        };

        const tryRestoreScroll = () => {
          const local = readLocal(cfg.progressKey);
          if (!local || local.chapter_id !== cfg.chapterId) {
            return;
          }
          const y = parseInt(local.scrollY, 10);
          if (y > 0) {
            window.scrollTo(0, y);
          }
        };

        const fetchBatch = () => {
          if (loading || (total && offset >= total)) {
            return;
          }
          loading = true;
          if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = Drupal.t('图片加载中…');
          }
          fetch(cfg.apiPages + '?offset=' + offset + '&limit=' + limit, { credentials: 'same-origin' })
            .then((r) => {
              if (r.status === 403) {
                throw new Error('forbidden');
              }
              if (!r.ok) {
                throw new Error('http');
              }
              return r.json();
            })
            .then((data) => {
              total = data.total || 0;
              (data.items || []).forEach((item) => {
                stage.appendChild(buildPage(item));
              });
              offset += (data.items || []).length;
              if (offset >= total) {
                if (statusEl) {
                  statusEl.hidden = true;
                }
                if (endBlock) {
                  endBlock.hidden = total === 0;
                }
                let restoreTries = 0;
                const restoreLoop = () => {
                  tryRestoreScroll();
                  if (restoreTries++ < 6) {
                    setTimeout(restoreLoop, 280);
                  }
                };
                restoreLoop();
              }
              else {
                fetchBatch();
              }
            })
            .catch((err) => {
              if (!statusEl) {
                return;
              }
              statusEl.hidden = false;
              statusEl.textContent = err.message === 'forbidden'
                ? Drupal.t('无权阅读本章，请登录或开通会员。')
                : Drupal.t('图片加载失败，请重试。');
              const retry = document.createElement('button');
              retry.type = 'button';
              retry.className = 'reader-btn';
              retry.textContent = Drupal.t('重试');
              retry.addEventListener('click', () => {
                statusEl.textContent = '';
                retry.remove();
                fetchBatch();
              });
              statusEl.appendChild(retry);
            })
            .finally(() => {
              loading = false;
            });
        };

        applyPrefs();

        window.addEventListener('scroll', throttleRaf(() => {
          updateScrollProgress();
          scheduleSave();
        }), { passive: true });

        const canvas = document.getElementById('reader-canvas');
        canvas?.addEventListener('click', (e) => {
          if (e.target.closest('a, button, .reader-page__retry')) {
            return;
          }
          const rect = canvas.getBoundingClientRect();
          const x = e.clientX - rect.left;
          const y = e.clientY - rect.top;
          const midX = x > rect.width * 0.28 && x < rect.width * 0.72;
          const midY = y > rect.height * 0.15 && y < rect.height * 0.85;
          if (midX && midY) {
            toggleChrome();
          }
        });

        root?.addEventListener('click', (e) => {
          const action = e.target.closest('[data-reader-action]');
          if (action) {
            const type = action.getAttribute('data-reader-action');
            if (type === 'toc') {
              openSheet(sheetToc);
            }
            if (type === 'settings') {
              openSheet(sheetSettings);
            }
          }
        });

        document.getElementById('reader-open-settings')?.addEventListener('click', () => openSheet(sheetSettings));
        document.getElementById('reader-open-toc')?.addEventListener('click', () => openSheet(sheetToc));
        overlay?.addEventListener('click', closeSheets);
        root?.querySelectorAll('[data-reader-close]').forEach((btn) => btn.addEventListener('click', closeSheets));

        document.getElementById('reader-night-toggle')?.addEventListener('click', (e) => {
          const btn = e.currentTarget;
          const next = btn.getAttribute('aria-checked') !== 'true';
          btn.setAttribute('aria-checked', next ? 'true' : 'false');
          localStorage.setItem(PREF_NIGHT, next ? '1' : '0');
          applyPrefs();
        });

        document.getElementById('reader-width-segments')?.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-width]');
          if (!btn) {
            return;
          }
          localStorage.setItem(PREF_WIDTH, btn.getAttribute('data-width'));
          applyPrefs();
        });

        const tocList = document.getElementById('reader-toc-list');
        document.getElementById('reader-toc-sort')?.addEventListener('click', (e) => {
          const btn = e.currentTarget;
          tocReversed = !tocReversed;
          btn.textContent = tocReversed ? Drupal.t('倒序') : Drupal.t('正序');
          btn.setAttribute('data-order', tocReversed ? 'desc' : 'asc');
          if (!tocList) {
            return;
          }
          const items = Array.from(tocList.querySelectorAll('.reader-toc-item'));
          items.sort((a, b) => {
            const an = parseFloat(a.getAttribute('data-number') || '0');
            const bn = parseFloat(b.getAttribute('data-number') || '0');
            return tocReversed ? bn - an : an - bn;
          });
          items.forEach((el) => tocList.appendChild(el));
        });

        document.addEventListener('keydown', (e) => {
          if (e.target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
          }
          if (e.key === 'ArrowLeft' && cfg.prevUrl) {
            window.location.href = cfg.prevUrl;
          }
          if (e.key === 'ArrowRight' && cfg.nextUrl) {
            window.location.href = cfg.nextUrl;
          }
          if (e.key === ' ' || e.code === 'Space') {
            e.preventDefault();
            window.scrollBy({ top: window.innerHeight * 0.85, behavior: 'smooth' });
          }
        });

        fetchBatch();
        updateScrollProgress();
      });
    },
  };
})(Drupal, drupalSettings, once);