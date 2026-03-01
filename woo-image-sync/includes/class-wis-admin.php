<?php
/**
 * Admin settings page for WooCommerce Image Sync.
 *
 * @package WooImageSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WIS_Admin {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Add submenu page under WooCommerce.
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Sincronizar Imagenes', 'woo-image-sync' ),
            __( 'Sync Imagenes', 'woo-image-sync' ),
            'manage_woocommerce',
            'woo-image-sync',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( 'wis_settings_group', 'wis_settings', array( $this, 'sanitize_settings' ) );

        add_settings_section(
            'wis_general_section',
            __( 'Configuracion General', 'woo-image-sync' ),
            array( $this, 'render_section_description' ),
            'woo-image-sync'
        );

        add_settings_field(
            'server_folder',
            __( 'Carpeta del Servidor', 'woo-image-sync' ),
            array( $this, 'render_server_folder_field' ),
            'woo-image-sync',
            'wis_general_section'
        );

        add_settings_field(
            'match_by',
            __( 'Asociar por', 'woo-image-sync' ),
            array( $this, 'render_match_by_field' ),
            'woo-image-sync',
            'wis_general_section'
        );

        add_settings_field(
            'skip_existing',
            __( 'Omitir productos con imagen', 'woo-image-sync' ),
            array( $this, 'render_skip_existing_field' ),
            'woo-image-sync',
            'wis_general_section'
        );

        add_settings_field(
            'image_type',
            __( 'Tipo de imagen', 'woo-image-sync' ),
            array( $this, 'render_image_type_field' ),
            'woo-image-sync',
            'wis_general_section'
        );
    }

    /**
     * Sanitize settings.
     *
     * @param array $input The raw settings input.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['server_folder'] = isset( $input['server_folder'] )
            ? sanitize_text_field( $input['server_folder'] )
            : '';

        $sanitized['match_by'] = isset( $input['match_by'] ) && in_array( $input['match_by'], array( 'sku', 'id', 'slug', 'name' ), true )
            ? $input['match_by']
            : 'sku';

        $sanitized['skip_existing'] = isset( $input['skip_existing'] ) ? 'yes' : 'no';

        $sanitized['image_type'] = isset( $input['image_type'] ) && in_array( $input['image_type'], array( 'featured', 'gallery', 'both' ), true )
            ? $input['image_type']
            : 'featured';

        $sanitized['allowed_formats'] = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

        return $sanitized;
    }

    /**
     * Render section description.
     */
    public function render_section_description() {
        echo '<p>' . esc_html__( 'Configure como se sincronizan las imagenes con los productos de WooCommerce.', 'woo-image-sync' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Formato del nombre de archivo:', 'woo-image-sync' ) . '</strong> ';
        echo esc_html__( 'El nombre del archivo de imagen debe coincidir con el SKU del producto (ej: SKU123.jpg para el producto con SKU "SKU123").', 'woo-image-sync' ) . '</p>';
    }

    /**
     * Render server folder field.
     */
    public function render_server_folder_field() {
        $settings = get_option( 'wis_settings', array() );
        $value = isset( $settings['server_folder'] ) ? $settings['server_folder'] : '';
        ?>
        <input type="text" name="wis_settings[server_folder]" value="<?php echo esc_attr( $value ); ?>"
               class="regular-text" placeholder="/ruta/a/carpeta/de/imagenes" />
        <p class="description">
            <?php esc_html_e( 'Ruta absoluta a la carpeta en el servidor donde estan las imagenes. Deja vacio si solo usaras la subida manual.', 'woo-image-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Render match by field.
     */
    public function render_match_by_field() {
        $settings = get_option( 'wis_settings', array() );
        $value = isset( $settings['match_by'] ) ? $settings['match_by'] : 'sku';
        ?>
        <select name="wis_settings[match_by]">
            <option value="sku" <?php selected( $value, 'sku' ); ?>>
                <?php esc_html_e( 'SKU del producto', 'woo-image-sync' ); ?>
            </option>
            <option value="id" <?php selected( $value, 'id' ); ?>>
                <?php esc_html_e( 'ID del producto', 'woo-image-sync' ); ?>
            </option>
            <option value="slug" <?php selected( $value, 'slug' ); ?>>
                <?php esc_html_e( 'Slug del producto', 'woo-image-sync' ); ?>
            </option>
            <option value="name" <?php selected( $value, 'name' ); ?>>
                <?php esc_html_e( 'Nombre del producto', 'woo-image-sync' ); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e( 'Define como se asocia el nombre del archivo de imagen con el producto. Ejemplo: si eliges SKU, el archivo "ABC123.jpg" se asociara al producto con SKU "ABC123".', 'woo-image-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Render skip existing field.
     */
    public function render_skip_existing_field() {
        $settings = get_option( 'wis_settings', array() );
        $value = isset( $settings['skip_existing'] ) ? $settings['skip_existing'] : 'yes';
        ?>
        <label>
            <input type="checkbox" name="wis_settings[skip_existing]" value="yes"
                   <?php checked( $value, 'yes' ); ?> />
            <?php esc_html_e( 'No subir imagen si el producto ya tiene una imagen destacada asignada', 'woo-image-sync' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Activa esta opcion para evitar duplicar imagenes en productos que ya tienen imagen.', 'woo-image-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Render image type field.
     */
    public function render_image_type_field() {
        $settings = get_option( 'wis_settings', array() );
        $value = isset( $settings['image_type'] ) ? $settings['image_type'] : 'featured';
        ?>
        <select name="wis_settings[image_type]">
            <option value="featured" <?php selected( $value, 'featured' ); ?>>
                <?php esc_html_e( 'Imagen destacada', 'woo-image-sync' ); ?>
            </option>
            <option value="gallery" <?php selected( $value, 'gallery' ); ?>>
                <?php esc_html_e( 'Galeria del producto', 'woo-image-sync' ); ?>
            </option>
            <option value="both" <?php selected( $value, 'both' ); ?>>
                <?php esc_html_e( 'Ambas (destacada + galeria)', 'woo-image-sync' ); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e( 'Selecciona donde se asignara la imagen sincronizada.', 'woo-image-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_woo-image-sync' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wis-admin-css',
            WIS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WIS_VERSION
        );

        wp_enqueue_script(
            'wis-admin-js',
            WIS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WIS_VERSION,
            true
        );

        wp_localize_script( 'wis-admin-js', 'wisAjax', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wis_nonce' ),
            'strings'   => array(
                'syncing'           => __( 'Sincronizando...', 'woo-image-sync' ),
                'uploading'         => __( 'Subiendo imagenes...', 'woo-image-sync' ),
                'syncComplete'      => __( 'Sincronizacion completada', 'woo-image-sync' ),
                'uploadComplete'    => __( 'Subida completada', 'woo-image-sync' ),
                'error'             => __( 'Error durante la sincronizacion', 'woo-image-sync' ),
                'noFiles'           => __( 'No se seleccionaron archivos', 'woo-image-sync' ),
                'confirmSync'       => __( 'Iniciar sincronizacion?', 'woo-image-sync' ),
                'processing'        => __( 'Procesando imagen', 'woo-image-sync' ),
                'of'                => __( 'de', 'woo-image-sync' ),
                'skipped'           => __( 'Omitido (ya tiene imagen)', 'woo-image-sync' ),
                'noMatch'           => __( 'Sin producto asociado', 'woo-image-sync' ),
                'assigned'          => __( 'Imagen asignada correctamente', 'woo-image-sync' ),
                'selectFiles'       => __( 'Selecciona archivos de imagen para subir', 'woo-image-sync' ),
            ),
        ) );
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta pagina.', 'woo-image-sync' ) );
        }
        ?>
        <div class="wrap wis-wrap">
            <h1>
                <span class="dashicons dashicons-images-alt2"></span>
                <?php esc_html_e( 'WooCommerce Image Sync', 'woo-image-sync' ); ?>
            </h1>

            <div class="wis-container">
                <!-- Tab Navigation -->
                <nav class="nav-tab-wrapper wis-tabs">
                    <a href="#wis-tab-upload" class="nav-tab nav-tab-active" data-tab="wis-tab-upload">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Subir desde PC', 'woo-image-sync' ); ?>
                    </a>
                    <a href="#wis-tab-server" class="nav-tab" data-tab="wis-tab-server">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e( 'Sincronizar desde Servidor', 'woo-image-sync' ); ?>
                    </a>
                    <a href="#wis-tab-settings" class="nav-tab" data-tab="wis-tab-settings">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e( 'Configuracion', 'woo-image-sync' ); ?>
                    </a>
                    <a href="#wis-tab-log" class="nav-tab" data-tab="wis-tab-log">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e( 'Registro', 'woo-image-sync' ); ?>
                    </a>
                </nav>

                <!-- Tab: Upload from PC -->
                <div id="wis-tab-upload" class="wis-tab-content active">
                    <div class="wis-card">
                        <h2><?php esc_html_e( 'Subir Imagenes desde tu PC', 'woo-image-sync' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Selecciona las imagenes de tu computadora. El nombre del archivo debe coincidir con el SKU del producto (ejemplo: SKU123.jpg).', 'woo-image-sync' ); ?>
                        </p>

                        <div class="wis-upload-area" id="wis-drop-zone">
                            <div class="wis-upload-icon">
                                <span class="dashicons dashicons-cloud-upload"></span>
                            </div>
                            <p><?php esc_html_e( 'Arrastra y suelta imagenes aqui', 'woo-image-sync' ); ?></p>
                            <p class="wis-or"><?php esc_html_e( 'o', 'woo-image-sync' ); ?></p>
                            <label for="wis-file-input" class="button button-primary button-large">
                                <?php esc_html_e( 'Seleccionar Archivos', 'woo-image-sync' ); ?>
                            </label>
                            <input type="file" id="wis-file-input" multiple accept="image/*" style="display:none;" />
                            <p class="description wis-formats">
                                <?php esc_html_e( 'Formatos aceptados: JPG, JPEG, PNG, GIF, WEBP', 'woo-image-sync' ); ?>
                            </p>
                        </div>

                        <!-- File Preview List -->
                        <div id="wis-file-list" class="wis-file-list" style="display:none;">
                            <h3><?php esc_html_e( 'Archivos seleccionados', 'woo-image-sync' ); ?> (<span id="wis-file-count">0</span>)</h3>
                            <div id="wis-file-items"></div>
                            <div class="wis-actions">
                                <button type="button" id="wis-upload-btn" class="button button-primary button-large">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php esc_html_e( 'Subir y Sincronizar', 'woo-image-sync' ); ?>
                                </button>
                                <button type="button" id="wis-clear-btn" class="button button-large">
                                    <?php esc_html_e( 'Limpiar', 'woo-image-sync' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Sync from Server -->
                <div id="wis-tab-server" class="wis-tab-content">
                    <div class="wis-card">
                        <h2><?php esc_html_e( 'Sincronizar desde Carpeta del Servidor', 'woo-image-sync' ); ?></h2>
                        <?php
                        $settings = get_option( 'wis_settings', array() );
                        $folder = isset( $settings['server_folder'] ) ? $settings['server_folder'] : '';
                        ?>
                        <?php if ( empty( $folder ) ) : ?>
                            <div class="notice notice-warning inline">
                                <p>
                                    <?php esc_html_e( 'No has configurado una carpeta del servidor. Ve a la pestana de Configuracion para establecer la ruta.', 'woo-image-sync' ); ?>
                                </p>
                            </div>
                        <?php else : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: server folder path */
                                    esc_html__( 'Se sincronizaran las imagenes de la carpeta: %s', 'woo-image-sync' ),
                                    '<code>' . esc_html( $folder ) . '</code>'
                                );
                                ?>
                            </p>

                            <div class="wis-server-info" id="wis-server-info">
                                <p><span class="dashicons dashicons-info"></span>
                                    <?php esc_html_e( 'Haz clic en "Escanear Carpeta" para ver las imagenes disponibles antes de sincronizar.', 'woo-image-sync' ); ?>
                                </p>
                            </div>

                            <div class="wis-actions">
                                <button type="button" id="wis-scan-btn" class="button button-secondary button-large">
                                    <span class="dashicons dashicons-search"></span>
                                    <?php esc_html_e( 'Escanear Carpeta', 'woo-image-sync' ); ?>
                                </button>
                                <button type="button" id="wis-sync-server-btn" class="button button-primary button-large" disabled>
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e( 'Sincronizar Imagenes', 'woo-image-sync' ); ?>
                                </button>
                            </div>

                            <!-- Scan Results -->
                            <div id="wis-scan-results" class="wis-scan-results" style="display:none;">
                                <h3><?php esc_html_e( 'Imagenes encontradas', 'woo-image-sync' ); ?></h3>
                                <div id="wis-scan-items"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Settings -->
                <div id="wis-tab-settings" class="wis-tab-content">
                    <div class="wis-card">
                        <form method="post" action="options.php">
                            <?php
                            settings_fields( 'wis_settings_group' );
                            do_settings_sections( 'woo-image-sync' );
                            submit_button( __( 'Guardar Configuracion', 'woo-image-sync' ) );
                            ?>
                        </form>
                    </div>
                </div>

                <!-- Tab: Log -->
                <div id="wis-tab-log" class="wis-tab-content">
                    <div class="wis-card">
                        <h2><?php esc_html_e( 'Registro de Sincronizacion', 'woo-image-sync' ); ?></h2>
                        <div id="wis-log-container" class="wis-log-container">
                            <p class="wis-log-empty"><?php esc_html_e( 'No hay registros de sincronizacion aun. Realiza una sincronizacion para ver los resultados aqui.', 'woo-image-sync' ); ?></p>
                        </div>
                        <button type="button" id="wis-clear-log-btn" class="button" style="display:none;">
                            <?php esc_html_e( 'Limpiar Registro', 'woo-image-sync' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Progress Bar (shared) -->
                <div id="wis-progress" class="wis-progress" style="display:none;">
                    <div class="wis-progress-header">
                        <h3 id="wis-progress-title"><?php esc_html_e( 'Progreso', 'woo-image-sync' ); ?></h3>
                        <span id="wis-progress-text">0%</span>
                    </div>
                    <div class="wis-progress-bar">
                        <div class="wis-progress-fill" id="wis-progress-fill" style="width:0%"></div>
                    </div>
                    <div id="wis-progress-details" class="wis-progress-details"></div>
                </div>

                <!-- Results Summary (shared) -->
                <div id="wis-results" class="wis-results" style="display:none;">
                    <h3><?php esc_html_e( 'Resumen de Sincronizacion', 'woo-image-sync' ); ?></h3>
                    <div class="wis-results-grid">
                        <div class="wis-result-item wis-result-success">
                            <span class="wis-result-number" id="wis-result-success">0</span>
                            <span class="wis-result-label"><?php esc_html_e( 'Asignadas', 'woo-image-sync' ); ?></span>
                        </div>
                        <div class="wis-result-item wis-result-skipped">
                            <span class="wis-result-number" id="wis-result-skipped">0</span>
                            <span class="wis-result-label"><?php esc_html_e( 'Omitidas', 'woo-image-sync' ); ?></span>
                        </div>
                        <div class="wis-result-item wis-result-nomatch">
                            <span class="wis-result-number" id="wis-result-nomatch">0</span>
                            <span class="wis-result-label"><?php esc_html_e( 'Sin coincidencia', 'woo-image-sync' ); ?></span>
                        </div>
                        <div class="wis-result-item wis-result-error">
                            <span class="wis-result-number" id="wis-result-errors">0</span>
                            <span class="wis-result-label"><?php esc_html_e( 'Errores', 'woo-image-sync' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
