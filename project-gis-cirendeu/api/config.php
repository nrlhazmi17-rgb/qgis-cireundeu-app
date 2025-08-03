<?php
/**
 * ===============================================
 * GIS CIRENDEU - API CONFIGURATION
 * Database and Application Configuration
 * ===============================================
 */

// Prevent direct access
if (!defined('API_ACCESS')) {
    define('API_ACCESS', true);
}

/**
 * ===============================================
 * DATABASE CONFIGURATION
 * ===============================================
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_gis_cirendeu');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * ===============================================
 * APPLICATION CONFIGURATION
 * ===============================================
 */
define('APP_NAME', 'GIS Cirendeu');
define('APP_VERSION', '2.0.0');
define('BASE_URL', 'http://localhost/project-gis-cirendeu/');
define('UPLOAD_PATH', '../uploads/');
define('LOG_PATH', '../logs/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

/**
 * ===============================================
 * SECURITY CONFIGURATION
 * ===============================================
 */
define('JWT_SECRET', 'your-secret-key-change-this-in-production');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('BCRYPT_COST', 12);
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour

/**
 * ===============================================
 * API RESPONSE HEADERS
 * ===============================================
 */
function setCorsHeaders() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=utf-8");
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * ===============================================
 * DATABASE CONNECTION
 * ===============================================
 */
class DatabaseConnection {
    private static $instance = null;
    private $pdo = null;

    private function __construct() {
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}

function getDBConnection() {
    return DatabaseConnection::getInstance()->getConnection();
}

/**
 * ===============================================
 * API RESPONSE HELPERS
 * ===============================================
 */
class ApiResponse {
    public static function send($data, $status = 200) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit();
    }

    public static function error($message, $status = 400, $details = null) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c'),
        ];
        
        if ($details && APP_DEBUG) {
            $response['details'] = $details;
        }
        
        self::send($response, $status);
    }

    public static function success($data = null, $message = 'Success') {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('c'),
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        self::send($response);
    }
}

// Legacy function wrappers for backward compatibility
function sendResponse($data, $status = 200) {
    ApiResponse::send($data, $status);
}

function sendError($message, $status = 400) {
    ApiResponse::error($message, $status);
}

function sendSuccess($data = null, $message = 'Success') {
    ApiResponse::success($data, $message);
}

/**
 * ===============================================
 * AUTHENTICATION HELPERS
 * ===============================================
 */
class AuthHelper {
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public static function startSecureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => SESSION_TIMEOUT,
                'cookie_secure' => isset($_SERVER['HTTPS']),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict'
            ]);
        }
    }

    public static function isLoggedIn() {
        self::startSecureSession();
        return isset($_SESSION['user_id']) && 
               isset($_SESSION['login_time']) && 
               (time() - $_SESSION['login_time']) < SESSION_TIMEOUT;
    }

    public static function requireAuth() {
        if (!self::isLoggedIn()) {
            ApiResponse::error('Authentication required', 401);
        }
    }

    public static function getCurrentUser() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id_user, nama, email FROM pengguna WHERE id_user = ?");
            $stmt->execute([$_SESSION['user_id']]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting current user: " . $e->getMessage());
            return null;
        }
    }
}

// Legacy function wrappers
function hashPassword($password) {
    return AuthHelper::hashPassword($password);
}

function verifyPassword($password, $hash) {
    return AuthHelper::verifyPassword($password, $hash);
}

function startSecureSession() {
    return AuthHelper::startSecureSession();
}

function isLoggedIn() {
    return AuthHelper::isLoggedIn();
}

function requireAuth() {
    return AuthHelper::requireAuth();
}

function getCurrentUser() {
    return AuthHelper::getCurrentUser();
}

/**
 * ===============================================
 * INPUT VALIDATION
 * ===============================================
 */
class InputValidator {
    public static function validate($data, $rules) {
        $errors = [];
        $clean_data = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // Required validation
            if (($rule['required'] ?? false) && empty($value)) {
                $errors[] = "Field '$field' is required";
                continue;
            }
            
            // Skip further validation if field is empty and not required
            if (empty($value)) {
                $clean_data[$field] = null;
                continue;
            }
            
            // Type validation
            $value = self::validateType($value, $rule['type'] ?? 'string', $field, $errors);
            
            // Length validation
            self::validateLength($value, $rule, $field, $errors);
            
            // Custom validation
            if (isset($rule['callback']) && is_callable($rule['callback'])) {
                $result = $rule['callback']($value);
                if ($result !== true) {
                    $errors[] = $result;
                    continue;
                }
            }
            
            $clean_data[$field] = self::sanitizeValue($value);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $clean_data
        ];
    }
    
    private static function validateType($value, $type, $field, &$errors) {
        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Field '$field' must be a valid email";
                    return $value;
                }
                break;
            case 'float':
                if (!is_numeric($value)) {
                    $errors[] = "Field '$field' must be a number";
                    return $value;
                }
                return (float)$value;
            case 'int':
                if (!is_numeric($value)) {
                    $errors[] = "Field '$field' must be an integer";
                    return $value;
                }
                return (int)$value;
        }
        return $value;
    }
    
    private static function validateLength($value, $rule, $field, &$errors) {
        if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
            $errors[] = "Field '$field' must not exceed {$rule['max_length']} characters";
        }
        
        if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
            $errors[] = "Field '$field' must be at least {$rule['min_length']} characters";
        }
    }
    
    private static function sanitizeValue($value) {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }
}

function validateInput($data, $rules) {
    return InputValidator::validate($data, $rules);
}

/**
 * ===============================================
 * FILE UPLOAD HELPER
 * ===============================================
 */
class FileUploadHelper {
    public static function handle($file, $allowed_types = ['image/jpeg', 'image/png', 'image/gif']) {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No file uploaded or upload error'];
        }
        
        // Check file size
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['success' => false, 'message' => 'File size exceeds maximum limit'];
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            return ['success' => false, 'message' => 'Invalid file type'];
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $upload_path = UPLOAD_PATH . $filename;
        
        // Create upload directory if not exists
        if (!is_dir(UPLOAD_PATH)) {
            mkdir(UPLOAD_PATH, 0755, true);
        }
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $upload_path
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to move uploaded file'];
        }
    }
}

function handleFileUpload($file, $allowed_types = ['image/jpeg', 'image/png', 'image/gif']) {
    return FileUploadHelper::handle($file, $allowed_types);
}

/**
 * ===============================================
 * LOGGING SYSTEM
 * ===============================================
 */
class Logger {
    public static function log($message, $level = 'INFO', $context = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null
        ];
        
        $log_file = LOG_PATH . 'app.log';
        $log_dir = dirname($log_file);
        
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    public static function error($message, $context = []) {
        self::log($message, 'ERROR', $context);
    }
    
    public static function info($message, $context = []) {
        self::log($message, 'INFO', $context);
    }
    
    public static function warning($message, $context = []) {
        self::log($message, 'WARNING', $context);
    }
}

function logError($message, $context = []) {
    Logger::error($message, $context);
}

/**
 * ===============================================
 * RATE LIMITING
 * ===============================================
 */
class RateLimiter {
    public static function check($identifier, $max_requests = RATE_LIMIT_REQUESTS, $time_window = RATE_LIMIT_WINDOW) {
        $cache_file = sys_get_temp_dir() . '/rate_limit_' . md5($identifier);
        
        if (file_exists($cache_file)) {
            $data = json_decode(file_get_contents($cache_file), true);
            
            if ($data['reset_time'] > time()) {
                if ($data['requests'] >= $max_requests) {
                    return false;
                }
                $data['requests']++;
            } else {
                $data = ['requests' => 1, 'reset_time' => time() + $time_window];
            }
        } else {
            $data = ['requests' => 1, 'reset_time' => time() + $time_window];
        }
        
        file_put_contents($cache_file, json_encode($data));
        return true;
    }
}

function checkRateLimit($identifier, $max_requests = RATE_LIMIT_REQUESTS, $time_window = RATE_LIMIT_WINDOW) {
    return RateLimiter::check($identifier, $max_requests, $time_window);
}

/**
 * ===============================================
 * INITIALIZATION
 * ===============================================
 */
// Set CORS headers for API calls
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    setCorsHeaders();
}

// Set error reporting
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set default timezone
date_default_timezone_set('Asia/Jakarta');
?>