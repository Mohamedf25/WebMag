<?php
/**
 * Sync engine for WooCommerce Image Sync.
 * Handles matching images to products and assigning them.
 *
 * @package WooImageSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WIS_Sync_Engine {

    /**
     * Allowed image extensions.
     *
     * @var array
     */
    private $allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

    /**
     * Plugin settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->settings = get_option( 'wis_settings', array() );
    }

    /**
     * Scan a server folder for images.
     *
     * @param string $folder_path Absolute path to the folder.
     * @return array|WP_Error List of image files or error.
     */
    public function scan_folder( $folder_path ) {
        if ( empty( $folder_path ) ) {
            return new WP_Error( 'no_folder', __( 'No se ha especificado una carpeta.', 'woo-image-sync' ) );
        }

        $folder_path = trailingslashit( realpath( $folder_path ) );

        if ( ! is_dir( $folder_path ) ) {
            return new WP_Error( 'invalid_folder', __( 'La carpeta especificada no existe o no es accesible.', 'woo-image-sync' ) );
        }

        if ( ! is_readable( $folder_path ) ) {
            return new WP_Error( 'not_readable', __( 'No se puede leer la carpeta. Verifica los permisos.', 'woo-image-sync' ) );
        }

        $images = array();
        $files = scandir( $folder_path );

        if ( false === $files ) {
            return new WP_Error( 'scan_failed', __( 'Error al escanear la carpeta.', 'woo-image-sync' ) );
        }

        foreach ( $files as $file ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }

            $extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

            if ( in_array( $extension, $this->allowed_extensions, true ) ) {
                $file_path = $folder_path . $file;
                $images[] = array(
                    'filename'  => $file,
                    'path'      => $file_path,
                    'size'      => filesize( $file_path ),
                    'extension' => $extension,
                    'name'      => pathinfo( $file, PATHINFO_FILENAME ),
                );
            }
        }

        return $images;
    }

    /**
     * Find a WooCommerce product by image filename.
     *
     * @param string $filename The image filename (without extension).
     * @return int|false Product ID or false if not found.
     */
    public function find_product_by_filename( $filename ) {
        $match_by = isset( $this->settings['match_by'] ) ? $this->settings['match_by'] : 'sku';

        switch ( $match_by ) {
            case 'sku':
                return $this->find_product_by_sku( $filename );

            case 'id':
                return $this->find_product_by_id( $filename );

            case 'slug':
                return $this->find_product_by_slug( $filename );

            case 'name':
                return $this->find_product_by_name( $filename );

            default:
                return $this->find_product_by_sku( $filename );
        }
    }

    /**
     * Find product by SKU.
     *
     * @param string $sku The product SKU.
     * @return int|false Product ID or false.
     */
    private function find_product_by_sku( $sku ) {
        $product_id = wc_get_product_id_by_sku( $sku );
        return $product_id > 0 ? $product_id : false;
    }

    /**
     * Find product by ID.
     *
     * @param string $id The product ID as string.
     * @return int|false Product ID or false.
     */
    private function find_product_by_id( $id ) {
        $product_id = absint( $id );
        if ( $product_id <= 0 ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        return $product ? $product_id : false;
    }

    /**
     * Find product by slug.
     *
     * @param string $slug The product slug.
     * @return int|false Product ID or false.
     */
    private function find_product_by_slug( $slug ) {
        $args = array(
            'post_type'      => 'product',
            'name'           => sanitize_title( $slug ),
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        );

        $products = get_posts( $args );
        return ! empty( $products ) ? $products[0] : false;
    }

    /**
     * Find product by name (title).
     *
     * @param string $name The product name.
     * @return int|false Product ID or false.
     */
    private function find_product_by_name( $name ) {
        $args = array(
            'post_type'      => 'product',
            'title'          => $name,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        );

        $products = get_posts( $args );

        // Fallback: try a looser search if exact title match fails
        if ( empty( $products ) ) {
            $args = array(
                'post_type'      => 'product',
                's'              => $name,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'exact'          => true,
            );
            $products = get_posts( $args );
        }

        return ! empty( $products ) ? $products[0] : false;
    }

    /**
     * Check if a product already has a featured image.
     *
     * @param int $product_id The product ID.
     * @return bool True if the product has a featured image.
     */
    public function product_has_image( $product_id ) {
        $thumbnail_id = get_post_thumbnail_id( $product_id );
        return ! empty( $thumbnail_id ) && $thumbnail_id > 0;
    }

    /**
     * Check if a product already has gallery images.
     *
     * @param int $product_id The product ID.
     * @return bool True if the product has gallery images.
     */
    public function product_has_gallery( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }
        $gallery_ids = $product->get_gallery_image_ids();
        return ! empty( $gallery_ids );
    }

    /**
     * Check if a specific image already exists in the media library for a product.
     * Prevents exact duplicate uploads.
     *
     * @param string $filename The original filename.
     * @param int    $product_id The product ID.
     * @return int|false Attachment ID if exists, false otherwise.
     */
    public function image_already_uploaded( $filename, $product_id ) {
        $name_without_ext = pathinfo( $filename, PATHINFO_FILENAME );

        // Check if an attachment with this filename already exists
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => '_wis_original_filename',
                    'value'   => $filename,
                    'compare' => '=',
                ),
                array(
                    'key'     => '_wis_product_id',
                    'value'   => $product_id,
                    'compare' => '=',
                ),
            ),
        );

        $existing = get_posts( $args );
        return ! empty( $existing ) ? $existing[0]->ID : false;
    }

    /**
     * Upload an image file to the WordPress media library.
     *
     * @param string $file_path Absolute path to the image file.
     * @param int    $product_id The product ID to associate with.
     * @return int|WP_Error Attachment ID or error.
     */
    public function upload_image_to_media_library( $file_path, $product_id ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'El archivo de imagen no existe.', 'woo-image-sync' ) );
        }

        $filename = basename( $file_path );

        // Check if this exact image was already uploaded for this product
        $existing_id = $this->image_already_uploaded( $filename, $product_id );
        if ( $existing_id ) {
            return $existing_id;
        }

        // Prepare file for upload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $file_path,
        );

        // For server files, copy to temp to avoid moving the original
        $temp_file = wp_tempnam( $filename );
        if ( ! copy( $file_path, $temp_file ) ) {
            return new WP_Error( 'copy_failed', __( 'Error al copiar el archivo de imagen.', 'woo-image-sync' ) );
        }
        $file_array['tmp_name'] = $temp_file;

        // Upload to media library
        $attachment_id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            // Clean up temp file if sideload failed
            if ( file_exists( $temp_file ) ) {
                wp_delete_file( $temp_file );
            }
            return $attachment_id;
        }

        // Store metadata for duplicate detection
        update_post_meta( $attachment_id, '_wis_original_filename', $filename );
        update_post_meta( $attachment_id, '_wis_product_id', $product_id );
        update_post_meta( $attachment_id, '_wis_sync_date', current_time( 'mysql' ) );

        return $attachment_id;
    }

    /**
     * Assign an image to a product.
     *
     * @param int    $product_id The product ID.
     * @param int    $attachment_id The attachment ID.
     * @param string $image_type Type of image assignment: 'featured', 'gallery', or 'both'.
     * @return bool True on success.
     */
    public function assign_image_to_product( $product_id, $attachment_id, $image_type = null ) {
        if ( null === $image_type ) {
            $image_type = isset( $this->settings['image_type'] ) ? $this->settings['image_type'] : 'featured';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $success = false;

        if ( 'featured' === $image_type || 'both' === $image_type ) {
            set_post_thumbnail( $product_id, $attachment_id );
            $success = true;
        }

        if ( 'gallery' === $image_type || 'both' === $image_type ) {
            $gallery_ids = $product->get_gallery_image_ids();

            // Avoid adding duplicate to gallery
            if ( ! in_array( $attachment_id, $gallery_ids, true ) ) {
                $gallery_ids[] = $attachment_id;
                $product->set_gallery_image_ids( $gallery_ids );
                $product->save();
            }
            $success = true;
        }

        return $success;
    }

    /**
     * Process a single image: find product, check duplicates, upload, assign.
     *
     * @param string $file_path The image file path.
     * @param string $filename  The original filename.
     * @return array Result with status and message.
     */
    public function process_single_image( $file_path, $filename = null ) {
        if ( null === $filename ) {
            $filename = basename( $file_path );
        }

        $name_without_ext = pathinfo( $filename, PATHINFO_FILENAME );
        $skip_existing = isset( $this->settings['skip_existing'] ) ? $this->settings['skip_existing'] : 'yes';

        $result = array(
            'filename'   => $filename,
            'identifier' => $name_without_ext,
            'status'     => '',
            'message'    => '',
            'product_id' => 0,
        );

        // Step 1: Find the matching product
        $product_id = $this->find_product_by_filename( $name_without_ext );

        if ( ! $product_id ) {
            $result['status']  = 'no_match';
            $result['message'] = sprintf(
                /* translators: %s: filename identifier */
                __( 'No se encontro producto con identificador "%s"', 'woo-image-sync' ),
                $name_without_ext
            );
            return $result;
        }

        $result['product_id'] = $product_id;
        $product = wc_get_product( $product_id );
        $product_name = $product ? $product->get_name() : '';

        // Step 2: Check if product already has an image (if skip_existing is on)
        if ( 'yes' === $skip_existing && $this->product_has_image( $product_id ) ) {
            $result['status']  = 'skipped';
            $result['message'] = sprintf(
                /* translators: 1: product name, 2: product ID */
                __( 'Producto "%1$s" (ID: %2$d) ya tiene imagen. Omitido.', 'woo-image-sync' ),
                $product_name,
                $product_id
            );
            return $result;
        }

        // Step 3: Upload image to media library
        $attachment_id = $this->upload_image_to_media_library( $file_path, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            $result['status']  = 'error';
            $result['message'] = sprintf(
                /* translators: 1: product name, 2: error message */
                __( 'Error al subir imagen para "%1$s": %2$s', 'woo-image-sync' ),
                $product_name,
                $attachment_id->get_error_message()
            );
            return $result;
        }

        // Step 4: Assign image to product
        $assigned = $this->assign_image_to_product( $product_id, $attachment_id );

        if ( $assigned ) {
            $result['status']  = 'success';
            $result['message'] = sprintf(
                /* translators: 1: product name, 2: product ID */
                __( 'Imagen asignada correctamente a "%1$s" (ID: %2$d)', 'woo-image-sync' ),
                $product_name,
                $product_id
            );
        } else {
            $result['status']  = 'error';
            $result['message'] = sprintf(
                /* translators: %s: product name */
                __( 'Error al asignar imagen al producto "%s"', 'woo-image-sync' ),
                $product_name
            );
        }

        return $result;
    }

    /**
     * Process all images in a server folder.
     *
     * @return array Results array with summary and details.
     */
    public function sync_from_server_folder() {
        $folder = isset( $this->settings['server_folder'] ) ? $this->settings['server_folder'] : '';
        $images = $this->scan_folder( $folder );

        if ( is_wp_error( $images ) ) {
            return array(
                'success' => false,
                'message' => $images->get_error_message(),
                'results' => array(),
            );
        }

        if ( empty( $images ) ) {
            return array(
                'success' => true,
                'message' => __( 'No se encontraron imagenes en la carpeta.', 'woo-image-sync' ),
                'results' => array(),
                'summary' => $this->empty_summary(),
            );
        }

        $results = array();
        $summary = $this->empty_summary();

        foreach ( $images as $image ) {
            $result = $this->process_single_image( $image['path'], $image['filename'] );
            $results[] = $result;
            $summary[ $result['status'] ]++;
            $summary['total']++;
        }

        return array(
            'success' => true,
            'message' => __( 'Sincronizacion completada.', 'woo-image-sync' ),
            'results' => $results,
            'summary' => $summary,
        );
    }

    /**
     * Get empty summary array.
     *
     * @return array
     */
    private function empty_summary() {
        return array(
            'total'    => 0,
            'success'  => 0,
            'skipped'  => 0,
            'no_match' => 0,
            'error'    => 0,
        );
    }
}
