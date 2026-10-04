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
        case UPLOAD_ERR_INI_SIZE: $errMsg = 'El archivo supera el límite en php.ini (upload_max_filesize)'; break;
        case UPLOAD_ERR_FORM_SIZE: $errMsg = 'El archivo supera el límite del formulario html'; break;
        case UPLOAD_ERR_PARTIAL: $errMsg = 'El archivo se subió parcialmente'; break;
        case UPLOAD_ERR_NO_FILE: $errMsg = 'No se subió ningún archivo'; break;
        case UPLOAD_ERR_NO_TMP_DIR: $errMsg = 'Falta la carpeta temporal en el servidor'; break;
        case UPLOAD_ERR_CANT_WRITE: $errMsg = 'No se pudo escribir en el disco del servidor'; break;
        case UPLOAD_ERR_EXTENSION: $errMsg = 'Una extensión de PHP detuvo la subida'; break;
        case 'NO_FILE': $errMsg = 'El archivo no llegó al servidor (probablemente supera post_max_size)'; break;
    }
    http_response_code(400);
    echo json_encode(['error' => $errMsg]);
    exit;
}

$file = $_FILES['file'];
$rawPath = isset($_POST['path']) ? (string)$_POST['path'] : '';

// 3) Rechaza segmentos "." o ".." y caracteres nulos.
if (strpos($rawPath, "\0") !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid path']);
    exit;
}

// Normalizar \ a / y quitar / al inicio y final
$normalizedPath = str_replace('\\', '/', $rawPath);
$normalizedPath = trim($normalizedPath, '/');

// Separar segmentos y validar traversal
$rawSegments = $normalizedPath === '' ? [] : explode('/', $normalizedPath);
$segments = [];
foreach ($rawSegments as $seg) {
    $segTrim = trim($seg);
    if ($segTrim === '.' || $segTrim === '..') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid path']);
        exit;
    }
    if ($segTrim !== '') {
        $segments[] = $segTrim;
    }
}
$pathParam = implode('/', $segments);

// 1) Rechaza con 403 si la ruta destino es oculta
if (isHiddenPath($pathParam)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado a rutas ocultas']);
    exit;
}

// 1) La ruta destino NO debe pasar por sanitizeName.
// Usa la ruta tal como llega y valídala con resolveSecurePath().
// Si la carpeta existe, sube ahí respetando mayúsculas.
if ($pathParam === '') {
    $targetPath = resolveSecurePath('');
    if ($targetPath === false || !is_dir($targetPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid upload path']);
        exit;
    }
    $relativeDirPath = '';
} else {
    $existingPath = resolveSecurePath($pathParam);
    if ($existingPath !== false && is_dir($existingPath)) {
        $targetPath = $existingPath;
        $relativeDirPath = $pathParam;
    } else {
        // 2) Solo cuando la carpeta NO existe (caso "Subir Carpeta"):
        // Separa la ruta en la parte que ya existe y los segmentos nuevos.
        $existingSegments = [];
        $newSegments = [];
        $currentCheck = '';
        $foundMissing = false;

        foreach ($segments as $seg) {
            if (!$foundMissing) {
                $nextCheck = ($currentCheck === '') ? $seg : $currentCheck . '/' . $seg;
                $resolved = resolveSecurePath($nextCheck);
                if ($resolved !== false && is_dir($resolved)) {
                    $existingSegments[] = $seg;
                    $currentCheck = $nextCheck;
                } else {
                    $foundMissing = true;
                    $newSegments[] = $seg;
                }
            } else {
                $newSegments[] = $seg;
            }
        }

        // Aplica sanitizeName SOLO a los segmentos nuevos, valida con resolveSecureParentPath y créalos con mkdir 0755 recursivo.
        $currentPathBuilder = implode('/', $existingSegments);
        foreach ($newSegments as $seg) {
            $sanitized = sanitizeName($seg);
            if ($sanitized === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid folder name']);
                exit;
            }
            $stepPath = ($currentPathBuilder === '') ? $sanitized : $currentPathBuilder . '/' . $sanitized;
            
            $parentResolved = resolveSecureParentPath($stepPath);
            if ($parentResolved === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid upload path']);
                exit;
            }

            if (!is_dir($parentResolved)) {
                if (!mkdir($parentResolved, 0755, true)) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create directory']);
                    exit;
                }
            }

            $currentPathBuilder = $stepPath;
        }

        $targetPath = resolveSecurePath($currentPathBuilder);
        if ($targetPath === false || !is_dir($targetPath)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid upload path']);
            exit;
        }
        $relativeDirPath = $currentPathBuilder;
    }
}

// Validar tamaño
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

// 1) Determinar nombre final preliminar
if ($isImage) {
    $finalName = $filenameWithoutExt . '.webp';
} else {
    $finalName = $filenameWithoutExt . ($extension ? '.' . $extension : '');
}

// 1) Rechaza con 403 si el nombre final del archivo es oculto
if (empty($finalName) || isHiddenItem($finalName)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado a archivos ocultos']);
    exit;
}

// 2) No sobrescribas archivos existentes: si ya existe en la carpeta destino, agrega sufijo -1, -2, etc.
$finalPath = $targetPath . '/' . $finalName;
if (file_exists($finalPath)) {
    $info = pathinfo($finalName);
    $baseName = $info['filename'];
    $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
    $counter = 1;
    while (file_exists($targetPath . '/' . $baseName . '-' . $counter . $ext)) {
        $counter++;
    }
    $finalName = $baseName . '-' . $counter . $ext;
    $finalPath = $targetPath . '/' . $finalName;
}

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

        // Redimensionar por el lado mayor si es muy pesada o excede MAX_WIDTH
        $maxSide = max($width, $height);
        if ($file['size'] > RESIZE_THRESHOLD || $maxSide > MAX_WIDTH) {
            if ($maxSide > MAX_WIDTH) {
                if ($width >= $height) {
                    $newWidth = MAX_WIDTH;
                    $newHeight = (int)round($height * (MAX_WIDTH / $width));
                } else {
                    $newHeight = MAX_WIDTH;
                    $newWidth = (int)round($width * (MAX_WIDTH / $height));
                }
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

        $success = imagewebp($destinationImage, $finalPath, WEBP_QUALITY);

        imagedestroy($sourceImage);
        imagedestroy($destinationImage);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error procesando la imagen']);
        exit;
    }
} else {
    // No es imagen (ej. PDF, Word, Zip). Solo lo movemos a la carpeta de destino.
    $success = move_uploaded_file($file['tmp_name'], $finalPath);
}

if ($success) {
    $relativePath = $relativeDirPath === '' ? $finalName : $relativeDirPath . '/' . $finalName;
    $filePayload = [
        'name' => $finalName,
        'path' => $relativePath,
        'url' => '/media/' . $relativePath
    ];
    if ($isImage) {
        $filePayload['width'] = $newWidth;
        $filePayload['height'] = $newHeight;
    }
    $filePayload['size'] = @filesize($finalPath) ?: 0;
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'file' => $filePayload
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error al guardar el archivo en el servidor']);
}
