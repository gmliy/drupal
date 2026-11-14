(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.xedcComicFavorite = {
    attach(context) {
      once('xedc-comic-favorite', '.xedc-comic-favorite', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const api = btn.getAttribute('data-api');
          if (!api) {
            return;
          }
          const token = drupalSettings.xedcComicDetail?.csrfFavorite || '';
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
            btn.classList.toggle('is-on', !!data.favorite);
            btn.textContent = data.favorite ? Drupal.t('已收藏') : Drupal.t('收藏');
          });
        });
      });
    },
  };

  Drupal.behaviors.xedcComicShare = {
    attach(context) {
      once('xedc-comic-share', '.xedc-comic-share', context).forEach((btn) => {
        btn.addEventListener('click', async () => {
          const url = btn.getAttribute('data-share-url') || window.location.href;
          const title = document.querySelector('.xedc-comic-detail__title')?.textContent || document.title;
          try {
            if (navigator.share) {
              await navigator.share({ title, url });
              return;
            }
          }
          catch (e) {
            // fall through
          }
          try {
            await navigator.clipboard.writeText(url);
            btn.textContent = Drupal.t('已复制链接');
          }
          catch (e) {
            window.prompt(Drupal.t('复制链接'), url);
          }
        });
      });
    },
  };

  Drupal.behaviors.xedcComicDesc = {
    attach(context) {
      once('xedc-comic-desc', '[data-comic-desc]', context).forEach((wrap) => {
        const text = wrap.querySelector('.xedc-comic-detail__desc-text');
        const toggle = wrap.querySelector('[data-desc-toggle]');
        if (!text || !toggle) {
          return;
        }
        if ((text.textContent || '').length > 240) {
          wrap.classList.add('is-collapsed');
          toggle.hidden = false;
          toggle.addEventListener('click', () => {
            const collapsed = wrap.classList.toggle('is-collapsed');
            toggle.textContent = collapsed ? Drupal.t('展开') : Drupal.t('收起');
          });
        }
      });
    },
  };

  Drupal.behaviors.xedcComicChapters = {
    attach(context) {
      once('xedc-comic-chapters', '[data-comic-chapters]', context).forEach((root) => {
        const list = root.querySelector('[data-chapter-list]');
        const search = root.querySelector('[data-chapter-search]');
        const sortBtn = root.querySelector('[data-chapter-sort]');
        if (!list) {
          return;
        }
        let reversed = false;
        if (search) {
          search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            list.querySelectorAll('[data-chapter-item]').forEach((item) => {
              const title = item.getAttribute('data-title') || '';
              item.hidden = q !== '' && !title.includes(q);
            });
          });
        }
        if (sortBtn) {
          sortBtn.addEventListener('click', () => {
            reversed = !reversed;
            sortBtn.textContent = reversed ? Drupal.t('正序') : Drupal.t('倒序');
            const items = Array.from(list.querySelectorAll('[data-chapter-item]'));
            items.sort((a, b) => {
              const an = parseFloat(a.querySelector('.xedc-comic-chapter-list__num')?.textContent || '0');
              const bn = parseFloat(b.querySelector('.xedc-comic-chapter-list__num')?.textContent || '0');
              return reversed ? bn - an : an - bn;
            });
            items.forEach((el) => list.appendChild(el));
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);