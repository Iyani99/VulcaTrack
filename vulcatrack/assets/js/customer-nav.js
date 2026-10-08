/* Customer header underline: the server still owns the active page and links. */
(function () {
  'use strict';

  var nav = document.querySelector('.cust .appnav');
  if (!nav) { return; }

  var active = nav.querySelector('a[aria-current="page"]');
  var indicator = nav.querySelector('.appnav__indicator');
  if (!active || !indicator) { return; }

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var pending = null;

  function place(link, immediate) {
    var navBox = nav.getBoundingClientRect();
    var linkBox = link.getBoundingClientRect();
    if (!linkBox.width) { return; }

    if (immediate) { indicator.style.transition = 'none'; }
    indicator.style.width = linkBox.width + 'px';
    indicator.style.transform = 'translate3d(' + (linkBox.left - navBox.left) + 'px, ' + (linkBox.bottom - navBox.top - 2) + 'px, 0)';
    nav.classList.add('has-indicator');
    if (immediate) {
      // Flush the resting position before enabling the next click transition.
      indicator.getBoundingClientRect();
      indicator.style.transition = '';
    }
  }

  place(active, true);

  nav.addEventListener('click', function (event) {
    var link = event.target.closest('a');
    if (event.defaultPrevented || event.button !== 0 || event.detail === 0 ||
        !link || !nav.contains(link) || link === active ||
        event.ctrlKey || event.shiftKey || event.metaKey || event.altKey ||
        link.hasAttribute('download') || (link.target && link.target !== '_self') ||
        link.origin !== window.location.origin || reducedMotion.matches) { return; }

    event.preventDefault();
    place(link, false);

    // Keep the delay aligned with the CSS motion token, including future edits.
    var seconds = parseFloat(window.getComputedStyle(indicator).transitionDuration) || 0;
    window.clearTimeout(pending);
    pending = window.setTimeout(function () { window.location.assign(link.href); }, seconds * 1000);
  });

  window.addEventListener('resize', function () { place(active, true); });
  window.addEventListener('pageshow', function () {
    window.clearTimeout(pending);
    pending = null;
    place(active, true);
  });
}());
