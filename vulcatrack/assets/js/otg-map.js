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

  // Landmark / address search (optional; only wired up when the elements exist).
  var geocodeUrl = el.getAttribute('data-geocode-url');
  var searchInput = document.getElementById('otg-search-q');
  var searchBtn = document.getElementById('otg-search-btn');
  var searchStatusEl = document.getElementById('otg-search-status');
  var resultsEl = document.getElementById('otg-search-results');
  var selectedEl = document.getElementById('otg-selected-label');
  var csrfInput = document.querySelector('input[name="_csrf"]');
  var selectedPlace = null; // label of the last landmark result the user picked

  function setStatus(msg, kind) {
    if (!statusEl) { return; }
    statusEl.textContent = msg;
    statusEl.className = 'loc-status' + (kind ? ' loc-status--' + kind : '');
  }

  function setSearchStatus(msg, kind) {
    if (!searchStatusEl) { return; }
    searchStatusEl.textContent = msg || '';
    searchStatusEl.className = 'loc-status' + (kind ? ' loc-status--' + kind : '');
    searchStatusEl.hidden = !msg;
  }

  function showSelected(text) {
    if (!selectedEl) { return; }
    selectedEl.textContent = text || '';
    selectedEl.hidden = !text;
  }

  function clearSelected() {
    selectedPlace = null;
    showSelected('');
  }

  // Called when the user drags the pin or clicks the map after a pick.
  function markAdjusted() {
    if (selectedPlace) { showSelected('Selected: ' + selectedPlace + ' — position adjusted on map'); }
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
            updateFormValue(p.lat, p.lng); drawLine(); markAdjusted();
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
      map.on('click', function (ev) { setCustomer(ev.latlng.lat, ev.latlng.lng, false); markAdjusted(); });
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
        function (pos) { clearSelected(); setCustomer(pos.coords.latitude, pos.coords.longitude, true); },
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
      clearSelected();
      setCustomer(lat, lng, true);
    });
  }

  // --- Landmark / address search --------------------------------------------
  if (geocodeUrl && searchInput && searchBtn && !readonly) {

    var renderResults = function (list, attribution) {
      resultsEl.textContent = '';
      if (!list.length) {
        resultsEl.hidden = true;
        setSearchStatus('No matching places found. Try a nearby landmark, or use your current location.', 'err');
        return;
      }
      list.forEach(function (place) {
        var lat = parseFloat(place.latitude), lng = parseFloat(place.longitude);
        if (!inRange(lat, lng)) { return; }
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'linklike loc-result';
        btn.textContent = place.label; // textContent -> external text is never HTML
        btn.addEventListener('click', function () {
          selectedPlace = place.label;
          setCustomer(lat, lng, true);
          showSelected('Selected: ' + place.label);
          setSearchStatus('');
          resultsEl.hidden = true;
        });
        li.appendChild(btn);
        resultsEl.appendChild(li);
      });
      resultsEl.hidden = false;
      setSearchStatus(attribution ? 'Pick the closest match, then drag the pin to fine-tune.' : '');
    };

    var runSearch = function () {
      var q = (searchInput.value || '').trim();
      if (q.length < 3) {
        setSearchStatus('Type at least 3 characters, e.g. a store or street name.', 'err');
        return;
      }
      var body = new FormData();
      body.append('q', q);
      if (csrfInput) { body.append('_csrf', csrfInput.value); }

      searchBtn.disabled = true;
      setSearchStatus('Searching…');
      resultsEl.hidden = true;

      fetch(geocodeUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) { return res.json().catch(function () { return { ok: false, error: 'unavailable' }; }); })
        .then(function (data) {
          if (data && data.ok) {
            renderResults(Array.isArray(data.results) ? data.results : [], data.attribution);
          } else {
            var code = data && data.error;
            setSearchStatus(
              code === 'too_short' ? 'Type at least 3 characters.' :
              code === 'auth' ? 'Your session expired — reload the page and sign in again.' :
              'Landmark search is unavailable right now. Use your current location or the map instead.',
              'err'
            );
          }
        })
        .catch(function () {
          setSearchStatus('Landmark search is unavailable right now. Use your current location or the map instead.', 'err');
        })
        .then(function () { searchBtn.disabled = false; });
    };

    searchBtn.addEventListener('click', runSearch);
    searchInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); }
    });
  }
})();
