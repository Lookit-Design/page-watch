<?php
/**
 * @package Lookit_Page_Watch
 */

class Test_Lookit_Page_Watch_Capture extends WP_UnitTestCase {

	/**
	 * Requests seen by the intercepting filter.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $seen = array();

	/**
	 * The filter installed for the current test, so it can be removed cleanly.
	 *
	 * @var callable|null
	 */
	private $interceptor = null;

	public function set_up() {
		parent::set_up();

		$settings             = lookit_page_watch_default_settings();
		$settings['endpoint'] = 'https://capture.example.com/webhook/page-watch-capture';
		$settings['token']    = str_repeat( 'a', 32 );
		update_option( 'lookit_page_watch_settings', $settings, false );
	}

	public function tear_down() {
		if ( $this->interceptor ) {
			remove_filter( 'pre_http_request', $this->interceptor, 10 );
			$this->interceptor = null;
		}
		foreach ( LPW_Store::get_pages() as $page ) {
			LPW_Store::delete_page( (int) $page->id );
		}
		$this->seen = array();
		delete_option( 'lookit_page_watch_settings' );
		parent::tear_down();
	}

	/**
	 * Answer the capture request from the HTTP layer rather than the network.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded response body.
	 * @return void
	 */
	private function answer_with( $code, array $body ) {
		$response = array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);

		$this->interceptor = function ( $preempt, $args, $url ) use ( $response ) {
			$this->seen[] = array(
				'url'  => $url,
				'args' => $args,
			);
			return $response;
		};

		add_filter( 'pre_http_request', $this->interceptor, 10, 3 );
	}

	/**
	 * Decoded body of the first intercepted request.
	 *
	 * @return array<string,mixed>
	 */
	private function first_request_body() {
		$this->assertNotEmpty( $this->seen, 'No request reached the HTTP layer.' );
		return json_decode( $this->seen[0]['args']['body'], true );
	}

	/**
	 * Create a minimal PNG header with chosen dimensions.
	 *
	 * @param int $width  Reported width.
	 * @param int $height Reported height.
	 * @return string Image-like bytes.
	 */
	private function png_header( $width, $height ) {
		$header  = "\x89PNG\r\n\x1a\n";
		$header .= pack( 'N', 13 ) . 'IHDR';
		$header .= pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		$header .= pack( 'N', 0 );
		return str_pad( $header, 512, "\0" );
	}

	/**
	 * Generate a real PNG.
	 *
	 * @return string PNG bytes.
	 */
	private function valid_png() {
		$image = imagecreatetruecolor( 100, 100 );
		$teal  = imagecolorallocate( $image, 2, 134, 115 );
		for ( $y = 0; $y < 100; ++$y ) {
			for ( $x = 0; $x < 100; ++$x ) {
				$colour = 0 === ( ( $x + $y ) % 3 )
					? $teal
					: ( ( ( $x * 53 + $y * 97 ) & 255 ) << 16 )
						| ( ( ( $x * 29 + $y * 71 ) & 255 ) << 8 )
						| ( ( $x * 11 + $y * 43 ) & 255 );
				imagesetpixel( $image, $x, $y, $colour );
			}
		}
		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();
		return $bytes;
	}

	public function test_connection_test_pings_instead_of_requesting_a_screenshot() {
		$this->answer_with(
			200,
			array(
				'ok'       => true,
				'ping'     => true,
				'provider' => 'mshots',
			)
		);

		$result = LPW_Capture::test_endpoint();

		$this->assertTrue( $result['ok'] );

		$sent = $this->first_request_body();
		$this->assertSame( 'ping', $sent['mode'] );
		$this->assertArrayNotHasKey( 'url', $sent );
		$headers = array_change_key_case( (array) $this->seen[0]['args']['headers'] );
		$this->assertSame( str_repeat( 'a', 32 ), $headers['x-lookit-token'] );
	}

	public function test_connection_test_blames_the_allowlist_and_not_the_token_on_403() {
		$this->answer_with(
			403,
			array(
				'ok'    => false,
				'error' => 'That hostname is not in the workflow allowed_hosts list.',
			)
		);

		$result = LPW_Capture::test_endpoint();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'allowed_hosts', $result['message'] );
		$this->assertStringNotContainsString( 'token', $result['message'] );
	}

	public function test_connection_test_blames_the_token_on_401() {
		$this->answer_with(
			401,
			array(
				'ok'    => false,
				'error' => 'Invalid or missing capture token.',
			)
		);

		$result = LPW_Capture::test_endpoint();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'token', $result['message'] );
		$this->assertStringNotContainsString( 'allowed_hosts', $result['message'] );
	}

	public function test_a_403_without_a_reason_still_points_at_the_allowlist() {
		$this->answer_with( 403, array( 'ok' => false ) );

		$result = LPW_Capture::test_endpoint();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'allowed_hosts', $result['message'] );
	}

	public function test_capture_sends_the_page_url_and_no_ping_mode() {
		$page_id = LPW_Store::add_page( 'https://example.com/about/' );
		$this->assertIsInt( $page_id );

		$this->answer_with( 200, array( 'ok' => true ) );

		LPW_Capture::run( $page_id );

		$sent = $this->first_request_body();
		$this->assertSame( 'https://example.com/about/', $sent['url'] );
		$this->assertArrayNotHasKey( 'mode', $sent );
	}

	public function test_capture_rejects_a_success_reply_that_carries_no_image() {
		$page_id = LPW_Store::add_page( 'https://example.com/about/' );

		$this->answer_with( 200, array( 'ok' => true ) );

		$result = LPW_Capture::run( $page_id );

		$this->assertFalse( $result['ok'] );

		$capture = LPW_Store::latest_capture( $page_id );
		$this->assertNotNull( $capture );
		$this->assertSame( 'failed', $capture->state );
	}

	public function test_capture_refuses_a_non_https_endpoint() {
		$settings             = lookit_page_watch_default_settings();
		$settings['endpoint'] = 'http://capture.example.com/webhook';
		$settings['token']    = str_repeat( 'a', 32 );
		update_option( 'lookit_page_watch_settings', $settings, false );

		$page_id = LPW_Store::add_page( 'https://example.com/about/' );
		$this->answer_with( 200, array( 'ok' => true ) );

		$result = LPW_Capture::run( $page_id );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'HTTPS', $result['message'] );
		$this->assertSame( array(), $this->seen, 'The request should never leave WordPress.' );
	}

	public function test_capture_rejects_an_image_over_the_byte_limit() {
		$page_id = LPW_Store::add_page( 'https://example.com/too-many-bytes/' );
		$encoded = str_repeat( 'A', (int) ceil( LPW_Capture::MAX_IMAGE_BYTES * 4 / 3 ) + 5 );

		$this->answer_with(
			200,
			array(
				'ok'           => true,
				'image_base64' => $encoded,
			)
		);

		$result = LPW_Capture::run( $page_id );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'too large', $result['message'] );
		$this->assertSame( 'failed', LPW_Store::latest_capture( $page_id )->state );
	}

	public function test_capture_rejects_an_image_with_an_oversized_edge() {
		$page_id = LPW_Store::add_page( 'https://example.com/too-wide/' );

		$this->answer_with(
			200,
			array(
				'ok'           => true,
				'image_base64' => base64_encode( $this->png_header( LPW_Capture::MAX_IMAGE_EDGE + 1, 1 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding an image fixture.
			)
		);

		$result = LPW_Capture::run( $page_id );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', LPW_Store::latest_capture( $page_id )->state );
	}

	public function test_capture_rejects_an_image_over_the_pixel_limit() {
		$page_id = LPW_Store::add_page( 'https://example.com/too-many-pixels/' );

		$this->answer_with(
			200,
			array(
				'ok'           => true,
				'image_base64' => base64_encode( $this->png_header( 4000, 4000 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding an image fixture.
			)
		);

		$result = LPW_Capture::run( $page_id );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', LPW_Store::latest_capture( $page_id )->state );
	}

	public function test_capture_accepts_a_real_image_and_creates_a_separate_baseline() {
		$settings                      = lookit_page_watch_get_settings();
		$settings['use_media_library'] = 0;
		update_option( 'lookit_page_watch_settings', $settings, false );

		$page_id = LPW_Store::add_page( 'https://example.com/valid-capture/' );
		$this->answer_with(
			200,
			array(
				'ok'           => true,
				'provider'     => 'browserless',
				'image_base64' => base64_encode( $this->valid_png() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding an image fixture.
			)
		);

		try {
			$result  = LPW_Capture::run( $page_id );
			$page    = LPW_Store::get_page( $page_id );
			$capture = LPW_Store::get_capture( $result['capture_id'] );

			$this->assertTrue( $result['ok'] );
			$this->assertSame( 'browserless', $capture->provider );
			$this->assertNotSame( $capture->file, $page->baseline_file );
			$this->assertFileExists( LPW_Store::capture_path( $capture ) );
			$this->assertFileExists( LPW_Store::baseline_path( $page ) );
		} finally {
			LPW_Store::delete_page( $page_id );
		}
	}
}
