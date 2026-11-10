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
    if (_fcCache) return Promise.resolve(_fcCache);
    if (global._SENTRI_GEOJSON) {
      _fcCache = global._SENTRI_GEOJSON;
      return Promise.resolve(_fcCache);
    }
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

  /** Escape a string for safe use as HTML text. */
  function _escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Minimum polygon area (km^2) a barangay must have for its label to be
   * drawn, by map zoom. Imus has 95 barangays, many of them slivers in the
   * Poblacion / Bayan Luma core, so labelling every one of them at the
   * city-wide zoom produces an unreadable wall of text. The thresholds step
   * down as the user zooms in, and every name is labelled by z17.
   */
  var LABEL_AREA_BY_ZOOM = [
    { maxZoom: 13, minKm2: 1.00 },
    { maxZoom: 14, minKm2: 0.50 },
    { maxZoom: 15, minKm2: 0.25 },
    { maxZoom: 16, minKm2: 0.10 },
    { maxZoom: Infinity, minKm2: 0 }
  ];

  /**
   * Label appearance by zoom. The type grows as the user zooms in (a 10px
   * name is unreadable at street level) and the chip behind it softens, so
   * the map is still readable when 95 names are showing at once.
   */
  var LABEL_STYLE_BY_ZOOM = [
    { maxZoom: 13, fontSize: 11, chip: 'rgba(255,255,255,.95)', border: 'rgba(10,61,98,.30)' },
    { maxZoom: 14, fontSize: 12, chip: 'rgba(255,255,255,.93)', border: 'rgba(10,61,98,.26)' },
    { maxZoom: 15, fontSize: 13, chip: 'rgba(255,255,255,.90)', border: 'rgba(10,61,98,.22)' },
    { maxZoom: 16, fontSize: 13, chip: 'rgba(255,255,255,.87)', border: 'rgba(10,61,98,.18)' },
    { maxZoom: Infinity, fontSize: 14, chip: 'rgba(255,255,255,.85)', border: 'rgba(10,61,98,.16)' }
  ];

  function _barangayLabelThreshold(zoom) {
    for (var i = 0; i < LABEL_AREA_BY_ZOOM.length; i++) {
      if (zoom <= LABEL_AREA_BY_ZOOM[i].maxZoom) return LABEL_AREA_BY_ZOOM[i].minKm2;
    }
    return 0;
  }

  function _labelStyle(zoom) {
    for (var i = 0; i < LABEL_STYLE_BY_ZOOM.length; i++) {
      if (zoom <= LABEL_STYLE_BY_ZOOM[i].maxZoom) return LABEL_STYLE_BY_ZOOM[i];
    }
    return LABEL_STYLE_BY_ZOOM[LABEL_STYLE_BY_ZOOM.length - 1];
  }

  /** Rough area of a barangay polygon in km^2 (planar, at the Imus latitude). */
  function _barangayAreaKm2(feature) {
    var polygons = _geometryPolygons(feature.geometry);
    var total = 0;
    for (var p = 0; p < polygons.length; p++) {
      var ring = polygons[p][0];
      var a = 0;
      for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        a += (ring[j][0] * ring[i][1]) - (ring[i][0] * ring[j][1]);
      }
      total += Math.abs(a / 2);
    }
    return total * 111.32 * 111.32 * Math.cos(14.4 * Math.PI / 180);
  }

  // ── Pole of inaccessibility (polylabel) ──────────────────────────────────
  // The point inside a polygon that is furthest from its boundary: the
  // "visual middle" that map labels are placed on. Unlike a plain centroid
  // it is guaranteed to lie inside the shape, and unlike the bbox centre it
  // does not drift onto the edge of an L-shaped or crescent barangay.

  /** Squared distance from a point to a line segment. */
  function _segDistSq(px, py, p, q) {
    var dx = q[0] - p[0], dy = q[1] - p[1];
    // A duplicate vertex is not a segment - ignoring it matters here,
    // because the OSM-derived perimeter set contains 133 of them and a
    // zero-length segment would otherwise report a distance of 0 for every
    // point on the map.
    if (dx === 0 && dy === 0) return Infinity;
    var t = ((px - p[0]) * dx + (py - p[1]) * dy) / (dx * dx + dy * dy);
    var x, y;
    if (t <= 0) { x = p[0]; y = p[1]; }          // closest point is the start
    else if (t >= 1) { x = q[0]; y = q[1]; }     // closest point is the end
    else { x = p[0] + t * dx; y = p[1] + t * dy; } // perpendicular foot
    dx = x - px; dy = y - py;
    return dx * dx + dy * dy;
  }

  /**
   * Signed distance from a point to the polygon boundary.
   * Positive inside, negative outside.
   */
  function _pointToPolygonDist(x, y, ring) {
    var inside = false;
    var minDistSq = Infinity;
    var n = ring.length;
    for (var i = 0, j = n - 1; i < n; j = i++) {
      var p = ring[i], q = ring[j];
      if (((p[1] > y) !== (q[1] > y)) &&
        (x < (q[0] - p[0]) * (y - p[1]) / (q[1] - p[1]) + p[0])) {
        inside = !inside;
      }
      var d = _segDistSq(x, y, p, q);
      if (d < minDistSq) minDistSq = d;
    }
    return Math.sqrt(minDistSq) * (inside ? 1 : -1);
  }

  /** Area-weighted centroid of a ring. */
  function _ringCentroid(ring) {
    var a = 0, x = 0, y = 0;
    var n = ring.length;
    for (var i = 0, j = n - 1; i < n; j = i++) {
      var f = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
      x += (ring[j][0] + ring[i][0]) * f;
      y += (ring[j][1] + ring[i][1]) * f;
      a += f;
    }
    if (a === 0) return null;
    return [x / (3 * a), y / (3 * a)];
  }

  /**
   * Pole of inaccessibility of a ring, as [lng, lat].
   * Grid search for the deepest cell, then a bounded hill-climb.
   * @param {Array<[number,number]>} ring outer ring, [[lng,lat],...]
   * @param {number} [precision] target resolution in degrees (~1e-5 = 1 m)
   * @returns {Array<number>|null}
   */
  function _polylabel(ring, precision) {
    if (!ring || ring.length < 3) return null;
    precision = precision || 1e-5;

    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (var i = 0; i < ring.length; i++) {
      var p = ring[i];
      if (p[0] < minX) minX = p[0];
      if (p[0] > maxX) maxX = p[0];
      if (p[1] < minY) minY = p[1];
      if (p[1] > maxY) maxY = p[1];
    }
    var width = maxX - minX, height = maxY - minY;
    if (width === 0 && height === 0) return [minX, minY];
    var cellSize = Math.min(width, height);
    if (cellSize <= 0) cellSize = Math.max(width, height);
    if (cellSize <= 0) return [minX, minY];
    // A very thin polygon would otherwise explode the seed grid (the step
    // is the smaller side), so the grid is capped at 64 cells per axis.
    cellSize = Math.max(cellSize, Math.max(width, height) / 64);

    // Candidate cells, kept in a simple array; we always take the one with
    // the largest distance to the boundary.
    var queue = [];
    function push(x, y, h) {
      queue.push({ x: x, y: y, h: h, d: _pointToPolygonDist(x, y, ring), max: 0 });
      queue[queue.length - 1].max = queue[queue.length - 1].d + h * Math.SQRT2;
    }
    function pop() {
      var best = 0;
      for (var k = 1; k < queue.length; k++) if (queue[k].d > queue[best].d) best = k;
      return queue.splice(best, 1)[0];
    }

    var h = cellSize / 2;
    for (var x = minX; x < maxX; x += cellSize) {
      for (var y = minY; y < maxY; y += cellSize) {
        push(x + h, y + h, h);
      }
    }

    // Seed with the area centroid and the bbox centre - both are usually
    // close to the answer and make the search converge much faster.
    var bestCell = { d: -Infinity, x: 0, y: 0, h: 0 };
    var seeds = [_ringCentroid(ring), [minX + width / 2, minY + height / 2]];
    for (var s = 0; s < seeds.length; s++) {
      var seed = seeds[s];
      if (!seed) continue;
      var sd = _pointToPolygonDist(seed[0], seed[1], ring);
      if (sd > bestCell.d) bestCell = { d: sd, x: seed[0], y: seed[1], h: 0 };
    }

    var guard = 0;
    while (queue.length && guard++ < 20000) {
      var cell = pop();
      if (cell.d > bestCell.d) bestCell = { d: cell.d, x: cell.x, y: cell.y, h: cell.h };
      // No chance of beating the current best from here.
      if (cell.max - bestCell.d <= precision) continue;
      var hh = cell.h / 2;
      if (hh <= precision) continue;
      push(cell.x - hh, cell.y - hh, hh);
      push(cell.x + hh, cell.y - hh, hh);
      push(cell.x - hh, cell.y + hh, hh);
      push(cell.x + hh, cell.y + hh, hh);
    }    return [bestCell.x, bestCell.y];
  }

  /**
   * The anchor a barangay's label should sit on: the pole of inaccessibility
   * of the largest polygon part, so the name lands in the visual middle of
   * the area. Falls back to the geojson's own `center` when the geometry
   * cannot be used.
   * @param {geojson.Feature} feature
   * @returns {Array<number>} [lng, lat]
   */
  function _barangayLabelAnchor(feature) {
    var polygons = _geometryPolygons(feature && feature.geometry);
    var best = null, bestD = -Infinity;
    for (var p = 0; p < polygons.length; p++) {
      var ring = polygons[p][0];
      if (!ring || ring.length < 3) continue;
      var pt = _polylabel(ring);
      if (!pt) continue;
      var d = _pointToPolygonDist(pt[0], pt[1], ring);
      if (d > bestD) { bestD = d; best = pt; }
    }
    if (best) {
      // A point inside a hole would read as "outside the barangay".
      var holes = polygons.length ? polygons[0].slice(1) : [];
      for (var h = 0; h < holes.length; h++) {
        if (_pointToPolygonDist(best[0], best[1], holes[h]) > 0) {
          var c = _ringCentroid(polygons[0][0]);
          if (c) return c;
        }
      }
      return best;
    }
    var props = (feature && feature.properties) || {};
    return (props.center && props.center.length >= 2) ? props.center : null;
  }

  /**
   * Nudge the barangay labels away from the report pins.
   *
   * The names and the incident pins cluster in the same places (the
   * Poblacion / Bayan Luma core), so a label drawn on the anchor ends up
   * hidden behind a pin. Each label is moved to the nearest free spot
   * instead - but only to a spot that is still inside its own barangay, so
   * a name never ends up on the wrong side of a boundary.
   *
   * @param {L.Map} map
   * @param {Array} placed the label records ({marker, anchor, ring})
   * @param {Array<[number,number]>} avoid [lat,lng] points to keep clear of
   */
  function _nudgeLabels(map, placed, avoid) {
    if (!placed || !placed.length) return;
    var PIN_HALF_W = 15;  // the report pin graphic is 30 x 38, anchored at
    var PIN_HEIGHT = 38;  // its bottom tip, so it covers the area above
    var PIN_MARGIN = 4;   // the anchor point
    var pins = [];
    if (avoid && avoid.length) {
      for (var i = 0; i < avoid.length; i++) {
        // avoid[] holds [lat, lng] pairs - Leaflet needs a real LatLng here.
        var a = avoid[i];
        var pp = map.latLngToContainerPoint(L.latLng(a[0], a[1]));
        pins.push({
          l: pp.x - PIN_HALF_W - PIN_MARGIN,
          r: pp.x + PIN_HALF_W + PIN_MARGIN,
          t: pp.y - PIN_HEIGHT - PIN_MARGIN,
          b: pp.y + PIN_MARGIN
        });
      }
    }

    // Big barangays are placed first so they get first pick of the best
    // spot; the small ones then fit around them.
    var order = placed.slice().sort(function (x, y) { return y.km2 - x.km2; });
    var taken = []; // rects already occupied by a placed label

    for (var n = 0; n < order.length; n++) {
      var it = order[n];
      var el = it.marker.getElement();
      var span = el && el.querySelector('span');
      if (!span) continue;
      if (it.marker.getOpacity && it.marker.getOpacity() === 0) continue;
      var w = span.offsetWidth, h = span.offsetHeight;
      if (!w || !h) continue;

      var base = map.latLngToContainerPoint(it.anchor);
      var chosen = null;
      for (var ring = 0; ring <= 56 && !chosen; ring += 8) {
        var cands = ring === 0
          ? [[0, 0]]
          : [[0, -ring], [0, ring], [-ring, 0], [ring, 0],
             [-ring, -ring], [ring, -ring], [-ring, ring], [ring, ring]];
        for (var c = 0; c < cands.length; c++) {
          var cx = base.x + cands[c][0], cy = base.y + cands[c][1];
          // The label must stay on its own barangay.
          if (it.ring) {
            var target = map.containerPointToLatLng(L.point(cx, cy));
            if (_pointToPolygonDist(target.lng, target.lat, it.ring) <= 0) continue;
          }
          var clash = false;
          for (var k = 0; k < pins.length; k++) {
            var pr = pins[k];
            if (cx + w / 2 > pr.l && cx - w / 2 < pr.r &&
                cy + h / 2 > pr.t && cy - h / 2 < pr.b) { clash = true; break; }
          }
          if (!clash) {
            for (var t = 0; t < taken.length; t++) {
              var tr = taken[t];
              if (cx + w / 2 > tr.l && cx - w / 2 < tr.r &&
                  cy + h / 2 > tr.t && cy - h / 2 < tr.b) { clash = true; break; }
            }
          }
          if (!clash) { chosen = cands[c]; break; }
        }
      }
      if (!chosen) chosen = [0, 0];
      var fx = base.x + chosen[0], fy = base.y + chosen[1];
      if (chosen[0] || chosen[1]) {
        it.marker.setLatLng(map.containerPointToLatLng(L.point(fx, fy)));
      } else {
        it.marker.setLatLng(L.latLng(it.anchor[0], it.anchor[1]));
      }
      taken.push({ l: fx - w / 2, r: fx + w / 2, t: fy - h / 2, b: fy + h / 2 });
    }
  }

  /**
   * Place a permanent name label on every barangay of the perimeter set.
   *
   * Labels are anchored on the pole of inaccessibility of the barangay
   * polygon, so the name always sits in the visual middle of its own area.
   * The geojson's stored `center` was not usable for this: it sits up to
   * 940 m away from the true middle of the largest barangays, which put the
   * name visibly off-centre.
   *
   * They are drawn as non-interactive markers in the marker pane, so they
   * never intercept a click meant for the boundary underneath.
   *
   * Which names are drawn depends on the zoom (see LABEL_AREA_BY_ZOOM): at
   * the city-wide view only the large barangays are named, and the rest
   * appear as the user zooms in. The type size and the chip behind it also
   * follow the zoom (see LABEL_STYLE_BY_ZOOM).
   *
   * @param {L.Map} map
   * @param {geojson.FeatureCollection} fc
   * @param {Object} [opts] {onlyName?:string, fontSize?:number,
   *                          allAt?:boolean  ignore the zoom thresholds}
   * @returns {L.LayerGroup}
   */
  function _addBarangayLabels(map, fc, opts) {
    opts = opts || {};
    var group = L.layerGroup();
    var placed = [];
    for (var f = 0; f < fc.features.length; f++) {
      var props = fc.features[f].properties || {};
      var name = props.name;
      if (!name) continue;
      if (opts.onlyName && name !== opts.onlyName) continue;
      var anchor = _barangayLabelAnchor(fc.features[f]);
      if (!anchor) continue;
      var polys = _geometryPolygons(fc.features[f].geometry);
      var html = '<div style="position:absolute;left:0;top:0;transform:translate(-50%,-50%);'
        + 'white-space:nowrap;pointer-events:none;">'
        + '<span style="display:inline-block;">' + _escHtml(name) + '</span></div>';
      var marker = L.marker([anchor[1], anchor[0]], {
        icon: L.divIcon({ className: '', html: html, iconSize: [0, 0], iconAnchor: [0, 0] }),
        interactive: false,
        keyboard: false,
        riseOnHover: false,
        // Above the report pins: a name is easier to read than a pin head,
        // and the chip is pointer-events:none so the pin stays clickable.
        zIndexOffset: 1000
      }).addTo(group);
      placed.push({
        marker: marker,
        km2: _barangayAreaKm2(fc.features[f]),
        anchor: [anchor[1], anchor[0]],
        ring: (polys.length && polys[0].length) ? polys[0][0] : null
      });
    }
    group.addTo(map);

    var lastAvoid = [];

    // Show, hide and re-style the names as the zoom level changes.
    function applyZoom() {
      var zoom = map.getZoom();
      var threshold = opts.allAt ? 0 : _barangayLabelThreshold(zoom);
      var style = _labelStyle(zoom);
      var fontSize = opts.fontSize != null ? opts.fontSize : style.fontSize;
      for (var i = 0; i < placed.length; i++) {
        var visible = placed[i].km2 >= threshold;
        placed[i].marker.setOpacity(visible ? 1 : 0);
        if (!visible) continue;
        var el = placed[i].marker.getElement();
        var span = el && el.querySelector('span');
        if (!span) continue;
        span.style.background = style.chip;
        span.style.border = '1px solid ' + style.border;
        span.style.borderRadius = '7px';
        span.style.padding = '1px 6px';
        span.style.boxShadow = '0 1px 3px rgba(6,36,68,.28)';
        span.style.fontFamily = "'Poppins',sans-serif";
        span.style.fontSize = fontSize + 'px';
        span.style.fontWeight = '700';
        span.style.color = '#0a3d62';
        span.style.letterSpacing = '.1px';
        span.style.lineHeight = '1.4';
      }
      _nudgeLabels(map, placed, lastAvoid);
    }
    map.on('zoomend', applyZoom);
    // invalidateSize() shifts every pixel position, so the placement has to
    // be recomputed then too - otherwise the labels drift off the spots
    // they were nudged to.
    map.on('resize', applyZoom);
    map._sentriBarangayLabels = {
      placed: placed,
      avoid: function (points) {
        lastAvoid = points || [];
        _nudgeLabels(map, placed, lastAvoid);
      }
    };
    applyZoom();
    return group;
  }

  /**
   * Draw thin translucent barangay outlines under map pins.
   * @param {L.Map} map Leaflet map
   * @param {Object} [opts] {onlyName?:string, style?:(feat)=>LeafletPathOptions,
   *                         onEach?: (feature, layer)=>void,
   *                         labels?:boolean|Object  draw the barangay name on
   *                          the map (default false). Object form accepts
   *                          {onlyName?, opacity?, fontSize?}.}
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
      if (opts.labels) {
        _addBarangayLabels(map, fc, (typeof opts.labels === 'object') ? opts.labels : {});
      }
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
 // Nothing live - hand back the last known fix so the caller can offer it
 // as a labelled fallback instead of showing an empty map.
 var cached = readLastFix();
 var err = failMsg({ code: 3 });
 if (cached) {
 err.fallback = cached;
 err.message = err.message + ' Using your last known location instead.';
 }
 settle(reject, err);
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
      var fix = {
        lat: c.latitude,
        lng: c.longitude,
        accuracy: typeof c.accuracy === 'number' ? c.accuracy : LOW_ACCURACY_LIMIT,
        speed: typeof c.speed === 'number' ? c.speed : null
      };
      storeLastFix(fix);
      callback(fix);
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

  // ── Last-known-location cache ────────────────────────────────────────────
  // A dropped connection or a refused geolocation permission must not leave
  // the user with no position at all. Every good fix is cached locally and
  // offered back as a clearly-labelled fallback.
  var LAST_FIX_KEY = 'sentri:lastFix:v1';
  var LAST_FIX_MAX_AGE = 7 * 24 * 60 * 60 * 1000; // 7 days

  /**
   * The cache is scoped per account. Two people sharing a browser (a family
   * tablet, a responder kiosk) must never see each other's position.
   */
  function lastFixKey() {
    var scope = 'anon';
    try {
      var meta = document.querySelector('meta[name="sentri-user"]');
      if (meta && meta.content) scope = meta.content;
    } catch (e) {}
    return LAST_FIX_KEY + ':' + scope;
  }

  function storeLastFix(fix) {
    if (!fix || !isFinite(fix.lat) || !isFinite(fix.lng)) return;
    try {
      var payload = {
        lat: fix.lat,
        lng: fix.lng,
        accuracy: typeof fix.accuracy === 'number' ? fix.accuracy : null,
        at: Date.now()
      };
      global.localStorage.setItem(lastFixKey(), JSON.stringify(payload));
    } catch (e) { /* storage may be unavailable (private mode) */ }
  }

  /**
   * Read the cached last-known fix.
   * @param {number} [maxAgeMs] oldest acceptable age in ms (default 7 days)
   * @returns {{lat:number,lng:number,accuracy:?number,at:number,ageMs:number}|null}
   */
  function readLastFix(maxAgeMs) {
    var limit = typeof maxAgeMs === 'number' ? maxAgeMs : LAST_FIX_MAX_AGE;
    try {
      var raw = global.localStorage.getItem(lastFixKey());
      if (!raw) return null;
      var d = JSON.parse(raw);
      if (!d || !isFinite(d.lat) || !isFinite(d.lng) || !d.at) return null;
      var age = Date.now() - d.at;
      if (age < 0 || age > limit) return null;
      return {
        lat: d.lat,
        lng: d.lng,
        accuracy: typeof d.accuracy === 'number' ? d.accuracy : null,
        at: d.at,
        ageMs: age
      };
    } catch (e) { return null; }
  }

  function clearLastFix() {
    try { global.localStorage.removeItem(lastFixKey()); } catch (e) {}
  }

  /**
   * Keep-alive watcher that reconnects after transient failures.
   *
   * A single geolocation error used to end tracking for the whole session, so
   * a brief tunnel, a screen lock or a dropped Wi-Fi left the app permanently
   * without a position. This retries with backoff and re-arms when the tab
   * becomes visible again.
   *
   * @param {(pos:{lat,lng,accuracy,speed,source:string})=>void} callback
   * @param {(err:Object)=>void} errorHandler called with the last error
   * @param {Object} [opts] {retryDelays:[ms], source:'browser'}
   */
  function watchUserLocationResilient(callback, errorHandler, opts) {
    opts = opts || {};
    var delays = opts.retryDelays || [5000, 15000, 30000, 60000];
    var attempt = 0;
    var watchId = null;
    var timer = null;
    var stopped = false;

    function arm() {
      if (stopped) return;
      if (watchId !== null) { clearWatch(watchId); watchId = null; }
      watchId = watchUserLocation(function (pos) {
        attempt = 0;
        pos.source = opts.source || 'browser';
        storeLastFix(pos);
        callback(pos);
      }, function (err) {
        if (stopped) return;
        if (typeof errorHandler === 'function') errorHandler(err);
        // Permission denial is permanent - do not hammer the user with prompts.
        if (err && err.code === 1) { stopped = true; return; }
        var delay = delays[Math.min(attempt, delays.length - 1)];
        attempt++;
        if (timer) global.clearTimeout(timer);
        timer = global.setTimeout(arm, delay);
      });
    }

    arm();

    // Re-arm when the tab becomes visible again: mobile browsers often stop
    // delivering positions while backgrounded.
    function onVisible() {
      if (stopped) return;
      if (global.document && global.document.visibilityState === 'visible') arm();
    }
    if (global.document && global.document.addEventListener) {
      global.document.addEventListener('visibilitychange', onVisible);
    }

    return {
      stop: function () {
        stopped = true;
        if (timer) global.clearTimeout(timer);
        if (watchId !== null) clearWatch(watchId);
        if (global.document && global.document.removeEventListener) {
          global.document.removeEventListener('visibilitychange', onVisible);
        }
      }
    };
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
    barangayLabelAnchor: _barangayLabelAnchor,
    avoidLabelOverlap: function (map, points) {
      var ctrl = map && map._sentriBarangayLabels;
      if (ctrl && typeof ctrl.avoid === 'function') ctrl.avoid(points);
    },
    lockToImus: lockToImus,
    applyImusZoomLimit: applyImusZoomLimit,
    haversine: haversine,
    getUserLocation: getUserLocation,
    watchUserLocation: watchUserLocation,
    watchUserLocationResilient: watchUserLocationResilient,
    clearWatch: clearWatch, // eslint-disable-line object-shorthand
    storeLastFix: storeLastFix,
    readLastFix: readLastFix,
    clearLastFix: clearLastFix,
    LAST_FIX_KEY: LAST_FIX_KEY,
    // read-only constants for callers that need the same thresholds
    LOW_ACCURACY_LIMIT: LOW_ACCURACY_LIMIT
  };
})(window);
