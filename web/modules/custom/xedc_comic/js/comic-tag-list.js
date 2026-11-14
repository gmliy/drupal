(function (Drupal, once) {
  'use strict';

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

  Drupal.behaviors.comicTagList = {
    attach(context) {
      once('comic-tag-page', '.comic-tag-page[data-comic-tag-page]', context).forEach((root) => {
        const toolbar = root.querySelector('[data-comic-tag-toolbar]');
        const form = root.querySelector('[data-comic-list-form]');
        const sortInput = form?.querySelector('[data-comic-filter-sort]');
        const sortSelect = form?.querySelector('[data-comic-tag-sort]');

        const syncSticky = throttleRaf(() => {
          if (!toolbar) {
            return;
          }
          const top = toolbar.getBoundingClientRect().top;
          toolbar.classList.toggle('is-stuck', top <= 1);
        });
        window.addEventListener('scroll', syncSticky, { passive: true });
        syncSticky();

        sortSelect?.addEventListener('change', () => {
          if (sortInput && sortSelect.value) {
            sortInput.value = sortSelect.value;
            form?.requestSubmit();
          }
        });

        form?.addEventListener('submit', () => {
          root.classList.add('is-loading');
        });
      });
    },
  };
})(Drupal, once);