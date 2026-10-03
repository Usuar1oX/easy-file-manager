<?php
// Configuración de Easy File Manager
return [
    // Directorio donde se guardan los archivos (relativo a la carpeta api)
    // '../' significa la carpeta raíz del proyecto
    'media_dir' => '../',
    
    // Usuarios que pueden acceder al sistema
    // 'usuario' => 'contraseña'
    'users' => [
        'admin' => 'admin123'
    ],

    // Tamaño máximo de subida
    'max_file_size' => 50 * 1024 * 1024, // 50MB
    
    // Umbral a partir del cual se redimensionan las imágenes
    'resize_threshold' => 5 * 1024 * 1024, // 5MB
    
    // Ancho máximo al redimensionar imágenes grandes
    'max_width' => 1920
];
