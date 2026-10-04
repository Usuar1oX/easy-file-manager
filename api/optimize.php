<?php
require_once 'core.php';
requireAuth();

header('Content-Type: application/json');

@set_time_limit(300);
@ini_set('memory_limit', '256M');

$validExts = ['webp', 'jpg', 'jpeg', 'png'];

$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $body = json_decode($rawInput, true) ?: [];
    }
}

$action = $_GET['action'] ?? ($body['action'] ?? '');

if ($action === 'analyze') {
    $folder = $_GET['path'] ?? ($body['path'] ?? '');
    $folder = trim(str_replace('\\', '/', $folder), '/');

    if (isHiddenPath($folder)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado a ruta oculta']);
        exit;
    }

    $fullFolder = resolveSecurePath($folder === '' ? '' : $folder);
    if ($fullFolder === false && $folder === '') {
        $fullFolder = realpath(MEDIA_DIR);
    }

    if ($fullFolder === false || !is_dir($fullFolder)) {
        http_response_code(400);
        echo json_encode(['error' => 'Directorio inválido']);
        exit;
    }

    $recursive = isset($_GET['recursive']) ? ($_GET['recursive'] === '1' || $_GET['recursive'] === 'true') : (!empty($body['recursive']));

    $mediaReal = realpath(MEDIA_DIR);
    $files = [];
    $totalImages = 0;
    $totalSize = 0;
    $estimatedTotalSize = 0;

    try {
        if ($recursive) {
            $dirIterator = new RecursiveDirectoryIterator($fullFolder, FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
        } else {
            $iterator = new FilesystemIterator($fullFolder, FilesystemIterator::SKIP_DOTS);
        }

        foreach ($iterator as $item) {
            if ($item->isLink()) continue; // NO seguir enlaces simbólicos
            if ($item->isDir()) continue;

            $itemPath = $item->getPathname();
            $itemReal = realpath($itemPath);
            if ($itemReal === false || is_link($itemReal)) continue;

            // Ruta relativa a MEDIA_DIR
            $subPath = substr($itemReal, strlen($mediaReal));
            $subPath = str_replace('\\', '/', ltrim($subPath, '/\\'));

            if (isHiddenPath($subPath)) continue;
            $filename = $item->getFilename();
            if (isHiddenItem($filename)) continue;

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, $validExts, true)) continue;

            $size = $item->getSize();
            $rule = getOptimizeRuleForPath($subPath);

            $width = 0;
            $height = 0;
            $imgInfo = @getimagesize($itemReal);
            if ($imgInfo && isset($imgInfo[0], $imgInfo[1])) {
                $width = (int)$imgInfo[0];
                $height = (int)$imgInfo[1];
            }

            // Estimar peso optimizado según redimensión y calidad
            $maxSide = max($width, $height);
            $newWidth = $width;
            $newHeight = $height;
            if ($maxSide > $rule['max_side'] && $maxSide > 0) {
                $ratio = $rule['max_side'] / $maxSide;
                $newWidth = (int)round($width * $ratio);
                $newHeight = (int)round($height * $ratio);
            }

            $pixelRatio = ($width > 0 && $height > 0) ? ($newWidth * $newHeight) / ($width * $height) : 1.0;
            $qualityFactor = $rule['quality'] / 85.0;
            $estFactor = min(0.95, $pixelRatio * $qualityFactor);
            $estSize = (int)round($size * $estFactor);
            if ($estSize >= $size) {
                $estSize = (int)round($size * 0.90);
            }
            if ($estSize <= 0) $estSize = 1;

            $hasBackup = file_exists(MEDIA_DIR . '/.originales/' . $subPath);

            $files[] = [
                'path' => $subPath,
                'name' => $filename,
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'rule' => $rule,
                'estimated_size' => $estSize,
                'has_backup' => $hasBackup
            ];

            $totalImages++;
            $totalSize += $size;
            $estimatedTotalSize += $estSize;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al analizar la carpeta: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'folder' => $folder,
        'recursive' => $recursive,
        'total_images' => $totalImages,
        'total_size' => $totalSize,
        'estimated_total_size' => $estimatedTotalSize,
        'estimated_savings' => max(0, $totalSize - $estimatedTotalSize),
        'files' => $files
    ]);
    exit;

} else if ($action === 'optimize') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }

    $filePaths = $body['files'] ?? [];
    if (!is_array($filePaths)) {
        http_response_code(400);
        echo json_encode(['error' => 'Se requiere un array de archivos']);
        exit;
    }

    // Lote de máximo 10 rutas
    $filePaths = array_slice($filePaths, 0, 10);
    $results = [];
    $errors = [];

    foreach ($filePaths as $relPath) {
        $tmpFile = null;
        try {
            $relPath = str_replace('\\', '/', ltrim($relPath, '/'));
            if (empty($relPath) || isHiddenPath($relPath)) {
                throw new Exception('Ruta oculta o inválida');
            }

            $fullPath = resolveSecurePath($relPath);
            if ($fullPath === false || !file_exists($fullPath) || is_dir($fullPath) || is_link($fullPath)) {
                throw new Exception('Archivo no encontrado o inaccesible');
            }

            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (!in_array($ext, $validExts, true)) {
                throw new Exception('Formato no soportado para optimización');
            }

            $rule = getOptimizeRuleForPath($relPath);
            $maxSideLimit = (int)$rule['max_side'];
            $quality = (int)$rule['quality'];

            $sourceImage = null;
            if ($ext === 'webp') {
                $sourceImage = @imagecreatefromwebp($fullPath);
            } elseif ($ext === 'jpg' || $ext === 'jpeg') {
                $sourceImage = @imagecreatefromjpeg($fullPath);
            } elseif ($ext === 'png') {
                $sourceImage = @imagecreatefrompng($fullPath);
            }

            if (!$sourceImage) {
                throw new Exception('No se pudo decodificar la imagen');
            }

            $width = imagesx($sourceImage);
            $height = imagesy($sourceImage);
            $maxSide = max($width, $height);

            $newWidth = $width;
            $newHeight = $height;
            // Redimensionar por el lado mayor al max_side de la regla (nunca agrandar)
            if ($maxSide > $maxSideLimit && $maxSide > 0) {
                if ($width >= $height) {
                    $newWidth = $maxSideLimit;
                    $newHeight = (int)round($height * ($maxSideLimit / $width));
                } else {
                    $newHeight = $maxSideLimit;
                    $newWidth = (int)round($width * ($maxSideLimit / $height));
                }
            }

            $destImage = imagecreatetruecolor($newWidth, $newHeight);

            // Preservar canal alfa (transparencia) para PNG y WebP
            if ($ext === 'png' || $ext === 'webp') {
                imagealphablending($destImage, false);
                imagesavealpha($destImage, true);
                $trans = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
                imagefilledrectangle($destImage, 0, 0, $newWidth, $newHeight, $trans);
            }

            imagecopyresampled($destImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            $tmpFile = tempnam(dirname($fullPath), 'opt_');
            $encoded = false;

            // Conservar el MISMO formato y extensión
            if ($ext === 'webp') {
                $encoded = imagewebp($destImage, $tmpFile, $quality);
            } elseif ($ext === 'jpg' || $ext === 'jpeg') {
                $encoded = imagejpeg($destImage, $tmpFile, $quality);
            } elseif ($ext === 'png') {
                $encoded = imagepng($destImage, $tmpFile, 9);
            }

            imagedestroy($sourceImage);
            imagedestroy($destImage);

            if (!$encoded || !file_exists($tmpFile)) {
                if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
                throw new Exception('Error al codificar imagen optimizada');
            }

            $oldSize = filesize($fullPath);
            $newSize = filesize($tmpFile);
            $threshold = $oldSize * 0.90; // Debe pesar al menos 10% menos

            if ($newSize <= $threshold) {
                // Copiar original a MEDIA_DIR/.originales/<misma ruta> (si no existe ya)
                $backupPath = MEDIA_DIR . '/.originales/' . $relPath;
                $backupDir = dirname($backupPath);
                if (!is_dir($backupDir)) {
                    @mkdir($backupDir, 0755, true);
                }
                if (!file_exists($backupPath)) {
                    if (!copy($fullPath, $backupPath)) {
                        @unlink($tmpFile);
                        throw new Exception('No se pudo crear copia de seguridad en .originales');
                    }
                }

                // Reemplazar la imagen conservando nombre, extensión y ruta
                if (!copy($tmpFile, $fullPath)) {
                    @unlink($tmpFile);
                    throw new Exception('No se pudo reemplazar el archivo original');
                }
                @unlink($tmpFile);

                $savedBytes = $oldSize - $newSize;
                $savedPercent = round(($savedBytes / $oldSize) * 100, 1);

                $results[] = [
                    'path' => $relPath,
                    'optimized' => true,
                    'original_size' => $oldSize,
                    'new_size' => $newSize,
                    'saved_bytes' => $savedBytes,
                    'saved_percent' => $savedPercent,
                    'width' => $newWidth,
                    'height' => $newHeight,
                    'rule' => $rule
                ];
            } else {
                @unlink($tmpFile);
                $results[] = [
                    'path' => $relPath,
                    'optimized' => false,
                    'reason' => 'Ahorro menor al 10%',
                    'original_size' => $oldSize,
                    'new_size' => $newSize,
                    'width' => $width,
                    'height' => $height,
                    'rule' => $rule
                ];
            }
        } catch (Throwable $e) {
            if ($tmpFile && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            $errors[] = [
                'path' => $relPath,
                'error' => $e->getMessage()
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'processed' => $results,
        'errors' => $errors
    ]);
    exit;

} else if ($action === 'restore') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }

    $relPath = $body['path'] ?? ($_GET['path'] ?? '');
    $relPath = str_replace('\\', '/', ltrim($relPath, '/'));

    if (empty($relPath) || isHiddenPath($relPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ruta de archivo inválida']);
        exit;
    }

    $backupPath = MEDIA_DIR . '/.originales/' . $relPath;
    if (!file_exists($backupPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'No existe copia original en .originales para este archivo']);
        exit;
    }

    $fullDest = resolveSecurePath($relPath);
    if ($fullDest === false) {
        $fullDest = resolveSecureParentPath($relPath);
    }

    if ($fullDest === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ruta destino inválida']);
        exit;
    }

    if (copy($backupPath, $fullDest)) {
        @unlink($backupPath);

        // Limpiar carpetas vacías en .originales
        $parentDir = dirname($backupPath);
        $rootBackup = realpath(MEDIA_DIR . '/.originales');
        while ($parentDir && $rootBackup && $parentDir !== $rootBackup && is_dir($parentDir)) {
            $contents = array_diff(scandir($parentDir), ['.', '..']);
            if (empty($contents)) {
                @rmdir($parentDir);
                $parentDir = dirname($parentDir);
            } else {
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Archivo original restaurado con éxito',
            'path' => $relPath
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al restaurar archivo desde .originales']);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida o no especificada']);
    exit;
}
