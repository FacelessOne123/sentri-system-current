<?php
/**
 * config/address.php - Barangay address-term helpers
 *
 * Shared normalization/classification rules for the address-term dataset.
 * Used by scripts/build-address-terms.php, scripts/seed-address-terms.php
 * and api/address_terms.php so the build output, the database rows and the
 * API search all agree on how a term is spelled and typed.
 *
 * Address suggestions are advisory only: residents may still type a street,
 * purok or house number that is not in the dataset.
 */

if (!function_exists('sentri_address_roman')) {
 /**
  * Convert Unicode Roman-numeral characters (common in OSM data) to ASCII.
  */
 function sentri_address_roman(string $s): string {
  return strtr($s, [
   'Ⅰ'=>'I','Ⅱ'=>'II','Ⅲ'=>'III','Ⅳ'=>'IV','Ⅴ'=>'V',
   'Ⅵ'=>'VI','Ⅶ'=>'VII','Ⅷ'=>'VIII','Ⅸ'=>'IX','Ⅹ'=>'X',
   'ⅰ'=>'i','ⅱ'=>'ii','ⅲ'=>'iii','ⅳ'=>'iv','ⅴ'=>'v',
   'ⅵ'=>'vi','ⅶ'=>'vii','ⅷ'=>'viii','ⅸ'=>'ix','ⅹ'=>'x',
  ]);
 }
}

if (!function_exists('sentri_address_normalize')) {
 /**
  * Lowercase, accent-free, punctuation-free search form ("mabini street").
  */
 function sentri_address_normalize(string $name): string {
  $s = sentri_address_roman($name);
  $s = trim($s);
  $s = strtr($s, [
   'ñ'=>'n','á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
   'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
   'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
   'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
   'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
   'ç'=>'c',
  ]);
  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
 }
}

if (!function_exists('sentri_address_key')) {
 /**
  * Dedupe key: the normalized term with a trailing generic suffix removed
  * and all separators stripped, e.g. "Mabini St." -> "mabini".
  */
 function sentri_address_key(string $name): string {
  $s = sentri_address_normalize($name);
  // Drop a trailing street/road/type word so "Mabini St." and
  // "Mabini Street" collapse to one key.
  $s = preg_replace('/\b(street|st|road|rd|avenue|ave|drive|dr|lane|ln|boulevard|blvd|highway|hwy|hway|extension|ext)$/', '', $s);
  $s = preg_replace('/\b(subdivision|subd|village|vill|homes|home|residences|residence|estate|estates)$/', '', $s);
  $s = trim($s);
  $s = preg_replace('/[^a-z0-9]+/', '', $s);
  return $s;
 }
}

if (!function_exists('sentri_address_titlecase')) {
 /**
  * Human-friendly display spelling. Keeps small connector words lowercase
  * and uppercases Roman numerals / common abbreviations.
  */
 function sentri_address_titlecase(string $name): string {
  $s = trim(preg_replace('/\s+/', ' ', $name));
  if ($s === '') return '';
  $small = ['of','and','the','de','del','la','las','los','on','in','at','by'];
  $words = preg_split('/\s+/', $s);
  $out = [];
  foreach ($words as $i => $word) {
   $lower = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
   $plain = rtrim($lower, '.');
   // Roman numerals (optionally followed by a letter suffix, e.g. "II-B").
   if (preg_match('/^(i{1,3}|iv|v|vi{0,3}|ix|x)(-[a-z])?$/', $plain)) {
    $out[] = function_exists('mb_strtoupper') ? mb_strtoupper($word, 'UTF-8') : strtoupper($word);
    continue;
   }
   if ($i > 0 && in_array($plain, $small, true)) {
    $out[] = $lower;
    continue;
   }
   // Preserve short acronyms that were already uppercase in the source
   // (e.g. "ACM Woodstock Homes"), except common type abbreviations.
   if (preg_match('/^[A-Z]{2,4}\.?$/', $word)
       && !in_array($plain, ['st','rd','ave','dr','blvd','ext','nr','ph','blk','po'], true)) {
    $out[] = $word;
    continue;
   }
   $first = function_exists('mb_substr') ? mb_substr($lower, 0, 1, 'UTF-8') : substr($lower, 0, 1);
   $rest  = function_exists('mb_substr') ? mb_substr($lower, 1, null, 'UTF-8') : substr($lower, 1);
   $out[] = (function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first)) . $rest;
  }
  $res = implode(' ', $out);
  // Phase/block codes such as "1-a" -> "1-A".
  $res = preg_replace_callback('/\b([0-9]+)-([a-z])\b/', function ($m) {
   $upper = function_exists('mb_strtoupper') ? mb_strtoupper($m[2], 'UTF-8') : strtoupper($m[2]);
   return $m[1] . '-' . $upper;
  }, $res);
  return $res;
 }
}

if (!function_exists('sentri_address_classify')) {
 /**
  * Classify a term name into one of the address-term types.
  *
  * @param string $name Term name as found in OSM / the override CSV.
  * @param string $hint Optional OSM hint: highway, residential, place, addr_place.
  * @return array{0:string,1:?string} [term_type, parent_term]
  */
 function sentri_address_classify(string $name, string $hint = ''): array {
  $n = sentri_address_normalize($name);
  $parent = null;

  // Phase / Block / Purok / Sitio carry a parent (the subdivision/area).
  // Extract the parent from the ORIGINAL name so acronyms keep their case.
  $parentBefore = function (int $offset) use ($name): ?string {
   $before = trim(substr($name, 0, $offset));
   return $before !== '' ? sentri_address_titlecase($before) : null;
  };

  if (preg_match('/\b(?:phase|phs?)\s*([0-9]+[a-z]?(?:-[a-z0-9]+)?)?/i', $name, $m, PREG_OFFSET_CAPTURE)) {
   return ['phase', $parentBefore((int)$m[0][1])];
  }
  if (preg_match('/\b(?:block|blk)\s*([0-9]+[a-z]?)/i', $name, $m, PREG_OFFSET_CAPTURE)) {
   return ['block', $parentBefore((int)$m[0][1])];
  }
  if (preg_match('/\b(?:purok)\b/i', $name, $m, PREG_OFFSET_CAPTURE)) {
   return ['purok', $parentBefore((int)$m[0][1])];
  }
  if (preg_match('/\b(?:sitio|sityo)\b/i', $name, $m, PREG_OFFSET_CAPTURE)) {
   return ['sitio', $parentBefore((int)$m[0][1])];
  }

  // A highway is a street/road.
  if ($hint === 'highway') {
   return [preg_match('/\b(road|rd|highway|hwy|hway|bypass)\b/', $n) ? 'road' : 'street', null];
  }

  // Residential landuse / place / addr:place names are subdivisions.
  if (in_array($hint, ['residential', 'place', 'addr_place'], true)) {
   return ['subdivision', null];
  }

  // Subdivision / village keywords.
  if (preg_match('/\b(subdivision|subd|village|vill|villas|villa|homes|home|estates|estate|residences|residence|townhomes|heights|ville|gardens|garden|meadows|manor|manors|court|courts|place|parc|parklane|greenpark|greengate|greenmark|classique|classic|regatta|lancaster|woodlane|woodsite|treelane|prima|gawad kalinga|condominium|condo|tower|towers|suites|subd)\b/', $n)) {
   return ['subdivision', null];
  }

  // Street suffix keywords.
  if (preg_match('/\b(street|st|road|rd|avenue|ave|drive|dr|lane|ln|boulevard|blvd|highway|hwy|hway|alley|circle|crescent|extension|ext|corner)\b/', $n)) {
   return [preg_match('/\b(road|rd|highway|hwy|hway|boulevard|blvd)\b/', $n) ? 'road' : 'street', null];
  }

  // Named places that are not obviously streets/villages (e.g. plazas).
  if ($hint === 'landmark') return ['landmark', null];

  return ['other', null];
 }
}

if (!function_exists('sentri_address_is_business')) {
 /**
  * Reject obvious business names so they don't pollute address suggestions.
  */
 function sentri_address_is_business(string $name): bool {
  $n = ' ' . sentri_address_normalize($name) . ' ';
  $words = [
   'inc','incorporated','corp','corporation','ltd','llc','company','co',
   'store','shop','mart','restaurant','cafe','coffee','bakery','pharmacy',
   'clinic','hospital','school','academy','university','college','bank',
   'hotel','motel','inn','gas station','petrol','terminal','mall','plaza',
   'market','church','chapel','office','building','bldg','center','centre',
   'salon','barber','car wash','water station','laundry','pawnshop',
   'lending','hardware','construction','trading','enterprises','services',
   'agency','realty','developer','philippines','resort','warehouse','factory',
  ];
  foreach ($words as $w) {
   if (strpos($n, ' ' . $w . ' ') !== false) return true;
  }
  return false;
 }
}

if (!function_exists('sentri_address_type_order')) {
 /**
  * Display/sort order for term types.
  */
 function sentri_address_type_order(): array {
  return ['street' => 0, 'road' => 1, 'subdivision' => 2, 'phase' => 3,
    'block' => 4, 'purok' => 5, 'sitio' => 6, 'landmark' => 7, 'other' => 8];
 }
}
