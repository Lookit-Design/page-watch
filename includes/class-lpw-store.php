<?php
/**
 * Storage layer for Lookit Page Watch.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tables, queries and file bookkeeping.
 */
class LPW_Store {

	/**
	 * Watched pages table name.
	 *
	 * @return string
	 */
	public static function pages_table() {
		global $wpdb;
		return $wpdb->prefix . 'lookit_page_watch_pages';
	}

	/**
	 * Captures table name.
	 *
	 * @return string
	 */
	public static function captures_table() {
		global $wpdb;
		return $wpdb->prefix . 'lookit_page_watch_captures';
	}

	/**
	 * Create or upgrade tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$pages   = self::pages_table();
		$caps    = self::captures_table();

		$sql_pages = "CREATE TABLE {$pages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL DEFAULT '',
			url varchar(512) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			width smallint(5) unsigned NOT NULL DEFAULT 1440,
			threshold decimal(5,2) NOT NULL DEFAULT 2.00,
			baseline_file varchar(255) NOT NULL DEFAULT '',
			baseline_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			baseline_at datetime DEFAULT NULL,
			baseline_locked tinyint(1) NOT NULL DEFAULT 1,
			latest_capture_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";

		$sql_caps = "CREATE TABLE {$caps} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL,
			file varchar(255) NOT NULL DEFAULT '',
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			captured_at datetime NOT NULL,
			diff_pct decimal(6,2) DEFAULT NULL,
			region_pct decimal(6,2) DEFAULT NULL,
			state varchar(20) NOT NULL DEFAULT 'ok',
			provider varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			notified tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY captured_at (captured_at)
		) {$charset};";

		dbDelta( $sql_pages );
		dbDelta( $sql_caps );
	}

	/**
	 * All watched pages.
	 *
	 * @param bool $active_only Limit to active pages.
	 * @return array<int,object>
	 */
	public static function get_pages( $active_only = false ) {
		global $wpdb;
		$table = self::pages_table();

		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC', $table, 'active' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ) );
	}

	/**
	 * One page.
	 *
	 * @param int $id Page row ID.
	 * @return object|null
	 */
	public static function get_page( $id ) {
		global $wpdb;
		$table = self::pages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $id ) );
	}

	/**
	 * Add a page to the watchlist.
	 *
	 * @param string $url   Absolute URL.
	 * @param string $label Friendly name.
	 * @return int|WP_Error New row ID.
	 */
	public static function add_page( $url, $label = '' ) {
		global $wpdb;

		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'lpw_bad_url', __( 'That does not look like a full URL. Include https://', 'lookit-page-watch' ) );
		}

		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			$parts = wp_parse_url( $url );
			$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
			$label = '' === $path ? __( 'Home', 'lookit-page-watch' ) : ucwords( str_replace( array( '-', '/' ), ' ', $path ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$ok = $wpdb->insert(
			self::pages_table(),
			array(
				'label'      => $label,
				'url'        => $url,
				'status'     => 'active',
				'width'      => (int) lookit_page_watch_setting( 'width', 1440 ),
				'threshold'  => (float) lookit_page_watch_setting( 'threshold', 2.0 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'lpw_insert_failed', __( 'Could not save that page.', 'lookit-page-watch' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update page columns.
	 *
	 * @param int                 $id   Page ID.
	 * @param array<string,mixed> $data Columns to set.
	 * @return void
	 */
	public static function update_page( $id, array $data ) {
		global $wpdb;

		$allowed = array(
			'label'                  => true,
			'url'                    => true,
			'status'                 => true,
			'width'                  => true,
			'threshold'              => true,
			'baseline_file'          => true,
			'baseline_attachment_id' => true,
			'baseline_at'            => true,
			'baseline_locked'        => true,
			'latest_capture_id'      => true,
		);
		$data    = array_intersect_key( $data, $allowed );

		if ( empty( $data ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$wpdb->update( self::pages_table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a page and all of its capture files.
	 *
	 * @param int $id Page ID.
	 * @return void
	 */
	public static function delete_page( $id ) {
		global $wpdb;
		$id   = (int) $id;
		$page = self::get_page( $id );

		do {
			$captures = self::get_captures( $id, 100 );
			foreach ( $captures as $capture ) {
				self::delete_file( $capture->file );
				if ( (int) $capture->attachment_id ) {
					LPW_Media::remove( (int) $capture->attachment_id );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table cleanup.
				$wpdb->delete( self::captures_table(), array( 'id' => (int) $capture->id ), array( '%d' ) );
			}
		} while ( ! empty( $captures ) );

		if ( $page ) {
			if ( $page->baseline_file ) {
				self::delete_file( $page->baseline_file );
			}
			if ( (int) $page->baseline_attachment_id ) {
				LPW_Media::remove( (int) $page->baseline_attachment_id );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$wpdb->delete( self::captures_table(), array( 'page_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$wpdb->delete( self::pages_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Record a capture.
	 *
	 * @param int         $page_id       Page ID.
	 * @param string      $file          Relative filename inside the storage dir, empty when using the Media Library.
	 * @param float|null  $diff_pct      Percentage difference against baseline.
	 * @param string      $state         ok|failed.
	 * @param string|null $message       Error text.
	 * @param int         $attachment_id Media Library attachment, 0 when storing a plain file.
	 * @param float|null  $region_pct    Worst single area difference, which catches localized edits.
	 * @param string      $provider      Which capture provider produced the image.
	 * @return int Capture row ID.
	 */
	public static function add_capture( $page_id, $file, $diff_pct = null, $state = 'ok', $message = null, $attachment_id = 0, $region_pct = null, $provider = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$wpdb->insert(
			self::captures_table(),
			array(
				'page_id'       => (int) $page_id,
				'file'          => $file,
				'attachment_id' => (int) $attachment_id,
				'captured_at'   => current_time( 'mysql' ),
				'diff_pct'      => null === $diff_pct ? null : (float) $diff_pct,
				'region_pct'    => null === $region_pct ? null : (float) $region_pct,
				'state'         => $state,
				'provider'      => sanitize_key( $provider ),
				'message'       => $message,
				'notified'      => 0,
			),
			array( '%d', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%d' )
		);

		$capture_id = (int) $wpdb->insert_id;
		self::update_page( $page_id, array( 'latest_capture_id' => $capture_id ) );

		return $capture_id;
	}

	/**
	 * Captures for a page, newest first.
	 *
	 * @param int $page_id Page ID.
	 * @param int $limit   Row limit.
	 * @return array<int,object>
	 */
	public static function get_captures( $page_id, $limit = 20 ) {
		global $wpdb;
		$table = self::captures_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE page_id = %d ORDER BY captured_at DESC LIMIT %d', $table, (int) $page_id, (int) $limit )
		);
	}

	/**
	 * The most recent capture for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return object|null
	 */
	public static function latest_capture( $page_id ) {
		$rows = self::get_captures( $page_id, 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * One capture row.
	 *
	 * @param int $id Capture ID.
	 * @return object|null
	 */
	public static function get_capture( $id ) {
		global $wpdb;
		$table = self::captures_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $id ) );
	}

	/**
	 * Promote a capture to be the page's baseline.
	 *
	 * The baseline is a separate, copied file. It is never overwritten by a
	 * scheduled run and never removed by retention cleanup — only this method
	 * replaces it, and only when a person asks for it.
	 *
	 * @param int $page_id    Page ID.
	 * @param int $capture_id Capture to promote. 0 uses the latest capture.
	 * @return true|WP_Error
	 */
	public static function set_baseline( $page_id, $capture_id = 0 ) {
		$page = self::get_page( $page_id );
		if ( ! $page ) {
			return new WP_Error( 'lpw_no_page', __( 'That page is not on the watchlist.', 'lookit-page-watch' ) );
		}

		$capture = $capture_id ? self::get_capture( $capture_id ) : self::latest_capture( $page_id );
		if ( ! $capture || 'ok' !== $capture->state ) {
			return new WP_Error( 'lpw_no_capture', __( 'There is no successful capture to use as a baseline yet.', 'lookit-page-watch' ) );
		}

		$old_file          = $page->baseline_file;
		$old_attachment_id = (int) $page->baseline_attachment_id;

		// The baseline is always a fresh copy, never a pointer at a capture
		// row, so retention cleanup can never take it away.
		if ( (int) $capture->attachment_id ) {
			$new_id = LPW_Media::duplicate(
				(int) $capture->attachment_id,
				(int) $page_id,
				sprintf( 'page-watch-baseline-%d-%s', (int) $page_id, gmdate( 'Ymd-His' ) )
			);

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			self::update_page(
				$page_id,
				array(
					'baseline_file'          => '',
					'baseline_attachment_id' => (int) $new_id,
					'baseline_at'            => current_time( 'mysql' ),
				)
			);
		} else {
			if ( empty( $capture->file ) ) {
				return new WP_Error( 'lpw_no_capture', __( 'There is no successful capture to use as a baseline yet.', 'lookit-page-watch' ) );
			}

			$dir    = lookit_page_watch_storage_dir();
			$source = $dir['path'] . $capture->file;

			if ( ! file_exists( $source ) ) {
				return new WP_Error( 'lpw_missing_file', __( 'That capture file is missing from disk.', 'lookit-page-watch' ) );
			}

			$ext      = pathinfo( $capture->file, PATHINFO_EXTENSION );
			$new_name = sprintf( 'baseline-%d-%s.%s', (int) $page_id, gmdate( 'Ymd-His' ), $ext ? $ext : 'png' );

			if ( ! copy( $source, $dir['path'] . $new_name ) ) {
				return new WP_Error( 'lpw_copy_failed', __( 'Could not write the new baseline file.', 'lookit-page-watch' ) );
			}

			self::update_page(
				$page_id,
				array(
					'baseline_file'          => $new_name,
					'baseline_attachment_id' => 0,
					'baseline_at'            => current_time( 'mysql' ),
				)
			);
		}

		if ( $old_file ) {
			self::delete_file( $old_file );
		}
		if ( $old_attachment_id ) {
			LPW_Media::remove( $old_attachment_id );
		}

		return true;
	}

	/**
	 * Delete a stored file by its relative name.
	 *
	 * @param string $file Filename.
	 * @return void
	 */
	public static function delete_file( $file ) {
		if ( empty( $file ) ) {
			return;
		}
		$dir  = lookit_page_watch_storage_dir();
		$path = $dir['path'] . basename( $file );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Public URL for a capture row, whichever storage it uses.
	 *
	 * @param object|null $capture Capture row.
	 * @return string
	 */
	public static function capture_url( $capture ) {
		if ( ! $capture ) {
			return '';
		}
		if ( (int) $capture->attachment_id ) {
			return LPW_Media::url( (int) $capture->attachment_id );
		}
		return self::file_url( $capture->file );
	}

	/**
	 * Local path for a capture row, whichever storage it uses.
	 *
	 * @param object|null $capture Capture row.
	 * @return string
	 */
	public static function capture_path( $capture ) {
		if ( ! $capture ) {
			return '';
		}
		if ( (int) $capture->attachment_id ) {
			return LPW_Media::path( (int) $capture->attachment_id );
		}
		if ( empty( $capture->file ) ) {
			return '';
		}
		$dir = lookit_page_watch_storage_dir();
		return $dir['path'] . basename( $capture->file );
	}

	/**
	 * Public URL for a page's baseline, whichever storage it uses.
	 *
	 * @param object|null $page Page row.
	 * @return string
	 */
	public static function baseline_url( $page ) {
		if ( ! $page ) {
			return '';
		}
		if ( (int) $page->baseline_attachment_id ) {
			return LPW_Media::url( (int) $page->baseline_attachment_id );
		}
		return self::file_url( $page->baseline_file );
	}

	/**
	 * Local path for a page's baseline, whichever storage it uses.
	 *
	 * @param object|null $page Page row.
	 * @return string
	 */
	public static function baseline_path( $page ) {
		if ( ! $page ) {
			return '';
		}
		if ( (int) $page->baseline_attachment_id ) {
			return LPW_Media::path( (int) $page->baseline_attachment_id );
		}
		if ( empty( $page->baseline_file ) ) {
			return '';
		}
		$dir = lookit_page_watch_storage_dir();
		return $dir['path'] . basename( $page->baseline_file );
	}

	/**
	 * Public URL for a stored file.
	 *
	 * @param string $file Filename.
	 * @return string
	 */
	public static function file_url( $file ) {
		if ( empty( $file ) ) {
			return '';
		}
		$dir = lookit_page_watch_storage_dir();
		return $dir['url'] . rawurlencode( basename( $file ) );
	}

	/**
	 * Remove captures older than the retention window. Baselines are exempt.
	 *
	 * @return int Number of rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) lookit_page_watch_setting( 'retain_days', 30 );
		if ( $days < 1 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$table  = self::captures_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
		$old = $wpdb->get_results( $wpdb->prepare( 'SELECT id, file, attachment_id FROM %i WHERE captured_at < %s', $table, $cutoff ) );

		$keep = array();
		foreach ( self::get_pages() as $page ) {
			$keep[] = (int) $page->latest_capture_id;
		}

		$removed = 0;
		foreach ( $old as $row ) {
			if ( in_array( (int) $row->id, $keep, true ) ) {
				continue;
			}
			self::delete_file( $row->file );
			if ( (int) $row->attachment_id ) {
				LPW_Media::remove( (int) $row->attachment_id );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; results change on every capture run, so caching them would defeat the point.
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			++$removed;
		}

		return $removed;
	}

	/**
	 * Total bytes used by stored captures.
	 *
	 * @return int
	 */
	public static function disk_usage() {
		$dir   = lookit_page_watch_storage_dir();
		$total = 0;
		$files = glob( $dir['path'] . '*.{png,jpg,jpeg,webp}', GLOB_BRACE );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$total += (int) filesize( $file );
			}
		}
		return $total;
	}
}
