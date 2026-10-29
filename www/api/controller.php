<?php
//Backend Controller & API

// Database connection configuration
$host = '127.0.0.1';
$db   = 'map_app';
$user = 'root';
$pass = ''; // Default XAMPP password is empty

// Set the response header to JSON since this acts as a REST API
header('Content-Type: application/json; charset=utf-8');

try {
    // Initialize PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Determine which action the client (frontend) is requesting
$action = $_GET['action'] ?? '';

/**
 * getParcelsInBounds
 * Receives the current map view (bbox) and returns overlapping parcels as GeoJSON.
 */
if ($action === 'getParcelsInBounds') {
    // The bbox parameter is expected in the format: minLon,minLat,maxLon,maxLat
    $bbox = $_GET['bbox'] ?? '';
    $coords = explode(',', $bbox);

    // Validate the bounding box input
    if (count($coords) !== 4) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid bbox format. Expected: minLon,minLat,maxLon,maxLat']);
        exit;
    }

    [$minLon, $minLat, $maxLon, $maxLat] = $coords;

    // Convert the bounding box into a WKT (Well-Known Text) Polygon
    $bboxWkt = "POLYGON(($minLon $minLat, $maxLon $minLat, $maxLon $maxLat, $minLon $maxLat, $minLon $minLat))";

    // SQL query using spatial functions to fetch only parcels visible on the screen
    // ST_Intersects uses the spatial index, ensuring high performance
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.label,
            ST_AsGeoJSON(pg.polygon) AS geojson
        FROM parcels p
        JOIN parcel_geometries pg ON p.id = pg.parcel_id
        WHERE ST_Intersects(pg.polygon, ST_GeomFromText(:bbox, 4326))
    ");

    $stmt->execute([':bbox' => $bboxWkt]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Construct a standard GeoJSON FeatureCollection for Mapbox
    $features = [];
    foreach ($results as $row) {
        $features[] = [
            'type' => 'Feature',
            'geometry' => json_decode($row['geojson']),
            'properties' => [
                'id' => $row['id'],
                'label' => $row['label'] // Optional: pass label for map tooltips
            ]
        ];
    }

    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features
    ]);
    exit;
}

/**
 * getParcelDetails
 * Receives a specific parcel ID and returns its descriptive attributes.
 */
if ($action === 'getParcelDetails') {
    $id = $_GET['id'] ?? 0;

    // Fetch lightweight text data from the parcels table
    $stmt = $pdo->prepare("SELECT * FROM parcels WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($parcel) {
        echo json_encode($parcel);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Parcel not found']);
    }
    exit;
}

// Fallback for missing or invalid action parameter
http_response_code(400);
echo json_encode(['error' => 'Invalid or missing action parameter']);
exit;
