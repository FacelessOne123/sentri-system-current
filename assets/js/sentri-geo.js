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

  /**
   * One-shot high-accuracy geolocation.
   * @param {Object} [opts] optional PositionOptions overrides
   * @returns {Promise<{lat:number,lng:number,accuracy:number,speed:number|null}>}
   *          Rejects with the GeolocationPositionError for the caller to toast.
   */
  function getUserLocation(opts) {
    opts = opts || {};
    return new Promise(function (resolve, reject) {
      if (!('geolocation' in global.navigator) || !global.navigator.geolocation) {
        reject(new Error('Geolocation not supported on this device.'));
        return;
      }
      global.navigator.geolocation.getCurrentPosition(function (pos) {
        var c = pos.coords || {};
        resolve({
          lat: c.latitude,
          lng: c.longitude,
          accuracy: typeof c.accuracy === 'number' ? c.accuracy : LOW_ACCURACY_LIMIT,
          speed: typeof c.speed === 'number' ? c.speed : null
        });
      }, reject, {
        enableHighAccuracy: opts.enableHighAccuracy !== undefined ? opts.enableHighAccuracy : true,
        timeout: opts.timeout !== undefined ? opts.timeout : 15000,
        maximumAge: opts.maximumAge !== undefined ? opts.maximumAge : 0
      });
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
      timeout: 20000,
      maximumAge: 0
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
    haversine: haversine,
    getUserLocation: getUserLocation,
    watchUserLocation: watchUserLocation,
    clearWatch: clearWatch, // eslint-disable-line object-shorthand
    // read-only constants for callers that need the same thresholds
    LOW_ACCURACY_LIMIT: LOW_ACCURACY_LIMIT
  };
})(window);
