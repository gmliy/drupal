(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcNotifyMessages = {
    attach(context) {
      once('xedc-notify-scroll', '[data-xn-msg-scroll]', context).forEach((el) => {
        el.scrollTop = el.scrollHeight;
      });
    },
  };
})(Drupal, once);
