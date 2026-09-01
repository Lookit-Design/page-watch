<?php
/**
 * Media Library storage for Lookit Page Watch.
 *
 * Captures can live either as plain files in a private uploads subfolder or as
 * real Media Library attachments. Attachments cost a little more work but give
 * WordPress a properly registered image: thumbnails, correct mime handling,
 * stable URLs, and something a person can open from the Media screen.
 *
 * Follows the same insert pattern as Lookit Media Master so the two plugins
 * produce attachments that behave identically.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes capture attachments.
 */
class LPW_Media {

	/**
	 * Is Media Library storage switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) lookit_page_watch_setting( 'use_media_library', 1 );
	}

	/**
	 * Store raw image bytes as a Media Library attachment.
	 *
	 * @param string $bytes    Raw image data.
	 * @param string $mime     Mime type.
	 * @param string $basename Filename without extension.
	 * @param int    $page_id  Watched page row ID, recorded as meta.
	 * @param string $kind     capture|baseline, recorded as meta.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function store( $bytes, $mime, $basename, $page_id, $kind = 'capture' ) {
		$ext = self::extension_for( $mime );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'lpw_uploads', $uploads['error'] );
		}

		$dir      = trailingslashit( $uploads['path'] );
		$filename = wp_unique_filename( $dir, sanitize_file_name( $basename . '.' . $ext ) );
		$dest     = $dir . $filename;

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $dest, $bytes, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'lpw_write', __( 'Could not write the image into the uploads folder.', 'lookit-page-watch' ) );
		}

		$url = trailingslashit( $uploads['url'] ) . $filename;

		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => $url,
				'post_mime_type' => $mime,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$dest
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $dest );
			return new WP_Error( 'lpw_attachment', __( 'Could not create the attachment.', 'lookit-page-watch' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $dest );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$page = LPW_Store::get_page( $page_id );
		$label = $page ? $page->label : '';

		update_post_meta( $attachment_id, '_lpw_page_id', (int) $page_id );
		update_post_meta( $attachment_id, '_lpw_kind', 'baseline' === $kind ? 'baseline' : 'capture' );
		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sprintf(
				/* translators: 1: page name, 2: baseline or capture. */
				__( 'Page Watch %2$s of %1$s', 'lookit-page-watch' ),
				$label,
				'baseline' === $kind ? __( 'baseline', 'lookit-page-watch' ) : __( 'capture', 'lookit-page-watch' )
			)
		);

		return (int) $attachment_id;
	}

	/**
	 * Copy an existing attachment into a new one, used when promoting a
	 * capture to baseline so retention cleanup can never delete the baseline.
	 *
	 * @param int    $attachment_id Source attachment.
	 * @param int    $page_id       Watched page row ID.
	 * @param string $basename      Filename without extension.
	 * @return int|WP_Error New attachment ID.
	 */
	public static function duplicate( $attachment_id, $page_id, $basename ) {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'lpw_missing', __( 'That capture file is missing from disk.', 'lookit-page-watch' ) );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local attachment.

		if ( false === $bytes ) {
			return new WP_Error( 'lpw_read', __( 'Could not read that capture file.', 'lookit-page-watch' ) );
		}

		$mime = get_post_mime_type( $attachment_id );

		return self::store( $bytes, $mime ? $mime : 'image/png', $basename, $page_id, 'baseline' );
	}

	/**
	 * Remove an attachment and its files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function remove( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! $attachment_id ) {
			return;
		}

		// Only ever delete attachments this plugin created.
		if ( '' === get_post_meta( $attachment_id, '_lpw_kind', true ) ) {
			return;
		}

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Local file path for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function path( $attachment_id ) {
		$path = get_attached_file( (int) $attachment_id );
		return $path ? $path : '';
	}

	/**
	 * Public URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function url( $attachment_id ) {
		$url = wp_get_attachment_url( (int) $attachment_id );
		return $url ? $url : '';
	}

	/**
	 * File extension for a mime type.
	 *
	 * @param string $mime Mime type.
	 * @return string
	 */
	public static function extension_for( $mime ) {
		$map = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/webp' => 'webp',
		);

		$mime = strtolower( trim( (string) $mime ) );

		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'png';
	}
}
