/**
 * @file
 * Keep Media Library modal list scrolling after jQuery UI height math.
 *
 * jQuery UI Dialog writes inline height:0 / max-height on .ui-dialog-content.
 * A generic overflow:auto on .ui-dialog-content then steals wheel/touch from
 * .view-content. Clear those inline sizes on open and after AJAX rebuilds.
 */
(function (Drupal, once, $) {
  'use strict';

  function isMediaLibraryDialog(dialog) {
    return !!(dialog && dialog.querySelector && dialog.querySelector('#media-library-wrapper, .media-library-wrapper'));
  }

  function clearInlineHeights(dialog) {
    if (!isMediaLibraryDialog(dialog)) {
      return;
    }
    const content = dialog.querySelector('.ui-dialog-content');
    if (content) {
      content.style.removeProperty('height');
      content.style.removeProperty('max-height');
      content.style.minHeight = '0px';
      content.style.overflow = 'hidden';
    }
  }

  function fixAllOpen() {
    document.querySelectorAll('.ui-dialog').forEach(clearInlineHeights);
  }

  Drupal.behaviors.xedcMediaLibraryDialogLayout = {
    attach(context) {
      once('xedc-media-library-dialog-layout-root', 'html', context).forEach(() => {
        $(window).on('dialog:aftercreate.xedcMediaLibrary dialogContentResize.xedcMediaLibrary', () => {
          window.requestAnimationFrame(fixAllOpen);
        });
      });

      once('xedc-media-library-dialog-shell', '.ui-dialog', context).forEach(clearInlineHeights);
      once('xedc-media-library-ajax-fix', '#media-library-wrapper', context).forEach((wrapper) => {
        const dialog = wrapper.closest('.ui-dialog');
        if (dialog) {
          clearInlineHeights(dialog);
        }
      });
    },
  };
})(Drupal, once, jQuery);
