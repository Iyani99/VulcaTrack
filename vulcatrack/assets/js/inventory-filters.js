(function () {
  'use strict';

  var form = document.querySelector('[data-inventory-filters]');
  if (!form) return;

  var search = form.elements.q;
  var timer = null;
  var submitting = false;
  form.classList.add('is-enhanced');

  function submit() {
    if (submitting) return;
    clearTimeout(timer);
    submitting = true;
    form.requestSubmit();
  }

  search.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(submit, 300);
  });
  form.querySelectorAll('select, input[type="checkbox"]').forEach(function (control) {
    control.addEventListener('change', submit);
  });
  form.addEventListener('submit', function () {
    clearTimeout(timer);
    submitting = true;
  });
}());
