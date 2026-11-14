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

  Drupal.behaviors.comicTagHub = {
    attach(context) {
      once('comic-tag-hub', '.comic-tag-hub[data-comic-tag-hub]', context).forEach((root) => {
        const input = root.querySelector('[data-tag-search]');
        const empty = root.querySelector('[data-tag-empty]');
        const chips = root.querySelectorAll('[data-tag-label]');
        const groups = root.querySelectorAll('[data-tag-group]');
        const hotCards = root.querySelectorAll('.comic-tag-hot__card[data-tag-label]');
        const alpha = root.querySelector('[data-tag-alpha]');
        const alphaLinks = alpha ? alpha.querySelectorAll('[data-alpha-link]') : [];

        const applyFilter = () => {
          const q = (input?.value || '').trim().toLowerCase();
          let visible = 0;
          const match = (el) => {
            const label = el.getAttribute('data-tag-label') || '';
            const show = q === '' || label.includes(q);
            if (el.classList.contains('comic-tag-chip') || el.classList.contains('comic-tag-hot__card')) {
              el.hidden = !show;
            }
            return show;
          };
          chips.forEach((el) => {
            if (match(el)) {
              visible++;
            }
          });
          hotCards.forEach((el) => {
            if (match(el)) {
              visible++;
            }
          });
          groups.forEach((group) => {
            const any = [...group.querySelectorAll('[data-tag-label]')].some((el) => !el.hidden);
            group.hidden = !any;
          });
          if (empty) {
            empty.hidden = visible > 0 || q === '';
          }
          if (alpha) {
            alpha.hidden = q !== '';
          }
        };

        input?.addEventListener('input', applyFilter);

        alphaLinks.forEach((link) => {
          link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            if (!href || !href.startsWith('#')) {
              return;
            }
            const target = root.querySelector(href);
            if (target) {
              e.preventDefault();
              target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });
        });

        if (alphaLinks.length && typeof IntersectionObserver !== 'undefined') {
          const spy = throttleRaf(() => {
            let current = alphaLinks[0];
            groups.forEach((group) => {
              const rect = group.getBoundingClientRect();
              if (rect.top < 120 && rect.bottom > 120) {
                const letter = group.getAttribute('data-tag-letter');
                current = [...alphaLinks].find((a) => a.getAttribute('data-alpha-link') === letter) || current;
              }
            });
            alphaLinks.forEach((a) => a.classList.remove('is-current'));
            current?.classList.add('is-current');
          });
          window.addEventListener('scroll', spy, { passive: true });
          spy();
        }
      });
    },
  };
})(Drupal, once);