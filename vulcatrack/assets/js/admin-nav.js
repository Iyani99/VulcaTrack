/* Mark real Admin link navigation; same-route forms and filters stay immediate. */
(function () {
  'use strict';

  var intentKey = 'vulcatrack-admin-nav-target';
  var root = document.documentElement;
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var destination = window.location.pathname + window.location.search;

  try {
    var intent = window.sessionStorage.getItem(intentKey);
    window.sessionStorage.removeItem(intentKey);
    if (intent === destination && !reducedMotion.matches) {
      root.classList.add('adm-page-enter');
    }
  } catch (error) {
    // Storage may be unavailable; links still navigate normally.
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 ||
        event.ctrlKey || event.shiftKey || event.metaKey || event.altKey ||
        !(event.target instanceof Element)) { return; }

    var link = event.target.closest('a[href]');
    if (!link || link.origin !== window.location.origin ||
        link.pathname === window.location.pathname ||
        link.pathname.indexOf('/admin/') === -1 ||
        link.hasAttribute('download') || (link.target && link.target !== '_self')) { return; }

    try {
      window.sessionStorage.setItem(intentKey, link.pathname + link.search);
    } catch (error) {
      // The destination still opens without the optional entrance effect.
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelector('body.admin .tabs[aria-label="Filter by status"]');
    if (!tabs) { return; }

    var active = tabs.querySelector('a[aria-current="true"]');
    var indicator = tabs.querySelector('.tabs__indicator');
    if (!active || !indicator) { return; }

    var pending = null;

    function place(link, immediate) {
      var navBox = tabs.getBoundingClientRect();
      var linkBox = link.getBoundingClientRect();
      if (!linkBox.width) { return; }

      if (immediate) { indicator.style.transition = 'none'; }
      indicator.style.width = linkBox.width + 'px';
      indicator.style.transform = 'translate3d(' + (linkBox.left - navBox.left) + 'px, ' +
        (linkBox.bottom - navBox.top - 2) + 'px, 0)';
      tabs.classList.add('has-indicator');
      if (immediate) {
        indicator.getBoundingClientRect();
        indicator.style.transition = '';
      }
    }

    place(active, true);

    tabs.addEventListener('click', function (event) {
      var link = event.target.closest('a');
      if (event.defaultPrevented || event.button !== 0 || event.detail === 0 ||
          !link || !tabs.contains(link) || link === active ||
          event.ctrlKey || event.shiftKey || event.metaKey || event.altKey ||
          link.hasAttribute('download') || (link.target && link.target !== '_self') ||
          link.origin !== window.location.origin || reducedMotion.matches) { return; }

      event.preventDefault();
      place(link, false);

      var duration = window.getComputedStyle(indicator).transitionDuration.split(',')[0].trim();
      var delayMs = parseFloat(duration) * (duration.endsWith('ms') ? 1 : 1000) || 0;
      window.clearTimeout(pending);
      pending = window.setTimeout(function () { window.location.assign(link.href); }, delayMs);
    });

    window.addEventListener('resize', function () { place(active, true); });
    window.addEventListener('pageshow', function () {
      window.clearTimeout(pending);
      pending = null;
      place(active, true);
    });
  });

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) { root.classList.remove('adm-page-enter'); }
  });
}());
