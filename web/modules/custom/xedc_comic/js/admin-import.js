(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcComicImportHub = {
    attach(context) {
      once('xedc-comic-import-hub', '.xedc-comic-import-hub', context).forEach(() => {
        // Placeholder for future client-side multi preview; server handles batch queue.
      });
    },
  };
})(Drupal, once);