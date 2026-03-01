<?php
/**
 * REST API handler for WooCommerce Image Sync.
 *
 * Provides a custom REST endpoint for uploading images from the desktop app.
 * Authenticates using WooCommerce consumer key/secret so no additional
 * WordPress credentials (Application Passwords) are needed.
 *
 * @package WooImageSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WIS_REST_API {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'wis/v1';

    /**
     * Constructor - register REST routes.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/upload',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_upload' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/ping',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_ping' ),
                'permission_callback' => array( $this, 'check_permissions' ),
            )
        );
    }

    /**
     * Check permissions using WooCommerce consumer key/secret.
     *
     * Validates the consumer_key and consumer_secret passed as query
     * parameters or via HTTP Basic Auth against the WooCommerce API keys
     * stored in the database.
     *
     * @param WP_REST_Request $request The REST request.
     * @return bool|WP_Error True if authenticated, WP_Error otherwise.
     */
    public function check_permissions( $request ) {
        $consumer_key    = '';
        $consumer_secret = '';

        // Try HTTP Basic Auth first
        if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
            $consumer_key    = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
            $consumer_secret = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
        }

        // Fallback to query parameters
        if ( empty( $consumer_key ) ) {
            $consumer_key    = $request->get_param( 'consumer_key' );
            $consumer_secret = $request->get_param( 'consumer_secret' );
        }

        if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
            return new WP_Error(
                'wis_auth_missing',
                __( 'Credenciales de autenticacion requeridas. Proporciona consumer_key y consumer_secret.', 'woo-image-sync' ),
                array( 'status' => 401 )
            );
        }

        // Validate the consumer key against WooCommerce's stored API keys
        global $wpdb;

        $key_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT consumer_key, consumer_secret, permissions, user_id
                 FROM {$wpdb->prefix}woocommerce_api_keys
                 WHERE consumer_key = %s",
                wc_api_hash( $consumer_key )
            )
        );

        if ( empty( $key_data ) ) {
            return new WP_Error(
                'wis_auth_invalid_key',
                __( 'Consumer key invalido.', 'woo-image-sync' ),
                array( 'status' => 401 )
            );
        }

        // Validate the consumer secret
        if ( ! hash_equals( $key_data->consumer_secret, $consumer_secret ) ) {
            return new WP_Error(
                'wis_auth_invalid_secret',
                __( 'Consumer secret invalido.', 'woo-image-sync' ),
                array( 'status' => 401 )
            );
        }

        // Check permissions (need read_write for uploads)
        if ( 'read_write' !== $key_data->permissions && 'write' !== $key_data->permissions ) {
            return new WP_Error(
                'wis_auth_insufficient_permissions',
                __( 'La clave API necesita permisos de Lectura/Escritura.', 'woo-image-sync' ),
                array( 'status' => 403 )
            );
        }

        // Set the current user to the API key owner so WordPress
        // functions like media_handle_sideload work correctly
        wp_set_current_user( $key_data->user_id );

        return true;
    }

    /**
     * Handle ping request - verify the plugin endpoint is available.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response
     */
    public function handle_ping( $request ) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'WooCommerce Image Sync plugin activo',
                'version' => WIS_VERSION,
            ),
            200
        );
    }

    /**
     * Handle image upload request.
     *
     * Accepts a multipart file upload and a product_id parameter.
     * Uploads the image to the WordPress media library and assigns it
     * as the featured image of the specified product.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response
     */
    public function handle_upload( $request ) {
        $product_id = absint( $request->get_param( 'product_id' ) );

        if ( ! $product_id ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __( 'Se requiere product_id.', 'woo-image-sync' ),
                ),
                400
            );
        }

        // Verify product exists
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Producto con ID %d no encontrado.', 'woo-image-sync' ),
                        $product_id
                    ),
                ),
                404
            );
        }

        // Get the uploaded file
        $files = $request->get_file_params();
        if ( empty( $files['image'] ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => __( 'No se recibio archivo de imagen. Envia el archivo en el campo "image".', 'woo-image-sync' ),
                ),
                400
            );
        }

        $file = $files['image'];

        // Validate file
        $filename  = sanitize_file_name( $file['name'] );
        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $allowed   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

        if ( ! in_array( $extension, $allowed, true ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Formato de imagen no permitido: %s. Formatos validos: %s', 'woo-image-sync' ),
                        $extension,
                        implode( ', ', $allowed )
                    ),
                ),
                400
            );
        }

        // Check for upload errors
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Error al recibir el archivo (codigo: %d).', 'woo-image-sync' ),
                        $file['error']
                    ),
                ),
                400
            );
        }

        // Load required WordPress functions for media handling
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Prepare file for sideload
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $file['tmp_name'],
            'type'     => $file['type'],
            'error'    => $file['error'],
            'size'     => $file['size'],
        );

        // Upload to media library using media_handle_sideload
        $attachment_id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => sprintf(
                        __( 'Error al subir a la biblioteca de medios: %s', 'woo-image-sync' ),
                        $attachment_id->get_error_message()
                    ),
                ),
                500
            );
        }

        // Store metadata for tracking
        update_post_meta( $attachment_id, '_wis_original_filename', $filename );
        update_post_meta( $attachment_id, '_wis_product_id', $product_id );
        update_post_meta( $attachment_id, '_wis_sync_date', current_time( 'mysql' ) );
        update_post_meta( $attachment_id, '_wis_upload_source', 'desktop_app' );

        // Assign as featured image
        set_post_thumbnail( $product_id, $attachment_id );

        $attachment_url = wp_get_attachment_url( $attachment_id );

        return new WP_REST_Response(
            array(
                'success'       => true,
                'message'       => sprintf(
                    __( 'Imagen subida y asignada a "%s" (ID: %d)', 'woo-image-sync' ),
                    $product->get_name(),
                    $product_id
                ),
                'attachment_id' => $attachment_id,
                'url'           => $attachment_url,
                'product_id'    => $product_id,
            ),
            200
        );
    }
}
