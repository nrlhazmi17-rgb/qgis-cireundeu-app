<?php
// api/fasilitas.php
// CRUD endpoints for facilities management

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

// Extract ID if present in URL
$facility_id = null;
if (end($path_parts) === 'fasilitas.php') {
    array_pop($path_parts);
}
if (!empty($path_parts) && is_numeric(end($path_parts))) {
    $facility_id = (int)array_pop($path_parts);
}

try {
    switch ($method) {
        case 'GET':
            // FIX: Handle actions like 'stats' or 'categories' first.
            if (isset($_GET['action'])) {
                switch ($_GET['action']) {
                    case 'categories':
                        getCategories();
                        break;
                    case 'stats':
                        getStatistics();
                        break;
                }
            }
            
            // If no action was handled, proceed with normal GET logic.
            if ($facility_id) {
                getFacility($facility_id);
            } else {
                getFacilities();
            }
            break;
        case 'POST': 
            if ($facility_id) {
                updateFacility($facility_id);
            } else {
                createFacility();
            }
            break;
        case 'PUT': 
            if ($facility_id) {
                updateFacility($facility_id);
            } else {
                sendError('Facility ID required for update', 400);
            }
            break;
        case 'DELETE':
            if ($facility_id) {
                deleteFacility($facility_id);
            } else {
                sendError('Facility ID required for deletion', 400);
            }
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    logError('Facilities API error: ' . $e->getMessage());
    sendError('Internal server error', 500);
}

function getFacilities() {
    try {
        $pdo = getDBConnection();
        
        $kategori = $_GET['kategori'] ?? null;
        $search = $_GET['search'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $where_conditions = [];
        $params = [];
        
        if ($kategori) {
            $where_conditions[] = "kategori = ?";
            $params[] = $kategori;
        }
        
        if ($search) {
            $where_conditions[] = "(nama_fasilitas LIKE ? OR alamat LIKE ? OR deskripsi LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) FROM fasilitas_umum $where_clause";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total = $count_stmt->fetchColumn();
        
        $sql = "
            SELECT id_fasilitas, nama_fasilitas, foto_fasilitas, alamat, 
                   deskripsi, latitude, longitude, kategori, created_at, updated_at
            FROM fasilitas_umum 
            $where_clause 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $facilities = $stmt->fetchAll();
        
        if (isset($_GET['format']) && $_GET['format'] === 'geojson') {
            $features = [];
            foreach ($facilities as $facility) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'id' => $facility['id_fasilitas'],
                        'nama' => $facility['nama_fasilitas'],
                        'alamat' => $facility['alamat'],
                        'deskripsi' => $facility['deskripsi'],
                        'kategori' => $facility['kategori'],
                        'foto' => $facility['foto_fasilitas'],
                        'foto_fasilitas' => $facility['foto_fasilitas']
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [
                            (float)$facility['longitude'],
                            (float)$facility['latitude']
                        ]
                    ]
                ];
            }
            
            sendSuccess([
                'type' => 'FeatureCollection',
                'features' => $features
            ]);
        }
        
        sendSuccess([
            'facilities' => $facilities,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ]);
        
    } catch (PDOException $e) {
        logError('Database error getting facilities: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function getFacility($id) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id_fasilitas, nama_fasilitas, foto_fasilitas, alamat, 
                   deskripsi, latitude, longitude, kategori, created_at, updated_at
            FROM fasilitas_umum 
            WHERE id_fasilitas = ?
        ");
        $stmt->execute([$id]);
        $facility = $stmt->fetch();
        
        if (!$facility) {
            sendError('Facility not found', 404);
        }
        
        sendSuccess($facility);
        
    } catch (PDOException $e) {
        logError('Database error getting facility: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function createFacility() {
    requireAuth();
    
    $input = [];
    if (isset($_POST['data'])) {
        $input = json_decode($_POST['data'], true);
    } else {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    }
    
    $validation = validateInput($input, [
        'nama_fasilitas' => ['required' => true, 'max_length' => 100],
        'alamat' => ['required' => false],
        'deskripsi' => ['required' => false],
        'latitude' => ['required' => true, 'type' => 'float', 'callback' => function($value) { return ($value >= -90 && $value <= 90) ? true : 'Latitude must be between -90 and 90'; }],
        'longitude' => ['required' => true, 'type' => 'float', 'callback' => function($value) { return ($value >= -180 && $value <= 180) ? true : 'Longitude must be between -180 and 180'; }],
        'kategori' => ['required' => true, 'callback' => function($value) { $allowed = ['Masjid', 'Pendidikan', 'Kesehatan', 'Prasarana Umum', 'Fasilitas Publik']; return in_array($value, $allowed) ? true : 'Invalid category'; }]
    ]);
    
    if (!$validation['valid']) {
        sendError('Validation failed: ' . implode(', ', $validation['errors']), 422);
    }
    
    $data = $validation['data'];
    
    $foto_filename = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handleFileUpload($_FILES['foto']);
        if (!$upload_result['success']) {
            sendError($upload_result['message'], 400);
        }
        $foto_filename = $upload_result['filename'];
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO fasilitas_umum (nama_fasilitas, foto_fasilitas, alamat, deskripsi, latitude, longitude, kategori) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['nama_fasilitas'], $foto_filename, $data['alamat'],
            $data['deskripsi'], $data['latitude'], $data['longitude'], $data['kategori']
        ]);
        
        $new_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("SELECT * FROM fasilitas_umum WHERE id_fasilitas = ?");
        $stmt->execute([$new_id]);
        $facility = $stmt->fetch();
        
        logError('Facility created', ['facility_id' => $new_id, 'created_by' => $_SESSION['user_id']]);
        sendSuccess($facility, 'Facility created successfully');
        
    } catch (PDOException $e) {
        logError('Database error creating facility: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function updateFacility($id) {
    requireAuth();
    
    $input = [];
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($content_type, 'multipart/form-data') !== false) {
        if (isset($_POST['data'])) {
            $input = json_decode($_POST['data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) sendError('Invalid JSON data', 400);
        } else {
            $input = $_POST;
        }
    } else {
        $json_input = file_get_contents('php://input');
        if ($json_input) {
            $input = json_decode($json_input, true);
            if (json_last_error() !== JSON_ERROR_NONE) sendError('Invalid JSON data', 400);
        }
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM fasilitas_umum WHERE id_fasilitas = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) sendError('Facility not found', 404);
    } catch (PDOException $e) {
        sendError('Database error', 500);
    }
    
    $allowed_fields = ['nama_fasilitas', 'kategori', 'latitude', 'longitude', 'alamat', 'deskripsi'];
    $update_fields = [];
    $params = [];
    
    $foto_filename = $existing['foto_fasilitas'];
    $file_uploaded = false;
    
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handleFileUpload($_FILES['foto']);
        if (!$upload_result['success']) sendError($upload_result['message'], 400);
        
        if ($foto_filename && file_exists(UPLOAD_PATH . $foto_filename)) {
            unlink(UPLOAD_PATH . $foto_filename);
        }
        
        $foto_filename = $upload_result['filename'];
        $file_uploaded = true;
    }
    
    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $input)) {
            $value = $input[$field];
            if (in_array($field, ['nama_fasilitas', 'kategori', 'latitude', 'longitude']) && empty($value)) {
                continue;
            }
            $update_fields[] = "$field = ?";
            $params[] = ($field === 'latitude' || $field === 'longitude') ? (string)$value : trim($value);
        }
    }
    
    if ($file_uploaded) {
        $update_fields[] = "foto_fasilitas = ?";
        $params[] = $foto_filename;
    }
    
    $update_fields[] = "updated_at = CURRENT_TIMESTAMP";
    
    if (count($update_fields) <= 1 && !$file_uploaded) {
        sendError('No valid data provided for update', 400);
    }
    
    $params[] = $id;
    
    try {
        $sql = "UPDATE fasilitas_umum SET " . implode(', ', $update_fields) . " WHERE id_fasilitas = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $stmt = $pdo->prepare("SELECT * FROM fasilitas_umum WHERE id_fasilitas = ?");
        $stmt->execute([$id]);
        $facility = $stmt->fetch();
        
        logError('Facility updated', ['facility_id' => $id, 'updated_by' => $_SESSION['user_id'] ?? 'unknown']);
        sendSuccess($facility, 'Facility updated successfully');
        
    } catch (PDOException $e) {
        logError('Database error updating facility: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function deleteFacility($id) {
    requireAuth();
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("SELECT nama_fasilitas, foto_fasilitas FROM fasilitas_umum WHERE id_fasilitas = ?");
        $stmt->execute([$id]);
        $facility = $stmt->fetch();
        
        if (!$facility) sendError('Facility not found', 404);
        
        $stmt = $pdo->prepare("DELETE FROM fasilitas_umum WHERE id_fasilitas = ?");
        $stmt->execute([$id]);
        
        if ($facility['foto_fasilitas'] && file_exists(UPLOAD_PATH . $facility['foto_fasilitas'])) {
            unlink(UPLOAD_PATH . $facility['foto_fasilitas']);
        }
        
        logError('Facility deleted', ['facility_id' => $id, 'facility_name' => $facility['nama_fasilitas'], 'deleted_by' => $_SESSION['user_id']]);
        sendSuccess(null, 'Facility deleted successfully');
        
    } catch (PDOException $e) {
        logError('Database error deleting facility: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function getCategories() {
    $categories = [
        ['value' => 'Masjid', 'label' => 'Masjid', 'icon' => 'fas fa-mosque', 'color' => '#2ecc71'],
        ['value' => 'Pendidikan', 'label' => 'Pendidikan', 'icon' => 'fas fa-graduation-cap', 'color' => '#3498db'],
        ['value' => 'Kesehatan', 'label' => 'Kesehatan', 'icon' => 'fas fa-hospital', 'color' => '#e74c3c'],
        ['value' => 'Prasarana Umum', 'label' => 'Prasarana Umum', 'icon' => 'fas fa-building', 'color' => '#9b59b6'],
        ['value' => 'Fasilitas Publik', 'label' => 'Fasilitas Publik', 'icon' => 'fas fa-gas-pump', 'color' => '#f39c12']
    ];
    sendSuccess($categories);
}

function getStatistics() {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("SELECT kategori, COUNT(*) as count FROM fasilitas_umum GROUP BY kategori");
        $stmt->execute();
        $stats = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM fasilitas_umum");
        $stmt->execute();
        $total = $stmt->fetchColumn();
        
        sendSuccess(['total' => $total, 'by_category' => $stats]);
        
    } catch (PDOException $e) {
        logError('Database error getting statistics: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}
?>