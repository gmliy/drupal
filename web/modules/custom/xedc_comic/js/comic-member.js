(function (Drupal, drupalSettings, once) {
  'use strict';

  function csrf() {
    return drupalSettings.xedcComicMember?.csrfToken || '';
  }

  Drupal.behaviors.xedcComicMemberFavorites = {
    attach(context) {
      once('xedc-comic-unfavorite', '.xedc-comic-member-unfavorite', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const api = btn.getAttribute('data-api');
          if (!api) {
            return;
          }
          fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'X-CSRF-Token': csrf(),
              'X-Requested-With': 'XMLHttpRequest',
            },
          }).then((r) => r.json()).then(() => {
            const item = btn.closest('.xedc-comic-member__item');
            if (item) {
              item.remove();
            }
          });
        });
      });
    },
  };

  Drupal.behaviors.xedcComicMemberHistory = {
    attach(context) {
      once('xedc-comic-history-del', '.xedc-comic-member-history-delete', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-comic-id');
          if (!id) {
            return;
          }
          fetch('/api/comic/reading-history/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
              'X-CSRF-Token': csrf(),
            },
          }).then((r) => {
            if (r.ok) {
              btn.closest('.xedc-comic-member-history__row')?.remove();
            }
          });
        });
      });
    },
  };
})(Drupal, drupalSettings, once);