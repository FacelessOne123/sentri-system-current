<?php
/**
 * config/geo.php - Authoritative Imus barangay list helpers
 *
 * The official barangay names live in assets/geo/imus-barangays.geojson
 * (95 OSM administrative boundaries). Signup, the community profile and the
 * report API all validate against this one source so a resident cannot type
 * a fabricated barangay name.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/geo.php';
 *   $names = sentri_barangay_names();          // sorted, ["Alapan I-A", ...]
 *   sentri_barangay_is_valid($submitted);      // true/false
 *   sentri_barangay_canonical($submitted);     // official spelling or null
 */

if (!function_exists('sentri_barangay_features')) {
 /**
  * Load the GeoJSON feature list once per request.
  * Returns an array of features, or [] when the file cannot be read.
  */
 function sentri_barangay_features(): array {
  static $cache = null;
  if ($cache !== null) return $cache;

  $path = dirname(__DIR__) . '/assets/geo/imus-barangays.geojson';
  $raw = @file_get_contents($path);
  if ($raw === false || strlen($raw) < 10) return $cache = [];

  $json = json_decode($raw, true);
  if (!is_array($json)
   || ($json['type'] ?? '') !== 'FeatureCollection'
   || !is_array($json['features'] ?? null)) {
   return $cache = [];
  }
  return $cache = $json['features'];
 }
}

if (!function_exists('sentri_barangay_names')) {
 /**
  * Official Imus barangay names, alphabetically sorted.
  */
 function sentri_barangay_names(): array {
  static $names = null;
  if ($names !== null) return $names;

  $names = [];
  foreach (sentri_barangay_features() as $feature) {
   $name = trim((string)($feature['properties']['name'] ?? ''));
   if ($name !== '') $names[] = $name;
  }
  $names = array_values(array_unique($names));
  natcasesort($names);
  return $names;
 }
}

if (!function_exists('sentri_barangay_normalize')) {
 /**
  * Case/accents/punctuation-insensitive key used for comparisons.
  */
 function sentri_barangay_normalize(string $name): string {
  $s = trim(mb_strtolower($name, 'UTF-8'));
  $s = strtr($s, ['ñ'=>'n','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
  $s = str_replace(['barangay','brgy'], '', $s);
  return preg_replace('/[^a-z0-9]/', '', $s);
 }
}

if (!function_exists('sentri_barangay_canonical')) {
 /**
  * Return the official spelling for a submitted barangay, or null.
  */
 function sentri_barangay_canonical(string $name): ?string {
  $key = sentri_barangay_normalize($name);
  if ($key === '') return null;
  foreach (sentri_barangay_names() as $official) {
   if (sentri_barangay_normalize($official) === $key) return $official;
  }
  return null;
 }
}

if (!function_exists('sentri_barangay_is_valid')) {
 function sentri_barangay_is_valid(string $name): bool {
  return sentri_barangay_canonical($name) !== null;
 }
}

if (!function_exists('sentri_barangay_center')) {
 /**
  * Approximate centre point of a barangay, used as a coarse "home area"
  * when no live position is available.
  *
  * The GeoJSON stores each feature's centre as [lng, lat] in the
  * "center" property; this returns [lat, lng] to match the rest of the
  * application (and the Leaflet/OSM convention).
  *
  * This is IDENTITY context, not a claim about where someone is right now:
  * a resident who is commuting is somewhere else entirely.
  *
  * @return array{0:float,1:float}|null [lat, lng] or null when unknown
  */
 function sentri_barangay_center(string $name): ?array {
  $canonical = sentri_barangay_canonical($name);
  if ($canonical === null) return null;
  foreach (sentri_barangay_features() as $feature) {
   $featureName = trim((string)($feature['properties']['name'] ?? ''));
   if ($featureName !== $canonical) continue;
   $center = $feature['properties']['center'] ?? null;
   if (!is_array($center) || count($center) < 2) return null;
   $lng = (float)$center[0];
   $lat = (float)$center[1];
   if (!is_finite($lat) || !is_finite($lng)) return null;
   if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
   return [$lat, $lng];
  }
  return null;
 }
}

/**
 * How long a community member must wait before changing their barangay again.
 *
 * TESTING VALUE: 1 minute. Set back to 43200 (30 days) before production.
 */
if (!defined('SENTRI_BRGY_LOCK_MINUTES')) {
 define('SENTRI_BRGY_LOCK_MINUTES', 1);
}

if (!function_exists('sentri_brgy_lock_state')) {
 /**
  * Current barangay + seconds remaining on the change lock, using DB time so
  * PHP/MySQL timezone differences cannot skew the countdown.
  *
  * @return array{0:string,1:int} [current barangay, seconds remaining]
  */
 function sentri_brgy_lock_state(mysqli $conn, int $uid): array {
  $minutes = SENTRI_BRGY_LOCK_MINUTES;
  $s = $conn->prepare("SELECT COALESCE(barangay_name,''),
    COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(barangay_changed_at, INTERVAL ? MINUTE))), 0)
    FROM users WHERE id=? LIMIT 1");
  if (!$s) return ['', 0];
  $s->bind_param("ii", $minutes, $uid);
  $s->execute();
  $s->bind_result($brgy, $secs);
  $s->fetch();
  $s->close();
  return [(string)$brgy, (int)$secs];
 }
}

if (!function_exists('sentri_residence_clean')) {
 /**
  * Normalise a free-text home address for storage/display.
  */
 function sentri_residence_clean(string $address): string {
  $address = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $address) ?? '';
  $address = preg_replace('/\s+/u', ' ', $address) ?? '';
  return trim($address);
 }
}
