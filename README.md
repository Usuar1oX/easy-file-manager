# Easy File Manager

Un gestor de archivos e imágenes minimalista, rápido y moderno en un solo archivo HTML y unos pocos scripts PHP en el backend.

## Instalación

1. Sube el contenido de este repositorio a tu servidor web (soporte para PHP 7.4+ requerido).
2. Entra a la carpeta `api/`.
3. Renombra o copia el archivo `config.example.php` a `config.php`.
4. Edita `config.php` y cambia el nombre de usuario y contraseña por defecto.
5. (Opcional) Cambia la ruta `media_dir` en `config.php` para decidir dónde se guardan físicamente los archivos. Por defecto los guardará en la misma carpeta raíz.
6. (Opcional) Agrega `'hidden_files' => ['archivo.ext']` en `config.php` si deseas ocultar archivos o carpetas adicionales en el explorador y protegerlos contra edición o borrado desde la API. El sistema ya oculta y protege de forma predeterminada todos los elementos que comienzan con punto (como `.htaccess`, `.ftpquota`, etc.).
7. (Opcional) Ajusta `'max_width'` (límite del lado mayor para fotos horizontales y verticales, por defecto 1920) y `'webp_quality'` (calidad de compresión WebP, por defecto 75).

## Estructura
- `index.html`: Toda la interfaz de usuario en una Single Page Application.
- `api/`: Los endpoints de backend en PHP.

## Características
- Compresión automática a WebP con calidad configurable (`webp_quality`).
- Redimensionamiento proporcional por el lado mayor (horizontal y vertical).
- Generación automática de versión mediana `-800` (lado mayor 800 px) para miniaturas y tarjetas responsive con `srcset`.
- Visualización de peso en KB y dimensiones en cada imagen, con indicador destacado en naranja para imágenes superiores a 300 KB.
- Drag and drop (arrastrar y soltar).
- Bulk actions (seleccionar varios para borrar, mover o copiar enlace).
- Navegación rápida sin recargas.
- Autenticación segura.

## Generar contraseñas
Para agregar usuarios, puedes generar el hash seguro de sus contraseñas ejecutando desde la línea de comandos:

```bash
php api/hash.php "miclave"
```

Luego, copia el hash resultante y pégalo en el array 'users' de api/config.php.

## Protección de subidas
Debes proteger el directorio donde se suben las imágenes para evitar ejecución de scripts. Copia el archivo `media.htaccess` de la raíz del proyecto y pégalo como `.htaccess` dentro de la carpeta de subidas de tu servidor web.

Ten en cuenta que el listado público de la carpeta es opcional (las 3 primeras líneas del archivo). Sin embargo, la directiva `DirectoryIndex disabled` es obligatoria si se deja activo el bloqueo de archivos `.html`/`.php`, para evitar que Apache intente resolver un archivo índice bloqueado y arroje un error 403 al abrir la carpeta.
