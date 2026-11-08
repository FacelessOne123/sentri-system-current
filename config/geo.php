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
