<?php
require_once 'core.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Límite de intentos persistente por IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attemptsFile = sys_get_temp_dir() . '/login_attempts.json';
    
    $attemptsData = [];
    $currentTime = time();
    $ipData = ['count' => 0, 'time' => $currentTime];
    $lockAcquired = false;
    $fp = @fopen($attemptsFile, 'c+');
    if ($fp !== false && flock($fp, LOCK_EX)) {
        $lockAcquired = true;
        $filesize = filesize($attemptsFile);
        if ($filesize > 0) {
            $json = fread($fp, $filesize);
            $attemptsData = json_decode($json, true) ?: [];
        }
        
        $ipData = $attemptsData[$ip] ?? ['count' => 0, 'time' => $currentTime];
        
        if ($currentTime - $ipData['time'] > 900) {
            $ipData = ['count' => 0, 'time' => $currentTime];
        }
        
        if ($ipData['count'] >= 5) {
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Demasiados intentos. Espera 15 minutos.']);
            exit;
        }
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
        if ($lockAcquired && $fp !== false) {
            $attemptsData[$ip] = ['count' => 0, 'time' => time()];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($attemptsData));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        echo json_encode(['success' => true, 'message' => 'Logged in successfully']);
    } else {
        if ($lockAcquired && $fp !== false) {
            $ipData['count']++;
            $attemptsData[$ip] = $ipData;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($attemptsData));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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
