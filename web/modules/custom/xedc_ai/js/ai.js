(function (Drupal, once) {
  Drupal.behaviors.xedcAiSelectAll = {
    attach(context) {
      once('xedc-ai-select-all', '.xedc-ai-select-all', context).forEach((box) => {
        box.addEventListener('change', () => {
          const form = box.closest('form');
          if (!form) {
            return;
          }
          form.querySelectorAll('input[type="checkbox"]').forEach((item) => {
            if (item !== box && item.name && (item.name.indexOf('reviews') === 0 || item.name.indexOf('nodes') === 0)) {
              item.checked = box.checked;
            }
          });
        });
      });
    },
  };
})(Drupal, once);
