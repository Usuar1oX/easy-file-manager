# Imagenes Easy Way (Easy File Manager)

Un gestor de archivos e imágenes minimalista, rápido y moderno en un solo archivo HTML y unos pocos scripts PHP en el backend.

## Instalación

1. Sube el contenido de este repositorio a tu servidor web (soporte para PHP 7.4+ requerido).
2. Entra a la carpeta \api\.
3. Renombra o copia el archivo \config.example.json\ a \config.json\.
4. Edita \config.json\ y cambia el nombre de usuario y contraseña por defecto.
5. (Opcional) Cambia la ruta \storagePath\ en \config.json\ para decidir dónde se guardan físicamente los archivos. Por defecto los guardará en la misma carpeta raíz.

## Estructura
- \index.html\: Toda la interfaz de usuario en una Single Page Application.
- \api/\: Los endpoints de backend en PHP.

## Características
- Compresión automática a WebP.
- Drag and drop (arrastrar y soltar).
- Bulk actions (seleccionar varios para borrar, mover o copiar enlace).
- Navegación rápida sin recargas.
- Autenticación segura.
