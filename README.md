# Imagenes Easy Way (Easy File Manager)

Un gestor de archivos e imágenes minimalista, rápido y moderno en un solo archivo HTML y unos pocos scripts PHP en el backend.

## Instalación

1. Sube el contenido de este repositorio a tu servidor web (soporte para PHP 7.4+ requerido).
2. Entra a la carpeta \api\.
3. Renombra o copia el archivo \config.example.php\ a \config.php\.
4. Edita \config.php\ y cambia el nombre de usuario y contraseña por defecto.
5. (Opcional) Cambia la ruta \media_dir\ en \config.php\ para decidir dónde se guardan físicamente los archivos. Por defecto los guardará en la misma carpeta raíz.

## Estructura
- \index.html\: Toda la interfaz de usuario en una Single Page Application.
- \api/\: Los endpoints de backend en PHP.

## Características
- Compresión automática a WebP.
- Drag and drop (arrastrar y soltar).
- Bulk actions (seleccionar varios para borrar, mover o copiar enlace).
- Navegación rápida sin recargas.
- Autenticación segura.

## Generar contraseñas
Para agregar usuarios, puedes generar el hash seguro de sus contraseñas ejecutando desde la línea de comandos:

``bash
php api/hash.php "miclave"
``

Luego, copia el hash resultante y pégalo en el array 'users' de pi/config.php.
