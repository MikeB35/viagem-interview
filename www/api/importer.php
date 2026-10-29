<?php
// importer.php - Parcel Data Importer

// Database connection setup (PDO)
$host = '127.0.0.1';
$db   = 'map_app';
$user = 'root';
$pass = ''; // XAMPP has an empty password by default

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

// Load the GML file
$file = '../src/data.gml';
if (!file_exists($file)) {
    die("File $file was not found.\n");
}

echo "Loading and parsing GML data...\n";
$xml = simplexml_load_file($file);

// Register XML namespaces used by ČÚZK (Czech Office for Surveying, Mapping and Cadastre)
$xml->registerXPathNamespace('cp', 'http://inspire.ec.europa.eu/schemas/cp/4.0');
$xml->registerXPathNamespace('gml', 'http://www.opengis.net/gml/3.2');
$xml->registerXPathNamespace('base', 'http://inspire.ec.europa.eu/schemas/base/3.3');
$xml->registerXPathNamespace('xlink', 'http://www.w3.org/1999/xlink');

// Find all parcels in the document
$parcels = $xml->xpath('//cp:CadastralParcel');
echo "Found parcels to process: " . count($parcels) . "\n";

// Prepared SQL statements for maximum performance and security
$stmtParcel = $pdo->prepare("
    INSERT IGNORE INTO parcels (id, label, area_m2, national_reference, municipality_name)
    VALUES (:id, :label, :area_m2, :national_reference, :municipality_name)
");

// Using ST_GeomFromText to convert WKT text to geometry with EPSG 4326
$stmtGeom = $pdo->prepare("
    INSERT IGNORE INTO parcel_geometries (parcel_id, polygon)
    VALUES (:parcel_id, ST_GeomFromText(:wkt, 4326))
");

$pdo->beginTransaction();
$importedCount = 0;

foreach ($parcels as $parcel) {
    // A) Extract descriptive data
    $localId = (string) $parcel->xpath('.//base:localId')[0];
    $id = (int) str_replace('CP.', '', $localId); // Remove "CP." for BIGINT

    $label = (string) $parcel->xpath('.//cp:label')[0];
    $area = (int) $parcel->xpath('.//cp:areaValue')[0];
    $nationalRef = (string) $parcel->xpath('.//cp:nationalCadastralReference')[0];

    // Get the municipality name (from the xlink:title attribute)
    $adminUnit = $parcel->xpath('.//cp:administrativeUnit')[0];
    $municipality = (string) $adminUnit->attributes('xlink', true)->title;

    // B) Extract and transform geometry
    // There can be an outer boundary (exterior) and potential holes (interior)
    $exteriors = $parcel->xpath('.//gml:exterior//gml:posList');
    $interiors = $parcel->xpath('.//gml:interior//gml:posList');

    $rings = [];

    // Process the outer boundary
    if (!empty($exteriors)) {
        $rings[] = processPosList((string) $exteriors[0]);
    }

    // Process inner holes (if they exist)
    foreach ($interiors as $interior) {
        $rings[] = processPosList((string) $interior);
    }

    // Construct the final WKT string
    $wktPolygon = "POLYGON(" . implode(", ", $rings) . ")";

    // C) Save to the database
    try {
        $stmtParcel->execute([
            ':id' => $id,
            ':label' => $label,
            ':area_m2' => $area,
            ':national_reference' => $nationalRef,
            ':municipality_name' => $municipality
        ]);

        $stmtGeom->execute([
            ':parcel_id' => $id,
            ':wkt' => $wktPolygon
        ]);

        $importedCount++;
    } catch (\PDOException $e) {
        echo "Error inserting parcel ID $id: " . $e->getMessage() . "\n";
    }
}

$pdo->commit();
echo "Done! Successfully imported parcels: $importedCount\n";


/**
 * Helper function to convert GML coordinates to WKT format
 */
function processPosList($posListString) {
    $coords = explode(' ', trim($posListString));
    $pairs = [];

    // GML has coordinates in LAT LON (Y X). Mapbox and WKT expect LON LAT (X Y)
    for ($i = 0; $i < count($coords); $i += 2) {
        $lat = $coords[$i];
        $lon = $coords[$i+1];
        $pairs[] = "$lon $lat";
    }

    return "(" . implode(", ", $pairs) . ")";
}
