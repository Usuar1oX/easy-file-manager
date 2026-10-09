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

    // (Opcional) Lista blanca de extensiones permitidas para subida (en minúsculas, sin punto).
    // Por motivos de seguridad, ejecutables/scripts (php, html, js, etc.) y archivos ocultos (.htaccess) están siempre bloqueados.
    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif', 'ico', 'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'zip', 'mp4', 'txt', 'css', 'json',
        'woff', 'woff2'
    ],

    // Reglas de optimización de imágenes (se evalúan en orden; gana la PRIMERA que coincida).
    // Coincidencia con fnmatch insensible a mayúsculas sobre la ruta relativa a media_dir.
    // max_side: lado mayor máximo en px. quality: calidad WebP inicial.
    // max_kb: peso máximo objetivo en KB (0 = sin límite); si se supera, la calidad baja de 5 en 5
    // hasta cumplirlo, sin bajar de min_quality.
    // min_side: si con min_quality aún supera max_kb, reduce dimensiones en pasos del 10% hasta este límite.
    'optimize_rules' => [
        ['pattern' => '*icono*',         'max_side' => 192,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 15],
        ['pattern' => '*favicon*',       'max_side' => 192,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 15],
        ['pattern' => '*logo*',          'max_side' => 600,  'quality' => 80, 'min_quality' => 60, 'max_kb' => 20],
        ['pattern' => '*portada-movil*', 'max_side' => 1280, 'quality' => 70, 'min_quality' => 50, 'max_kb' => 60,  'min_side' => 1024],
        ['pattern' => 'dominios/*',      'max_side' => 1600, 'quality' => 75, 'min_quality' => 55, 'max_kb' => 120, 'min_side' => 1280],
        ['pattern' => '*',               'max_side' => 1024, 'quality' => 70, 'min_quality' => 55, 'max_kb' => 90,  'min_side' => 720],
    ]
];
