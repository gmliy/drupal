(function (Drupal, once) {
  Drupal.behaviors.xedcStorageSwitch = {
    attach(context) {
      once('xedc-storage-switch', '.xedc-ops-switch', context).forEach((form) => {
        form.addEventListener('submit', () => {
          window.setTimeout(() => {
            form.querySelectorAll('[type="submit"]').forEach((button) => {
              button.disabled = true;
            });
          }, 0);
        });
      });
    },
  };
})(Drupal, once);
