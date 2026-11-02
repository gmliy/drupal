(function (Drupal, once) {
  Drupal.behaviors.xedcStorageSecretToggle = {
    attach(context) {
      once('xedc-storage-secret', '[data-xedc-toggle-secret]', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          const input = document.getElementById(button.getAttribute('data-xedc-toggle-secret') || '');
          if (!input) {
            return;
          }
          const show = input.type === 'password';
          input.type = show ? 'text' : 'password';
          button.textContent = show ? '隐藏输入' : '显示输入';
        });
      });
    },
  };
})(Drupal, once);
