/* VulcaTrack -- On-the-Go location map (customer side).
 *
 * Progressive enhancement over a plain form:
 *  - "Use my current location" uses the browser Geolocation API (no library).
 *  - When Leaflet (vendored) is available it also shows a map: click to drop /
 *    move the pin, and a straight line to the shop (the ETA shown never changes
 *    -- it is the frozen value from the server).
 *  - When Leaflet fails to load, the map area is replaced with a short note and
 *    the geolocation button + manual latitude/longitude fields still work.
 */
(function () {
  'use strict';

  var el = document.getElementById('otg-map');
  if (!el) { return; }

  var shopLat = parseFloat(el.getAttribute('data-shop-lat'));
  var shopLng = parseFloat(el.getAttribute('data-shop-lng'));
  var shopName = el.getAttribute('data-shop-name') || 'Shop';
  var readonly = el.getAttribute('data-readonly') === '1';

  var latInput = document.getElementById('otg-lat');
  var lngInput = document.getElementById('otg-lng');
  var statusEl = document.getElementById('otg-loc-status');
  var locateBtn = document.getElementById('otg-locate');
  var manualLat = document.getElementById('otg-lat-manual');
  var manualLng = document.getElementById('otg-lng-manual');
  var manualApply = document.getElementById('otg-apply-manual');

  function setStatus(msg, kind) {
    if (!statusEl) { return; }
    statusEl.textContent = msg;
    statusEl.className = 'loc-status' + (kind ? ' loc-status--' + kind : '');
  }

  function inRange(lat, lng) {
    return isFinite(lat) && isFinite(lng) &&
      lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
  }

  var hasLeaflet = typeof window.L !== 'undefined';
  var map = null, shopMarker = null, custMarker = null, line = null;

  function readInitialCustomer() {
    var dLat = parseFloat(el.getAttribute('data-cust-lat'));
    var dLng = parseFloat(el.getAttribute('data-cust-lng'));
    if (inRange(dLat, dLng)) { return [dLat, dLng]; }
    if (latInput && lngInput) {
      var fLat = parseFloat(latInput.value), fLng = parseFloat(lngInput.value);
      if (inRange(fLat, fLng)) { return [fLat, fLng]; }
    }
    return null;
  }

  function updateFormValue(lat, lng) {
    if (latInput) { latInput.value = lat.toFixed(7); }
    if (lngInput) { lngInput.value = lng.toFixed(7); }
    setStatus('Location set: ' + lat.toFixed(5) + ', ' + lng.toFixed(5), 'ok');
  }

  function drawLine() {
    if (!hasLeaflet || !map || !custMarker || !inRange(shopLat, shopLng)) { return; }
    var pts = [custMarker.getLatLng(), [shopLat, shopLng]];
    if (line) { line.setLatLngs(pts); } else {
      line = window.L.polyline(pts, { color: '#0a58ca', dashArray: '5,6', weight: 3 }).addTo(map);
    }
  }

  function fitAll() {
    if (!hasLeaflet || !map) { return; }
    var pts = [];
    if (inRange(shopLat, shopLng)) { pts.push([shopLat, shopLng]); }
    if (custMarker) { pts.push(custMarker.getLatLng()); }
    if (pts.length === 2) { map.fitBounds(pts, { padding: [30, 30] }); }
    else if (pts.length === 1) { map.setView(pts[0], 14); }
  }

  function setCustomer(lat, lng, recenter) {
    if (!inRange(lat, lng)) { return; }
    if (hasLeaflet && map) {
      if (custMarker) { custMarker.setLatLng([lat, lng]); }
      else {
        custMarker = window.L.marker([lat, lng], { draggable: !readonly }).addTo(map);
        custMarker.bindPopup('Your location');
        if (!readonly) {
          custMarker.on('dragend', function () {
            var p = custMarker.getLatLng();
            updateFormValue(p.lat, p.lng); drawLine();
          });
        }
      }
      drawLine();
      if (recenter) { fitAll(); }
    }
    updateFormValue(lat, lng);
  }

  // --- Map setup -----------------------------------------------------------
  if (hasLeaflet && inRange(shopLat, shopLng)) {
    map = window.L.map(el);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    shopMarker = window.L.marker([shopLat, shopLng]).addTo(map).bindPopup(shopName);
    map.setView([shopLat, shopLng], 13);

    if (!readonly) {
      map.on('click', function (ev) { setCustomer(ev.latlng.lat, ev.latlng.lng, false); });
    }

    var initial = readInitialCustomer();
    if (initial) { setCustomer(initial[0], initial[1], true); } else { fitAll(); }
  } else {
    // No Leaflet (or no shop coords): replace the map box with a note.
    el.classList.add('otg-map--off');
    var cust = readInitialCustomer();
    var note = document.createElement('p');
    note.className = 'muted';
    if (readonly && cust) {
      note.innerHTML = 'Map unavailable. Your location: ' + cust[0].toFixed(5) + ', ' + cust[1].toFixed(5) +
        ' &middot; <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' +
        cust[0] + '&mlon=' + cust[1] + '#map=15/' + cust[0] + '/' + cust[1] + '">open map</a>';
    } else {
      note.textContent = 'Map unavailable. Use "Use my current location" or enter coordinates below.';
    }
    el.appendChild(note);
  }

  // --- Geolocation button -------------------------------------------------
  if (locateBtn && !readonly) {
    locateBtn.addEventListener('click', function () {
      if (!navigator.geolocation) {
        setStatus('This browser cannot share your location. Enter coordinates below.', 'err');
        return;
      }
      setStatus('Getting your location…');
      navigator.geolocation.getCurrentPosition(
        function (pos) { setCustomer(pos.coords.latitude, pos.coords.longitude, true); },
        function (err) {
          setStatus(
            (err && err.code === 1
              ? 'Location permission denied. Click the map or enter coordinates below.'
              : 'Could not get your location. Click the map or enter coordinates below.'),
            'err'
          );
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
      );
    });
  }

  // --- Manual entry ------------------------------------------------------
  if (manualApply && manualLat && manualLng && !readonly) {
    manualApply.addEventListener('click', function () {
      var lat = parseFloat(manualLat.value), lng = parseFloat(manualLng.value);
      if (!inRange(lat, lng)) {
        setStatus('Enter a valid latitude (-90..90) and longitude (-180..180).', 'err');
        return;
      }
      setCustomer(lat, lng, true);
    });
  }
})();
