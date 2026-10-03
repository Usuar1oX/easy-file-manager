# Imagenes Easy Way (Easy File Manager)

Un gestor de archivos e imÃ¡genes minimalista, rÃ¡pido y moderno en un solo archivo HTML y unos pocos scripts PHP en el backend.

## InstalaciÃ³n

1. Sube el contenido de este repositorio a tu servidor web (soporte para PHP 7.4+ requerido).
2. Entra a la carpeta \api\.
3. Renombra o copia el archivo \config.example.php\ a \config.php\.
4. Edita \config.php\ y cambia el nombre de usuario y contraseÃ±a por defecto.
5. (Opcional) Cambia la ruta \storagePath\ en \config.php\ para decidir dÃ³nde se guardan fÃ­sicamente los archivos. Por defecto los guardarÃ¡ en la misma carpeta raÃ­z.

## Estructura
- \index.html\: Toda la interfaz de usuario en una Single Page Application.
- \api/\: Los endpoints de backend en PHP.

## CaracterÃ­sticas
- CompresiÃ³n automÃ¡tica a WebP.
- Drag and drop (arrastrar y soltar).
- Bulk actions (seleccionar varios para borrar, mover o copiar enlace).
- NavegaciÃ³n rÃ¡pida sin recargas.
- AutenticaciÃ³n segura.
