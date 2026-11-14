(function (Drupal, drupalSettings, once) {
  'use strict';

  function readProgress(key) {
    try {
      return JSON.parse(localStorage.getItem(key) || 'null');
    } catch (e) {
      return null;
    }
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

  Drupal.behaviors.comicDetail = {
    attach(context) {
      once('comic-detail-root', '.comic-detail[data-comic-id]', context).forEach((detail) => {
        const meta = drupalSettings.xedcComicDetail || {};
        applyContinue(detail, meta);
        applyReadMarks(detail, meta);
        initTabs(detail);
        initDock(detail);
        initFavorite(detail, meta);
        initDesc(detail);
        initChapters(detail);
      });
    },
  };

  function applyContinue(detail, meta) {
    if (!meta.progressKey || !meta.comicId) {
      return;
    }
    const saved = readProgress(meta.progressKey);
    if (!saved?.chapter_id) {
      return;
    }
    const item = detail.querySelector('[data-chapter-id="' + saved.chapter_id + '"]');
    const num = item?.getAttribute('data-chapter-number') || '';
    const title = item?.querySelector('[data-chapter-title]')?.textContent?.trim() || '';
    const locked = item?.querySelector('.comic-detail-chapter.is-locked');
    if (!item || locked) {
      return;
    }
    const url = '/comic/' + meta.comicId + '/' + saved.chapter_id;
    const label = num
      ? Drupal.t('继续阅读 · 第 @n 话', { '@n': num })
      : (title ? Drupal.t('继续阅读 · @title', { '@title': title }) : Drupal.t('继续阅读'));
    detail.querySelectorAll('[data-read-cta]').forEach((el) => {
      el.setAttribute('href', url);
      el.textContent = label;
    });
  }

  function applyReadMarks(detail, meta) {
    if (!meta.progressKey) {
      return;
    }
    const saved = readProgress(meta.progressKey);
    if (!saved?.chapter_id) {
      return;
    }
    const lastId = String(saved.chapter_id);
    const lastItem = detail.querySelector('[data-chapter-id="' + lastId + '"]');
    const lastNum = parseFloat(lastItem?.getAttribute('data-chapter-number') || '0');
    detail.querySelectorAll('[data-chapter-item]').forEach((item) => {
      const id = String(item.getAttribute('data-chapter-id'));
      const num = parseFloat(item.getAttribute('data-chapter-number') || '0');
      if (id === lastId) {
        item.classList.add('is-last-read');
        const badge = item.querySelector('[data-last-read]');
        if (badge) {
          badge.hidden = false;
        }
      }
      else if (lastNum > 0 && num > 0 && num < lastNum) {
        item.classList.add('is-read');
      }
    });
  }

  function syncTabIndicator(tabsRoot) {
    const bar = tabsRoot.querySelector('.comic-detail-tabs__bar');
    const indicator = tabsRoot.querySelector('[data-tab-indicator]');
    const active = tabsRoot.querySelector('.comic-detail-tabs__tab.is-active');
    if (!bar || !indicator || !active) {
      return;
    }
    const barRect = bar.getBoundingClientRect();
    const tabRect = active.getBoundingClientRect();
    indicator.style.width = tabRect.width + 'px';
    indicator.style.transform = 'translateX(' + (tabRect.left - barRect.left) + 'px)';
  }

  function initTabs(detail) {
    const tabsRoot = detail.querySelector('[data-comic-tabs]');
    if (!tabsRoot) {
      return;
    }
    const syncIndicator = throttleRaf(() => syncTabIndicator(tabsRoot));
    syncIndicator();
    window.addEventListener('resize', syncIndicator, { passive: true });

    tabsRoot.addEventListener('click', (e) => {
      const tab = e.target.closest('.comic-detail-tabs__tab');
      if (!tab) {
        return;
      }
      const key = tab.getAttribute('data-tab');
      tabsRoot.querySelectorAll('.comic-detail-tabs__tab').forEach((el) => {
        const active = el === tab;
        el.classList.toggle('is-active', active);
        el.setAttribute('aria-selected', active ? 'true' : 'false');
        el.tabIndex = active ? 0 : -1;
      });
      tabsRoot.querySelectorAll('[data-panel]').forEach((panel) => {
        const show = panel.getAttribute('data-panel') === key;
        panel.classList.toggle('is-active', show);
        if (show) {
          panel.removeAttribute('hidden');
        }
        else {
          panel.setAttribute('hidden', '');
        }
      });
      syncIndicator();
    });
  }

  function initDock(detail) {
    const dock = detail.querySelector('#comic-detail-dock');
    const inline = detail.querySelector('.comic-detail-actions--inline');
    if (!dock || !inline || !window.IntersectionObserver) {
      return;
    }
    if (window.matchMedia('(min-width: 768px)').matches) {
      return;
    }
    const syncDock = (visible) => {
      dock.hidden = !!visible;
    };
    const io = new IntersectionObserver((entries) => {
      syncDock(entries[0]?.isIntersecting);
    }, { threshold: 0, rootMargin: '0px 0px -20px 0px' });
    io.observe(inline);
    requestAnimationFrame(() => {
      const rect = inline.getBoundingClientRect();
      const inView = rect.bottom > 0 && rect.top < window.innerHeight;
      syncDock(inView);
    });
  }

  function initFavorite(detail, meta) {
    detail.addEventListener('click', (e) => {
      const btn = e.target.closest('.comic-detail-favorite');
      if (!btn) {
        return;
      }
      const api = btn.getAttribute('data-api');
      if (!api) {
        return;
      }
      const token = meta.csrfFavorite || '';
      fetch(api, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': token,
        },
      }).then((r) => {
        if (r.status === 403) {
          window.location.href = '/user/login';
          return null;
        }
        return r.json();
      }).then((data) => {
        if (!data) {
          return;
        }
        detail.querySelectorAll('.comic-detail-favorite').forEach((el) => {
          el.classList.toggle('is-on', !!data.favorite);
          el.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
          const label = el.querySelector('span');
          if (label) {
            label.textContent = data.favorite ? Drupal.t('已收藏') : Drupal.t('收藏');
          }
        });
      });
    });
  }

  function initDesc(detail) {
    once('comic-detail-desc', '[data-comic-desc]', detail).forEach((wrap) => {
      const text = wrap.querySelector('.comic-detail-desc__text');
      const toggle = wrap.querySelector('[data-desc-toggle]');
      if (!text || !toggle) {
        return;
      }
      const collapsedMax = () => {
        const lineHeight = parseFloat(getComputedStyle(text).lineHeight) || 22;
        return lineHeight * 3;
      };
      let fullHeight = text.scrollHeight;
      const refreshHeights = () => {
        text.style.maxHeight = 'none';
        fullHeight = text.scrollHeight;
        if (wrap.classList.contains('is-collapsed')) {
          text.style.maxHeight = collapsedMax() + 'px';
        }
        else {
          text.style.maxHeight = fullHeight + 'px';
        }
      };
      wrap.classList.add('is-collapsed');
      const needsToggle = (text.textContent || '').trim().length > 80 || fullHeight > collapsedMax() + 4;
      refreshHeights();
      if (!needsToggle) {
        wrap.classList.remove('is-collapsed');
        text.style.maxHeight = 'none';
        return;
      }
      toggle.hidden = false;
      toggle.addEventListener('click', () => {
        const collapsed = wrap.classList.toggle('is-collapsed');
        toggle.textContent = collapsed ? Drupal.t('展开') : Drupal.t('收起');
        text.style.maxHeight = collapsed ? collapsedMax() + 'px' : fullHeight + 'px';
      });
      window.addEventListener('resize', throttleRaf(refreshHeights), { passive: true });
    });
  }

  function initChapters(detail) {
    const panel = detail.querySelector('[data-comic-chapters]');
    if (!panel) {
      return;
    }
    const list = panel.querySelector('[data-chapter-list]');
    const search = panel.querySelector('[data-chapter-search]');
    const sortBtn = panel.querySelector('[data-chapter-sort]');
    const rangeSelect = panel.querySelector('[data-chapter-range]');
    const segmentSize = parseInt(list?.getAttribute('data-segment-size') || '50', 10);
    const total = parseInt(detail.getAttribute('data-chapter-total') || '0', 10);
    let reversed = false;
    let activeSegment = 0;

    const buildRangeOptions = () => {
      if (!rangeSelect || total <= 20) {
        return;
      }
      rangeSelect.innerHTML = '';
      const segments = Math.ceil(total / segmentSize);
      for (let i = 0; i < segments; i += 1) {
        const start = i * segmentSize + 1;
        const end = Math.min((i + 1) * segmentSize, total);
        const opt = document.createElement('option');
        opt.value = String(i);
        opt.textContent = start + '–' + end;
        rangeSelect.appendChild(opt);
      }
    };

    buildRangeOptions();

    const syncVisibility = () => {
      if (!list) {
        return;
      }
      const q = search?.value.trim().toLowerCase() || '';
      const items = list.querySelectorAll('[data-chapter-item]');
      items.forEach((item, index) => {
        const title = item.getAttribute('data-title') || '';
        const matchSearch = q === '' || title.includes(q);
        if (!matchSearch) {
          item.classList.add('is-filtered-out');
          item.classList.remove('is-segment-hidden');
          return;
        }
        item.classList.remove('is-filtered-out');
        if (q || total <= 20) {
          item.classList.remove('is-segment-hidden');
          return;
        }
        const segmentIndex = Math.floor(index / segmentSize);
        item.classList.toggle('is-segment-hidden', segmentIndex !== activeSegment);
      });
    };

    syncVisibility();

    search?.addEventListener('input', syncVisibility);

    rangeSelect?.addEventListener('change', () => {
      activeSegment = parseInt(rangeSelect.value, 10) || 0;
      syncVisibility();
    });

    sortBtn?.addEventListener('click', () => {
      if (!list) {
        return;
      }
      reversed = !reversed;
      sortBtn.textContent = reversed ? Drupal.t('正序') : Drupal.t('倒序');
      const items = Array.from(list.querySelectorAll('[data-chapter-item]'));
      items.sort((a, b) => {
        const an = parseFloat(a.querySelector('.comic-detail-chapter-list__num')?.textContent || '0');
        const bn = parseFloat(b.querySelector('.comic-detail-chapter-list__num')?.textContent || '0');
        return reversed ? bn - an : an - bn;
      });
      items.forEach((el) => list.appendChild(el));
      syncVisibility();
    });
  }
})(Drupal, drupalSettings, once);