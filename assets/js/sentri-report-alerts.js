/*!
 * SenTri Report Alerts
 * Lightweight, right-side popup alerts for new / escalated reports.
 *
 * Usage:
 *   SentriReportAlerts.create({
 *     api: '../api/reports.php',
 *     seenKey: 'sentri_resp_alert_seen_v1',
 *     containerId: 'toastContainer',
 *     viewUrl: 'responder.php?view=queue',
 *     viewLabel: 'View in queue'
 *   }).start();
 *
 * The server decides which reports are alert-worthy for the signed-in role.
 * This script only polls, de-duplicates and renders the popup.
 */
(function () {
 'use strict';

 var CATEGORY_ICONS = {
  fire: 'fa-fire',
  crime: 'fa-user-shield',
  accident: 'fa-car-burst',
  flooding: 'fa-water',
  health: 'fa-heart-pulse',
  infrastructure: 'fa-road-barrier',
  other: 'fa-circle-exclamation'
 };
 var CATEGORY_LABELS = {
  fire: 'Fire',
  crime: 'Crime',
  accident: 'Accident',
  flooding: 'Flooding',
  health: 'Health',
  infrastructure: 'Infrastructure',
  other: 'Other'
 };

 function esc(value) {
  return String(value == null ? '' : value)
   .replace(/&/g, '&amp;')
   .replace(/</g, '&lt;')
   .replace(/>/g, '&gt;')
   .replace(/"/g, '&quot;')
   .replace(/'/g, '&#39;');
 }

 function truncate(value, max) {
  var s = String(value == null ? '' : value);
  return s.length > max ? s.slice(0, max - 1) + '\u2026' : s;
 }

 function create(config) {
  config = config || {};

  var api = config.api || '../api/reports.php';
  var seenKey = config.seenKey || 'sentri_report_alerts_seen_v1';
  var containerId = config.containerId || 'toastContainer';
  var viewUrl = config.viewUrl || '';
  var viewLabel = config.viewLabel || 'View report';
  var interval = config.interval || 15000;
  var dismissMs = config.dismissMs || 7000;
  var maxVisible = config.maxVisible || 3;

  var seen = {};
  try {
   var stored = JSON.parse(localStorage.getItem(seenKey) || '{}');
   if (stored && typeof stored === 'object') seen = stored;
  } catch (e) {
   seen = {};
  }

  var sinceId = 0;
  var sinceTime = '';
  var primed = false;
  var inFlight = false;
  var timer = null;
  var visibilityBound = false;

  function saveSeen() {
   try {
    var ids = Object.keys(seen);
    if (ids.length > 400) {
     ids.sort(function (a, b) { return Number(b) - Number(a); });
     ids.slice(300).forEach(function (id) { delete seen[id]; });
    }
    localStorage.setItem(seenKey, JSON.stringify(seen));
   } catch (e) { /* storage may be unavailable */ }
  }

  function keyFor(report) {
   return String(report.status || '') + '|' + (Number(report.escalated_to_lgu) ? 1 : 0);
  }

  function reasonFor(report, previousKey, isNewId) {
   if (isNewId) return 'new';
   var escalated = Number(report.escalated_to_lgu) ? 1 : 0;
   if (!previousKey) {
    if (escalated) return 'escalated';
    // First time this report becomes alert-worthy (e.g. caution -> dangerous).
    if (report.status === 'dangerous') return 'upgraded';
    return 'updated';
   }
   var parts = previousKey.split('|');
   var prevStatus = parts[0] || '';
   var prevEsc = parts[1] || '0';
   if (escalated && prevEsc === '0') return 'escalated';
   if (report.status === 'dangerous' && prevStatus !== 'dangerous') return 'upgraded';
   return 'updated';
  }

  function labelFor(reason, report) {
   if (reason === 'escalated') return 'Report Escalated to LGU';
   if (reason === 'upgraded') return 'Report Upgraded to Dangerous';
   if (reason === 'active') {
    if (report.status === 'dangerous') return 'Active Dangerous Report';
    if (report.status === 'caution') return 'Active Caution Report';
    return 'Report Requires Attention';
   }
   if (reason === 'new') {
    if (report.status === 'dangerous') return 'New Dangerous Report';
    if (report.status === 'caution') return 'New Caution Report';
    return 'New Report';
   }
   return 'Report Updated';
  }

  function toneFor(reason, report) {
   if (reason === 'escalated') return 'escalated';
   if (report.status === 'caution') return 'caution';
   if (report.status === 'dangerous') return 'dangerous';
   return 'info';
  }

  function iconFor(reason, report) {
   if (reason === 'escalated') return 'fa-arrow-up-from-bracket';
   return CATEGORY_ICONS[report.category] || 'fa-circle-exclamation';
  }

  function locationFor(report) {
   return [report.barangay, report.city].filter(function (part) {
    return part && String(part).trim() !== '';
   }).join(', ');
  }

  function timeFor(report, reason) {
   var raw = (reason === 'escalated' && report.updated_at) ? report.updated_at : (report.created_at || '');
   var date = new Date(String(raw).replace(' ', 'T'));
   if (isNaN(date.getTime())) return raw;
   var diff = Math.round((Date.now() - date.getTime()) / 1000);
   if (diff >= 0 && diff < 60) return 'just now';
   if (diff >= 60 && diff < 3600) return Math.round(diff / 60) + 'm ago';
   if (diff >= 3600 && diff < 86400) return Math.round(diff / 3600) + 'h ago';
   return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function dismiss(el) {
   if (!el || !el.parentNode) return;
   el.classList.add('alert-out');
   setTimeout(function () {
    if (el.parentNode) el.parentNode.removeChild(el);
   }, 380);
  }

  function renderAlert(report, reason) {
   if (typeof config.onAlert === 'function') {
    config.onAlert(report, labelFor(reason, report), reason);
    return;
   }

   var container = document.getElementById(containerId);
   if (!container) return;

   var tone = toneFor(reason, report);
   var label = labelFor(reason, report);
   var category = CATEGORY_LABELS[report.category] || (report.category || 'Report');
   var location = locationFor(report);
   var meta = [category, location, timeFor(report, reason)].filter(function (part) {
    return part && String(part).trim() !== '';
   }).join(' \u00b7 ');
   var description = truncate(report.description || '', 120);

   var el = document.createElement('div');
   el.className = 'alert-toast alert-' + tone;
   el.setAttribute('role', 'alert');
   el.setAttribute('aria-live', 'assertive');
   el.innerHTML =
    '<div class="alert-toast-icon"><i class="fas ' + esc(iconFor(reason, report)) + '"></i></div>' +
    '<div class="alert-toast-body">' +
     '<div class="alert-toast-title">' + esc(label) + '</div>' +
     '<div class="alert-toast-text">' +
      '<strong>' + esc(report.title || 'Untitled report') + '</strong>' +
      (meta ? '<span>' + esc(meta) + '</span>' : '') +
      (description ? '<span class="alert-toast-desc">' + esc(description) + '</span>' : '') +
     '</div>' +
     (viewUrl ? '<a class="alert-toast-link" href="' + esc(viewUrl) + '">' + esc(viewLabel) + ' <i class="fas fa-arrow-right"></i></a>' : '') +
    '</div>' +
    '<button type="button" class="alert-toast-close" aria-label="Dismiss alert"><i class="fas fa-xmark"></i></button>' +
    '<div class="alert-toast-progress" style="animation-duration:' + (dismissMs / 1000) + 's;"></div>';

   container.appendChild(el);

   var close = el.querySelector('.alert-toast-close');
   if (close) close.onclick = function () { dismiss(el); };

   var visible = container.querySelectorAll('.alert-toast');
   if (visible.length > maxVisible) {
    for (var i = 0; i < visible.length - maxVisible; i++) dismiss(visible[i]);
   }

   setTimeout(function () { dismiss(el); }, dismissMs);
  }

  function buildUrl(prime) {
   var sep = api.indexOf('?') >= 0 ? '&' : '?';
   return api + sep + 'action=poll_report_alerts' +
    '&since_id=' + encodeURIComponent(sinceId) +
    '&since_time=' + encodeURIComponent(sinceTime) +
    (prime ? '&prime=1' : '');
  }

  function poll(prime) {
   if (inFlight) return Promise.resolve();
   if (!prime && document.hidden) return Promise.resolve();

   inFlight = true;
   return fetch(buildUrl(prime), {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { 'Accept': 'application/json' }
   })
    .then(function (response) {
     if (!response.ok) throw new Error('HTTP ' + response.status);
     return response.json();
    })
    .then(function (data) {
     if (!data || data.status !== 'success') return;
     var list = Array.isArray(data.reports) ? data.reports : [];

     if (prime) {
      // Show the newest reports that have not been announced on this device
      // yet. Anything older is marked as seen silently to avoid a flood.
      var unseen = list.filter(function (report) {
       return seen[report.id] !== keyFor(report);
      });
      unseen.slice(0, maxVisible).reverse().forEach(function (report) {
       var reason = (config.escalation && Number(report.escalated_to_lgu)) ? 'escalated' : 'active';
       renderAlert(report, reason);
      });
      list.forEach(function (report) { seen[report.id] = keyFor(report); });
      saveSeen();
      sinceId = Number(data.latest_id) || sinceId;
      sinceTime = data.server_time || sinceTime;
      primed = true;
      return;
     }

     var previousSinceId = sinceId;
     list.slice().reverse().forEach(function (report) {
      var key = keyFor(report);
      var previousKey = seen[report.id];
      if (previousKey === key) return;

      var isNewId = Number(report.id) > previousSinceId;
      var reason = reasonFor(report, previousKey, isNewId);
      // The server already filtered this row as alert-worthy; any changed key
      // is either a new report, a status upgrade or a new escalation.
      renderAlert(report, reason);
      seen[report.id] = key;
     });

     saveSeen();
     sinceId = Math.max(previousSinceId, Number(data.latest_id) || 0);
     sinceTime = data.server_time || sinceTime;
    })
    .catch(function () { /* keep polling silently */ })
    .then(function () { inFlight = false; });
  }

  function start() {
   if (timer) return;
   poll(true);
   timer = setInterval(function () {
    if (!primed) poll(true);
    else poll(false);
   }, interval);

   if (!visibilityBound) {
    visibilityBound = true;
    document.addEventListener('visibilitychange', function () {
     if (document.visibilityState === 'visible' && primed) poll(false);
    });
   }
  }

  function stop() {
   if (timer) {
    clearInterval(timer);
    timer = null;
   }
  }

  return {
   start: start,
   stop: stop,
   pollNow: function () { return poll(!primed); }
  };
 }

 window.SentriReportAlerts = { create: create };
})();
