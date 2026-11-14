(function (Drupal, drupalSettings, once) {
  'use strict';

  function readProgress(key) {
    try {
      return JSON.parse(localStorage.getItem(key) || 'null');
    } catch (e) {
      return null;
    }
  }

  Drupal.behaviors.comicDetail = {
    attach(context) {
      once('comic-detail-root', '.comic-detail[data-comic-id]', context).forEach((detail) => {
        const meta = drupalSettings.xedcComicDetail || {};
        applyContinue(detail, meta);
        applyReadMarks(detail, meta);
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
    const locked = item?.querySelector('.comic-detail-chapter.is-locked');
    if (!item || locked) {
      return;
    }
    const url = '/comic/' + meta.comicId + '/' + saved.chapter_id;
    const label = num
      ? Drupal.t('继续阅读 第 @n 话', { '@n': num })
      : Drupal.t('继续阅读');
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
          const favLabel = data.favorite ? Drupal.t('已收藏') : Drupal.t('收藏');
          el.setAttribute('aria-label', favLabel);
          const label = el.querySelector('span');
          if (label) {
            label.textContent = favLabel;
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
      const lineHeight = () => parseFloat(getComputedStyle(text).lineHeight) || 23.8;
      const collapsedMax = () => lineHeight() * 3;
      const refresh = () => {
        text.style.maxHeight = 'none';
        const full = text.scrollHeight;
        if (wrap.classList.contains('is-collapsed')) {
          text.style.maxHeight = collapsedMax() + 'px';
        }
        else {
          text.style.maxHeight = full + 'px';
        }
        return full;
      };
      wrap.classList.add('is-collapsed');
      let fullHeight = refresh();
      const needs = (text.textContent || '').trim().length > 0 && fullHeight > collapsedMax() + 2;
      if (!needs) {
        wrap.classList.remove('is-collapsed');
        text.style.maxHeight = 'none';
        return;
      }
      toggle.hidden = false;
      toggle.addEventListener('click', () => {
        const collapsed = wrap.classList.toggle('is-collapsed');
        toggle.textContent = collapsed ? Drupal.t('展开') : Drupal.t('收起');
        fullHeight = refresh();
        if (!collapsed) {
          text.style.maxHeight = fullHeight + 'px';
        }
      });
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
    const sortLabel = sortBtn?.querySelector('[data-sort-label]');
    const rangeSelect = panel.querySelector('[data-chapter-range]');
    const moreBtn = panel.querySelector('[data-chapter-more]');
    const segmentSize = parseInt(list?.getAttribute('data-segment-size') || '50', 10);
    const initialVisible = parseInt(list?.getAttribute('data-initial-visible') || '20', 10);
    const total = parseInt(detail.getAttribute('data-chapter-total') || '0', 10);
    let reversed = false;
    let activeSegment = 0;
    let expanded = total <= initialVisible;

    if (rangeSelect && total > 30) {
      rangeSelect.innerHTML = '';
      const segments = Math.ceil(total / segmentSize);
      for (let i = 0; i < segments; i += 1) {
        const start = i * segmentSize + 1;
        const end = Math.min((i + 1) * segmentSize, total);
        const opt = document.createElement('option');
        opt.value = String(i);
        opt.textContent = start + '-' + end;
        rangeSelect.appendChild(opt);
      }
    }

    const updateMoreButton = () => {
      if (!moreBtn) {
        return;
      }
      if (expanded || total <= initialVisible) {
        moreBtn.hidden = true;
        return;
      }
      moreBtn.hidden = false;
      moreBtn.textContent = Drupal.t('查看全部');
    };

    const syncVisibility = () => {
      if (!list) {
        return;
      }
      const q = search?.value.trim().toLowerCase() || '';
      const items = list.querySelectorAll('[data-chapter-item]:not(.comic-detail-chapter-item--empty)');
      let visibleIndex = 0;
      items.forEach((item) => {
        const title = item.getAttribute('data-title') || '';
        const matchSearch = q === '' || title.includes(q);
        item.classList.remove('is-segment-hidden', 'is-limit-hidden', 'is-filtered-out');
        if (!matchSearch) {
          item.classList.add('is-filtered-out');
          return;
        }
        if (q) {
          return;
        }
        if (!expanded && total > initialVisible) {
          if (visibleIndex >= initialVisible) {
            item.classList.add('is-limit-hidden');
          }
          visibleIndex += 1;
          return;
        }
        if (total > 30 && expanded) {
          const idx = Array.from(items).indexOf(item);
          const segmentIndex = Math.floor(idx / segmentSize);
          if (segmentIndex !== activeSegment) {
            item.classList.add('is-segment-hidden');
          }
        }
      });
      updateMoreButton();
      if (rangeSelect) {
        rangeSelect.hidden = !(expanded && total > 30 && q === '');
      }
    };

    moreBtn?.addEventListener('click', () => {
      expanded = true;
      syncVisibility();
    });

    syncVisibility();
    search?.addEventListener('input', () => {
      if (search.value.trim()) {
        expanded = true;
      }
      syncVisibility();
    });
    rangeSelect?.addEventListener('change', () => {
      activeSegment = parseInt(rangeSelect.value, 10) || 0;
      syncVisibility();
    });

    sortBtn?.addEventListener('click', () => {
      if (!list) {
        return;
      }
      reversed = !reversed;
      if (sortLabel) {
        sortLabel.textContent = reversed ? Drupal.t('正序') : Drupal.t('倒序');
      }
      const items = Array.from(list.querySelectorAll('[data-chapter-item]:not(.comic-detail-chapter-item--empty)'));
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