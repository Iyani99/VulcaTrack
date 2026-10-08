/* Visual completion cues for the three editable Rescue steps. PHP remains authoritative. */
(function () {
  'use strict';

  var form = document.querySelector('.cust .rb form');
  if (!form) { return; }

  var steps = {
    vehicle: form.querySelector('[data-rescue-step="vehicle"]'),
    problem: form.querySelector('[data-rescue-step="problem"]'),
    location: form.querySelector('[data-rescue-step="location"]')
  };
  var description = form.querySelector('#problem_description');
  var latitude = form.querySelector('#otg-lat');
  var longitude = form.querySelector('#otg-lng');

  function validCoordinate(input, limit) {
    var value = input && input.value.trim();
    return !!value && /^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(value) &&
      Number.isFinite(Number(value)) && Math.abs(Number(value)) <= limit;
  }

  function isValid(name) {
    if (name === 'vehicle') {
      var selected = steps.vehicle.querySelector('input[name="vehicle_id"]:checked');
      return !!selected && selected.checkValidity();
    }
    if (name === 'problem') {
      var length = Array.from(description.value.trim()).length;
      return description.checkValidity() && length >= 5 && length <= 2000;
    }
    return validCoordinate(latitude, 90) && validCoordinate(longitude, 180);
  }

  function clearServerError(step) {
    step.classList.remove('rb-step--error');
    step.removeAttribute('aria-describedby');
    step.querySelectorAll('.error').forEach(function (message) { message.remove(); });
    step.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
    });
    if (!form.querySelector('.rb-step--error')) {
      var alert = form.parentElement.querySelector('[data-rescue-field-alert]');
      if (alert) { alert.remove(); }
    }
  }

  function update(name, changedByUser) {
    var step = steps[name];
    if (!step) { return; }
    var valid = isValid(name);
    if (valid && changedByUser && step.classList.contains('rb-step--error')) {
      clearServerError(step);
    }
    step.classList.toggle('is-complete', valid && !step.classList.contains('rb-step--error'));
  }

  function updateForInput(event) {
    var step = event.target.closest('[data-rescue-step]');
    if (step) { update(step.getAttribute('data-rescue-step'), true); }
  }

  form.addEventListener('input', updateForInput);
  form.addEventListener('change', updateForInput);
  var map = form.querySelector('#otg-map');
  if (map) { map.addEventListener('otg:locationchange', function () { update('location', true); }); }

  Object.keys(steps).forEach(function (name) { if (steps[name]) { update(name, false); } });
}());
