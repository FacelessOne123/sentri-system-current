/*!
 * sentri-address-autocomplete.js
 * Barangay-scoped address suggestion box (advisory only).
 *
 * Usage:
 *   SentriAddress.attach(document.getElementById('gateAddress'), {
 *     getBarangay: function () { return document.getElementById('gateBarangay').value; },
 *     barangayEl: document.getElementById('gateBarangay'),
 *     onSelect: function (term) { ... } // optional
 *   });
 *
 * Suggestions come from api/address_terms.php and are cached per barangay.
 * Users are never forced to pick one - free-form addresses remain valid.
 */
(function () {
  'use strict';

  if (window.SentriAddress) return;

  var SCRIPT = document.currentScript || (function () {
    var s = document.getElementsByTagName('script');
    return s[s.length - 1];
  })();
  var ENDPOINT;
  try {
    ENDPOINT = new URL('../../api/address_terms.php', SCRIPT.src).href;
  } catch (e) {
    ENDPOINT = '../../api/address_terms.php';
  }

  var TYPE_LABEL = {
    street: 'Street', road: 'Road', subdivision: 'Subdivision',
    phase: 'Phase', block: 'Block', purok: 'Purok', sitio: 'Sitio',
    landmark: 'Landmark', other: 'Other'
  };
  var TYPE_ICON = {
    street: 'fa-road', road: 'fa-road', subdivision: 'fa-house-chimney',
    phase: 'fa-layer-group', block: 'fa-table-cells-large',
    purok: 'fa-people-roof', sitio: 'fa-location-dot',
    landmark: 'fa-map-pin', other: 'fa-tag'
  };

  var cache = {}; // barangay -> {terms:[], promise:Promise}
  var stylesInjected = false;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function norm(s) {
    return String(s || '').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, ' ').trim();
  }

  function injectStyles() {
    if (stylesInjected || document.getElementById('sentri-address-css')) return;
    stylesInjected = true;
    var css = ''
      + '.sa-wrap{position:relative;width:100%;}'
      + '.sa-drop{position:fixed;z-index:2147483000;'
      + 'background:var(--card,#fff);border:1.5px solid #93c5fd;border-radius:10px;'
      + 'max-height:240px;overflow-y:auto;box-shadow:0 12px 34px rgba(0,0,0,.18);'
      + 'font-family:inherit;text-align:left;}'
      + '.sa-head{padding:7px 13px;font-size:.7rem;font-weight:700;color:#64748b;'
      + 'text-transform:uppercase;letter-spacing:.5px;background:#f8fafc;'
      + 'border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:1;}'
      + '.sa-item{display:flex;align-items:center;justify-content:space-between;gap:10px;'
      + 'padding:9px 13px;font-size:.84rem;color:#1e293b;cursor:pointer;'
      + 'border-bottom:1px solid #f1f5f9;}'
      + '.sa-item:last-of-type{border-bottom:none;}'
      + '.sa-item:hover,.sa-item.active{background:#eff6ff;color:#1d4ed8;}'
      + '.sa-term{display:flex;align-items:center;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.sa-term i{color:#3b82f6;font-size:.8rem;margin-right:8px;flex-shrink:0;}'
      + '.sa-badge{flex-shrink:0;font-size:.68rem;font-weight:700;color:#64748b;'
      + 'background:#f1f5f9;padding:2px 8px;border-radius:6px;white-space:nowrap;}'
      + '.sa-empty{padding:12px 14px;font-size:.8rem;color:#94a3b8;font-style:italic;text-align:center;}'
      + '.sa-foot{padding:6px 13px;font-size:.64rem;color:#94a3b8;text-align:right;'
      + 'background:#f8fafc;border-top:1px solid #e2e8f0;}'
      + 'body.dark .sa-drop{background:#1e293b;border-color:#3b82f6;}'
      + 'body.dark .sa-head{background:#0f172a;border-bottom-color:#334155;color:#94a3b8;}'
      + 'body.dark .sa-item{color:#f1f5f9;border-bottom-color:#334155;}'
      + 'body.dark .sa-item:hover,body.dark .sa-item.active{background:#1e3a8a;color:#93c5fd;}'
      + 'body.dark .sa-badge{background:#334155;color:#94a3b8;}'
      + 'body.dark .sa-foot{background:#0f172a;border-top-color:#334155;}';
    var st = document.createElement('style');
    st.id = 'sentri-address-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  function fetchTerms(barangay) {
    if (!barangay) return Promise.resolve([]);
    var hit = cache[barangay];
    if (hit && hit.terms) return Promise.resolve(hit.terms);
    if (hit && hit.promise) return hit.promise;

    var url = ENDPOINT + '?barangay=' + encodeURIComponent(barangay);
    var p = fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var list = (d && d.status === 'success' && Array.isArray(d.terms)) ? d.terms : [];
        cache[barangay] = { terms: list };
        return list;
      })
      .catch(function () {
        cache[barangay] = { terms: [] };
        return [];
      });
    cache[barangay] = { promise: p };
    return p;
  }

  function attach(input, opts) {
    opts = opts || {};
    if (!input || input.dataset.sentriAddress === '1') return null;
    input.dataset.sentriAddress = '1';
    injectStyles();

    var wrap = document.createElement('div');
    wrap.className = 'sa-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var drop = document.createElement('div');
    drop.className = 'sa-drop';
    drop.style.display = 'none';
    drop.setAttribute('role', 'listbox');
    document.body.appendChild(drop);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    var activeIdx = -1;
    var current = [];
    var seq = 0;

    function getBrgy() {
      try {
        var b = opts.getBarangay ? opts.getBarangay() : '';
        return String(b || '').trim();
      } catch (e) { return ''; }
    }

    function close() {
      drop.style.display = 'none';
      input.setAttribute('aria-expanded', 'false');
      activeIdx = -1;
    }

    // The dropdown lives on <body> so it is never clipped by a modal's
    // overflow container. Anchor it to the input, flipping above when the
    // space below is too small.
    function positionDrop() {
      var r = input.getBoundingClientRect();
      var h = Math.min(drop.scrollHeight || 240, 240);
      var spaceBelow = window.innerHeight - r.bottom - 10;
      var spaceAbove = r.top - 10;
      var top = (spaceBelow < h && spaceAbove > spaceBelow)
        ? r.top - h - 2
        : r.bottom + 2;
      var width = Math.max(160, r.width);
      drop.style.width = width + 'px';
      drop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8)) + 'px';
      drop.style.top = Math.max(8, Math.min(top, window.innerHeight - 40)) + 'px';
    }

    function pick(t) {
      if (!t) return;
      input.value = t.term + ', ';
      try {
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
      } catch (e) {}
      close();
      if (typeof opts.onSelect === 'function') {
        try { opts.onSelect(t); } catch (e) {}
      }
      try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
    }

    function render(list, query) {
      current = list || [];
      activeIdx = -1;
      var brgy = getBrgy();
      var html = '<div class="sa-head">'
        + (query ? 'Matching suggestions' : 'Suggestions for ' + esc(brgy))
        + '</div>';
      if (!current.length) {
        html += '<div class="sa-empty">'
          + (query
            ? 'No matching suggestion — you can still type your address.'
            : 'No suggestions for this barangay yet — you can still type your address.')
          + '</div>';
      } else {
        current.forEach(function (t, i) {
          html += '<div class="sa-item" role="option" data-idx="' + i + '">'
            + '<span class="sa-term"><i class="fas ' + (TYPE_ICON[t.type] || 'fa-map-pin') + '"></i>'
            + esc(t.term) + '</span>'
            + '<span class="sa-badge">' + esc(TYPE_LABEL[t.type] || 'Other') + '</span>'
            + '</div>';
        });
      }
      html += '<div class="sa-foot">Address suggestions © OpenStreetMap contributors (ODbL)</div>';
      drop.innerHTML = html;
      drop.style.display = 'block';
      positionDrop();
      input.setAttribute('aria-expanded', 'true');
      Array.prototype.forEach.call(drop.querySelectorAll('.sa-item'), function (el) {
        el.addEventListener('mousedown', function (e) {
          e.preventDefault();
          pick(current[parseInt(el.dataset.idx, 10)]);
        });
      });
    }

    function show(forceHint) {
      var brgy = getBrgy();
      if (!brgy) {
        if (forceHint) {
          drop.innerHTML = '<div class="sa-empty">Select a barangay first to see street suggestions.</div>'
            + '<div class="sa-foot">Address suggestions © OpenStreetMap contributors (ODbL)</div>';
          drop.style.display = 'block';
          positionDrop();
          input.setAttribute('aria-expanded', 'true');
        } else {
          close();
        }
        return;
      }

      var mySeq = ++seq;
      var query = input.value.trim();
      var qn = norm(query);

      fetchTerms(brgy).then(function (all) {
        if (mySeq !== seq || getBrgy() !== brgy) return;
        var list;
        if (!qn) {
          list = all.slice(0, 50);
        } else {
          var starts = [], contains = [];
          all.forEach(function (t) {
            var key = norm(t.term);
            var parent = norm(t.parent || '');
            if (key.indexOf(qn) === 0) starts.push(t);
            else if (key.indexOf(qn) !== -1 || parent.indexOf(qn) !== -1) contains.push(t);
          });
          list = starts.concat(contains).slice(0, 50);
        }
        render(list, !!qn);
      });
    }

    function moveActive(delta) {
      var items = drop.querySelectorAll('.sa-item');
      if (!items.length) return;
      activeIdx += delta;
      if (activeIdx < 0) activeIdx = items.length - 1;
      if (activeIdx >= items.length) activeIdx = 0;
      Array.prototype.forEach.call(items, function (el, i) {
        el.classList.toggle('active', i === activeIdx);
      });
      if (items[activeIdx] && items[activeIdx].scrollIntoView) {
        items[activeIdx].scrollIntoView({ block: 'nearest' });
      }
    }

    input.addEventListener('focus', function () { show(true); });
    input.addEventListener('input', function () { show(false); });
    input.addEventListener('keydown', function (e) {
      var open = drop.style.display !== 'none';
      if (e.key === 'ArrowDown') {
        if (!open) { show(true); } else { e.preventDefault(); moveActive(1); }
      } else if (e.key === 'ArrowUp') {
        if (open) { e.preventDefault(); moveActive(-1); }
      } else if (e.key === 'Enter') {
        if (open && activeIdx >= 0 && current[activeIdx]) {
          e.preventDefault();
          pick(current[activeIdx]);
        }
      } else if (e.key === 'Escape') {
        if (open) { e.preventDefault(); close(); }
      } else if (e.key === 'Tab') {
        close();
      }
    });

    document.addEventListener('mousedown', function (e) {
      if (!wrap.contains(e.target) && !drop.contains(e.target)) close();
    });
    function onScrollResize() {
      if (drop.style.display !== 'none') positionDrop();
    }
    window.addEventListener('scroll', onScrollResize, true);
    window.addEventListener('resize', onScrollResize);

    if (opts.barangayEl) {
      opts.barangayEl.addEventListener('change', function () {
        close();
        if (input.value.trim()) show(false);
      });
    }

    return {
      input: input,
      close: close,
      refresh: function () { show(true); }
    };
  }

  window.SentriAddress = {
    attach: attach,
    fetchTerms: fetchTerms
  };
})();
