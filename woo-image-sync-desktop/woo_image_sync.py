#!/usr/bin/env python3
"""
WooCommerce Image Sync - Desktop Application
Sincroniza imagenes de productos desde una carpeta local a WooCommerce.
"""

import json
import os
import sys
import threading
import tkinter as tk
from tkinter import ttk, filedialog, messagebox, scrolledtext
from pathlib import Path
from datetime import datetime, date

try:
    import requests
except ImportError:
    print("Instalando dependencia 'requests'...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "requests"])
    import requests

# ============================================================
# Configuration Manager
# ============================================================

CONFIG_DIR = os.path.join(str(Path.home()), ".woo-image-sync")
CONFIG_FILE = os.path.join(CONFIG_DIR, "config.json")

ALLOWED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}


def load_config():
    """Load saved configuration."""
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                return json.load(f)
        except (json.JSONDecodeError, IOError):
            pass
    return {
        "store_url": "",
        "consumer_key": "",
        "consumer_secret": "",
        "folder_path": "",
        "match_by": "sku",
        "skip_existing": True,
        "filter_by_date": False,
        "filter_date": "",
    }


def save_config(config):
    """Save configuration to disk."""
    os.makedirs(CONFIG_DIR, exist_ok=True)
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(config, f, indent=2)


# ============================================================
# WooCommerce API Client
# ============================================================

class WooCommerceClient:
    """Simple WooCommerce REST API client."""

    def __init__(self, store_url, consumer_key, consumer_secret):
        self.base_url = store_url.rstrip("/") + "/wp-json/wc/v3"
        self.auth = (consumer_key, consumer_secret)
        self.session = requests.Session()
        self.session.auth = self.auth
        self.session.headers.update({"User-Agent": "WooImageSync/1.0"})

    def test_connection(self):
        """Test the API connection."""
        try:
            resp = self.session.get(f"{self.base_url}/system_status", timeout=15)
            if resp.status_code == 200:
                return True, "Conexion exitosa"
            elif resp.status_code == 401:
                return False, "Error de autenticacion. Verifica las claves API."
            else:
                return False, f"Error HTTP {resp.status_code}: {resp.text[:200]}"
        except requests.exceptions.ConnectionError:
            return False, "No se puede conectar al servidor. Verifica la URL."
        except requests.exceptions.Timeout:
            return False, "Tiempo de espera agotado."
        except Exception as e:
            return False, f"Error: {str(e)}"

    def get_all_products(self, callback=None):
        """Fetch all products from WooCommerce."""
        products = []
        page = 1
        per_page = 100

        while True:
            try:
                resp = self.session.get(
                    f"{self.base_url}/products",
                    params={"page": page, "per_page": per_page, "status": "publish"},
                    timeout=30,
                )
                resp.raise_for_status()
                batch = resp.json()

                if not batch:
                    break

                products.extend(batch)
                if callback:
                    callback(f"Cargando productos... ({len(products)} encontrados)")

                # Check if there are more pages
                total_pages = int(resp.headers.get("X-WP-TotalPages", 1))
                if page >= total_pages:
                    break
                page += 1

            except Exception as e:
                if callback:
                    callback(f"Error al cargar productos: {str(e)}")
                break

        return products

    def get_product_by_sku(self, sku):
        """Find a product by SKU."""
        try:
            resp = self.session.get(
                f"{self.base_url}/products",
                params={"sku": sku, "per_page": 1},
                timeout=15,
            )
            resp.raise_for_status()
            products = resp.json()
            return products[0] if products else None
        except Exception:
            return None

    def get_product_by_id(self, product_id):
        """Find a product by ID."""
        try:
            resp = self.session.get(
                f"{self.base_url}/products/{product_id}",
                timeout=15,
            )
            if resp.status_code == 200:
                return resp.json()
            return None
        except Exception:
            return None

    def get_product_by_slug(self, slug):
        """Find a product by slug."""
        try:
            resp = self.session.get(
                f"{self.base_url}/products",
                params={"slug": slug, "per_page": 1},
                timeout=15,
            )
            resp.raise_for_status()
            products = resp.json()
            return products[0] if products else None
        except Exception:
            return None

    def product_has_image(self, product):
        """Check if a product already has a featured image."""
        if not product:
            return False
        images = product.get("images", [])
        if not images:
            return False
        # WooCommerce always returns a placeholder if no image is set
        # Check if the first image is a real image (not placeholder)
        first_image = images[0]
        src = first_image.get("src", "")
        # Placeholder images typically contain "woocommerce-placeholder" or "placeholder"
        if "placeholder" in src.lower():
            return False
        return bool(src)

    def upload_image_to_product(self, product_id, image_path):
        """Upload an image and set it as the product's featured image.

        Uses a temporary local HTTP server to serve the image file, then
        passes the URL to the WooCommerce REST API which downloads it.
        This is the most reliable method since WC consumer key/secret
        does not authenticate against the WP REST API media endpoint.
        """
        filename = os.path.basename(image_path)
        errors = []

        # Method 1: Temporary HTTP server + WC API src URL
        # WooCommerce accepts a publicly-accessible URL in images[].src
        # and will download and import the image itself.
        try:
            success, message = self._upload_via_temp_server(
                product_id, image_path, filename)
            if success:
                return True, message
            errors.append(f"Metodo servidor temporal: {message}")
        except Exception as e:
            errors.append(f"Metodo servidor temporal: {str(e)}")

        # Method 2: Direct WP media upload (works if Application Passwords
        # or a JWT auth plugin is configured)
        try:
            success, message = self._upload_via_wp_media(
                product_id, image_path, filename)
            if success:
                return True, message
            errors.append(f"Metodo WP Media: {message}")
        except Exception as e:
            errors.append(f"Metodo WP Media: {str(e)}")

        # Method 3: Multipart form upload directly to WC API
        try:
            success, message = self._upload_via_multipart(
                product_id, image_path, filename)
            if success:
                return True, message
            errors.append(f"Metodo multipart: {message}")
        except Exception as e:
            errors.append(f"Metodo multipart: {str(e)}")

        return False, "Todos los metodos fallaron: " + " | ".join(errors)

    def _upload_via_temp_server(self, product_id, image_path, filename):
        """Upload by starting a temporary HTTP server to serve the image,
        then telling WooCommerce to download it from that URL."""
        import http.server
        import socketserver
        import urllib.parse

        image_dir = os.path.dirname(os.path.abspath(image_path))
        image_name = os.path.basename(image_path)

        # Find a free port
        server = None
        port = 0
        try:
            handler = http.server.SimpleHTTPRequestHandler

            class QuietHandler(handler):
                """Suppress log output."""
                def log_message(self, format, *args):
                    pass

                def __init__(self, *args, **kwargs):
                    super().__init__(*args, directory=image_dir, **kwargs)

            server = socketserver.TCPServer(("0.0.0.0", 0), QuietHandler)
            port = server.server_address[1]

            # Start server in a background thread
            server_thread = threading.Thread(target=server.serve_forever, daemon=True)
            server_thread.start()

            # Get the public IP or use the store URL's domain to determine reachability
            # Since the local server won't be reachable from a remote WooCommerce store,
            # this method only works if WooCommerce is on the same machine or network.
            # We try it and fall through to other methods if it fails.
            encoded_name = urllib.parse.quote(image_name)
            image_url = f"http://localhost:{port}/{encoded_name}"

            # Get existing gallery images to preserve them
            existing_images = self._get_existing_images(product_id)

            # Tell WooCommerce to download the image from our temp server
            images_payload = [{"src": image_url, "name": filename}]
            images_payload.extend(existing_images)

            resp = self.session.put(
                f"{self.base_url}/products/{product_id}",
                json={"images": images_payload},
                timeout=120,
            )

            if resp.status_code == 200:
                result = resp.json()
                new_images = result.get("images", [])
                if new_images and not any(
                    "placeholder" in (img.get("src", "")).lower()
                    for img in new_images[:1]
                ):
                    return True, "Imagen subida correctamente (via servidor temporal)"
                else:
                    return False, "WooCommerce no descargo la imagen"
            else:
                error_text = resp.text[:300] if resp.text else "Sin respuesta"
                return False, f"Error HTTP {resp.status_code}: {error_text}"

        except Exception as e:
            return False, str(e)
        finally:
            if server:
                server.shutdown()

    def _upload_via_wp_media(self, product_id, image_path, filename):
        """Upload via WP REST API media endpoint.
        Works if Application Passwords or JWT Auth is enabled."""
        mime_types = {
            ".jpg": "image/jpeg",
            ".jpeg": "image/jpeg",
            ".png": "image/png",
            ".gif": "image/gif",
            ".webp": "image/webp",
        }
        ext = os.path.splitext(filename)[1].lower()
        mime_type = mime_types.get(ext, "image/jpeg")

        media_url = self.base_url.replace("/wc/v3", "/wp/v2") + "/media"

        try:
            with open(image_path, "rb") as img_file:
                file_data = img_file.read()

            headers = {
                "Content-Disposition": f'attachment; filename="{filename}"',
                "Content-Type": mime_type,
            }

            resp = self.session.post(
                media_url,
                data=file_data,
                headers=headers,
                timeout=60,
            )

            if resp.status_code in (200, 201):
                media_data = resp.json()
                media_id = media_data.get("id")
                media_src = media_data.get("source_url", "")

                existing_images = self._get_existing_images(product_id)
                images_payload = [{"id": media_id, "src": media_src}]
                images_payload.extend(existing_images)

                update_resp = self.session.put(
                    f"{self.base_url}/products/{product_id}",
                    json={"images": images_payload},
                    timeout=30,
                )
                update_resp.raise_for_status()
                return True, f"Imagen subida correctamente (Media ID: {media_id})"
            else:
                return False, f"Error HTTP {resp.status_code}: {resp.text[:200]}"

        except Exception as e:
            return False, str(e)

    def _upload_via_multipart(self, product_id, image_path, filename):
        """Upload image using multipart form data to the WC products endpoint."""
        mime_types = {
            ".jpg": "image/jpeg",
            ".jpeg": "image/jpeg",
            ".png": "image/png",
            ".gif": "image/gif",
            ".webp": "image/webp",
        }
        ext = os.path.splitext(filename)[1].lower()
        mime_type = mime_types.get(ext, "image/jpeg")

        try:
            # Try uploading the image as a file attachment to the product
            with open(image_path, "rb") as img_file:
                files = {"file": (filename, img_file, mime_type)}
                # Use a separate request without the session's JSON headers
                resp = requests.post(
                    f"{self.base_url}/products/{product_id}",
                    auth=self.auth,
                    files=files,
                    data={"images": json.dumps([{"name": filename}])},
                    timeout=60,
                )

            if resp.status_code == 200:
                return True, "Imagen subida correctamente (via multipart)"
            else:
                return False, f"Error HTTP {resp.status_code}: {resp.text[:200]}"

        except Exception as e:
            return False, str(e)

    def _get_existing_images(self, product_id):
        """Get existing gallery images for a product (excluding the first/featured)."""
        try:
            resp = self.session.get(
                f"{self.base_url}/products/{product_id}",
                timeout=15,
            )
            if resp.status_code == 200:
                product = resp.json()
                images = product.get("images", [])
                # Return all images except the first one (featured)
                # Filter out placeholder images
                real_images = []
                for img in images:
                    src = img.get("src", "")
                    if src and "placeholder" not in src.lower():
                        real_images.append({"id": img.get("id"), "src": src})
                if len(real_images) > 0:
                    return real_images[1:]  # Skip the featured image
            return []
        except Exception:
            return []


# ============================================================
# Main GUI Application
# ============================================================

class WooImageSyncApp:
    """Main application window."""

    def __init__(self, root):
        self.root = root
        self.root.title("WooCommerce Image Sync")
        self.root.geometry("800x700")
        self.root.minsize(700, 600)

        # Load config
        self.config = load_config()
        self.client = None
        self.is_syncing = False

        # Style
        style = ttk.Style()
        style.theme_use("clam")

        self.setup_ui()
        self.load_config_to_ui()

    def setup_ui(self):
        """Build the user interface."""
        # Main notebook (tabs)
        self.notebook = ttk.Notebook(self.root)
        self.notebook.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)

        # Tab 1: Configuration
        self.config_frame = ttk.Frame(self.notebook, padding=15)
        self.notebook.add(self.config_frame, text="  Configuracion  ")
        self.setup_config_tab()

        # Tab 2: Sync
        self.sync_frame = ttk.Frame(self.notebook, padding=15)
        self.notebook.add(self.sync_frame, text="  Sincronizar  ")
        self.setup_sync_tab()

        # Tab 3: Log
        self.log_frame = ttk.Frame(self.notebook, padding=15)
        self.notebook.add(self.log_frame, text="  Registro  ")
        self.setup_log_tab()

    def setup_config_tab(self):
        """Setup the configuration tab."""
        frame = self.config_frame

        # Title
        ttk.Label(frame, text="Configuracion de WooCommerce",
                  font=("", 14, "bold")).grid(row=0, column=0, columnspan=3,
                                               sticky=tk.W, pady=(0, 15))

        # Store URL
        ttk.Label(frame, text="URL de la tienda:").grid(
            row=1, column=0, sticky=tk.W, pady=5)
        self.url_var = tk.StringVar()
        ttk.Entry(frame, textvariable=self.url_var, width=50).grid(
            row=1, column=1, columnspan=2, sticky=tk.EW, pady=5, padx=(10, 0))

        ttk.Label(frame, text="Ej: https://tu-tienda.com",
                  foreground="gray").grid(row=2, column=1, sticky=tk.W, padx=(10, 0))

        # Consumer Key
        ttk.Label(frame, text="Consumer Key:").grid(
            row=3, column=0, sticky=tk.W, pady=5)
        self.key_var = tk.StringVar()
        ttk.Entry(frame, textvariable=self.key_var, width=50).grid(
            row=3, column=1, columnspan=2, sticky=tk.EW, pady=5, padx=(10, 0))

        # Consumer Secret
        ttk.Label(frame, text="Consumer Secret:").grid(
            row=4, column=0, sticky=tk.W, pady=5)
        self.secret_var = tk.StringVar()
        ttk.Entry(frame, textvariable=self.secret_var, width=50, show="*").grid(
            row=4, column=1, columnspan=2, sticky=tk.EW, pady=5, padx=(10, 0))

        # Help text for API keys
        help_text = ("Para obtener las claves API:\n"
                     "WooCommerce > Ajustes > Avanzado > API REST > Anadir clave\n"
                     "Permisos: Lectura/Escritura")
        ttk.Label(frame, text=help_text, foreground="gray",
                  justify=tk.LEFT).grid(row=5, column=0, columnspan=3,
                                         sticky=tk.W, pady=(5, 15))

        # Separator
        ttk.Separator(frame, orient=tk.HORIZONTAL).grid(
            row=6, column=0, columnspan=3, sticky=tk.EW, pady=10)

        # Folder Path
        ttk.Label(frame, text="Carpeta de imagenes:").grid(
            row=7, column=0, sticky=tk.W, pady=5)
        self.folder_var = tk.StringVar()
        ttk.Entry(frame, textvariable=self.folder_var, width=40).grid(
            row=7, column=1, sticky=tk.EW, pady=5, padx=(10, 5))
        ttk.Button(frame, text="Examinar...", command=self.browse_folder).grid(
            row=7, column=2, pady=5)

        # Match by
        ttk.Label(frame, text="Asociar por:").grid(
            row=8, column=0, sticky=tk.W, pady=5)
        self.match_var = tk.StringVar(value="sku")
        match_combo = ttk.Combobox(frame, textvariable=self.match_var,
                                    values=["sku", "id", "slug"],
                                    state="readonly", width=20)
        match_combo.grid(row=8, column=1, sticky=tk.W, pady=5, padx=(10, 0))

        ttk.Label(frame, text="El nombre del archivo = SKU/ID/Slug del producto",
                  foreground="gray").grid(row=9, column=1, sticky=tk.W, padx=(10, 0))

        # Skip existing
        self.skip_var = tk.BooleanVar(value=True)
        ttk.Checkbutton(frame, text="No subir si el producto ya tiene imagen",
                        variable=self.skip_var).grid(
            row=10, column=0, columnspan=3, sticky=tk.W, pady=5)

        # Separator
        ttk.Separator(frame, orient=tk.HORIZONTAL).grid(
            row=11, column=0, columnspan=3, sticky=tk.EW, pady=10)

        # Date filter section
        ttk.Label(frame, text="Filtro por Fecha",
                  font=("", 12, "bold")).grid(row=12, column=0, columnspan=3,
                                               sticky=tk.W, pady=(5, 5))

        self.date_filter_var = tk.BooleanVar(value=False)
        ttk.Checkbutton(frame, text="Solo sincronizar imagenes desde una fecha",
                        variable=self.date_filter_var,
                        command=self._toggle_date_filter).grid(
            row=13, column=0, columnspan=3, sticky=tk.W, pady=5)

        # Date input frame
        self.date_frame = ttk.Frame(frame)
        self.date_frame.grid(row=14, column=0, columnspan=3, sticky=tk.W, pady=5)

        ttk.Label(self.date_frame, text="Desde:").pack(side=tk.LEFT)

        # Day
        ttk.Label(self.date_frame, text="  Dia:").pack(side=tk.LEFT, padx=(10, 2))
        self.day_var = tk.StringVar(value=str(date.today().day))
        self.day_spin = ttk.Spinbox(self.date_frame, from_=1, to=31, width=4,
                                     textvariable=self.day_var, state=tk.DISABLED)
        self.day_spin.pack(side=tk.LEFT)

        # Month
        ttk.Label(self.date_frame, text="  Mes:").pack(side=tk.LEFT, padx=(10, 2))
        self.month_var = tk.StringVar(value=str(date.today().month))
        self.month_spin = ttk.Spinbox(self.date_frame, from_=1, to=12, width=4,
                                       textvariable=self.month_var, state=tk.DISABLED)
        self.month_spin.pack(side=tk.LEFT)

        # Year
        ttk.Label(self.date_frame, text="  Ano:").pack(side=tk.LEFT, padx=(10, 2))
        self.year_var = tk.StringVar(value=str(date.today().year))
        self.year_spin = ttk.Spinbox(self.date_frame, from_=2020, to=2030, width=6,
                                      textvariable=self.year_var, state=tk.DISABLED)
        self.year_spin.pack(side=tk.LEFT)

        ttk.Label(frame, text="Solo se sincronizaran imagenes creadas o modificadas despues de esta fecha.",
                  foreground="gray").grid(row=15, column=0, columnspan=3, sticky=tk.W, padx=(0, 0))

        # Buttons
        btn_frame = ttk.Frame(frame)
        btn_frame.grid(row=16, column=0, columnspan=3, pady=15)

        ttk.Button(btn_frame, text="Probar Conexion",
                   command=self.test_connection).pack(side=tk.LEFT, padx=5)
        ttk.Button(btn_frame, text="Guardar Configuracion",
                   command=self.save_configuration).pack(side=tk.LEFT, padx=5)

        # Connection status
        self.status_var = tk.StringVar(value="")
        ttk.Label(frame, textvariable=self.status_var,
                  font=("", 10)).grid(row=17, column=0, columnspan=3, pady=5)

        # Configure grid weights
        frame.columnconfigure(1, weight=1)

    def setup_sync_tab(self):
        """Setup the sync tab."""
        frame = self.sync_frame

        # Title
        ttk.Label(frame, text="Sincronizar Imagenes",
                  font=("", 14, "bold")).pack(anchor=tk.W, pady=(0, 10))

        # Info
        info_frame = ttk.LabelFrame(frame, text="Informacion", padding=10)
        info_frame.pack(fill=tk.X, pady=(0, 10))

        self.info_label = ttk.Label(info_frame,
                                     text="Configura tu tienda en la pestana de Configuracion y haz clic en 'Escanear'.",
                                     wraplength=700)
        self.info_label.pack(anchor=tk.W)

        # Buttons
        btn_frame = ttk.Frame(frame)
        btn_frame.pack(fill=tk.X, pady=10)

        self.scan_btn = ttk.Button(btn_frame, text="Escanear Carpeta",
                                    command=self.scan_folder)
        self.scan_btn.pack(side=tk.LEFT, padx=5)

        self.sync_btn = ttk.Button(btn_frame, text="Sincronizar",
                                    command=self.start_sync, state=tk.DISABLED)
        self.sync_btn.pack(side=tk.LEFT, padx=5)

        self.stop_btn = ttk.Button(btn_frame, text="Detener",
                                    command=self.stop_sync, state=tk.DISABLED)
        self.stop_btn.pack(side=tk.LEFT, padx=5)

        # Progress
        progress_frame = ttk.LabelFrame(frame, text="Progreso", padding=10)
        progress_frame.pack(fill=tk.X, pady=(0, 10))

        self.progress_var = tk.DoubleVar(value=0)
        self.progress_bar = ttk.Progressbar(progress_frame,
                                             variable=self.progress_var,
                                             maximum=100)
        self.progress_bar.pack(fill=tk.X, pady=(0, 5))

        self.progress_label = ttk.Label(progress_frame, text="0 / 0")
        self.progress_label.pack(anchor=tk.W)

        # File list / results
        list_frame = ttk.LabelFrame(frame, text="Archivos", padding=10)
        list_frame.pack(fill=tk.BOTH, expand=True)

        # Treeview for file list
        columns = ("archivo", "identificador", "producto", "estado")
        self.tree = ttk.Treeview(list_frame, columns=columns,
                                  show="headings", height=12)
        self.tree.heading("archivo", text="Archivo")
        self.tree.heading("identificador", text="Identificador")
        self.tree.heading("producto", text="Producto")
        self.tree.heading("estado", text="Estado")

        self.tree.column("archivo", width=200)
        self.tree.column("identificador", width=120)
        self.tree.column("producto", width=200)
        self.tree.column("estado", width=150)

        scrollbar = ttk.Scrollbar(list_frame, orient=tk.VERTICAL,
                                   command=self.tree.yview)
        self.tree.configure(yscrollcommand=scrollbar.set)

        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)

        # Summary
        self.summary_frame = ttk.Frame(frame)
        self.summary_frame.pack(fill=tk.X, pady=10)

        self.summary_label = ttk.Label(self.summary_frame, text="",
                                        font=("", 10))
        self.summary_label.pack(anchor=tk.W)

    def setup_log_tab(self):
        """Setup the log tab."""
        frame = self.log_frame

        ttk.Label(frame, text="Registro de Actividad",
                  font=("", 14, "bold")).pack(anchor=tk.W, pady=(0, 10))

        self.log_text = scrolledtext.ScrolledText(frame, height=25, width=80,
                                                    font=("Consolas", 9),
                                                    bg="#1d2327", fg="#c3c4c7")
        self.log_text.pack(fill=tk.BOTH, expand=True)

        ttk.Button(frame, text="Limpiar Registro",
                   command=self.clear_log).pack(anchor=tk.W, pady=5)

    # ==================
    # Actions
    # ==================

    def browse_folder(self):
        """Open folder browser dialog."""
        folder = filedialog.askdirectory(title="Selecciona la carpeta de imagenes")
        if folder:
            self.folder_var.set(folder)

    def _toggle_date_filter(self):
        """Enable or disable date filter inputs."""
        if self.date_filter_var.get():
            self.day_spin.config(state=tk.NORMAL)
            self.month_spin.config(state=tk.NORMAL)
            self.year_spin.config(state=tk.NORMAL)
        else:
            self.day_spin.config(state=tk.DISABLED)
            self.month_spin.config(state=tk.DISABLED)
            self.year_spin.config(state=tk.DISABLED)

    def _get_filter_date(self):
        """Get the filter date as a datetime object, or None if disabled."""
        if not self.date_filter_var.get():
            return None
        try:
            day = int(self.day_var.get())
            month = int(self.month_var.get())
            year = int(self.year_var.get())
            return datetime(year, month, day)
        except (ValueError, TypeError):
            messagebox.showerror("Fecha invalida",
                                 "La fecha ingresada no es valida. Verifica dia, mes y ano.")
            return None

    def load_config_to_ui(self):
        """Load saved config into UI fields."""
        self.url_var.set(self.config.get("store_url", ""))
        self.key_var.set(self.config.get("consumer_key", ""))
        self.secret_var.set(self.config.get("consumer_secret", ""))
        self.folder_var.set(self.config.get("folder_path", ""))
        self.match_var.set(self.config.get("match_by", "sku"))
        self.skip_var.set(self.config.get("skip_existing", True))
        self.date_filter_var.set(self.config.get("filter_by_date", False))
        saved_date = self.config.get("filter_date", "")
        if saved_date:
            try:
                d = datetime.strptime(saved_date, "%Y-%m-%d")
                self.day_var.set(str(d.day))
                self.month_var.set(str(d.month))
                self.year_var.set(str(d.year))
            except ValueError:
                pass
        self._toggle_date_filter()

    def save_configuration(self):
        """Save current configuration."""
        filter_date_str = ""
        if self.date_filter_var.get():
            try:
                day = int(self.day_var.get())
                month = int(self.month_var.get())
                year = int(self.year_var.get())
                filter_date_str = f"{year:04d}-{month:02d}-{day:02d}"
            except (ValueError, TypeError):
                pass

        self.config = {
            "store_url": self.url_var.get().strip(),
            "consumer_key": self.key_var.get().strip(),
            "consumer_secret": self.secret_var.get().strip(),
            "folder_path": self.folder_var.get().strip(),
            "match_by": self.match_var.get(),
            "skip_existing": self.skip_var.get(),
            "filter_by_date": self.date_filter_var.get(),
            "filter_date": filter_date_str,
        }
        save_config(self.config)
        self.status_var.set("Configuracion guardada correctamente")
        self.log("Configuracion guardada")

    def test_connection(self):
        """Test WooCommerce API connection."""
        url = self.url_var.get().strip()
        key = self.key_var.get().strip()
        secret = self.secret_var.get().strip()

        if not url or not key or not secret:
            messagebox.showwarning("Datos incompletos",
                                   "Ingresa la URL, Consumer Key y Consumer Secret.")
            return

        self.status_var.set("Probando conexion...")
        self.root.update()

        client = WooCommerceClient(url, key, secret)
        success, message = client.test_connection()

        if success:
            self.status_var.set("Conexion exitosa")
            self.client = client
            self.log("Conexion exitosa a " + url)
            messagebox.showinfo("Conexion", "Conexion exitosa a WooCommerce!")
        else:
            self.status_var.set("Error: " + message)
            self.log("Error de conexion: " + message)
            messagebox.showerror("Error de Conexion", message)

    def _get_client(self):
        """Get or create WooCommerce client."""
        url = self.url_var.get().strip()
        key = self.key_var.get().strip()
        secret = self.secret_var.get().strip()

        if not url or not key or not secret:
            messagebox.showwarning("Datos incompletos",
                                   "Configura la conexion a WooCommerce primero.")
            return None

        if not self.client:
            self.client = WooCommerceClient(url, key, secret)

        return self.client

    def scan_folder(self):
        """Scan the configured folder for images."""
        folder = self.folder_var.get().strip()

        if not folder:
            messagebox.showwarning("Sin carpeta",
                                   "Selecciona una carpeta de imagenes primero.")
            return

        if not os.path.isdir(folder):
            messagebox.showerror("Carpeta invalida",
                                  f"La carpeta no existe: {folder}")
            return

        # Clear tree
        for item in self.tree.get_children():
            self.tree.delete(item)

        # Get date filter
        filter_date = self._get_filter_date()
        if self.date_filter_var.get() and filter_date is None:
            return  # Invalid date entered

        # Scan for images
        images = []
        skipped_by_date = 0
        for filename in sorted(os.listdir(folder)):
            ext = os.path.splitext(filename)[1].lower()
            if ext in ALLOWED_EXTENSIONS:
                filepath = os.path.join(folder, filename)

                # Apply date filter
                if filter_date:
                    file_mtime = datetime.fromtimestamp(os.path.getmtime(filepath))
                    if file_mtime < filter_date:
                        skipped_by_date += 1
                        continue

                name_without_ext = os.path.splitext(filename)[0]
                images.append({
                    "filename": filename,
                    "path": filepath,
                    "identifier": name_without_ext,
                })

        if not images:
            date_msg = ""
            if filter_date:
                date_msg = f" (se omitieron {skipped_by_date} por fecha)"
            self.info_label.config(
                text=f"No se encontraron imagenes en la carpeta seleccionada{date_msg}.")
            self.log(f"Escaneo: 0 imagenes en {folder}{date_msg}")
            return

        # Add to tree
        for img in images:
            self.tree.insert("", tk.END, values=(
                img["filename"],
                img["identifier"],
                "Pendiente...",
                "Sin escanear"
            ))

        date_msg = ""
        if filter_date:
            date_msg = f" ({skipped_by_date} omitidas por fecha anterior a {filter_date.strftime('%d/%m/%Y')})"
        self.info_label.config(
            text=f"Se encontraron {len(images)} imagenes{date_msg}. "
                 f"Haz clic en 'Sincronizar' para iniciar.")
        self.sync_btn.config(state=tk.NORMAL)
        self.log(f"Escaneo: {len(images)} imagenes encontradas en {folder}{date_msg}")

    def start_sync(self):
        """Start the sync process in a background thread."""
        client = self._get_client()
        if not client:
            return

        if self.is_syncing:
            return

        self.is_syncing = True
        self.sync_btn.config(state=tk.DISABLED)
        self.scan_btn.config(state=tk.DISABLED)
        self.stop_btn.config(state=tk.NORMAL)

        # Save config before syncing
        self.save_configuration()

        # Run sync in background thread
        thread = threading.Thread(target=self._sync_worker, daemon=True)
        thread.start()

    def stop_sync(self):
        """Stop the sync process."""
        self.is_syncing = False
        self.stop_btn.config(state=tk.DISABLED)
        self.log("Sincronizacion detenida por el usuario")

    def _sync_worker(self):
        """Background sync worker."""
        client = self.client
        folder = self.folder_var.get().strip()
        match_by = self.match_var.get()
        skip_existing = self.skip_var.get()

        # Get all tree items
        items = self.tree.get_children()
        total = len(items)

        summary = {"success": 0, "skipped": 0, "no_match": 0, "error": 0}

        self.log(f"Iniciando sincronizacion de {total} imagenes...")

        for i, item_id in enumerate(items):
            if not self.is_syncing:
                break

            values = self.tree.item(item_id, "values")
            filename = values[0]
            identifier = values[1]
            filepath = os.path.join(folder, filename)

            # Update progress
            progress = ((i + 1) / total) * 100
            self.root.after(0, self._update_progress, progress, i + 1, total)
            self.root.after(0, self._update_tree_item, item_id,
                          filename, identifier, "Buscando...", "Procesando...")

            # Find product
            product = None
            try:
                if match_by == "sku":
                    product = client.get_product_by_sku(identifier)
                elif match_by == "id":
                    product = client.get_product_by_id(identifier)
                elif match_by == "slug":
                    product = client.get_product_by_slug(identifier)
            except Exception as e:
                self.log(f"Error al buscar producto '{identifier}': {str(e)}")

            if not product:
                summary["no_match"] += 1
                self.root.after(0, self._update_tree_item, item_id,
                              filename, identifier, "No encontrado",
                              "Sin coincidencia")
                self.log(f"Sin coincidencia: {filename} (identificador: {identifier})")
                continue

            product_name = product.get("name", "Sin nombre")
            product_id = product.get("id")

            # Check if product already has image
            if skip_existing and client.product_has_image(product):
                summary["skipped"] += 1
                self.root.after(0, self._update_tree_item, item_id,
                              filename, identifier, product_name,
                              "Omitido (ya tiene imagen)")
                self.log(f"Omitido: {filename} -> {product_name} (ya tiene imagen)")
                continue

            # Upload image
            self.root.after(0, self._update_tree_item, item_id,
                          filename, identifier, product_name, "Subiendo...")

            try:
                success, message = client.upload_image_to_product(
                    product_id, filepath)

                if success:
                    summary["success"] += 1
                    self.root.after(0, self._update_tree_item, item_id,
                                  filename, identifier, product_name,
                                  "Asignada")
                    self.log(f"Exito: {filename} -> {product_name} ({message})")
                else:
                    summary["error"] += 1
                    self.root.after(0, self._update_tree_item, item_id,
                                  filename, identifier, product_name,
                                  "Error")
                    self.log(f"Error: {filename} -> {product_name}: {message}")

            except Exception as e:
                summary["error"] += 1
                self.root.after(0, self._update_tree_item, item_id,
                              filename, identifier, product_name,
                              "Error")
                self.log(f"Error: {filename} -> {str(e)}")

        # Done
        self.is_syncing = False
        total_processed = sum(summary.values())
        summary_text = (
            f"Completado: {summary['success']} asignadas, "
            f"{summary['skipped']} omitidas, "
            f"{summary['no_match']} sin coincidencia, "
            f"{summary['error']} errores "
            f"(de {total_processed} procesadas)"
        )

        self.root.after(0, self._sync_complete, summary_text)
        self.log(summary_text)

    def _update_progress(self, progress, current, total):
        """Update progress bar (must be called from main thread)."""
        self.progress_var.set(progress)
        self.progress_label.config(text=f"{current} / {total}")

    def _update_tree_item(self, item_id, filename, identifier, product, status):
        """Update a tree item (must be called from main thread)."""
        self.tree.item(item_id, values=(filename, identifier, product, status))
        self.tree.see(item_id)

    def _sync_complete(self, summary_text):
        """Handle sync completion (must be called from main thread)."""
        self.sync_btn.config(state=tk.NORMAL)
        self.scan_btn.config(state=tk.NORMAL)
        self.stop_btn.config(state=tk.DISABLED)
        self.summary_label.config(text=summary_text)
        self.info_label.config(text="Sincronizacion completada. " + summary_text)
        messagebox.showinfo("Sincronizacion Completada", summary_text)

    def log(self, message):
        """Add a message to the log."""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_line = f"[{timestamp}] {message}\n"

        def _append():
            self.log_text.insert(tk.END, log_line)
            self.log_text.see(tk.END)

        self.root.after(0, _append)

    def clear_log(self):
        """Clear the log."""
        self.log_text.delete("1.0", tk.END)


# ============================================================
# Entry Point
# ============================================================

def main():
    root = tk.Tk()

    # Set icon if available
    try:
        root.iconbitmap(default="")
    except Exception:
        pass

    app = WooImageSyncApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
