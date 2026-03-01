<?php
/**
 * AJAX handler for WooCommerce Image Sync.
 *
 * @package WooImageSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WIS_Ajax_Handler {

    /**
     * Constructor.
     */
    public function __construct() {
        // Upload images from PC
        add_action( 'wp_ajax_wis_upload_images', array( $this, 'handle_upload_images' ) );

        // Sync from server folder
        add_action( 'wp_ajax_wis_sync_server', array( $this, 'handle_sync_server' ) );

        // Scan server folder
        add_action( 'wp_ajax_wis_scan_folder', array( $this, 'handle_scan_folder' ) );

        // Process single image (for one-by-one processing)
        add_action( 'wp_ajax_wis_process_single', array( $this, 'handle_process_single' ) );
    }

    /**
     * Verify AJAX request.
     *
     * @return bool
     */
    private function verify_request() {
        if ( ! check_ajax_referer( 'wis_nonce', 'nonce', false ) ) {
            wp_send_json_error( array(
                'message' => __( 'Error de seguridad. Recarga la pagina e intentalo de nuevo.', 'woo-image-sync' ),
            ) );
            return false;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array(
                'message' => __( 'No tienes permisos para realizar esta accion.', 'woo-image-sync' ),
            ) );
            return false;
        }

        return true;
    }

    /**
     * Handle image upload from PC.
     * Images are uploaded one at a time via AJAX for progress tracking.
     */
    public function handle_upload_images() {
        $this->verify_request();

        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( array(
                'message' => __( 'No se recibio ningun archivo.', 'woo-image-sync' ),
            ) );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = $_FILES['image'];
        $filename = sanitize_file_name( $file['name'] );
        $allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, $allowed_extensions, true ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: filename */
                    __( 'Formato no permitido para el archivo "%s".', 'woo-image-sync' ),
                    $filename
                ),
            ) );
            return;
        }

        // Save uploaded file to temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/wis-temp';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        $temp_path = $temp_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $temp_path ) ) {
            wp_send_json_error( array(
                'message' => __( 'Error al guardar el archivo subido.', 'woo-image-sync' ),
            ) );
            return;
        }

        // Process the image
        $engine = new WIS_Sync_Engine();
        $result = $engine->process_single_image( $temp_path, $filename );

        // Clean up temp file
        if ( file_exists( $temp_path ) ) {
            wp_delete_file( $temp_path );
        }

        // Log the result
        $this->log_result( $result );

        wp_send_json_success( $result );
    }

    /**
     * Handle sync from server folder.
     */
    public function handle_sync_server() {
        $this->verify_request();

        $engine = new WIS_Sync_Engine();
        $results = $engine->sync_from_server_folder();

        if ( ! $results['success'] ) {
            wp_send_json_error( array(
                'message' => $results['message'],
            ) );
            return;
        }

        // Log all results
        foreach ( $results['results'] as $result ) {
            $this->log_result( $result );
        }

        wp_send_json_success( $results );
    }

    /**
     * Handle folder scan request.
     */
    public function handle_scan_folder() {
        $this->verify_request();

        $engine = new WIS_Sync_Engine();
        $settings = get_option( 'wis_settings', array() );
        $folder = isset( $settings['server_folder'] ) ? $settings['server_folder'] : '';

        $images = $engine->scan_folder( $folder );

        if ( is_wp_error( $images ) ) {
            wp_send_json_error( array(
                'message' => $images->get_error_message(),
            ) );
            return;
        }

        // Enrich with product match info
        $enriched = array();
        foreach ( $images as $image ) {
            $name_without_ext = pathinfo( $image['filename'], PATHINFO_FILENAME );
            $product_id = $engine->find_product_by_filename( $name_without_ext );

            $image['product_id'] = $product_id ? $product_id : 0;
            $image['product_name'] = '';
            $image['has_image'] = false;

            if ( $product_id ) {
                $product = wc_get_product( $product_id );
                $image['product_name'] = $product ? $product->get_name() : '';
                $image['has_image'] = $engine->product_has_image( $product_id );
            }

            $image['size_formatted'] = size_format( $image['size'] );
            $enriched[] = $image;
        }

        wp_send_json_success( array(
            'images' => $enriched,
            'total'  => count( $enriched ),
        ) );
    }

    /**
     * Handle processing a single server image.
     */
    public function handle_process_single() {
        $this->verify_request();

        $file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['file_path'] ) ) : '';
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';

        if ( empty( $file_path ) || empty( $filename ) ) {
            wp_send_json_error( array(
                'message' => __( 'Datos de archivo invalidos.', 'woo-image-sync' ),
            ) );
            return;
        }

        // Security: verify the file is within the configured server folder
        $settings = get_option( 'wis_settings', array() );
        $server_folder = isset( $settings['server_folder'] ) ? realpath( $settings['server_folder'] ) : '';
        $real_path = realpath( $file_path );

        if ( empty( $server_folder ) || false === $real_path || 0 !== strpos( $real_path, $server_folder ) ) {
            wp_send_json_error( array(
                'message' => __( 'Acceso denegado. El archivo no esta en la carpeta configurada.', 'woo-image-sync' ),
            ) );
            return;
        }

        $engine = new WIS_Sync_Engine();
        $result = $engine->process_single_image( $real_path, $filename );

        $this->log_result( $result );

        wp_send_json_success( $result );
    }

    /**
     * Log a sync result to the options table.
     *
     * @param array $result The sync result.
     */
    private function log_result( $result ) {
        $log = get_option( 'wis_sync_log', array() );

        $log[] = array(
            'timestamp'  => current_time( 'mysql' ),
            'filename'   => $result['filename'],
            'identifier' => $result['identifier'],
            'status'     => $result['status'],
            'message'    => $result['message'],
            'product_id' => $result['product_id'],
        );

        // Keep only last 500 entries
        if ( count( $log ) > 500 ) {
            $log = array_slice( $log, -500 );
        }

        update_option( 'wis_sync_log', $log );
    }
}
