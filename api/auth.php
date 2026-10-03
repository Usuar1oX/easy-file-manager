<?php
require_once 'core.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Límite de intentos
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = "login_attempts_$ip";
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 0, 'time' => time()];
    }
    
    if (time() - $_SESSION[$rateLimitKey]['time'] > 900) {
        // Reset after 15 minutes
        $_SESSION[$rateLimitKey] = ['count' => 0, 'time' => time()];
    }
    
    if ($_SESSION[$rateLimitKey]['count'] >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Demasiados intentos. Espera 15 minutos.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    $authenticated = false;
    if (isset($users[$username])) {
        $savedHash = $users[$username];
        if (strpos($savedHash, '$2y$') === 0 || strpos($savedHash, '$argon2') === 0) {
            $authenticated = password_verify($password, $savedHash);
        } else {
            $authenticated = hash_equals($savedHash, $password);
        }
    }

    if ($authenticated) {
        $_SESSION[$rateLimitKey]['count'] = 0;
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        echo json_encode(['success' => true, 'message' => 'Logged in successfully']);
    } else {
        $_SESSION[$rateLimitKey]['count']++;
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
} else if ($method === 'GET') {
    // Check auth status
    if (isAuthenticated()) {
        echo json_encode(['authenticated' => true, 'user' => $_SESSION['user']]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
} else if ($method === 'DELETE') {
    // Logout
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
