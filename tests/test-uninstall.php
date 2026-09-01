<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Uninstall extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'lookit_page_watch_settings' );
		delete_option( 'lookit_page_watch_db_version' );
		delete_option( 'lookit_page_watch_last_digest' );
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
}
