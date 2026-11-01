/**
 * @file
 * Account menu. Messages, dialogs, and AJAX keep Drupal's own behavior.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcAdminDropdown = {
    attach(context) {
      once('xedc-admin-dropdown', '[data-xedc-admin-dropdown]', context).forEach((root) => {
        const button = root.querySelector('[data-xedc-admin-dropdown-button]');
        const menu = root.querySelector('[data-xedc-admin-dropdown-menu]');
        if (!button || !menu) {
          return;
        }

        const close = () => {
          menu.hidden = true;
          button.setAttribute('aria-expanded', 'false');
        };
        const open = () => {
          menu.hidden = false;
          button.setAttribute('aria-expanded', 'true');
        };

        button.addEventListener('click', (event) => {
          event.preventDefault();
          if (menu.hidden) {
            open();
          }
          else {
            close();
          }
        });

        document.addEventListener('click', (event) => {
          if (!root.contains(event.target)) {
            close();
          }
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !menu.hidden) {
            close();
            button.focus();
          }
        });
      });
    },
  };
})(Drupal, once);
