(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.report-period').forEach(function (form) {
      var period = form.querySelector('.report-period__select');
      var month = form.querySelector('.report-period__month');
      if (!period || !month) { return; }
      function showMonth() {
        month.hidden = period.value !== 'month';
        month.querySelector('select').disabled = month.hidden;
      }
      showMonth();
      period.addEventListener('change', showMonth);
    });

    if (!('IntersectionObserver' in window) ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

    var visuals = document.querySelectorAll('.dash-trend, .rpt-chart, .rpt-source');
    if (!visuals.length) { return; }
    document.body.classList.add('report-motion-ready');
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) { return; }
        entry.target.classList.add('is-revealed');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px 140px 0px', threshold: 0 });
    visuals.forEach(function (visual) { observer.observe(visual); });
  });
}());
