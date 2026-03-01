# WooCommerce Image Sync - Aplicacion de Escritorio

Sincroniza automaticamente las imagenes de productos desde una carpeta de tu PC/servidor local a WooCommerce.

## Requisitos

- Python 3.8 o superior (descargar de https://www.python.org/downloads/)
- Conexion a internet
- Claves API de WooCommerce

## Instalacion

### 1. Instalar Python
Si no tienes Python, descargalo de https://www.python.org/downloads/
**IMPORTANTE**: Durante la instalacion, marca la casilla "Add Python to PATH".

### 2. Instalar dependencias
Abre la terminal/CMD y ejecuta:
```
pip install requests
```

### 3. Ejecutar la aplicacion
Doble clic en `woo_image_sync.py` o desde la terminal:
```
python woo_image_sync.py
```

## Configuracion

### Obtener claves API de WooCommerce:
1. Ve a tu tienda WordPress
2. WooCommerce > Ajustes > Avanzado > API REST
3. Haz clic en "Anadir clave"
4. Nombre: "Image Sync"
5. Permisos: **Lectura/Escritura**
6. Haz clic en "Generar clave API"
7. Copia el **Consumer Key** y **Consumer Secret**

### En la aplicacion:
1. Pestana **Configuracion**:
   - URL de la tienda: `https://tu-tienda.com`
   - Consumer Key: pega tu clave
   - Consumer Secret: pega tu secreto
   - Carpeta de imagenes: selecciona la carpeta con las imagenes
2. Haz clic en **Probar Conexion** para verificar
3. Haz clic en **Guardar Configuracion**

## Uso

1. Nombra tus archivos de imagen con el SKU del producto:
   - Ejemplo: `SKU001.jpg`, `SKU002.png`, `PROD-ABC.webp`
2. Ve a la pestana **Sincronizar**
3. Haz clic en **Escanear Carpeta**
4. Revisa la lista de archivos encontrados
5. Haz clic en **Sincronizar**
6. Espera a que termine - veras el progreso en tiempo real

## Notas

- Los productos que ya tienen imagen NO se duplicaran (configurable)
- Formatos soportados: JPG, JPEG, PNG, GIF, WEBP
- La configuracion se guarda automaticamente en tu PC
- Puedes detener la sincronizacion en cualquier momento
