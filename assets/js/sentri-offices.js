/**
 * sentri-offices.js — government unit-office database + fuzzy suggestion box
 * for SenTri admin account provisioning.
 *
 * Data sources:
 *   • Curated: standard Imus City offices (RA 7160 departments), Cavite
 *     provincial bodies (Trece Martires), and national field units stationed
 *     in Imus (BFP / PNP / EMS).
 *   • Generated: every barangay in assets/geo/imus-barangays.geojson gets a
 *     "Brgy. <Name> Hall, Imus" entry at runtime — stays in sync with the
 *     map perimeters (95 barangays).
 *
 * Used by admin.php on the Office / Unit Name field as a combobox. Dev-toolkit
 * file like sentri-geo.js; no secrets inside.
 */
(function (global) {
  'use strict';

  var CITY_OFFICES = [
    'Imus City Hall',
    'Imus City DRRMO (Disaster Risk Reduction & Management Office)',
    'Imus City Health Office',
    'Imus City Social Welfare & Development Office',
    'Imus City Engineering Office',
    'Imus City Public Information Office',
    'Imus City Civil Registrar\'s Office',
    'Imus City Assessor\'s Office',
    'Imus City Treasurer\'s Office',
    'Imus City Planning & Development Office',
    'Imus City Legal Office',
    'Imus City Environment & Natural Resources Office (CENRO)',
    'Imus City Human Resources Office',
    'Imus City Budget Office',
    'Imus City Accounting Office',
    'Imus City Veterinary Office'
  ];

  var PROVINCE_OFFICES = [
    'Cavite Provincial Capitol (Trece Martires)',
    'Provincial DRRMO Cavite (PDRRMO)',
    'Cavite Provincial Hospital',
    'PNP Cavite Provincial Office (PPO)',
    'BFP Cavite Provincial Office'
  ];

  var AGENCY_UNITS = {
    bfp: [
      'BFP Imus City Fire Station',
      'BFP Cavite Provincial Office'
    ],
    pnp: [
      'PNP Imus City Police Station',
      'PNP Cavite Provincial Office (PPO)'
    ],
    ems: [
      'Imus Emergency Medical Services (EMS)',
      'Imus City DRRMO Control & Dispatch',
      'Cavite Provincial Mobile EMS'
    ],
    drrmo: [
      'Imus City DRRMO (Disaster Risk Reduction & Management Office)',
      'Imus City DRRMO Control & Dispatch'
    ],
    mdrrmo: [
      'Imus City DRRMO Operations Center',
      'Imus City DRRMO Rescue Team',
      'Provincial DRRMO Cavite (PDRRMO)'
    ],
    hospital: [
      'Imus District Hospital',
      'Imus City Health Office',
      'Cavite Provincial Hospital'
    ],
    other: []
  };

  // ── Barangay halls (generated from the official perimeters) ───────────────
  var _halls = null, _hallsPromise = null;
  function loadBarangayHalls() {
    if (_halls) return Promise.resolve(_halls);
    if (_hallsPromise) return _hallsPromise;
    var geo = global.SentriGeo;
    if (!geo || typeof geo.loadBarangays !== 'function') {
      _halls = [];
      return Promise.resolve(_halls);
    }
    _hallsPromise = geo.loadBarangays().then(function (fc) {
      _halls = (fc.features || []).map(function (f) {
        var name = f.properties && f.properties.name;
        return name ? 'Brgy. ' + name + ' Hall, Imus' : null;
      }).filter(Boolean).sort();
      return _halls;
    }).catch(function () { _halls = []; return _halls; });
    return _hallsPromise;
  }

  function barangayHall(name) { return 'Brgy. ' + name + ' Hall, Imus'; }

  /**
   * Suggestion pool for the Office / Unit Name field.
   * opts: { role: 'barangay'|'lgu'|'first_responder', responderType: string }
   * Returns a Promise<string[]> (barangay halls load async).
   */
  function poolFor(opts) {
    var role = opts && opts.role;
    var t = (opts && opts.responderType) || '';
    if (role === 'barangay') {
      return loadBarangayHalls().then(function (halls) {
        return halls.concat(['Imus City Hall']);
      });
    }
    if (role === 'lgu') {
      return Promise.resolve(CITY_OFFICES.concat(PROVINCE_OFFICES));
    }
    if (role === 'first_responder') {
      var units = (AGENCY_UNITS[t] || []).slice();
      if (t === 'drrmo' || t === 'mdrrmo') {
        units = units.concat(['Imus City DRRMO Operations Center']);
      }
      return loadBarangayHalls().then(function (halls) {
        return units.concat(halls, PROVINCE_OFFICES);
      });
    }
    return Promise.resolve(CITY_OFFICES.slice());
  }

  // ── Fuzzy scoring (exact > startsWith > word-start > contains > sequence) ──
  function norm(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }
  function score(query, text) {
    var q = norm(query), t = norm(text);
    if (!q) return 0.5;
    if (t === q) return 1000;
    if (t.indexOf(q) === 0) return 800 - Math.min(200, t.length - q.length);
    var tw = t.split(' ');
    for (var i = 0; i < tw.length; i++) {
      if (tw[i].indexOf(q) === 0) return 600;
    }
    var idx = t.indexOf(q);
    if (idx > -1) return 400 - Math.min(100, idx);
    var qi = 0, extra = 0;
    for (var j = 0; j < t.length && qi < q.length; j++) {
      if (t.charAt(j) === q.charAt(qi)) qi++; else extra++;
    }
    if (qi === q.length) return Math.max(50, 200 - extra * 4);
    return -1;
  }

  function suggest(query, pool, limit) {
    limit = limit || 9;
    var scored = [];
    for (var i = 0; i < pool.length; i++) {
      var s = score(query, pool[i]);
      if (s >= 0) scored.push([s, pool[i]]);
    }
    scored.sort(function (a, b) { return b[0] - a[0]; });
    var out = [];
    for (var k = 0; k < scored.length && k < limit; k++) out.push(scored[k][1]);
    return out;
  }

  // ── Combobox widget ────────────────────────────────────────────────────────
  function combobox(input, poolProvider) {
    if (input.getAttribute('data-sentri-ac')) return null;
    input.setAttribute('data-sentri-ac', '1');

    var box = document.createElement('ul');
    box.className = 'sentri-ac';
    var st = box.style;
    st.position = 'absolute';
    st.zIndex = '100500';
    st.listStyle = 'none';
    st.margin = '0';
    st.padding = '4px 0';
    st.background = '#fff';
    st.border = '1px solid #e2e8f0';
    st.borderRadius = '10px';
    st.boxShadow = '0 10px 28px rgba(15,23,42,0.18)';
    st.display = 'none';
    st.maxHeight = '220px';
    st.overflowY = 'auto';
    st.fontSize = '0.8rem';
    st.color = '#1f2937';
    st.cursor = 'pointer';
    st.whiteSpace = 'normal';

    var host = input.parentElement;
    if (!host) return null;
    var pos = global.getComputedStyle ? getComputedStyle(host).position : 'static';
    if (pos === 'static') host.style.position = 'relative';
    host.appendChild(box);

    var items = [], active = -1;

    function hide() { box.style.display = 'none'; active = -1; }
    function esc(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function render(list) {
      items = list;
      active = -1;
      if (!list.length) { hide(); return; }
      var html = '';
      for (var i = 0; i < list.length; i++) {
        html += '<li data-v="' + esc(list[i]) + '" style="padding:7px 12px;">' + esc(list[i]) + '</li>';
      }
      box.innerHTML = html;
      box.style.top = (input.offsetHeight + 2) + 'px';
      box.style.left = '0';
      box.style.right = '0';
      box.style.display = 'block';
    }
    function highlight(el) {
      for (var i = 0; i < box.children.length; i++) {
        box.children[i].style.background = (el && box.children[i] === el) ? '#dcfce7' : '';
      }
    }
    function pick(v) {
      input.value = v;
      input.dispatchEvent(new global.Event('input', { bubbles: true }));
      hide();
    }

    box.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var t = e.target;
      while (t && t !== box && t.tagName !== 'LI') t = t.parentNode;
      if (t && t.tagName === 'LI') pick(t.getAttribute('data-v'));
    });
    input.addEventListener('input', function () {
      var q = input.value;
      if (!q) { hide(); return; }
      Promise.resolve(poolProvider()).then(function (pool) {
        render(suggest(q, pool));
      }).catch(function () { hide(); });
    });
    input.addEventListener('keydown', function (e) {
      if (box.style.display === 'none' || !items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        active = Math.min(items.length - 1, active + 1);
        highlight(box.children[active]);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(0, active - 1);
        highlight(box.children[active]);
      } else if (e.key === 'Enter') {
        if (active >= 0) { e.preventDefault(); pick(items[active]); }
      } else if (e.key === 'Escape') {
        hide();
      }
    });
    input.addEventListener('blur', function () { global.setTimeout(hide, 150); });

    return { hide: hide };
  }

  global.SentriOffices = {
    CITY_OFFICES: CITY_OFFICES,
    PROVINCE_OFFICES: PROVINCE_OFFICES,
    AGENCY_UNITS: AGENCY_UNITS,
    barangayHall: barangayHall,
    loadBarangayHalls: loadBarangayHalls,
    poolFor: poolFor,
    suggest: suggest,
    combobox: combobox
  };
})(window);
