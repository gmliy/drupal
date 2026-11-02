/**
 * @file
 * Switches the mail preview iframe without submitting the form.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcMailPreview = {
    attach(context) {
      once('xedc-mail-preview', '[data-xedc-mail-frame]', context).forEach((frame) => {
        const form = frame.closest('form');
        if (!form) {
          return;
        }
        const key = form.querySelector('[name="key"]');
        const widths = form.querySelectorAll('[name="width"]');
        const paint = () => {
          const selected = key ? key.value : 'password_reset';
          frame.setAttribute('src', '/admin/config/people/accounts/email-preview/render/' + encodeURIComponent(selected));
          let width = '600';
          widths.forEach((item) => {
            if (item.checked) {
              width = item.value;
            }
          });
          frame.style.width = width === '375' ? '375px' : '600px';
        };
        if (key) {
          key.addEventListener('change', paint);
        }
        widths.forEach((item) => item.addEventListener('change', paint));
      });
    },
  };
})(Drupal, once);
