/**
 * SenTri Geo — shared geographic utilities (Imus City barangays + GPS accuracy).
 *
 * Exposes the global `SentriGeo` object. Leaflet is OPTIONAL: functions only
 * touch `L` when actually invoked, so this file is safe to include on pages
 * without a map.
 *
 * Barangay perimeters are served from assets/geo/imus-barangays.geojson
 * (FeatureCollection, 95 barangays of Imus City, Cavite; properties:
 * {name, ref, old_ref, center:[lon,lat]}).
 */
(function (global) {
  'use strict';

  var GEO_PATH_PORTAL = '../assets/geo/imus-barangays.geojson';
  var GEO_PATH_ROOT = 'assets/geo/imus-barangays.geojson';

  var _fcPromise = null; // cache of the FeatureCollection fetch
  var _fcCache = null;   // the resolved FeatureCollection (for sync access)

  /**
   * Fallback bounding box of the Imus perimeter set, used only when the
   * geojson cannot be loaded (offline / 404). Values are the bbox of
   * assets/geo/imus-barangays.geojson (95 barangays of Imus City).
   * When the geojson DOES load, the exact bounds are computed from it and
   * this constant is ignored - it only keeps the map usable if the fetch
   * fails.
   */
  var IMUS_BOUNDS_FALLBACK = {
    minLat: 14.354107, minLng: 120.892088,
    maxLat: 14.446365, maxLng: 120.981669
  };

  /** Resolve the geojson URL relative to the current page. */
  function _geoUrl() {
    var rel = String(global.location && global.location.pathname || '').indexOf('/portal/') !== -1
      ? GEO_PATH_PORTAL
      : GEO_PATH_ROOT;
    // document.baseURI honors <base href> and works from any served path.
    var base = global.document && global.document.baseURI ? global.document.baseURI : undefined;
    try {
      return new URL(rel, base || undefined).href;
    } catch (e) {
      return rel; // graceful fallback for file:// or malformed baseURI
    }
  }

  /**
   * Load (and cache) the Imus barangays FeatureCollection.
   * @returns {Promise<geojson.FeatureCollection>}
   */
  function loadBarangays() {
    if (_fcPromise) return _fcPromise;
    _fcPromise = fetch(_geoUrl())
      .then(function (res) {
        if (!res.ok) throw new Error('SentriGeo: geojson ' + res.status + ' ' + res.statusText);
        return res.json();
      })
      .then(function (fc) {
        _fcCache = fc; // keep the resolved data for synchronous queries
        return fc;
      })
      .catch(function (err) {
        _fcPromise = null; // allow retry on network error
        throw err;
      });
    return _fcPromise;
  }

  /**
   * Ray-casting point-in-polygon for a single ring.
   * @param {Array<[number,number]>} ring [[lon,lat],...] as in GeoJSON
   * @param {number} lng longitude
   * @param {number} lat latitude
   * @returns {boolean}
   */
  function pointInPolygon(ring, lng, lat) {
    if (!Array.isArray(ring) || ring.length < 3) return false;
    var inside = false;
    for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      var xi = ring[i][0], yi = ring[i][1];
      var xj = ring[j][0], yj = ring[j][1];
      var intersects = ((yi > lat) !== (yj > lat)) &&
        (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
      if (intersects) inside = !inside;
    }
    return inside;
  }

  /**
   * True when [lng,lat] is inside outerRing and not inside any of its holes.
   * rings = [outerRing, hole1, hole2, ...] (GeoJSON Polygon.coordinates entry)
   */
  function _inRings(rings, lng, lat) {
    if (!rings || !rings.length || !pointInPolygon(rings[0], lng, lat)) return false;
    for (var h = 1; h < rings.length; h++) {
      if (pointInPolygon(rings[h], lng, lat)) return false;
    }
    return true;
  }

  /** Extract every polygon (array of ring-arrays) from a GeoJSON geometry. */
  function _geometryPolygons(geom) {
    if (!geom) return [];
    if (geom.type === 'Polygon') return [geom.coordinates];
    if (geom.type === 'MultiPolygon') return geom.coordinates;
    return [];
  }

  /** All outer rings of a feature (first ring of every polygon). */
  function _outerRings(feature) {
    var out = [];
    var polygons = _geometryPolygons(feature && feature.geometry);
    for (var i = 0; i < polygons.length; i++) {
      if (polygons[i] && polygons[i].length) out.push(polygons[i][0]);
    }
    return out;
  }

  /**
   * Which Imus barangay contains the point?
   * @param {number} lat
   * @param {number} lng
   * @returns {Promise<{name:string, ref:string}|null>}
   */
  function findBarangay(lat, lng) {
    return loadBarangays().then(function (fc) {
      if (typeof lat !== 'number' || typeof lng !== 'number' ||
        !isFinite(lat) || !isFinite(lng)) return null;
      for (var f = 0; f < fc.features.length; f++) {
        var feature = fc.features[f];
        var polygons = _geometryPolygons(feature.geometry);
        for (var p = 0; p < polygons.length; p++) {
          if (_inRings(polygons[p], lng, lat)) {
            var props = feature.properties || {};
            return { name: props.name, ref: props.ref };
          }
        }
      }
      return null;
    });
  }

  /**
   * Bounding box of the Imus perimeter set.
   * Computed from the loaded geojson when available, otherwise the built-in
   * fallback box is returned so the map still works offline.
   * @returns {{minLat:number,minLng:number,maxLat:number,maxLng:number}}
   */
  function imusBounds() {
    var fc = _fcCache;
    if (fc && fc.features && fc.features.length) {
      var minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
      for (var f = 0; f < fc.features.length; f++) {
        var polygons = _geometryPolygons(fc.features[f].geometry);
        for (var p = 0; p < polygons.length; p++) {
          for (var r = 0; r < polygons[p].length; r++) {
            var ring = polygons[p][r];
            for (var i = 0; i < ring.length; i++) {
              var lng = ring[i][0], lat = ring[i][1];
              if (lat < minLat) minLat = lat;
              if (lat > maxLat) maxLat = lat;
              if (lng < minLng) minLng = lng;
              if (lng > maxLng) maxLng = lng;
            }
          }
        }
      }
      if (isFinite(minLat) && isFinite(minLng) && isFinite(maxLat) && isFinite(maxLng)) {
        return { minLat: minLat, minLng: minLng, maxLat: maxLat, maxLng: maxLng };
      }
    }
    return {
      minLat: IMUS_BOUNDS_FALLBACK.minLat, minLng: IMUS_BOUNDS_FALLBACK.minLng,
      maxLat: IMUS_BOUNDS_FALLBACK.maxLat, maxLng: IMUS_BOUNDS_FALLBACK.maxLng
    };
  }

  /**
   * Has the Imus perimeter set finished loading?
   * Callers that filter by isInImus() must wait for this: until the geometry
   * is available isInImus() cannot answer and would report every point as
   * outside the city.
   * @returns {boolean}
   */
  function geoReady() {
    return !!(global.SentriGeo && _fcCache && _fcCache.features && _fcCache.features.length);
  }

  /**
   * Is this point inside the Imus City perimeter set?
   * Exact test: the point must fall inside one of the 95 barangay polygons
   * (not merely inside the bounding box), so sea area and neighbouring
   * municipalities are excluded.
   * @param {number} lat
   * @param {number} lng
   * @returns {boolean} false while the geometry is still loading
   */
  function isInImus(lat, lng) {
    var fc = _fcCache;
    if (!fc || !fc.features || !fc.features.length) return false;
    if (typeof lat !== 'number' || typeof lng !== 'number' ||
      !isFinite(lat) || !isFinite(lng)) return false;
    for (var f = 0; f < fc.features.length; f++) {
      var polygons = _geometryPolygons(fc.features[f].geometry);
      for (var p = 0; p < polygons.length; p++) {
        if (_inRings(polygons[p], lng, lat)) return true;
      }
    }
    return false;
  }

  /**
   * The Imus area as a Leaflet rectangle, ready for map.setMaxBounds().
   * @param {number} [pad] padding as a fraction of the Imus span
   * @returns {L.LatLngBounds|null}
   */
  function imusLatLngBounds(pad) {
    if (typeof L === 'undefined') return null;
    var b = imusBounds();
    var p = (pad != null) ? pad : 0.06;
    var padLat = (b.maxLat - b.minLat) * p;
    var padLng = (b.maxLng - b.minLng) * p;
    return L.latLngBounds(
      [b.minLat - padLat, b.minLng - padLng],
      [b.maxLat + padLat, b.maxLng + padLng]
    );
  }

  /**
   * Restrict a Leaflet map to the Imus City area.
   *
   * Two guards are applied:
   *  - maxBounds  -> the viewport can never be panned outside the city.
   *  - minZoom    -> the user cannot zoom out past the city, so the map
   *                  never becomes a view of the whole region.
   *
   * @param {L.Map} map
   * @param {Object} [opts] {pad?:number  padding as a fraction of the Imus
   *                                  span (default 0.06),
   *                          fit?:boolean  fit the view to Imus,
   *                          maxZoom?:number zoom cap when fitting}
   * @returns {Promise<Object>} resolves with the Imus bounds
   */
  function lockToImus(map, opts) {
    opts = opts || {};
    if (typeof L === 'undefined') return Promise.reject(new Error('SentriGeo: Leaflet (L) not loaded'));
    if (!map) return Promise.reject(new Error('SentriGeo: map is required'));
    return loadBarangays().catch(function () { return null; }).then(function () {
      var bounds = imusLatLngBounds(opts.pad != null ? opts.pad : 0.06);
      if (!bounds) throw new Error('SentriGeo: Imus bounds unavailable');
      map.setMaxBounds(bounds);
      // The zoom cap depends on the container size, which is often still 0
      // when the map is created (hidden panel, modal just opened). Re-apply
      // it whenever Leaflet reports a new size.
      map.on('resize', function () { applyImusZoomLimit(map, bounds); });
      applyImusZoomLimit(map, bounds);
      if (opts.fit) {
        map.fitBounds(bounds, { padding: [10, 10], maxZoom: opts.maxZoom != null ? opts.maxZoom : 13 });
      }
      return imusBounds();
    });
  }

  /**
   * Cap a map's zoom-out so the viewport cannot grow past the Imus box.
   * Recomputed whenever the map is resized (the container size drives the
   * zoom level at which the box fills the viewport).
   * @param {L.Map} map
   * @param {L.LatLngBounds} [bounds] defaults to the map's current maxBounds
   */
  function applyImusZoomLimit(map, bounds) {
    if (!map) return;
    var box = bounds || map.options.maxBounds;
    if (!box) return;
    var size = map.getSize();
    if (!size || !size.x || !size.y) return; // hidden container - try again later
    var z = map.getBoundsZoom(box);
    if (isFinite(z) && z > 0) map.setMinZoom(Math.floor(z));
  }

  /**
   * Draw thin translucent barangay outlines under map pins.
   * @param {L.Map} map Leaflet map
   * @param {Object} [opts] {onlyName?:string, style?:(feat)=>LeafletPathOptions,
   *                         onEach?: (feature, layer)=>void}
   * @returns {Promise<L.GeoJSON>}
   */
  function addBarangayOverlay(map, opts) {
    opts = opts || {};
    if (typeof L === 'undefined') return Promise.reject(new Error('SentriGeo: Leaflet (L) not loaded'));
    if (!map) return Promise.reject(new Error('SentriGeo: map is required'));
    return loadBarangays().then(function (fc) {
      var layer = L.geoJSON(null, {
        style: function (feature) {
          var name = feature && feature.properties && feature.properties.name;
          if (opts.onlyName && name === opts.onlyName) {
            return { weight: 3, color: '#2563eb', opacity: 0.8, fillOpacity: 0.18 };
          }
          if (typeof opts.style === 'function') {
            var custom = opts.style(feature);
            if (custom) return custom;
          }
          return { weight: 2, color: '#2563eb', opacity: 0.8, fillOpacity: 0.05 };
        },
        onEachFeature: function (feature, l) {
          if (typeof opts.onEach === 'function') opts.onEach(feature, l);
        }
      });
      layer.addData(fc);
      layer.addTo(map);
      if (opts.onlyName) {
        // Bring the highlighted barangay above the rest of the overlays.
        layer.eachLayer(function (l) {
          if (l.feature && l.feature.properties && l.feature.properties.name === opts.onlyName) {
            if (l.bringToFront) l.bringToFront();
          }
        });
      }
      return layer;
    });
  }

  /**
   * Great-circle distance in metres.
   * @param {number} lat1
   * @param {number} lng1
   * @param {number} lat2
   * @param {number} lng2
   * @returns {number} metres
   */
  function haversine(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  var LOW_ACCURACY_LIMIT = 1500; // metres; above this a fix is treated as junk
  var FAST_FIX_MAX_AGE = 60000;   // ms; cached fixes up to 1 min old OK for the instant probe
  var FAST_FIX_TIMEOUT = 2500;    // ms; the probe must never delay real acquisition
  var FAST_RESOLVE_ACCURACY = 250; // m; a cached coarse fix at least this good resolves instantly

  /**
   * One-shot high-accuracy geolocation.
   *
   * Acquisition ladder (fast → slow):
   *  0. Instant probe — any CACHED fix ≤ 60s old good to ±250m resolves at once.
   *     (maximumAge used to be 3000ms on every call, which forced a cold GPS
   *     lock each time "Get My GPS"/report-pinning was used and made geolocation
   *     feel like it hung for 30-60s.)
   *  1. High-accuracy getCurrentPosition — caller `timeout` (default 30s),
   *     maximumAge 15s so a fresh high-accuracy cache also resolves instantly.
   *     Fixes worse than ±LOW_ACCURACY_LIMIT are ignored, not resolved.
   *  2. Persistent high-accuracy watch — up to 60s more; GPS chips frequently
   *     deliver a fix a beat after the single-call window closes. As a last
   *     resort before giving up, the coarse probe result (if any) resolves,
   *     so the user gets a fix instead of an error after ~75s.
   * @param {Object} [opts] optional PositionOptions overrides
   * @returns {Promise<{lat:number,lng:number,accuracy:number,speed:number|null}>}
   *          Rejects with the GeolocationPositionError for the caller to toast.
   */
 function getUserLocation(opts) {
 opts = opts || {};
 var hiTimeout = typeof opts.timeout === 'number' ? opts.timeout : 30000;
 return new Promise(function (resolve, reject) {
 if (!('geolocation' in global.navigator) || !global.navigator.geolocation) {
 reject(new Error('Geolocation not supported on this device.'));
 return;
 }
 var done = false;
 var coarse = null; // stage-0 probe fix, kept as the last-resort resolve
 var watchId = null, hardStop = null;
 function settle(fn, v) { if (done) return; done = true; if (watchId !== null) global.navigator.geolocation.clearWatch(watchId); if (hardStop !== null) global.clearTimeout(hardStop); fn(v); }
 function extract(pos) { var c = pos.coords || {};
 return { lat: c.latitude, lng: c.longitude,
 accuracy: typeof c.accuracy === 'number' ? c.accuracy : LOW_ACCURACY_LIMIT,
 speed: typeof c.speed === 'number' ? c.speed : null }; }
 function acceptable(fix) { return isFinite(fix.lat) && isFinite(fix.lng) && fix.accuracy <= LOW_ACCURACY_LIMIT; }
 function failMsg(e) {
 var code = e && typeof e.code === 'number' ? e.code : null;
 var msg = code === 1 ? 'Location access denied — allow location for this site, then retry, or pick the spot on the map yourself.'
 : code === 3 ? 'No high-accuracy GPS fix yet — devices without GPS hardware cannot provide one; drag the pin on the map to set it manually.'
 : 'Location services unavailable — drag the pin on the map to set it manually.';
 return code === null ? new Error(msg) : { code: code, message: msg };
 }
 // ── Stage 0: instant cached-fix probe; silently dismisses on any error ──
 global.navigator.geolocation.getCurrentPosition(function (pos) {
 coarse = extract(pos);
 if (acceptable(coarse) && coarse.accuracy <= FAST_RESOLVE_ACCURACY) settle(resolve, coarse);
 }, function () {}, { enableHighAccuracy: false, timeout: FAST_FIX_TIMEOUT, maximumAge: FAST_FIX_MAX_AGE });
 // ── Stage 1 + 2: persistent high-accuracy acquisition ─────────────────
 function startWatch() {
 if (done || watchId !== null) return;
 watchId = global.navigator.geolocation.watchPosition(function (pos) {
 var fix = extract(pos);
 if (acceptable(fix)) settle(resolve, fix);
 },
 function (werr) { settle(reject, failMsg(werr)); },
 { enableHighAccuracy: true, timeout: 60000, maximumAge: 3000 });
 }
 global.navigator.geolocation.getCurrentPosition(function (pos) {
 var hi = extract(pos);
 if (acceptable(hi)) { settle(resolve, hi); return; }
 startWatch(); // real but junk-grade fix — keep waiting for a better one
 }, function (e) {
 var code = e && typeof e.code === 'number' ? e.code : null;
 if (code === 1) { settle(reject, failMsg(e)); return; } // denied: retrying can't help
 // codes 2/3: transient — keep listening high-accuracy
 startWatch();
 if (code === null) settle(reject, failMsg(e)); // non-standard error, don't wait
 }, { enableHighAccuracy: true, timeout: hiTimeout, maximumAge: 15000 });
 hardStop = global.setTimeout(function () {
 if (coarse && acceptable(coarse)) { settle(resolve, coarse); return; } // coarse beats nothing
 settle(reject, failMsg({ code: 3 }));
 }, hiTimeout + 46000);
 });
 }

  /**
   * Continuous high-accuracy location watch.
   * @param {(pos:{lat:number,lng:number,accuracy:number,speed:number|null})=>void} callback
   * @param {(err:GeolocationPositionError)=>void} errorHandler
   * @returns {number} watchId
   */
  function watchUserLocation(callback, errorHandler) {
    return global.navigator.geolocation.watchPosition(function (pos) {
      var c = pos.coords || {};
      callback({
        lat: c.latitude,
        lng: c.longitude,
        accuracy: typeof c.accuracy === 'number' ? c.accuracy : LOW_ACCURACY_LIMIT,
        speed: typeof c.speed === 'number' ? c.speed : null
      });
    }, errorHandler || function () {}, {
      enableHighAccuracy: true,
      timeout: 60000, // was 20000 — a cold GPS chip needs up to a minute; aborting early appeared as a "delay" with no recovery
      maximumAge: 0 // navigation positions must be fresh, not replayed
    });
  }

  /** Stop a watch started with watchUserLocation. */
  function clearWatch(id) {
    if (id !== undefined && id !== null && global.navigator.geolocation) {
      global.navigator.geolocation.clearWatch(id);
    }
  }

  global.SentriGeo = {
    loadBarangays: loadBarangays,
    pointInPolygon: pointInPolygon,
    findBarangay: findBarangay,
    addBarangayOverlay: addBarangayOverlay,
    imusBounds: imusBounds,
    imusLatLngBounds: imusLatLngBounds,
    geoReady: geoReady,
    isInImus: isInImus,
    lockToImus: lockToImus,
    applyImusZoomLimit: applyImusZoomLimit,
    haversine: haversine,
    getUserLocation: getUserLocation,
    watchUserLocation: watchUserLocation,
    clearWatch: clearWatch, // eslint-disable-line object-shorthand
    // read-only constants for callers that need the same thresholds
    LOW_ACCURACY_LIMIT: LOW_ACCURACY_LIMIT
  };
})(window);
