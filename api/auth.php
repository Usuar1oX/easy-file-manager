<?php
require_once 'config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (isset($users[$username]) && $users[$username] === $password) {
        $_SESSION['user'] = $username;
        echo json_encode(['success' => true, 'message' => 'Logged in successfully']);
    } else {
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
