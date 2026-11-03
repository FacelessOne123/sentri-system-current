<?php
/**
 * SenTri - Nominatim Reverse Geocode Proxy
 * Routes Nominatim requests server-side to bypass browser CORS restrictions.
 */
require_once __DIR__ . '/../config/session.php';
// Community realm is the safe default for this proxy: it only needs a signed-in
// identity as a rate-limiting gate for the shared Nominatim quota. Should gov
// portals ever need it, switch to sentri_boot_auto_realm(null) instead.
sentri_boot_realm('community');
header('Content-Type: application/json');

if (!sentri_identity()) {
 echo json_encode(['address' => []]); exit;
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;

if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
 echo json_encode(['address' => []]); exit;
}

$url = sprintf(
 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%s&lon=%s&zoom=18&addressdetails=1',
 urlencode((string)$lat),
 urlencode((string)$lon)
);

$ctx = stream_context_create([
 'http' => [
 'method' => 'GET',
 'header' => "User-Agent: SenTri/1.0\r\nAccept-Language: en\r\n",
 'timeout' => 6,
 ]
]);

$response = @file_get_contents($url, false, $ctx);
echo $response !== false ? $response : json_encode(['address' => []]);
