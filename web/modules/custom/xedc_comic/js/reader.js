(function (Drupal, drupalSettings, once) {
  'use strict';

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

  Drupal.behaviors.xedcComicReader = {
    attach(context) {
      const cfg = drupalSettings.xedcComic;
      if (!cfg) {
        return;
      }
      once('xedc-comic-reader', '#xedc-comic-stage', context).forEach((stage) => {
        const toolbar = document.getElementById('xedc-comic-toolbar');
        const pager = document.getElementById('xedc-comic-pager');
        let mode = localStorage.getItem(cfg.modeKey) || 'scroll';
        let pages = [];
        let total = 0;
        let current = 1;
        let loading = false;
        let offset = 0;
        const limit = 8;

        const applyMode = (next) => {
          mode = next;
          localStorage.setItem(cfg.modeKey, mode);
          stage.classList.remove('mode-scroll', 'mode-single', 'mode-spread');
          stage.classList.add('mode-' + mode);
          toolbar.querySelectorAll('[data-mode]').forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
          });
          renderActive();
        };

        const renderActive = () => {
          stage.querySelectorAll('img').forEach((img) => {
            const page = parseInt(img.dataset.page || '0', 10);
            img.classList.toggle('page-active', page === current || mode === 'scroll');
          });
          if (pager) {
            pager.textContent = current + ' / ' + total;
          }
        };

        const saveProgress = () => {
          const payload = {
            comic_id: cfg.comicId,
            chapter_id: cfg.chapterId,
            page: current,
            progress: total ? current / total : 0,
            mode: mode,
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
            body: JSON.stringify(payload),
          }).catch(() => {});
        };

        let saveTimer;
        const scheduleSave = () => {
          clearTimeout(saveTimer);
          saveTimer = setTimeout(saveProgress, (cfg.saveInterval || 3) * 1000);
        };

        const appendImages = (items) => {
          items.forEach((item) => {
            const img = document.createElement('img');
            img.src = item.url;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.dataset.page = String(item.page);
            img.alt = '第 ' + item.page + ' 页';
            img.addEventListener('error', () => {
              img.classList.add('is-error');
            });
            img.addEventListener('click', () => {
              if (mode !== 'scroll') {
                return;
              }
              current = item.page;
              scheduleSave();
            });
            stage.appendChild(img);
          });
        };

        let statusEl = stage.parentElement.querySelector('.xedc-comic-reader__status');
        if (!statusEl) {
          statusEl = document.createElement('p');
          statusEl.className = 'xedc-comic-reader__status';
          stage.parentElement.insertBefore(statusEl, stage);
        }

        const fetchBatch = () => {
          if (loading || (total && offset >= total)) {
            return;
          }
          loading = true;
          statusEl.textContent = Drupal.t('图片加载中…');
          const url = cfg.apiPages + '?offset=' + offset + '&limit=' + limit;
          fetch(url, { credentials: 'same-origin' })
            .then((r) => {
              if (r.status === 403) {
                throw new Error('forbidden');
              }
              return r.json();
            })
            .then((data) => {
              total = data.total || 0;
              appendImages(data.items || []);
              offset += (data.items || []).length;
              renderActive();
              if (offset >= total && total === 0) {
                statusEl.textContent = Drupal.t('本章暂无图片。');
              }
              else if (offset < total) {
                fetchBatch();
              }
              else {
                statusEl.textContent = '';
              }
            })
            .catch(() => {
              statusEl.textContent = Drupal.t('加载失败');
              const retry = document.createElement('button');
              retry.type = 'button';
              retry.className = 'btn btn-sm btn-light';
              retry.textContent = Drupal.t('重试');
              retry.addEventListener('click', () => {
                retry.remove();
                fetchBatch();
              });
              statusEl.appendChild(document.createTextNode(' '));
              statusEl.appendChild(retry);
            })
            .finally(() => {
              loading = false;
            });
        };

        toolbar.querySelectorAll('[data-mode]').forEach((btn) => {
          btn.addEventListener('click', () => applyMode(btn.getAttribute('data-mode')));
        });

        document.addEventListener('keydown', (e) => {
          if (mode === 'scroll') {
            return;
          }
          if (e.key === 'ArrowRight') {
            current = Math.min(total, current + (mode === 'spread' && window.innerWidth > 767 ? 2 : 1));
            renderActive();
            scheduleSave();
          }
          if (e.key === 'ArrowLeft') {
            current = Math.max(1, current - (mode === 'spread' && window.innerWidth > 767 ? 2 : 1));
            renderActive();
            scheduleSave();
          }
        });

        if (window.IntersectionObserver && mode === 'scroll') {
          const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting && entry.target.dataset.page) {
                current = parseInt(entry.target.dataset.page, 10);
                scheduleSave();
              }
            });
          }, { rootMargin: '0px 0px -45% 0px', threshold: 0.01 });
          const watch = new MutationObserver(() => {
            stage.querySelectorAll('img').forEach((img) => io.observe(img));
          });
          watch.observe(stage, { childList: true });
        }

        const urlPage = parseInt(new URLSearchParams(window.location.search).get('page') || '0', 10);
        const local = readLocal(cfg.progressKey);
        if (urlPage > 0) {
          current = urlPage;
        }
        else if (local && local.chapter_id === cfg.chapterId && local.page) {
          current = local.page;
        }

        const fsBtn = document.getElementById('xedc-comic-fullscreen');
        if (fsBtn) {
          fsBtn.addEventListener('click', () => {
            const root = document.querySelector('.xedc-comic-reader');
            if (!root) {
              return;
            }
            if (!document.fullscreenElement) {
              root.requestFullscreen?.().catch(() => {});
            }
            else {
              document.exitFullscreen?.();
            }
          });
        }
        applyMode(mode);
        fetchBatch();
      });
    },
  };
})(Drupal, drupalSettings, once);