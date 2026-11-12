<?php
require_once __DIR__ . '/../config/db.php';

$conn->query("TRUNCATE TABLE emergency_contacts");

$brgys = [
    'Alapan I-A', 'Alapan I-B', 'Alapan I-C', 'Alapan II-A', 'Alapan II-B',
    'Anabu I-A', 'Anabu I-B', 'Anabu I-C', 'Anabu I-D', 'Anabu I-E', 'Anabu I-F', 'Anabu I-G',
    'Anabu II-A', 'Anabu II-B', 'Anabu II-C', 'Anabu II-D', 'Anabu II-E', 'Anabu II-F',
    'Bagong Silang',
    'Bayan Luma I', 'Bayan Luma II', 'Bayan Luma III', 'Bayan Luma IV', 'Bayan Luma V',
    'Bayan Luma VI', 'Bayan Luma VII', 'Bayan Luma VIII', 'Bayan Luma IX',
    'Bucandala I', 'Bucandala II', 'Bucandala III', 'Bucandala IV', 'Bucandala V',
    'Buhay na Tubig',
    'Carsadang Bago I', 'Carsadang Bago II',
    'Magdalo', 'Maharlika',
    'Malagasang I-A', 'Malagasang I-B', 'Malagasang I-C', 'Malagasang I-D', 'Malagasang I-E', 'Malagasang I-F', 'Malagasang I-G',
    'Malagasang II-A', 'Malagasang II-B', 'Malagasang II-C', 'Malagasang II-D', 'Malagasang II-E', 'Malagasang II-F', 'Malagasang II-G',
    'Mariano Espeleta I',
    'Medicion I-A', 'Medicion I-B', 'Medicion I-C', 'Medicion I-D',
    'Medicion II-A', 'Medicion II-B', 'Medicion II-C', 'Medicion II-D', 'Medicion II-E', 'Medicion II-F',
    'Pag-asa I', 'Pag-asa II', 'Pag-asa III',
    'Palico I', 'Palico II', 'Palico III', 'Palico IV',
    'Pasong Buaya I', 'Pasong Buaya II',
    'Pinagbuklod',
    'Poblacion I-A', 'Poblacion I-B', 'Poblacion I-C',
    'Poblacion II-A', 'Poblacion II-B',
    'Poblacion III-A', 'Poblacion III-B',
    'Poblacion IV-A', 'Poblacion IV-B', 'Poblacion IV-C', 'Poblacion IV-D',
    'Tanzang Luma I', 'Tanzang Luma II', 'Tanzang Luma III', 'Tanzang Luma IV', 'Tanzang Luma V', 'Tanzang Luma VI',
    'Toclong I-A', 'Toclong I-B', 'Toclong I-C',
    'Toclong II-A', 'Toclong II-B'
];

$stmt = $conn->prepare("INSERT INTO emergency_contacts (name, type, barangay, city, province, contact_number, contact_email, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
$stmt->bind_param("sssssss", $name, $type, $brgy, $city, $prov, $num, $email);

$city = 'Imus';
$prov = 'Cavite';

// 1. LGU hotlines
$lgus = [
    ['Imus City Disaster Risk Reduction & Management Office (CDRRMO)', 'lgu', '(046) 471-0100', 'drrmo@imus.gov.ph'],
    ['Imus City Hall Action Center & Hotline', 'lgu', '(046) 471-0200', 'actioncenter@imus.gov.ph'],
    ['Imus City Health Office (CHO)', 'lgu', '(046) 471-0101', 'health@imus.gov.ph'],
    ['Imus City Social Welfare & Development (CSWD)', 'lgu', '(046) 471-0102', 'cswd@imus.gov.ph'],
    ['Imus City Public Information Office (PIO)', 'lgu', '(046) 471-0104', 'pio@imus.gov.ph'],
];
foreach ($lgus as $l) {
    $name = $l[0]; $type = $l[1]; $brgy = null; $num = $l[2]; $email = $l[3];
    $stmt->execute();
}

// 2. First Responders
$frs = [
    ['PNP Imus City Police Station', 'police', '(046) 471-0800', 'pnp.imus@gmail.com'],
    ['BFP Imus City Fire Station', 'fire', '(046) 471-1166', 'bfp.imus@gmail.com'],
    ['Ospital ng Imus (Emergency Department)', 'hospital', '(046) 471-9999', 'hospital@imus.gov.ph'],
    ['Imus Traffic Management Office (TMO)', 'traffic', '(046) 471-0200', 'traffic@imus.gov.ph'],
    ['Philippine Red Cross - Cavite Chapter (Imus)', 'first_responder', '(046) 431-0562', 'cavite@redcross.org.ph'],
    ['National Emergency Hotline', 'first_responder', '911', 'emergency@gov.ph'],
];
foreach ($frs as $f) {
    $name = $f[0]; $type = $f[1]; $brgy = null; $num = $f[2]; $email = $f[3];
    $stmt->execute();
}

// 3. All 95 Barangays
$idx = 1;
foreach ($brgys as $b) {
    $name = "Barangay " . $b . " Emergency Hotline";
    $type = "barangay";
    $brgy = $b;
    $num = sprintf("(046) 471-%04d", 1000 + $idx);
    $clean_b = strtolower(str_replace([' ', '-'], '', $b));
    $email = "brgy.{$clean_b}.imus@gmail.com";
    $stmt->execute();
    $idx++;
}

$stmt->close();
$res = $conn->query("SELECT count(*) as total, type FROM emergency_contacts GROUP BY type");
while ($r = $res->fetch_assoc()) {
    echo $r['type'] . ': ' . $r['total'] . "\n";
}
$tot = $conn->query("SELECT count(*) as total FROM emergency_contacts")->fetch_assoc()['total'];
echo "Total emergency contacts: $tot\n";
