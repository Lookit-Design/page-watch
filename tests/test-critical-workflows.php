<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Critical_Workflows extends WP_UnitTestCase {

	/**
	 * Temporary files created by image comparison tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_files = array();

	public function tear_down() {
		LPW_Cron::unschedule();
		delete_option( 'lookit_page_watch_settings' );
		delete_option( 'timezone_string' );

		foreach ( $this->temporary_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$this->temporary_files = array();
		parent::tear_down();
	}

	/**
	 * Create a solid PNG with an optional rectangle in a second colour.
	 *
	 * @param int        $width     Image width.
	 * @param int        $height    Image height.
	 * @param array|null $rectangle Rectangle coordinates.
	 * @return string PNG bytes.
	 */
	private function png( $width, $height, $rectangle = null ) {
		$image = imagecreatetruecolor( $width, $height );
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$black = imagecolorallocate( $image, 0, 0, 0 );
		imagefill( $image, 0, 0, $white );

		if ( is_array( $rectangle ) ) {
			imagefilledrectangle( $image, $rectangle[0], $rectangle[1], $rectangle[2], $rectangle[3], $black );
		}

		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();

		return $bytes;
	}

	/**
	 * Write image bytes to a temporary file.
	 *
	 * @param string $bytes File contents.
	 * @return string Path.
	 */
	private function temporary_image( $bytes ) {
		$file = wp_tempnam( 'lpw-test.png' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary test fixture.
		file_put_contents( $file, $bytes );
		$this->temporary_files[] = $file;
		return $file;
	}

	/**
	 * Use plain-file storage for a test.
	 *
	 * @return array{path:string,url:string}
	 */
	private function plain_storage() {
		$settings                      = lookit_page_watch_default_settings();
		$settings['use_media_library'] = 0;
		$settings['dir_key']           = 'critical-workflow-tests';
		update_option( 'lookit_page_watch_settings', $settings, false );
		return lookit_page_watch_storage_dir();
	}

	public function test_diff_reports_identical_images_as_unchanged() {
		$bytes = $this->png( 200, 800 );
		$first = $this->temporary_image( $bytes );
		$other = $this->temporary_image( $bytes );

		$result = LPW_Diff::compare( $first, $other );

		$this->assertSame(
			array(
				'overall' => 0.0,
				'region'  => 0.0,
				'height'  => 0.0,
			),
			$result
		);
	}

	public function test_diff_detects_a_small_localised_change() {
		$baseline = $this->temporary_image( $this->png( 200, 800 ) );
		$changed  = $this->temporary_image( $this->png( 200, 800, array( 0, 0, 19, 19 ) ) );

		$result = LPW_Diff::compare( $baseline, $changed );

		$this->assertSame( 0.25, $result['overall'] );
		$this->assertSame( 100.0, $result['region'] );
		$this->assertSame( 0.0, $result['height'] );
	}

	public function test_diff_counts_a_height_change_as_an_overall_change() {
		$baseline = $this->temporary_image( $this->png( 200, 800 ) );
		$shorter  = $this->temporary_image( $this->png( 200, 400 ) );

		$result = LPW_Diff::compare( $baseline, $shorter );

		$this->assertSame( 50.0, $result['overall'] );
		$this->assertSame( 50.0, $result['height'] );
	}

	public function test_media_baseline_is_a_separate_attachment_and_can_be_replaced() {
		$page_id = LPW_Store::add_page( 'https://example.com/media-baseline/' );
		$first   = LPW_Media::store( $this->png( 40, 40 ), 'image/png', 'first-capture', $page_id );
		$second  = LPW_Media::store( $this->png( 40, 40, array( 0, 0, 9, 9 ) ), 'image/png', 'second-capture', $page_id );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );

		$first_capture  = LPW_Store::add_capture( $page_id, '', 0, 'ok', null, $first );
		$second_capture = LPW_Store::add_capture( $page_id, '', 1, 'ok', null, $second );

		try {
			$this->assertTrue( LPW_Store::set_baseline( $page_id, $first_capture ) );

			$page              = LPW_Store::get_page( $page_id );
			$first_baseline_id = (int) $page->baseline_attachment_id;

			$this->assertNotSame( $first, $first_baseline_id );
			$this->assertSame( 'baseline', get_post_meta( $first_baseline_id, '_lpw_kind', true ) );
			$this->assertFileExists( get_attached_file( $first_baseline_id ) );
			$this->assertInstanceOf( WP_Post::class, get_post( $first ) );

			$this->assertTrue( LPW_Store::set_baseline( $page_id, $second_capture ) );

			$page               = LPW_Store::get_page( $page_id );
			$second_baseline_id = (int) $page->baseline_attachment_id;

			$this->assertNotSame( $first_baseline_id, $second_baseline_id );
			$this->assertNull( get_post( $first_baseline_id ) );
			$this->assertInstanceOf( WP_Post::class, get_post( $first ) );
			$this->assertInstanceOf( WP_Post::class, get_post( $second ) );
		} finally {
			LPW_Store::delete_page( $page_id );
		}
	}

	public function test_plain_file_baseline_is_copied_and_survives_its_capture() {
		$storage = $this->plain_storage();
		$page_id = LPW_Store::add_page( 'https://example.com/plain-baseline/' );
		$file    = 'capture.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned test fixture.
		file_put_contents( $storage['path'] . $file, $this->png( 40, 40 ) );
		$capture_id = LPW_Store::add_capture( $page_id, $file );

		try {
			$this->assertTrue( LPW_Store::set_baseline( $page_id, $capture_id ) );

			$page = LPW_Store::get_page( $page_id );
			$this->assertNotSame( $file, $page->baseline_file );
			$this->assertFileExists( $storage['path'] . $file );
			$this->assertFileExists( LPW_Store::baseline_path( $page ) );
		} finally {
			LPW_Store::delete_page( $page_id );
		}
	}

	public function test_prune_removes_expired_history_but_keeps_latest_and_baseline() {
		global $wpdb;

		$storage = $this->plain_storage();
		$page_id = LPW_Store::add_page( 'https://example.com/prune/' );
		$files   = array( 'expired.png', 'recent.png', 'latest.png' );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned test fixture.
			file_put_contents( $storage['path'] . $file, $this->png( 20, 20 ) );
		}

		$expired_id = LPW_Store::add_capture( $page_id, $files[0] );
		$recent_id  = LPW_Store::add_capture( $page_id, $files[1] );
		$latest_id  = LPW_Store::add_capture( $page_id, $files[2] );
		LPW_Store::set_baseline( $page_id, $recent_id );

		$table = LPW_Store::captures_table();
		$old   = gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- arranging custom-table test records.
		$wpdb->update( $table, array( 'captured_at' => $old ), array( 'id' => $expired_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- arranging custom-table test records.
		$wpdb->update( $table, array( 'captured_at' => $old ), array( 'id' => $latest_id ) );

		$page          = LPW_Store::get_page( $page_id );
		$baseline_path = LPW_Store::baseline_path( $page );

		try {
			$this->assertSame( 1, LPW_Store::prune() );
			$this->assertNull( LPW_Store::get_capture( $expired_id ) );
			$this->assertNotNull( LPW_Store::get_capture( $recent_id ) );
			$this->assertNotNull( LPW_Store::get_capture( $latest_id ) );
			$this->assertFileDoesNotExist( $storage['path'] . $files[0] );
			$this->assertFileExists( $storage['path'] . $files[1] );
			$this->assertFileExists( $storage['path'] . $files[2] );
			$this->assertFileExists( $baseline_path );
		} finally {
			LPW_Store::delete_page( $page_id );
		}
	}

	public function test_next_run_uses_site_time_and_rolls_forward() {
		update_option( 'timezone_string', 'America/Toronto' );

		$timestamp = LPW_Cron::next_run_for( '13:37' );
		$local     = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );

		$this->assertGreaterThan( time(), $timestamp );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS + HOUR_IN_SECONDS, $timestamp );
		$this->assertSame( '13:37', $local->format( 'H:i' ) );
	}

	public function test_reschedule_rejects_an_unknown_interval_and_obeys_digest_mode() {
		$settings                = lookit_page_watch_default_settings();
		$settings['interval']    = 'unknown';
		$settings['digest_mode'] = 'daily_changes';
		update_option( 'lookit_page_watch_settings', $settings, false );

		LPW_Cron::reschedule();

		$capture = wp_get_scheduled_event( 'lpw_capture_event' );
		$digest  = wp_get_scheduled_event( 'lpw_digest_event' );
		$this->assertSame( 'lpw_24h', $capture->schedule );
		$this->assertSame( 'daily', $digest->schedule );

		$settings['digest_mode'] = 'every_run';
		update_option( 'lookit_page_watch_settings', $settings, false );
		LPW_Cron::reschedule();

		$this->assertFalse( wp_next_scheduled( 'lpw_digest_event' ) );
	}
}
