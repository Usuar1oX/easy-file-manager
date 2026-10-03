<?php
require_once 'core.php';
requireAuth();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$pathParam = isset($_GET['path']) ? $_GET['path'] : '';
// Evitar Directory Traversal
$pathParam = str_replace(['../', '..\\'], '', $pathParam);
$pathParam = trim($pathParam, '/');

$targetPath = MEDIA_DIR;
if ($pathParam !== '') {
    $targetPath .= '/' . $pathParam;
}

if (!is_dir(MEDIA_DIR)) {
    mkdir(MEDIA_DIR, 0777, true);
}

function deleteDir($dirPath) {
    if (!is_dir($dirPath)) {
        return false;
    }
    $files = array_diff(scandir($dirPath), array('.','..'));
    foreach ($files as $file) {
        $path = "$dirPath/$file";
        (is_dir($path)) ? deleteDir($path) : unlink($path);
    }
    return rmdir($dirPath);
}

function copyDir($src, $dst) {
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0777, true);
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                copyDir("$src/$file", "$dst/$file");
            }
        }
    } else if (file_exists($src)) {
        copy($src, $dst);
    }
}

function normalizeSearchText($text) {
    $lower = mb_strtolower($text, 'UTF-8');
    return strtr($lower, [
        'Ã¡'=>'a', 'Ã©'=>'e', 'Ã­'=>'i', 'Ã³'=>'o', 'Ãº'=>'u', 'Ã¼'=>'u', 'Ã±'=>'n',
        'Ã'=>'a', 'Ã‰'=>'e', 'Ã'=>'i', 'Ã“'=>'o', 'Ãš'=>'u', 'Ãœ'=>'u', 'Ã‘'=>'n'
    ]);
}

if ($method === 'GET') {
    $searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    if ($searchQuery !== '') {
        if (!is_dir(MEDIA_DIR)) {
            echo json_encode(['isSearch' => true, 'searchQuery' => $searchQuery, 'folders' => [], 'files' => []]);
            exit;
        }

        $folders = [];
        $files = [];
        $maxResults = 250;
        $normSearch = normalizeSearchText($searchQuery);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(MEDIA_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                $filename = $fileInfo->getFilename();
                if ($filename === '.htaccess') continue;

                $normName = normalizeSearchText($filename);

                if (strpos($normName, $normSearch) !== false) {
                    $subPath = $iterator->getSubPathname();
                    $subPath = str_replace('\\', '/', $subPath);
                    $parentDir = dirname($subPath);
                    if ($parentDir === '.') $parentDir = '';

                    if ($fileInfo->isDir()) {
                        $folders[] = [
                            'name' => $filename,
                            'path' => $subPath,
                            'directory' => $parentDir
                        ];
                    } else {
                        $fullPath = $fileInfo->getPathname();
                        $url = '/media/' . $subPath;
                        $files[] = [
                            'name' => $filename,
                            'path' => $subPath,
                            'directory' => $parentDir,
                            'url' => $url,
                            'size' => $fileInfo->getSize(),
                            'type' => @mime_content_type($fullPath) ?: 'application/octet-stream'
                        ];
                    }

                    if (count($folders) + count($files) >= $maxResults) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // Manejo de excepciones en permisos de lectura
        }

        echo json_encode([
            'isSearch' => true,
            'searchQuery' => $searchQuery,
            'folders' => $folders,
            'files' => $files
        ]);
        exit;
    }

    if (!is_dir($targetPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Directory not found']);
        exit;
    }

    $items = scandir($targetPath);
    $folders = [];
    $files = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item === '.htaccess') continue;
        
        $itemPath = $targetPath . '/' . $item;
        $relativePath = $pathParam === '' ? $item : $pathParam . '/' . $item;
        
        // Relative URL para public
        $url = '/media/' . $relativePath;
        
        if (is_dir($itemPath)) {
            $folders[] = [
                'name' => $item,
                'path' => $relativePath
            ];
        } else {
            $files[] = [
                'name' => $item,
                'path' => $relativePath,
                'url' => $url,
                'size' => filesize($itemPath),
                'type' => mime_content_type($itemPath)
            ];
        }
    }

    echo json_encode([
        'currentPath' => $pathParam,
        'folders' => $folders,
        'files' => $files
    ]);

} else if ($method === 'POST') {
    // Crear carpeta
    $data = json_decode(file_get_contents('php://input'), true);
    $folderName = $data['folderName'] ?? '';
    
    if (empty($folderName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Folder name is required']);
        exit;
    }
    
    // Limpiar nombre
    $folderName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $folderName);
    
    $newFolderPath = $targetPath . '/' . $folderName;
    
    if (is_dir($newFolderPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Folder already exists']);
        exit;
    }
    
    if (mkdir($newFolderPath, 0777, true)) {
        echo json_encode(['success' => true, 'message' => 'Folder created']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create folder']);
    }
} else if ($method === 'DELETE') {
    if (empty($pathParam) || !file_exists($targetPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid path']);
        exit;
    }
    
    $success = false;
    if (is_dir($targetPath)) {
        $success = deleteDir($targetPath);
    } else {
        $success = unlink($targetPath);
    }
    
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete']);
    }
} else if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $newName = $data['newName'] ?? '';
    
    if (empty($newName) || empty($pathParam) || !file_exists($targetPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }
    
    // Validar nombre (letras, numeros, puntos, guiones, espacios no al inicio/fin)
    $newName = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $newName);
    $newName = trim($newName);
    
    if (empty($newName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid name']);
        exit;
    }

    $parentDir = dirname($targetPath);
    $newPath = $parentDir . '/' . $newName;
    
    if (file_exists($newPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'A file or folder with that name already exists']);
        exit;
    }
    
    if (rename($targetPath, $newPath)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to rename']);
    }
} else if ($method === 'PATCH') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $sourcePath = $data['sourcePath'] ?? '';
    $targetDir = $data['targetDir'] ?? '';
    
    if (empty($action) || empty($sourcePath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }

    $sourcePath = str_replace(['../', '..\\'], '', $sourcePath);
    $sourcePath = trim($sourcePath, '/');
    
    $targetDir = str_replace(['../', '..\\'], '', $targetDir);
    $targetDir = trim($targetDir, '/');

    $fullSourcePath = MEDIA_DIR . '/' . $sourcePath;
    $fullTargetDir = MEDIA_DIR;
    if ($targetDir !== '') {
        $fullTargetDir .= '/' . $targetDir;
    }

    if (!file_exists($fullSourcePath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Source not found']);
        exit;
    }

    $sourceName = basename($fullSourcePath);
    $fullTargetPath = $fullTargetDir . '/' . $sourceName;

    // Evitar mover dentro de si mismo
    if (strpos(realpath($fullTargetDir), realpath($fullSourcePath)) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot move/copy into itself']);
        exit;
    }

    if (file_exists($fullTargetPath)) {
        $info = pathinfo($sourceName);
        $name = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $counter = 1;
        while (file_exists($fullTargetDir . '/' . $name . '-' . $counter . $ext)) {
            $counter++;
        }
        $fullTargetPath = $fullTargetDir . '/' . $name . '-' . $counter . $ext;
    }

    $success = false;
    if ($action === 'cut') {
        $success = rename($fullSourcePath, $fullTargetPath);
    } else if ($action === 'copy') {
        if (is_dir($fullSourcePath)) {
            copyDir($fullSourcePath, $fullTargetPath);
            $success = true;
        } else {
            $success = copy($fullSourcePath, $fullTargetPath);
        }
    } else if ($action === 'convert_webp') {
        if (!file_exists($fullSourcePath) || is_dir($fullSourcePath)) {
            http_response_code(400);
            echo json_encode(['error' => 'Source file not found or is a directory']);
            exit;
        }
        
        $mimeType = mime_content_type($fullSourcePath);
        $isImage = in_array($mimeType, ['image/jpeg', 'image/png']);
        
        if (!$isImage) {
            http_response_code(400);
            echo json_encode(['error' => 'Not a valid image for conversion']);
            exit;
        }
        
        $sourceImage = null;
        if ($mimeType === 'image/jpeg') {
            $sourceImage = @imagecreatefromjpeg($fullSourcePath);
        } elseif ($mimeType === 'image/png') {
            $sourceImage = @imagecreatefrompng($fullSourcePath);
        }
        
        if ($sourceImage) {
            $width = imagesx($sourceImage);
            $height = imagesy($sourceImage);
            $newWidth = $width;
            $newHeight = $height;

            $fileSize = filesize($fullSourcePath);
            if ($fileSize > RESIZE_THRESHOLD || $width > MAX_WIDTH) {
                if ($width > MAX_WIDTH) {
                    $newWidth = MAX_WIDTH;
                    $newHeight = floor($height * (MAX_WIDTH / $width));
                }
            }

            $destinationImage = imagecreatetruecolor($newWidth, $newHeight);

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

            $info = pathinfo(basename($fullSourcePath));
            $finalName = $info['filename'] . '.webp';
            $finalPath = dirname($fullSourcePath) . '/' . $finalName;

            $success = imagewebp($destinationImage, $finalPath, 80);

            imagedestroy($sourceImage);
            imagedestroy($destinationImage);
            
            if ($success) {
                unlink($fullSourcePath); // Borrar el original
                echo json_encode(['success' => true]);
                exit;
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to convert image']);
                exit;
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error reading the original image']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Action failed']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
