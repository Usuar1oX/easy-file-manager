<?php
// Configuración de Easy File Manager
return [
    // Directorio donde se guardan los archivos (relativo a la carpeta api)
    // '../' significa la carpeta raíz del proyecto
    'media_dir' => '../media',
    
    // Usuarios que pueden acceder al sistema
    // 'usuario' => 'contraseña'
    'users' => [
        'admin' => 'admin'
    ],

    // Tamaño máximo de subida
    'max_file_size' => 50 * 1024 * 1024, // 50MB
    
    // Umbral a partir del cual se redimensionan las imágenes
    'resize_threshold' => 5 * 1024 * 1024, // 5MB
    
    // Límite máximo en píxeles para el lado mayor (ancho o alto) al redimensionar imágenes
    'max_width' => 1920,

    // Calidad de compresión WebP (1-100, por defecto 75)
    'webp_quality' => 75,

    // (Opcional) Archivos o carpetas adicionales para ocultar en el listado y proteger contra edición/borrado.
    // Los nombres que empiezan con punto (.htaccess, .ftpquota, etc.) se ocultan siempre por defecto.
    'hidden_files' => [
        // 'archivo_privado.ext',
        // 'carpeta_privada'
    ],

    // Reglas de optimización de imágenes (se evalúan en orden; gana la PRIMERA que coincida).
    // Coincidencia con fnmatch insensible a mayúsculas sobre la ruta relativa a media_dir.
    'optimize_rules' => [
        ['pattern' => '*icono*',    'max_side' => 192,  'quality' => 80],
        ['pattern' => '*favicon*',  'max_side' => 192,  'quality' => 80],
        ['pattern' => '*logo*',     'max_side' => 800,  'quality' => 80],
        ['pattern' => 'dominios/*', 'max_side' => 1600, 'quality' => 75],
        ['pattern' => '*',          'max_side' => 1024, 'quality' => 70],
    ]
];
