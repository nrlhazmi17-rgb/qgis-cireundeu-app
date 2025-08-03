<?php
// api/auth.php
// Authentication endpoints for admin system

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));
$action = end($path_parts);

try {
    switch ($method) {
        case 'POST':
            handlePost($action);
            break;
        case 'GET':
            handleGet($action);
            break;
        case 'DELETE':
            handleDelete($action);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    logError('Authentication API error: ' . $e->getMessage());
    sendError('Internal server error', 500);
}

function handlePost($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'login':
            login($input);
            break;
        case 'register':
            register($input);
            break;
        default:
            sendError('Invalid endpoint', 404);
    }
}

function handleGet($action) {
    switch ($action) {
        case 'profile':
            getProfile();
            break;
        case 'check':
            checkAuth();
            break;
        default:
            sendError('Invalid endpoint', 404);
    }
}

function handleDelete($action) {
    switch ($action) {
        case 'logout':
            logout();
            break;
        default:
            sendError('Invalid endpoint', 404);
    }
}

function login($input) {
    // Validate input
    $validation = validateInput($input, [
        'email' => [
            'required' => true,
            'type' => 'email',
            'max_length' => 40
        ],
        'password' => [
            'required' => true,
            'min_length' => 3
        ]
    ]);
    
    if (!$validation['valid']) {
        sendError('Validation failed: ' . implode(', ', $validation['errors']), 422);
    }
    
    $data = $validation['data'];
    
    // Rate limiting
    if (!checkRateLimit($_SERVER['REMOTE_ADDR'] . '_login', 10, 900)) { // 10 attempts per 15 minutes
        sendError('Too many login attempts. Please try again later.', 429);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get user by email
        $stmt = $pdo->prepare("SELECT id_user, nama, email, password FROM pengguna WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch();
        
        if (!$user || !verifyPassword($data['password'], $user['password'])) {
            logError('Failed login attempt', ['email' => $data['email'], 'ip' => $_SERVER['REMOTE_ADDR']]);
            sendError('Invalid email or password', 401);
        }
        
        // Start session
        startSecureSession();
        $_SESSION['user_id'] = $user['id_user'];
        $_SESSION['user_name'] = $user['nama'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['login_time'] = time();
        
        // Remove password from response
        unset($user['password']);
        
        logError('Successful login', ['user_id' => $user['id_user'], 'email' => $user['email']]);
        
        sendSuccess([
            'user' => $user,
            'session_timeout' => SESSION_TIMEOUT
        ], 'Login successful');
        
    } catch (PDOException $e) {
        logError('Database error during login: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function register($input) {
    // Check if user is already logged in (only logged in admins can create new admins)
    requireAuth();
    
    // Validate input
    $validation = validateInput($input, [
        'nama' => [
            'required' => true,
            'max_length' => 50
        ],
        'email' => [
            'required' => true,
            'type' => 'email',
            'max_length' => 40
        ],
        'password' => [
            'required' => true,
            'min_length' => 6
        ],
        'confirm_password' => [
            'required' => true
        ]
    ]);
    
    if (!$validation['valid']) {
        sendError('Validation failed: ' . implode(', ', $validation['errors']), 422);
    }
    
    $data = $validation['data'];
    
    // Check password confirmation
    if ($data['password'] !== $data['confirm_password']) {
        sendError('Password confirmation does not match', 422);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pengguna WHERE email = ?");
        $stmt->execute([$data['email']]);
        
        if ($stmt->fetchColumn() > 0) {
            sendError('Email already exists', 409);
        }
        
        // Create new user
        $stmt = $pdo->prepare("
            INSERT INTO pengguna (nama, email, password) 
            VALUES (?, ?, ?)
        ");
        
        $hashed_password = hashPassword($data['password']);
        $stmt->execute([$data['nama'], $data['email'], $hashed_password]);
        
        $new_user_id = $pdo->lastInsertId();
        
        logError('New admin registered', [
            'new_user_id' => $new_user_id,
            'registered_by' => $_SESSION['user_id']
        ]);
        
        sendSuccess([
            'id_user' => $new_user_id,
            'nama' => $data['nama'],
            'email' => $data['email']
        ], 'Admin berhasil didaftarkan');
        
    } catch (PDOException $e) {
        logError('Database error during registration: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function getProfile() {
    requireAuth();
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id_user, nama, email, created_at 
            FROM pengguna 
            WHERE id_user = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendError('User not found', 404);
        }
        
        sendSuccess($user);
        
    } catch (PDOException $e) {
        logError('Database error getting profile: ' . $e->getMessage());
        sendError('Database error', 500);
    }
}

function checkAuth() {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        if ($user) {
            sendSuccess([
                'authenticated' => true,
                'user' => $user,
                'session_remaining' => SESSION_TIMEOUT - (time() - $_SESSION['login_time'])
            ]);
        }
    }
    
    sendSuccess(['authenticated' => false]);
}

function logout() {
    startSecureSession();
    
    if (isset($_SESSION['user_id'])) {
        logError('User logged out', ['user_id' => $_SESSION['user_id']]);
    }
    
    // Destroy session
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    
    sendSuccess(null, 'Logout successful');
}

// Additional utility function for password reset (future implementation)
function requestPasswordReset($input) {
    $validation = validateInput($input, [
        'email' => [
            'required' => true,
            'type' => 'email'
        ]
    ]);
    
    if (!$validation['valid']) {
        sendError('Validation failed: ' . implode(', ', $validation['errors']), 422);
    }
    
    // TODO: Implement password reset logic
    // - Generate reset token
    // - Send email with reset link
    // - Store token in database with expiration
    
    sendSuccess(null, 'If the email exists, a password reset link has been sent.');
}
?>