/**
 * @file
 * Frontend dialog close control.
 *
 * Keep an accessible Chinese label while CSS hides the English "Close"
 * text that otherwise stacks above the X icon.
 *
 * Media Library modal height and touch scrolling live in
 * xedc_member/js/media-library-modal.js so the admin theme gets the same
 * behavior.
 */
(function (Drupal, once) {
  'use strict';

  function fixCloseButton(button) {
    if (!button) {
      return;
    }
    button.setAttribute('aria-label', Drupal.t('关闭'));
    button.setAttribute('title', Drupal.t('关闭'));
    button.removeAttribute('value');
  }

  Drupal.behaviors.xedcDialogCloseFix = {
    attach(context) {
      once('xedc-dialog-close-fix', 'html', context).forEach(() => {
        document.querySelectorAll('.ui-dialog-titlebar-close').forEach(fixCloseButton);
        const observer = new MutationObserver(() => {
          document.querySelectorAll('.ui-dialog-titlebar-close').forEach(fixCloseButton);
        });
        observer.observe(document.body, { childList: true, subtree: true });
      });
    },
  };
})(Drupal, once);
