<?php
session_start();

// ConfiguraciÃ³n de CORS para desarrollo (permitir Vite)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}

// Cargar configuraciÃ³n desde config.json
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'No config.php found. Please configure the app.']);
    exit;
}

$config = require $configFile;

// ConfiguraciÃ³n general
define('MEDIA_DIR', __DIR__ . '/' . ($config['media_dir'] ?? '../../media'));
define('MAX_FILE_SIZE', $config['max_file_size'] ?? 50 * 1024 * 1024); // 50MB
define('RESIZE_THRESHOLD', $config['resize_threshold'] ?? 5 * 1024 * 1024); // 5MB
define('MAX_WIDTH', $config['max_width'] ?? 1920);

// Usuarios autorizados
$users = $config['users'] ?? ['admin' => 'admin'];

function isAuthenticated() {
    return isset($_SESSION['user']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

