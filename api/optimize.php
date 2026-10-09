<?php
require_once 'core.php';
requireAuth();

header('Content-Type: application/json');

@set_time_limit(300);
@ini_set('memory_limit', '256M');

ensureOriginalesDir();

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
    $registry = getOptimizedRegistry();
    $force = isset($_GET['force']) ? ($_GET['force'] === '1' || $_GET['force'] === 'true') : (!empty($body['force']));
    $mediaReal = realpath(MEDIA_DIR);
    if ($mediaReal === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Directorio media no accesible']);
        exit;
    }

    $filesToAnalyze = [];

    // Comprobar si se enviaron targets específicos (desde selección múltiple: carpetas e imágenes)
    $targets = $body['targets'] ?? null;
    if (is_array($targets) && count($targets) > 0) {
        foreach ($targets as $tgt) {
            $rawPath = is_array($tgt) ? ($tgt['path'] ?? '') : (string)$tgt;
            $safe = validateSafeRelativePath($rawPath);
            if ($safe === false || isHiddenPath($safe)) continue;

            $fullTgt = resolveSecurePath($safe);
            if ($fullTgt === false || is_link($fullTgt)) continue;

            if (is_dir($fullTgt)) {
                // Carpeta seleccionada: escanear recursivamente
                try {
                    $dirIt = new RecursiveDirectoryIterator($fullTgt, FilesystemIterator::SKIP_DOTS);
                    $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($it as $item) {
                        if ($item->isLink() || $item->isDir()) continue;
                        $realItem = realpath($item->getPathname());
                        if ($realItem === false || is_link($realItem)) continue;
                        $sub = substr($realItem, strlen($mediaReal));
                        $sub = str_replace('\\', '/', ltrim($sub, '/\\'));
                        if (isHiddenPath($sub) || isHiddenItem($item->getFilename())) continue;
                        $filesToAnalyze[$sub] = $realItem;
                    }
                } catch (Exception $e) {}
            } else if (file_exists($fullTgt)) {
                // Archivo seleccionado
                $sub = substr(realpath($fullTgt), strlen($mediaReal));
                $sub = str_replace('\\', '/', ltrim($sub, '/\\'));
                if (!isHiddenPath($sub) && !isHiddenItem(basename($sub))) {
                    $filesToAnalyze[$sub] = realpath($fullTgt);
                }
            }
        }
    } else {
        // Carpeta indicada (comportamiento estándar)
        $folder = $_GET['path'] ?? ($body['path'] ?? '');
        $folder = trim(str_replace('\\', '/', (string)$folder), '/');

        if ($folder !== '') {
            $safeFolder = validateSafeRelativePath($folder);
            if ($safeFolder === false || isHiddenPath($safeFolder)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ruta de carpeta inválida o segmentos no permitidos']);
                exit;
            }
            $folder = $safeFolder;
        }

        $fullFolder = resolveSecurePath($folder === '' ? '' : $folder);
        if ($fullFolder === false && $folder === '') {
            $fullFolder = $mediaReal;
        }

        if ($fullFolder === false || !is_dir($fullFolder)) {
            http_response_code(400);
            echo json_encode(['error' => 'Directorio inválido']);
            exit;
        }

        $recursive = isset($_GET['recursive']) ? ($_GET['recursive'] === '1' || $_GET['recursive'] === 'true') : (!empty($body['recursive']));

        try {
            if ($recursive) {
                $dirIterator = new RecursiveDirectoryIterator($fullFolder, FilesystemIterator::SKIP_DOTS);
                $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
            } else {
                $iterator = new FilesystemIterator($fullFolder, FilesystemIterator::SKIP_DOTS);
            }

            foreach ($iterator as $item) {
                if ($item->isLink() || $item->isDir()) continue;
                $itemReal = realpath($item->getPathname());
                if ($itemReal === false || is_link($itemReal)) continue;

                $subPath = substr($itemReal, strlen($mediaReal));
                $subPath = str_replace('\\', '/', ltrim($subPath, '/\\'));

                if (isHiddenPath($subPath) || isHiddenItem($item->getFilename())) continue;
                $filesToAnalyze[$subPath] = $itemReal;
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al analizar la carpeta: ' . $e->getMessage()]);
            exit;
        }
    }

    $files = [];
    $totalImages = 0;
    $totalSize = 0;
    $estimatedTotalSize = 0;

    foreach ($filesToAnalyze as $subPath => $itemReal) {
        $filename = basename($itemReal);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $validExts, true)) continue;

        $rule = getOptimizeRuleForPath($subPath);
        $size = filesize($itemReal);

        $width = 0;
        $height = 0;
        $imgInfo = @getimagesize($itemReal);
        if ($imgInfo && isset($imgInfo[0], $imgInfo[1])) {
            $width = (int)$imgInfo[0];
            $height = (int)$imgInfo[1];
        }

        $isPending = isImagePendingOptimization($subPath, $itemReal, $rule, $registry, $width, $height);
        if (!$isPending && !$force) {
            continue; // Se salta si ya está optimizada con la misma regla, salvo que force sea true
        }

        // Estimar nuevo peso
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
        // Si es JPG o PNG se convertirá a WebP, lo cual produce un ahorro adicional notable
        $formatFactor = ($ext !== 'webp') ? 0.65 : 0.85;
        $estFactor = min(0.90, $pixelRatio * $qualityFactor * $formatFactor);
        $estSize = (int)round($size * $estFactor);
        if ($estSize >= $size) {
            $estSize = (int)round($size * 0.90);
        }
        if (!empty($rule['max_kb'])) {
            $estSize = min($estSize, (int)$rule['max_kb'] * 1024);
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
            'is_pending' => $isPending,
            'will_convert' => ($ext !== 'webp'),
            'estimated_size' => $estSize,
            'has_backup' => $hasBackup
        ];

        $totalImages++;
        $totalSize += $size;
        $estimatedTotalSize += $estSize;
    }

    echo json_encode([
        'success' => true,
        'force' => $force,
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

    $force = !empty($body['force']);
    $registry = getOptimizedRegistry();

    // Lote de máximo 10 rutas
    $filePaths = array_slice($filePaths, 0, 10);
    $results = [];
    $errors = [];

    foreach ($filePaths as $relPath) {
        $tmpFile = null;
        try {
            // Rechaza con 400 rutas con segmentos ".." o "."
            $safeRelPath = validateSafeRelativePath($relPath);
            if ($safeRelPath === false || isHiddenPath($safeRelPath)) {
                http_response_code(400);
                throw new Exception('Ruta no válida o contiene segmentos no permitidos (".." o ".")');
            }
            $relPath = $safeRelPath;

            $fullPath = resolveSecurePath($relPath);
            if ($fullPath === false || !file_exists($fullPath) || is_dir($fullPath) || is_link($fullPath)) {
                throw new Exception('Archivo no encontrado o inaccesible');
            }

            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (!in_array($ext, $validExts, true)) {
                throw new Exception('Formato no soportado para optimización (nunca se procesan .gif ni otros)');
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
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $trans = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $newWidth, $newHeight, $trans);

            imagecopyresampled($destImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Archivo temporal oculto en la MISMA carpeta para reemplazo atómico con rename()
            $tmpFile = dirname($fullPath) . '/.opt_' . uniqid() . '.webp';
            // Codifica respetando el peso máximo (max_kb) de la regla, bajando la calidad si hace falta
            $finalQuality = encodeWebpWithinBudget($destImage, $tmpFile, $rule, $newWidth, $newHeight);
            $encoded = ($finalQuality !== false);

            imagedestroy($sourceImage);
            imagedestroy($destImage);

            if (!$encoded || !file_exists($tmpFile)) {
                if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
                throw new Exception('Error al codificar imagen a WebP');
            }

            $oldSize = filesize($fullPath);
            $newSize = filesize($tmpFile);

            if ($ext !== 'webp') {
                // CASO 1: JPG / JPEG / PNG -> SIEMPRE se convierte a WebP
                // 1) Copiar original a .originales/<relPath>
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

                // 2) Determinar nombre final .webp (con sufijos -1, -2 si ya existe)
                $baseName = pathinfo($fullPath, PATHINFO_FILENAME);
                $parentDir = dirname($fullPath);
                $destFileName = $baseName . '.webp';
                $destFilePath = $parentDir . '/' . $destFileName;
                $counter = 1;
                while (file_exists($destFilePath)) {
                    $destFileName = $baseName . '-' . $counter . '.webp';
                    $destFilePath = $parentDir . '/' . $destFileName;
                    $counter++;
                }

                // 3) Reemplazo atómico con rename()
                if (!@rename($tmpFile, $destFilePath)) {
                    @unlink($tmpFile);
                    throw new Exception('No se pudo mover el archivo temporal a su destino final');
                }

                // 4) Borrar el archivo original
                @unlink($fullPath);

                $destRelDir = dirname($relPath);
                $destRelDir = ($destRelDir === '.' || $destRelDir === '/') ? '' : $destRelDir;
                $finalRelPath = $destRelDir === '' ? $destFileName : $destRelDir . '/' . $destFileName;

                $savedBytes = max(0, $oldSize - $newSize);
                $savedPercent = $oldSize > 0 ? round(($savedBytes / $oldSize) * 100, 1) : 0;

                // Registrar en .optimizadas.json
                updateOptimizedRegistryEntry($finalRelPath, [
                    'fecha' => date('c'),
                    'regla' => $rule,
                    'calidad_final' => $finalQuality,
                    'peso_antes' => $oldSize,
                    'peso_despues' => $newSize,
                    'original_convertido' => $relPath
                ]);
                if ($finalRelPath !== $relPath) {
                    removeOptimizedRegistryEntry($relPath);
                }

                $results[] = [
                    'path' => $relPath,
                    'final_path' => $finalRelPath,
                    'optimized' => true,
                    'converted_to_webp' => true,
                    'original_size' => $oldSize,
                    'new_size' => $newSize,
                    'saved_bytes' => $savedBytes,
                    'saved_percent' => $savedPercent,
                    'width' => $newWidth,
                    'height' => $newHeight,
                    'rule' => $rule
                ];

            } else {
                // CASO 2: WEBP -> Optimizar conservando nombre y ruta
                $threshold = $oldSize * 0.90; // Debe pesar al menos 10% menos o requerir redimensionamiento
                $needsResize = ($maxSide > $maxSideLimit);
                // Si supera el peso máximo de su regla, cualquier reducción se acepta
                $overBudget = ((int)($rule['max_kb'] ?? 0) > 0) && ($oldSize > (int)$rule['max_kb'] * 1024);

                if ($newSize <= $threshold || $needsResize || $force || ($overBudget && $newSize < $oldSize)) {
                    // Si el nuevo tamaño es menor, aplicamos el reemplazo
                    if ($newSize < $oldSize || $needsResize) {
                        $backupPath = MEDIA_DIR . '/.originales/' . $relPath;
                        $backupDir = dirname($backupPath);
                        if (!is_dir($backupDir)) {
                            @mkdir($backupDir, 0755, true);
                        }
                        if (!file_exists($backupPath)) {
                            if (!copy($fullPath, $backupPath)) {
                                @unlink($tmpFile);
                                throw new Exception('No se pudo respaldar original en .originales');
                            }
                        }

                        // Reemplazo atómico con rename()
                        if (!@rename($tmpFile, $fullPath)) {
                            @unlink($tmpFile);
                            throw new Exception('No se pudo reemplazar el archivo con rename()');
                        }

                        $savedBytes = max(0, $oldSize - $newSize);
                        $savedPercent = $oldSize > 0 ? round(($savedBytes / $oldSize) * 100, 1) : 0;

                        updateOptimizedRegistryEntry($relPath, [
                            'fecha' => date('c'),
                            'regla' => $rule,
                            'calidad_final' => $finalQuality,
                            'peso_antes' => $oldSize,
                            'peso_despues' => $newSize
                        ]);

                        $results[] = [
                            'path' => $relPath,
                            'final_path' => $relPath,
                            'optimized' => true,
                            'converted_to_webp' => false,
                            'original_size' => $oldSize,
                            'new_size' => $newSize,
                            'saved_bytes' => $savedBytes,
                            'saved_percent' => $savedPercent,
                            'width' => $newWidth,
                            'height' => $newHeight,
                            'rule' => $rule
                        ];
                    } else {
                        // El nuevo archivo pesaba más o igual, no conviene reemplazar
                        @unlink($tmpFile);
                        updateOptimizedRegistryEntry($relPath, [
                            'fecha' => date('c'),
                            'regla' => $rule,
                            'calidad_final' => $finalQuality,
                            'peso_antes' => $oldSize,
                            'peso_despues' => $oldSize,
                            'skipped' => true
                        ]);

                        $results[] = [
                            'path' => $relPath,
                            'final_path' => $relPath,
                            'optimized' => false,
                            'reason' => 'Sin reducción de peso',
                            'original_size' => $oldSize,
                            'new_size' => $oldSize,
                            'width' => $width,
                            'height' => $height,
                            'rule' => $rule
                        ];
                    }
                } else {
                    @unlink($tmpFile);
                    updateOptimizedRegistryEntry($relPath, [
                        'fecha' => date('c'),
                        'regla' => $rule,
                        'calidad_final' => $finalQuality,
                        'peso_antes' => $oldSize,
                        'peso_despues' => $oldSize,
                        'skipped' => true
                    ]);

                    $results[] = [
                        'path' => $relPath,
                        'final_path' => $relPath,
                        'optimized' => false,
                        'reason' => 'Ahorro menor al 10%',
                        'original_size' => $oldSize,
                        'new_size' => $oldSize,
                        'width' => $width,
                        'height' => $height,
                        'rule' => $rule
                    ];
                }
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

    $rawPath = $body['path'] ?? ($_GET['path'] ?? '');
    // Rechaza con 400 rutas con segmentos ".." o "."
    $safeRelPath = validateSafeRelativePath($rawPath);
    if ($safeRelPath === false || isHiddenPath($safeRelPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ruta no válida o contiene segmentos no permitidos (".." o ".")']);
        exit;
    }
    $relPath = $safeRelPath;

    $backupPath = MEDIA_DIR . '/.originales/' . $relPath;
    $targetRestoreRel = $relPath;
    $isConvertedFromOther = false;

    if (!file_exists($backupPath)) {
        $registry = getOptimizedRegistry();
        if (isset($registry[$relPath]['original_convertido'])) {
            $origConv = $registry[$relPath]['original_convertido'];
            $altBackup = MEDIA_DIR . '/.originales/' . $origConv;
            if (file_exists($altBackup)) {
                $backupPath = $altBackup;
                $targetRestoreRel = $origConv;
                $isConvertedFromOther = true;
            }
        }
    }

    if (!file_exists($backupPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'No existe copia en .originales para este archivo']);
        exit;
    }

    $fullDest = resolveSecurePath($targetRestoreRel);
    if ($fullDest === false) {
        $fullDest = resolveSecureParentPath($targetRestoreRel);
    }

    if ($fullDest === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ruta destino inválida']);
        exit;
    }

    $parentDir = dirname($fullDest);
    if (!is_dir($parentDir)) {
        @mkdir($parentDir, 0755, true);
    }

    if (copy($backupPath, $fullDest)) {
        @unlink($backupPath);
        removeOptimizedRegistryEntry($relPath);

        // Si era una imagen convertida desde JPG/PNG, borrar el .webp generado
        if ($isConvertedFromOther) {
            $webpCurrent = resolveSecurePath($relPath);
            if ($webpCurrent && file_exists($webpCurrent)) {
                @unlink($webpCurrent);
            }
            removeOptimizedRegistryEntry($targetRestoreRel);
        }

        // Limpiar carpetas vacías en .originales
        $parentBackup = dirname($backupPath);
        $rootBackup = realpath(MEDIA_DIR . '/.originales');
        while ($parentBackup && $rootBackup && $parentBackup !== $rootBackup && is_dir($parentBackup)) {
            $contents = array_diff(scandir($parentBackup), ['.', '..']);
            if (empty($contents)) {
                @rmdir($parentBackup);
                $parentBackup = dirname($parentBackup);
            } else {
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Archivo original restaurado con éxito',
            'path' => $targetRestoreRel
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al restaurar archivo desde .originales']);
        exit;
    }
} else if ($action === 'purge_backups') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }

    $origDir = realpath(MEDIA_DIR . '/.originales');
    if (!$origDir || !is_dir($origDir)) {
        echo json_encode(['success' => true, 'deleted_files' => 0, 'freed_bytes' => 0]);
        exit;
    }

    $deletedCount = 0;
    $freedBytes = 0;

    try {
        $dirIt = new RecursiveDirectoryIterator($origDir, FilesystemIterator::SKIP_DOTS);
        $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($it as $item) {
            $filename = $item->getFilename();
            if ($filename === '.htaccess' || $filename === '.optimizadas.json') {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                $fileSize = $item->getSize();
                if (@unlink($item->getPathname())) {
                    $deletedCount++;
                    $freedBytes += $fileSize;
                }
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al vaciar respaldos: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'deleted_files' => $deletedCount,
        'freed_bytes' => $freedBytes
    ]);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida o no especificada']);
    exit;
}

