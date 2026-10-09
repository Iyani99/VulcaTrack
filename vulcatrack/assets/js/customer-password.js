/* Customer login only: the button changes the real password input type. */
(function () {
  'use strict';

  var input = document.getElementById('password');
  var toggle = document.querySelector('.cauth-password__toggle');
  if (!input || !toggle) { return; }

  toggle.addEventListener('click', function () {
    var showing = input.type === 'password';
    input.type = showing ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(showing));
    toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
  });
}());
