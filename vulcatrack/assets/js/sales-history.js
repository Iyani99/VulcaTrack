/* Small progressive enhancement for the server-rendered Sales History filters. */
(() => {
  const panel = document.querySelector('.sales-history');
  if (!panel) return;
  panel.classList.add('is-enhanced');

  const links = [...panel.querySelectorAll('[data-sales-period]')];
  const exactForm = panel.querySelector('.sales-history__dates--exact');
  const customForm = panel.querySelector('.sales-history__dates--custom');
  const exactDay = exactForm.querySelector('[name="day"]');
  const from = customForm.querySelector('[name="from"]');
  const to = customForm.querySelector('[name="to"]');
  const heading = panel.querySelector('.sales-history__period strong');
  const range = panel.querySelector('.sales-history__period span');
  const validDay = input => /^\d{4}-\d{2}-\d{2}$/.test(input.value) && input.validity.valid;

  for (const link of links) {
    if (!['exact', 'custom'].includes(link.dataset.salesPeriod)) continue;
    link.addEventListener('click', event => {
      event.preventDefault();
      const mode = link.dataset.salesPeriod;
      panel.dataset.open = mode;
      for (const choice of links) {
        const selected = choice === link;
        choice.classList.toggle('is-active', selected);
        if (selected) choice.setAttribute('aria-current', 'true');
        else choice.removeAttribute('aria-current');
      }
      heading.textContent = mode === 'exact' ? 'Exact Day' : 'Custom';
      range.textContent = mode === 'exact' ? 'Select a date' : 'Choose From and To dates';
      (mode === 'exact' ? exactDay : from).focus();
    });
  }

  exactDay.addEventListener('change', () => {
    if (validDay(exactDay)) exactForm.requestSubmit();
  });
  const applyRange = () => {
    if (validDay(from) && validDay(to)) {
      customForm.requestSubmit(); // the server reports a reversed range visibly
    } else if (panel.dataset.legacyOpen === 'true'
        && ((validDay(from) && to.value === '') || (validDay(to) && from.value === ''))) {
      customForm.requestSubmit(); // preserve editable one-sided Reports URLs
    }
  };
  from.addEventListener('change', applyRange);
  to.addEventListener('change', applyRange);

  const optionsForm = panel.querySelector('.sales-history__filters');
  for (const select of optionsForm.querySelectorAll('select')) {
    select.addEventListener('change', () => optionsForm.requestSubmit());
  }
})();
