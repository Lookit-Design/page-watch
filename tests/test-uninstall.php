<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Uninstall extends WP_UnitTestCase {

	public function tear_down() {
		LPW_Store::install();
		delete_option( 'lookit_page_watch_settings' );
		delete_option( 'lookit_page_watch_db_version' );
		delete_option( 'lookit_page_watch_last_digest' );
		LPW_Cron::unschedule();
		parent::tear_down();
	}

	public function test_uninstall_preserves_settings_by_default() {
		update_option(
			'lookit_page_watch_settings',
			array_merge(
				lookit_page_watch_default_settings(),
				array( 'preserve_on_uninstall' => 1 )
			),
			false
		);
		update_option( 'lookit_page_watch_db_version', LPW_VERSION, false );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lookit-page-watch/lookit-page-watch.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertNotFalse( get_option( 'lookit_page_watch_settings' ) );
		$this->assertSame( LPW_VERSION, get_option( 'lookit_page_watch_db_version' ) );
	}

	public function test_uninstall_removes_plugin_data_when_preservation_is_disabled() {
		global $wpdb;

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lookit-page-watch/lookit-page-watch.php' );
		}

		$settings                          = lookit_page_watch_default_settings();
		$settings['preserve_on_uninstall'] = 0;
		$settings['dir_key']               = 'uninstall-test';
		update_option( 'lookit_page_watch_settings', $settings, false );
		update_option( 'lookit_page_watch_db_version', LPW_VERSION, false );
		update_option( 'lookit_page_watch_last_digest', array( 'time' => time() ), false );

		$storage = lookit_page_watch_storage_dir();
		$fixture = $storage['path'] . 'capture.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-owned test fixture.
		file_put_contents( $fixture, 'capture' );

		$page_id = LPW_Store::add_page( 'https://example.com/uninstall/' );
		LPW_Store::add_capture( $page_id, 'capture.png' );

		$plugin_attachment = self::factory()->attachment->create();
		update_post_meta( $plugin_attachment, '_lpw_kind', 'capture' );
		$other_attachment = self::factory()->attachment->create();

		LPW_Cron::reschedule();
		$this->assertNotFalse( wp_next_scheduled( 'lpw_capture_event' ) );

		require dirname( __DIR__ ) . '/uninstall.php';
		$last_query = $wpdb->last_query;

		$this->assertFalse( get_option( 'lookit_page_watch_settings' ) );
		$this->assertFalse( get_option( 'lookit_page_watch_db_version' ) );
		$this->assertFalse( get_option( 'lookit_page_watch_last_digest' ) );
		$this->assertFalse( wp_next_scheduled( 'lpw_capture_event' ) );
		$this->assertFalse( wp_next_scheduled( 'lpw_digest_event' ) );
		$this->assertDirectoryDoesNotExist( $storage['path'] );
		$this->assertNull( get_post( $plugin_attachment ) );
		$this->assertNotNull( get_post( $other_attachment ) );
		$this->assertMatchesRegularExpression( '/DROP (?:TEMPORARY )?TABLE IF EXISTS/', $last_query );
		$this->assertStringContainsString( LPW_Store::pages_table(), $last_query );
	}
}
