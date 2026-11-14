(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcComicAdminShell = {
    attach(context) {
      once('xedc-comic-admin-shell', '[data-xedc-comic-admin]', context).forEach((root) => {
        const nav = root.querySelector('.xedc-comic-hub__nav-scroll');
        if (nav) {
          const active = nav.querySelector('.is-active');
          if (active && typeof active.scrollIntoView === 'function') {
            active.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'instant' });
          }
        }
      });
    },
  };
})(Drupal, once);