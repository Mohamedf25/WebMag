# WooCommerce Image Sync

Plugin de WordPress para sincronizar automaticamente imagenes de productos con WooCommerce.

## Caracteristicas

- **Subida desde PC**: Arrastra y suelta imagenes desde tu computadora para sincronizarlas con productos
- **Sincronizacion desde servidor**: Configura una carpeta en el servidor y sincroniza todas las imagenes de una vez
- **Asociacion por SKU**: El nombre del archivo de imagen debe coincidir con el SKU del producto (ej: `SKU123.jpg` se asigna al producto con SKU `SKU123`)
- **Opciones de asociacion**: Tambien puedes asociar por ID, slug o nombre del producto
- **Deteccion de duplicados**: No sube imagenes a productos que ya tienen imagen destacada
- **Barra de progreso**: Muestra el progreso en tiempo real durante la sincronizacion
- **Registro de actividad**: Historial de todas las sincronizaciones realizadas

## Instalacion

1. Descarga el plugin (carpeta `woo-image-sync`)
2. Sube la carpeta al directorio `/wp-content/plugins/` de tu WordPress
3. Activa el plugin desde el panel de WordPress en **Plugins**
4. Ve a **WooCommerce > Sync Imagenes** para configurar y usar

## Uso

### Subir imagenes desde tu PC

1. Ve a **WooCommerce > Sync Imagenes**
2. En la pestana "Subir desde PC", arrastra imagenes o haz clic en "Seleccionar Archivos"
3. Asegurate de que el nombre de cada archivo sea el SKU del producto (ej: `ABC123.jpg`)
4. Haz clic en "Subir y Sincronizar"

### Sincronizar desde carpeta del servidor

1. Ve a **WooCommerce > Sync Imagenes > Configuracion**
2. Establece la ruta de la carpeta del servidor (ej: `/var/www/html/imagenes-productos/`)
3. Guarda la configuracion
4. Ve a la pestana "Sincronizar desde Servidor"
5. Haz clic en "Escanear Carpeta" para previsualizar
6. Haz clic en "Sincronizar Imagenes" para ejecutar

## Configuracion

- **Carpeta del servidor**: Ruta absoluta donde estan las imagenes
- **Asociar por**: SKU (default), ID, Slug o Nombre del producto
- **Omitir productos con imagen**: Evita duplicar imagenes (activado por defecto)
- **Tipo de imagen**: Imagen destacada, galeria, o ambas

## Formatos soportados

JPG, JPEG, PNG, GIF, WEBP

## Requisitos

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
