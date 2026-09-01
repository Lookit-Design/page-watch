<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Credential_Hygiene extends WP_UnitTestCase {

	const SECRET = 'secret-page-watch-token';

	public function tear_down() {
		delete_option( 'lookit_page_watch_settings' );
		parent::tear_down();
	}

	public function test_blank_token_keeps_existing_value() {
		$current          = lookit_page_watch_default_settings();
		$current['token'] = self::SECRET;

		$result = LPW_Admin::sanitize_settings(
			array(
				'token'    => '',
				'endpoint' => 'https://example.com/webhook',
				'interval' => 'lpw_24h',
			),
			$current
		);

		$this->assertSame( self::SECRET, $result['token'] );
	}

	public function test_sanitize_replaces_token_when_new_value_submitted() {
		$current          = lookit_page_watch_default_settings();
		$current['token'] = 'old-token';

		$result = LPW_Admin::sanitize_settings(
			array(
				'token'    => '  new-token  ',
				'endpoint' => 'https://example.com/webhook',
			),
			$current
		);

		$this->assertSame( 'new-token', $result['token'] );
	}

	public function test_settings_page_never_outputs_the_saved_token() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings          = lookit_page_watch_default_settings();
		$settings['token'] = self::SECRET;
		update_option( 'lookit_page_watch_settings', $settings, false );

		ob_start();
		LPW_Admin::render_settings();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'name="token"', $html );
		$this->assertStringContainsString( 'value=""', $html );
	}

	public function test_settings_option_is_not_autoloaded() {
		$settings          = lookit_page_watch_default_settings();
		$settings['token'] = self::SECRET;
		delete_option( 'lookit_page_watch_settings' );
		add_option( 'lookit_page_watch_settings', $settings, '', false );

		$this->assertArrayNotHasKey( 'lookit_page_watch_settings', wp_load_alloptions() );
		$this->assertSame( self::SECRET, lookit_page_watch_setting( 'token' ) );
	}

	public function test_existing_autoloaded_settings_are_migrated() {
		$settings          = lookit_page_watch_default_settings();
		$settings['token'] = self::SECRET;
		delete_option( 'lookit_page_watch_settings' );
		add_option( 'lookit_page_watch_settings', $settings, '', true );

		$this->assertArrayHasKey( 'lookit_page_watch_settings', wp_load_alloptions() );

		lookit_page_watch_maybe_disable_settings_autoload();

		$this->assertArrayNotHasKey( 'lookit_page_watch_settings', wp_load_alloptions() );
		$this->assertSame( self::SECRET, lookit_page_watch_setting( 'token' ) );
	}
}
