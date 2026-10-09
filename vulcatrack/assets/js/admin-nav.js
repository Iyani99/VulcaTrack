/* Mark real Admin link navigation; same-route forms and filters stay immediate. */
(function () {
  'use strict';

  var intentKey = 'vulcatrack-admin-nav-target';
  var root = document.documentElement;
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var destination = window.location.pathname + window.location.search;

  function rememberNavigation(link) {
    try {
      window.sessionStorage.setItem(intentKey, link.pathname + link.search);
    } catch (error) {
      // Storage may be unavailable; the link still navigates normally.
    }
  }

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

    rememberNavigation(link);
  });

  document.addEventListener('DOMContentLoaded', function () {
    var nav = document.querySelector('body.admin .adm-nav');
    var activeNav = nav && nav.querySelector('a[aria-current="page"]');
    var navIndicator = nav && nav.querySelector('.adm-nav__indicator');
    if (activeNav && navIndicator) {
      var navPending = null;
      var narrowNav = window.matchMedia('(max-width: 60rem)');

      function placeNav(link, immediate) {
        if (!link.offsetWidth) { return; }
        var horizontal = narrowNav.matches;
        var x = link.offsetLeft;
        var y = link.offsetTop + (horizontal ? link.offsetHeight - 3 : 0);

        if (immediate) { navIndicator.style.transition = 'none'; }
        navIndicator.style.width = (horizontal ? link.offsetWidth : 3) + 'px';
        navIndicator.style.height = (horizontal ? 3 : link.offsetHeight) + 'px';
        navIndicator.style.transform = 'translate3d(' + x + 'px, ' + y + 'px, 0)';
        nav.classList.add('has-indicator');
        if (immediate) {
          navIndicator.getBoundingClientRect();
          navIndicator.style.transition = '';
        }
      }

      placeNav(activeNav, true);

      nav.addEventListener('click', function (event) {
        var link = event.target instanceof Element && event.target.closest('a[href]');
        if (event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.shiftKey || event.metaKey || event.altKey ||
            !link || link === activeNav || link.origin !== window.location.origin ||
            link.hasAttribute('download') || (link.target && link.target !== '_self') ||
            reducedMotion.matches) { return; }

        event.preventDefault();
        placeNav(link, false);
        rememberNavigation(link);

        var duration = window.getComputedStyle(navIndicator).transitionDuration.split(',')[0].trim();
        var delayMs = parseFloat(duration) * (duration.endsWith('ms') ? 1 : 1000) || 0;
        window.clearTimeout(navPending);
        navPending = window.setTimeout(function () { window.location.assign(link.href); }, delayMs);
      });

      window.addEventListener('resize', function () { placeNav(activeNav, true); });
      window.addEventListener('pageshow', function () {
        window.clearTimeout(navPending);
        navPending = null;
        placeNav(activeNav, true);
      });
    }

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
