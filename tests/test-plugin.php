<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Plugin extends WP_UnitTestCase {

	public function test_plugin_defines_version() {
		$this->assertTrue( defined( 'LPW_VERSION' ) );
		$this->assertSame( '0.8.1', LPW_VERSION );
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
