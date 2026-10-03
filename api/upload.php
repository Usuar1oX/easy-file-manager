<?php
require_once 'core.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : 'NO_FILE';
    $errMsg = 'Error desconocido';
    switch ($errCode) {
        case UPLOAD_ERR_INI_SIZE: $errMsg = 'El archivo supera el lÃ­mite en php.ini (upload_max_filesize)'; break;
        case UPLOAD_ERR_FORM_SIZE: $errMsg = 'El archivo supera el lÃ­mite del formulario html'; break;
        case UPLOAD_ERR_PARTIAL: $errMsg = 'El archivo se subiÃ³ parcialmente'; break;
        case UPLOAD_ERR_NO_FILE: $errMsg = 'No se subiÃ³ ningÃºn archivo'; break;
        case UPLOAD_ERR_NO_TMP_DIR: $errMsg = 'Falta la carpeta temporal en el servidor'; break;
        case UPLOAD_ERR_CANT_WRITE: $errMsg = 'No se pudo escribir en el disco del servidor'; break;
        case UPLOAD_ERR_EXTENSION: $errMsg = 'Una extensiÃ³n de PHP detuvo la subida'; break;
        case 'NO_FILE': $errMsg = 'El archivo no llegÃ³ al servidor (probablemente supera post_max_size)'; break;
    }
    http_response_code(400);
    echo json_encode(['error' => $errMsg]);
    exit;
}

function sanitizeName($name) {
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, [
        'Ã¡'=>'a', 'Ã©'=>'e', 'Ã­'=>'i', 'Ã³'=>'o', 'Ãº'=>'u', 'Ã¼'=>'u', 'Ã±'=>'n'
    ]);
    $name = str_replace([' ', '_'], '-', $name);
    $name = preg_replace('/[^a-z0-9\-\.]/', '', $name);
    $name = preg_replace('/-+/', '-', $name);
    return trim($name, '-');
}

function sanitizePath($path) {
    if (empty($path)) return '';
    $parts = explode('/', $path);
    foreach ($parts as &$part) {
        $part = sanitizeName($part);
    }
    return implode('/', array_filter($parts));
}

$file = $_FILES['file'];
$pathParam = isset($_POST['path']) ? trim($_POST['path'], '/') : '';
$pathParam = sanitizePath($pathParam);
$targetPath = resolveSecurePath($pathParam === '' ? '' : $pathParam);

if ($targetPath === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid upload path']);
    exit;
}

if (!is_dir($targetPath)) {
    mkdir($targetPath, 0755, true);
}

// Validar tamaÃ±o
if ($file['size'] > MAX_FILE_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'File exceeds maximum size of ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB']);
    exit;
}

$originalName = basename($file['name']);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$filenameWithoutExt = sanitizeName(pathinfo($originalName, PATHINFO_FILENAME));

$mimeType = mime_content_type($file['tmp_name']);
$isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp']);

// Lista blanca de extensiones
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'mp4', 'txt'];

if (!in_array(strtolower($extension), $allowedExtensions) || $originalName === '.htaccess') {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de archivo no permitido.']);
    exit;
}

$success = false;
$finalName = $filenameWithoutExt . ($extension ? '.' . $extension : '');

if ($isImage) {
    // Es una imagen: la convertimos a WebP y redimensionamos
    $sourceImage = null;
    if ($mimeType === 'image/jpeg') {
        $sourceImage = @imagecreatefromjpeg($file['tmp_name']);
    } elseif ($mimeType === 'image/png') {
        $sourceImage = @imagecreatefrompng($file['tmp_name']);
    } elseif ($mimeType === 'image/webp') {
        $sourceImage = @imagecreatefromwebp($file['tmp_name']);
    }

    if ($sourceImage) {
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $newWidth = $width;
        $newHeight = $height;

        // Redimensionar si es muy pesada o muy ancha
        if ($file['size'] > RESIZE_THRESHOLD || $width > MAX_WIDTH) {
            if ($width > MAX_WIDTH) {
                $newWidth = MAX_WIDTH;
                $newHeight = floor($height * (MAX_WIDTH / $width));
            }
        }

        $destinationImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preservar transparencia
        imagealphablending($destinationImage, false);
        imagesavealpha($destinationImage, true);
        $transparent = imagecolorallocatealpha($destinationImage, 255, 255, 255, 127);
        imagefilledrectangle($destinationImage, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled(
            $destinationImage, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );

        $finalName = $filenameWithoutExt . '.webp';
        $finalPath = $targetPath . '/' . $finalName;

        $success = imagewebp($destinationImage, $finalPath, 80);

        imagedestroy($sourceImage);
        imagedestroy($destinationImage);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error procesando la imagen']);
        exit;
    }
} else {
    // No es imagen (ej. PDF, Word, Zip). Solo lo movemos a la carpeta de destino original.
    $finalPath = $targetPath . '/' . $finalName;
    $success = move_uploaded_file($file['tmp_name'], $finalPath);
}

if ($success) {
    $relativePath = $pathParam === '' ? $finalName : $pathParam . '/' . $finalName;
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'file' => [
            'name' => $finalName,
            'path' => $relativePath,
            'url' => '/media/' . $relativePath
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error al guardar el archivo en el servidor']);
}
