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
define('WEBP_QUALITY', $config['webp_quality'] ?? 75);

// Lista blanca de extensiones de subida configurables
$defaultAllowedExtensions = [
    'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif', 'ico', 'pdf',
    'doc', 'docx', 'xls', 'xlsx', 'zip', 'mp4', 'txt', 'css', 'json',
    'woff', 'woff2'
];
define('ALLOWED_EXTENSIONS', is_array($config['allowed_extensions'] ?? null) ? $config['allowed_extensions'] : $defaultAllowedExtensions);

// Usuarios autorizados
$users = $config['users'] ?? ['admin' => 'admin'];

// Reglas de optimización de imágenes (patrón, lado mayor máximo y calidad)
// max_kb: peso máximo objetivo en KB (0 = sin límite). Si la imagen supera ese peso con 'quality',
// la calidad baja de 5 en 5 hasta 'min_quality'; si aún no cumple, las dimensiones bajan en pasos
// del 10% sin que el lado mayor baje de 'min_side' (por defecto, igual a max_side: no se reduce).
$defaultOptimizeRules = [
    ['pattern' => '*icono*',         'max_side' => 192,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 15],
    ['pattern' => '*favicon*',       'max_side' => 192,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 15],
    ['pattern' => '*logo*',          'max_side' => 600,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 20],
    ['pattern' => '*portada-movil*', 'max_side' => 1280, 'quality' => 70, 'min_quality' => 50, 'max_kb' => 60,  'min_side' => 1024],
    ['pattern' => 'dominios/*',      'max_side' => 1600, 'quality' => 75, 'min_quality' => 55, 'max_kb' => 120, 'min_side' => 1280],
    ['pattern' => '*',               'max_side' => 1024, 'quality' => 70, 'min_quality' => 55, 'max_kb' => 90,  'min_side' => 720],
];
$optimizeRules = $config['optimize_rules'] ?? $defaultOptimizeRules;
if (!is_array($optimizeRules)) {
    $optimizeRules = $defaultOptimizeRules;
}

function getOptimizeRuleForPath($relativePath) {
    global $optimizeRules;
    $norm = str_replace('\\', '/', ltrim($relativePath, '/'));
    $flags = defined('FNM_CASEFOLD') ? FNM_CASEFOLD : 0;
    foreach ($optimizeRules as $rule) {
        if (!isset($rule['pattern'])) continue;
        $pattern = $rule['pattern'];
        if (fnmatch($pattern, $norm, $flags) || fnmatch(mb_strtolower($pattern, 'UTF-8'), mb_strtolower($norm, 'UTF-8'))) {
            $quality = (int)($rule['quality'] ?? 70);
            return [
                'pattern' => $rule['pattern'],
                'max_side' => (int)($rule['max_side'] ?? 1024),
                'quality' => $quality,
                'min_quality' => min($quality, (int)($rule['min_quality'] ?? $quality)),
                'max_kb' => max(0, (int)($rule['max_kb'] ?? 0)),
                'min_side' => (int)($rule['min_side'] ?? ($rule['max_side'] ?? 1024)),
            ];
        }
    }
    return ['pattern' => '*', 'max_side' => 1024, 'quality' => 70, 'min_quality' => 55, 'max_kb' => 90, 'min_side' => 720];
}

/**
 * Codifica $image como WebP en $destFile respetando el peso máximo de la regla (max_kb).
 * 1) Empieza con $rule['quality'] y baja la calidad de 5 en 5 hasta $rule['min_quality'].
 * 2) Si con la calidad mínima aún supera max_kb, reduce las dimensiones en pasos del 10%
 *    (sin bajar el lado mayor de $rule['min_side']) y vuelve a codificar con calidad mínima.
 * Las fotos con mucho detalle (selva, ruinas) casi no bajan de peso solo con calidad;
 * reducir un poco las dimensiones es lo que realmente las aligera.
 * $outW/$outH reciben las dimensiones finales. Devuelve la calidad final, o false si falló.
 */
function encodeWebpWithinBudget($image, $destFile, $rule, &$outW = null, &$outH = null) {
    $quality = (int)($rule['quality'] ?? 70);
    $minQuality = min($quality, (int)($rule['min_quality'] ?? $quality));
    $maxBytes = max(0, (int)($rule['max_kb'] ?? 0)) * 1024;
    $width = imagesx($image);
    $height = imagesy($image);
    $minSide = (int)($rule['min_side'] ?? max($width, $height));
    $outW = $width;
    $outH = $height;

    $fits = function () use ($destFile, $maxBytes) {
        clearstatcache(true, $destFile);
        $size = @filesize($destFile);
        return $size !== false && ($maxBytes <= 0 || $size <= $maxBytes);
    };

    // 1) Bajar calidad
    while (true) {
        if (!imagewebp($image, $destFile, $quality)) {
            return false;
        }
        if ($fits() || $quality <= $minQuality) {
            break;
        }
        $quality = max($minQuality, $quality - 5);
    }
    if ($fits()) {
        return $quality;
    }

    // 2) Reducir dimensiones en pasos del 10% sin bajar de min_side
    $maxSide = max($width, $height);
    for ($scale = 0.9; $scale >= 0.5; $scale -= 0.1) {
        $w = (int)round($width * $scale);
        $h = (int)round($height * $scale);
        if (max($w, $h) < $minSide || $w < 1 || $h < 1) {
            break;
        }
        $scaled = imagecreatetruecolor($w, $h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefilledrectangle($scaled, 0, 0, $w, $h, imagecolorallocatealpha($scaled, 255, 255, 255, 127));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        $ok = imagewebp($scaled, $destFile, $minQuality);
        imagedestroy($scaled);
        if (!$ok) {
            return false;
        }
        $outW = $w;
        $outH = $h;
        if ($fits()) {
            break;
        }
    }
    return $minQuality;
}

// Archivos o carpetas ocultos
$hiddenFiles = $config['hidden_files'] ?? [];
if (!is_array($hiddenFiles)) {
    $hiddenFiles = [];
}

function isHiddenItem($name) {
    global $hiddenFiles;
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (strpos($name, '.') === 0) {
        return true;
    }
    if (in_array($name, $hiddenFiles, true)) {
        return true;
    }
    return false;
}

function isHiddenPath($path) {
    if (empty($path)) return false;
    $path = str_replace('\\', '/', $path);
    $segments = explode('/', trim($path, '/'));
    foreach ($segments as $segment) {
        if (isHiddenItem($segment)) {
            return true;
        }
    }
    return false;
}

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

function ensureOriginalesDir() {
    $origDir = MEDIA_DIR . '/.originales';
    if (!is_dir($origDir)) {
        @mkdir($origDir, 0755, true);
    }
    $htaccess = $origDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
    return $origDir;
}

function validateSafeRelativePath($path) {
    $norm = str_replace('\\', '/', trim((string)$path, "/ \t\n\r\0\x0B"));
    if ($norm === '') return false;
    $segments = explode('/', $norm);
    foreach ($segments as $seg) {
        if ($seg === '.' || $seg === '..') {
            return false;
        }
    }
    return $norm;
}

function saveOptimizedRegistry($registry) {
    ensureOriginalesDir();
    $regFile = MEDIA_DIR . '/.originales/.optimizadas.json';
    $fp = @fopen($regFile, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function getOptimizedRegistry() {
    ensureOriginalesDir();
    $regFile = MEDIA_DIR . '/.originales/.optimizadas.json';
    
    // Si no existe, crear registro inicial con las imágenes que ya tengan copia en .originales
    if (!file_exists($regFile)) {
        $registry = [];
        $origReal = realpath(MEDIA_DIR . '/.originales');
        if ($origReal && is_dir($origReal)) {
            try {
                $dirIt = new RecursiveDirectoryIterator($origReal, FilesystemIterator::SKIP_DOTS);
                $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $item) {
                    if ($item->isDir() || $item->isLink()) continue;
                    $filename = $item->getFilename();
                    if ($filename === '.htaccess' || $filename === '.optimizadas.json') continue;
                    $itemReal = realpath($item->getPathname());
                    if (!$itemReal) continue;
                    $subPath = substr($itemReal, strlen($origReal));
                    $subPath = str_replace('\\', '/', ltrim($subPath, '/\\'));
                    
                    $targetPath = $subPath;
                    if (!file_exists(MEDIA_DIR . '/' . $targetPath)) {
                        $webpCandidate = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $subPath);
                        if (file_exists(MEDIA_DIR . '/' . $webpCandidate)) {
                            $targetPath = $webpCandidate;
                        }
                    }
                    $rule = getOptimizeRuleForPath($targetPath);
                    $mediaFile = MEDIA_DIR . '/' . $targetPath;
                    $sizeAfter = file_exists($mediaFile) ? filesize($mediaFile) : 0;
                    $registry[$targetPath] = [
                        'fecha' => date('c', filemtime($itemReal)),
                        'regla' => $rule,
                        'peso_antes' => filesize($itemReal),
                        'peso_despues' => $sizeAfter
                    ];
                }
            } catch (Exception $e) {}
        }
        saveOptimizedRegistry($registry);
        return $registry;
    }

    $fp = @fopen($regFile, 'r');
    if (!$fp) return [];
    if (flock($fp, LOCK_SH)) {
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    fclose($fp);
    return [];
}

function updateOptimizedRegistryEntry($relativePath, $entryData) {
    ensureOriginalesDir();
    $regFile = MEDIA_DIR . '/.originales/.optimizadas.json';
    $norm = str_replace('\\', '/', ltrim($relativePath, '/'));
    $fp = @fopen($regFile, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $registry = $content ? (json_decode($content, true) ?: []) : [];
        $registry[$norm] = $entryData;
        ftruncate($fp, 0);
        rewind($fp);
        $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function renameOptimizedRegistryEntry($oldRelPath, $newRelPath) {
    ensureOriginalesDir();
    $regFile = MEDIA_DIR . '/.originales/.optimizadas.json';
    $oldNorm = str_replace('\\', '/', trim($oldRelPath, '/'));
    $newNorm = str_replace('\\', '/', trim($newRelPath, '/'));
    if ($oldNorm === '' || $newNorm === '') return false;

    // Si existe copia física en .originales, mover/renombrar también
    $oldOrig = MEDIA_DIR . '/.originales/' . $oldNorm;
    $newOrig = MEDIA_DIR . '/.originales/' . $newNorm;
    if (file_exists($oldOrig)) {
        $parentNew = dirname($newOrig);
        if (!is_dir($parentNew)) {
            @mkdir($parentNew, 0755, true);
        }
        @rename($oldOrig, $newOrig);
    }

    $fp = @fopen($regFile, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $registry = $content ? (json_decode($content, true) ?: []) : [];
        $changed = false;
        
        if (isset($registry[$oldNorm])) {
            $registry[$newNorm] = $registry[$oldNorm];
            unset($registry[$oldNorm]);
            $changed = true;
        }

        $oldPrefix = $oldNorm . '/';
        $newPrefix = $newNorm . '/';
        $prefixLen = strlen($oldPrefix);
        foreach ($registry as $k => $v) {
            if (strpos($k, $oldPrefix) === 0) {
                $subKey = substr($k, $prefixLen);
                $registry[$newPrefix . $subKey] = $v;
                unset($registry[$k]);
                $changed = true;
            }
        }

        if ($changed) {
            ftruncate($fp, 0);
            rewind($fp);
            $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            fwrite($fp, $json);
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function removeOptimizedRegistryEntry($relPath) {
    ensureOriginalesDir();
    $regFile = MEDIA_DIR . '/.originales/.optimizadas.json';
    $norm = str_replace('\\', '/', trim($relPath, '/'));
    if ($norm === '') return false;

    $fp = @fopen($regFile, 'c+');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $registry = $content ? (json_decode($content, true) ?: []) : [];
        $changed = false;
        
        if (isset($registry[$norm])) {
            unset($registry[$norm]);
            $changed = true;
        }

        $prefix = $norm . '/';
        foreach ($registry as $k => $v) {
            if (strpos($k, $prefix) === 0) {
                unset($registry[$k]);
                $changed = true;
            }
        }

        if ($changed) {
            ftruncate($fp, 0);
            rewind($fp);
            $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            fwrite($fp, $json);
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function isImagePendingOptimization($relPath, $fullPath = null, $rule = null, $registry = null, $imgWidth = 0, $imgHeight = 0) {
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true)) {
        return false;
    }
    // 1) Todo .jpg/.jpeg/.png siempre está pendiente (para convertirse a WebP)
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return true;
    }

    if ($rule === null) {
        $rule = getOptimizeRuleForPath($relPath);
    }
    $maxSideLimit = (int)($rule['max_side'] ?? 1024);
    $qualityTarget = (int)($rule['quality'] ?? 70);

    // 2) Si su lado mayor > max_side de su regla -> pendiente
    if ($imgWidth <= 0 || $imgHeight <= 0) {
        if ($fullPath && file_exists($fullPath)) {
            $info = @getimagesize($fullPath);
            if ($info && isset($info[0], $info[1])) {
                $imgWidth = (int)$info[0];
                $imgHeight = (int)$info[1];
            }
        }
    }
    if (max($imgWidth, $imgHeight) > $maxSideLimit && max($imgWidth, $imgHeight) > 0) {
        return true;
    }

    // 3) Si no está en el registro -> pendiente
    $norm = str_replace('\\', '/', ltrim($relPath, '/'));
    if ($registry === null) {
        $registry = getOptimizedRegistry();
    }
    if (!isset($registry[$norm])) {
        return true;
    }

    // 3b) Si pesa más que el max_kb de su regla -> pendiente, salvo que ya se haya
    //     intentado con la calidad mínima de esa misma regla (no se puede bajar más)
    $maxKb = (int)($rule['max_kb'] ?? 0);
    if ($maxKb > 0 && $fullPath && file_exists($fullPath) && filesize($fullPath) > $maxKb * 1024) {
        $entryQ = $registry[$norm]['calidad_final'] ?? null;
        $entryRule = $registry[$norm]['regla'] ?? [];
        $minQ = (int)($rule['min_quality'] ?? $qualityTarget);
        $triedAtMin = $entryQ !== null && (int)$entryQ <= $minQ
            && (int)($entryRule['max_kb'] ?? 0) === $maxKb;
        if (!$triedAtMin) {
            return true;
        }
    }

    // 4) Si está en el registro con una regla diferente (distinto max_side o quality) -> pendiente
    $entry = $registry[$norm];
    $regRule = $entry['regla'] ?? [];
    if (!isset($regRule['max_side']) || !isset($regRule['quality'])) {
        return true;
    }
    if ((int)$regRule['max_side'] !== $maxSideLimit || (int)$regRule['quality'] !== $qualityTarget) {
        return true;
    }

    // Ya optimizada con la misma regla y dimensiones adecuadas
    return false;
}

function getBackupsInfo() {
    $origDir = realpath(MEDIA_DIR . '/.originales');
    $count = 0;
    $size = 0;
    if ($origDir && is_dir($origDir)) {
        try {
            $dirIt = new RecursiveDirectoryIterator($origDir, FilesystemIterator::SKIP_DOTS);
            $it = new RecursiveIteratorIterator($dirIt, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $item) {
                if ($item->isDir() || $item->isLink()) continue;
                $fn = $item->getFilename();
                if ($fn === '.htaccess' || $fn === '.optimizadas.json') continue;
                $count++;
                $size += $item->getSize();
            }
        } catch (Exception $e) {}
    }
    return ['count' => $count, 'size' => $size];
}

