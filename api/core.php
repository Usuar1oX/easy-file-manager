<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Cargar configuración desde config.php
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'No config.php found. Please configure the app.']);
    exit;
}

$config = require $configFile;

// Configuración general
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

function resolveSecurePath($path) {
    $mediaReal = realpath(MEDIA_DIR);
    if ($mediaReal === false) return false;
    $pathReal = realpath(MEDIA_DIR . '/' . $path);
    if ($pathReal === false) return false;
    if ($pathReal !== $mediaReal && strpos($pathReal, $mediaReal . DIRECTORY_SEPARATOR) !== 0) return false;
    return $pathReal;
}

function resolveSecureParentPath($path) {
    $mediaReal = realpath(MEDIA_DIR);
    if ($mediaReal === false) return false;
    $parent = dirname(MEDIA_DIR . '/' . $path);
    $parentReal = realpath($parent);
    if ($parentReal === false) return false;
    if ($parentReal !== $mediaReal && strpos($parentReal, $mediaReal . DIRECTORY_SEPARATOR) !== 0) return false;
    return MEDIA_DIR . '/' . ltrim($path, '/');
}

function sanitizeName($name) {
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n'
    ]);
    $name = str_replace([' ', '_'], '-', $name);
    $name = preg_replace('/[^a-z0-9\-\.]/', '', $name);
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, '-');
    $name = ltrim($name, '.');
    $name = trim($name, '-');
    if ($name === '.' || $name === '..') {
        return '';
    }
    return $name;
}
