<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Plugin extends WP_UnitTestCase {

	public function test_plugin_defines_version() {
		$this->assertTrue( defined( 'LPW_VERSION' ) );
		$this->assertSame( '0.8.3', LPW_VERSION );
	}

	public function test_default_settings_include_required_keys() {
		$defaults = lookit_page_watch_default_settings();
		$this->assertArrayHasKey( 'endpoint', $defaults );
		$this->assertArrayHasKey( 'token', $defaults );
		$this->assertArrayHasKey( 'interval', $defaults );
		$this->assertSame( '', $defaults['token'] );
	}

	public function test_extension_for_whitelists_image_types() {
		$this->assertSame( 'png', LPW_Media::extension_for( 'image/png' ) );
		$this->assertSame( 'jpg', LPW_Media::extension_for( 'image/jpeg' ) );
		$this->assertSame( 'webp', LPW_Media::extension_for( 'image/webp' ) );
		$this->assertSame( 'png', LPW_Media::extension_for( 'text/html' ) );
		$this->assertTrue( LPW_Media::is_allowed_mime( 'image/png' ) );
		$this->assertFalse( LPW_Media::is_allowed_mime( 'text/html' ) );
	}

	public function test_add_page_rejects_invalid_url() {
		$result = LPW_Store::add_page( 'not-a-url' );
		$this->assertWPError( $result );
	}

	public function test_capture_endpoint_requires_https_except_on_localhost() {
		$this->assertSame( '', LPW_Admin::sanitize_endpoint( 'http://example.com/webhook' ) );
		$this->assertSame( 'https://example.com/webhook', LPW_Admin::sanitize_endpoint( 'https://example.com/webhook' ) );
		$this->assertSame( 'http://127.0.0.1:5678/webhook', LPW_Admin::sanitize_endpoint( 'http://127.0.0.1:5678/webhook' ) );
	}

	public function test_deleting_page_removes_more_than_one_capture_batch() {
		global $wpdb;

		$page_id = LPW_Store::add_page( 'https://example.com/' );
		$this->assertIsInt( $page_id );

		for ( $i = 0; $i < 105; $i++ ) {
			LPW_Store::add_capture( $page_id, '', null, 'failed', 'test' );
		}

		LPW_Store::delete_page( $page_id );

		$table = LPW_Store::captures_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifying plugin table cleanup.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE page_id = %d', $table, $page_id ) );
		$this->assertSame( '0', $count );
	}

	public function test_watchlist_hidden_from_subscriber() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$this->expectException( WPDieException::class );
		LPW_Admin::render_watchlist();
	}

	public function test_settings_page_hidden_from_subscriber() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$this->expectException( WPDieException::class );
		LPW_Admin::render_settings();
	}
}
