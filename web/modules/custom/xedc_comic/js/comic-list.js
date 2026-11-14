(function (Drupal, once) {
  'use strict';

  const VIEW_KEY = 'xedcComicListView';
  const MAIN_SORT = ['update', 'views', 'favorites'];
  const MAIN_STATUS = ['', 'ongoing', 'completed'];

  function applyView(root, mode) {
    const grid = root.querySelector('[data-comic-list-grid]');
    if (!grid) {
      return;
    }
    const isList = mode === 'list';
    grid.classList.toggle('comic-list-grid--list', isList);
    root.dataset.comicListView = mode;
    root.querySelectorAll('[data-comic-card]').forEach((card) => {
      if (card.classList.contains('comic-list-card--spotlight')) {
        return;
      }
      card.classList.toggle('comic-list-card--list', isList);
      card.classList.toggle('comic-list-card--grid', !isList);
    });
    root.querySelectorAll('[data-view-mode]').forEach((btn) => {
      const on = btn.getAttribute('data-view-mode') === mode;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function syncSegmentUi(root, field, value) {
    const group = root.querySelector(`[data-comic-segment="${field}"]`);
    if (!group) {
      return;
    }
    const allowed = field === 'sort' ? MAIN_SORT : MAIN_STATUS;
    group.querySelectorAll('[data-segment-value]').forEach((btn) => {
      const match = allowed.includes(value) && btn.getAttribute('data-segment-value') === value;
      btn.classList.toggle('is-active', match);
    });
  }

  Drupal.behaviors.comicList = {
    attach(context) {
      once('comic-list-root', '.comic-list[data-comic-list]', context).forEach((root) => {
        const form = root.querySelector('[data-comic-list-form]');
        const sortInput = form?.querySelector('[data-comic-filter-sort]');
        const statusInput = form?.querySelector('[data-comic-filter-status]');
        const sortExtra = form?.querySelector('[data-comic-list-sort-extra]');
        const statusExtra = form?.querySelector('[data-comic-list-status-extra]');
        const sticky = root.querySelector('[data-comic-list-sticky]');
        const searchWrap = root.querySelector('[data-comic-list-search]');
        const searchToggle = root.querySelector('[data-search-toggle]');
        const searchInput = searchWrap?.querySelector('input[type="search"]');

        const storedView = localStorage.getItem(VIEW_KEY) || 'grid';
        applyView(root, storedView === 'list' ? 'list' : 'grid');

        if (sortExtra && sortInput) {
          sortExtra.value = sortInput.value;
        }
        if (statusExtra && statusInput) {
          statusExtra.value = statusInput.value;
        }
        syncSegmentUi(root, 'sort', sortInput?.value || 'update');
        syncSegmentUi(root, 'status', statusInput?.value || '');

        const submitForm = () => {
          if (form) {
            form.requestSubmit();
          }
        };

        root.querySelectorAll('[data-comic-segment]').forEach((group) => {
          group.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-segment-value]');
            if (!btn || !form) {
              return;
            }
            const field = group.getAttribute('data-comic-segment');
            const value = btn.getAttribute('data-segment-value') ?? '';
            if (field === 'sort' && sortInput) {
              sortInput.value = value;
              if (sortExtra) {
                sortExtra.value = value;
              }
            }
            if (field === 'status' && statusInput) {
              statusInput.value = value;
              if (statusExtra) {
                statusExtra.value = value;
              }
            }
            submitForm();
          });
        });

        sortExtra?.addEventListener('change', () => {
          if (!sortInput) {
            return;
          }
          sortInput.value = sortExtra.value;
          syncSegmentUi(root, 'sort', sortInput.value);
          submitForm();
        });

        statusExtra?.addEventListener('change', () => {
          if (!statusInput) {
            return;
          }
          statusInput.value = statusExtra.value;
          syncSegmentUi(root, 'status', statusInput.value);
          submitForm();
        });

        form?.querySelector('select[name="access"]')?.addEventListener('change', submitForm);

        searchInput?.addEventListener('change', submitForm);
        searchInput?.addEventListener('search', () => {
          if (searchInput.value === '') {
            submitForm();
          }
        });

        root.querySelectorAll('[data-view-mode]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const mode = btn.getAttribute('data-view-mode') || 'grid';
            localStorage.setItem(VIEW_KEY, mode);
            applyView(root, mode);
          });
        });

        searchToggle?.addEventListener('click', () => {
          const open = searchWrap?.hasAttribute('hidden');
          if (open) {
            searchWrap.removeAttribute('hidden');
            searchWrap.classList.add('is-open');
            searchToggle.setAttribute('aria-expanded', 'true');
            searchInput?.focus();
          }
          else {
            searchWrap?.setAttribute('hidden', '');
            searchWrap?.classList.remove('is-open');
            searchToggle.setAttribute('aria-expanded', 'false');
          }
        });

        if (searchWrap && (searchWrap.classList.contains('is-open') || searchInput?.value)) {
          searchWrap.removeAttribute('hidden');
          searchToggle?.setAttribute('aria-expanded', 'true');
        }

        if (sticky) {
          const onScroll = () => {
            sticky.classList.toggle('is-stuck', sticky.getBoundingClientRect().top <= 0);
          };
          window.addEventListener('scroll', onScroll, { passive: true });
          onScroll();
        }
      });
    },
  };
})(Drupal, once);