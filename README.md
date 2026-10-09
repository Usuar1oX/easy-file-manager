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
8. (Opcional) Configura reglas en `'optimize_rules'` para ajustar el tamaño máximo (`max_side`), la calidad inicial (`quality`), el peso máximo objetivo en KB (`max_kb`), la calidad mínima permitida (`min_quality`) y el límite inferior de dimensiones (`min_side`) según patrones de nombres o rutas (por ejemplo logos, iconos, portadas móviles o fotos generales). Si una imagen supera `max_kb`, la calidad baja de 5 en 5 hasta cumplirlo (sin bajar de `min_quality`) y, si aún excede el peso, reduce las dimensiones en pasos del 10% sin bajar de `min_side`.
9. (Opcional) Configura `'allowed_extensions'` para definir la lista blanca de extensiones permitidas al subir archivos (por defecto incluye imágenes, documentos, audio/video y recursos web como `.css`, `.json`, `.woff`, `.woff2`). Por motivos de seguridad, ejecutables/scripts (`php`, `html`, `js`, etc.) y archivos ocultos (`.htaccess`) permanecen siempre bloqueados.

## Estructura
- `index.html`: Toda la interfaz de usuario en una Single Page Application.
- `api/`: Los endpoints de backend en PHP (`auth.php`, `core.php`, `explorer.php`, `upload.php`, `optimize.php`).

## Características
- Compresión automática a WebP con calidad configurable (`webp_quality`).
- Redimensionamiento proporcional por el lado mayor (horizontal y vertical).
- Herramienta **Optimizar imágenes** unificada:
  - Convierte automáticamente imágenes `.jpg`/`.jpeg`/`.png` a formato WebP conservando una copia del original en `.originales`.
  - Optimiza imágenes `.webp` existentes según las reglas `optimize_rules` aplicables por patrón de nombre o ruta, conservando nombre y ruta.
  - Peso máximo por regla (`max_kb`): las imágenes que lo superan se marcan como pendientes y se recomprimen con calidad descendente (hasta `min_quality`) y reducción gradual de dimensiones (hasta `min_side`) hasta cumplirlo.
  - Registro atómico concurrente en `MEDIA_DIR/.originales/.optimizadas.json` (evita dobles optimizaciones innecesarias).
  - Indicador visual **"Sin optimizar"** en miniaturas y contador de pendientes en la barra superior.
  - Soporte para **"Optimizar selección"** (carpetas e imágenes por lotes de 10) con progreso en vivo y botón de cancelación segura.
- Botón **Restaurar original** en el visor y menú contextual de cada imagen optimizada.
- Visualización de peso en KB y dimensiones en cada imagen, con indicador destacado en naranja para imágenes superiores a 300 KB.
- Drag and drop (arrastrar y soltar).
- Bulk actions (seleccionar varios para borrar, mover, optimizar o copiar enlace).
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
