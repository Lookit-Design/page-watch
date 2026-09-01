<?php
/**
 * Capture service for Lookit Page Watch.
 *
 * WordPress cannot render a page to an image, so capture happens on the
 * platform: this class posts a URL to the n8n webhook and stores the PNG that
 * comes back. No provider credentials live in WordPress.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to n8n and writes capture files.
 */
class LPW_Capture {

	/**
	 * Capture one page.
	 *
	 * @param int $page_id Page row ID.
	 * @return array{ok:bool,message:string,diff:float|null,capture_id:int}
	 */
	public static function run( $page_id ) {
		$page = LPW_Store::get_page( $page_id );

		if ( ! $page ) {
			return self::result( false, __( 'That page is not on the watchlist.', 'lookit-page-watch' ) );
		}

		$endpoint = trim( (string) lookit_page_watch_setting( 'endpoint' ) );
		if ( empty( $endpoint ) ) {
			return self::result( false, __( 'No capture endpoint is set. Add the n8n webhook URL under Schedule and email.', 'lookit-page-watch' ) );
		}

		$attempt = self::request(
			$endpoint,
			array(
				'url'       => $page->url,
				'width'     => (int) $page->width,
				'full_page' => (bool) lookit_page_watch_setting( 'full_page', 1 ),
				'source'    => home_url( '/' ),
			)
		);

		if ( ! $attempt['ok'] ) {
			LPW_Store::add_capture( $page_id, '', null, 'failed', $attempt['message'] );
			return self::result( false, $attempt['message'] );
		}

		$body = $attempt['body'];

		$binary = base64_decode( $body['image_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own capture payload.

		if ( false === $binary || strlen( $binary ) < 512 ) {
			$message = __( 'The capture service returned an unreadable image.', 'lookit-page-watch' );
			LPW_Store::add_capture( $page_id, '', null, 'failed', $message );
			return self::result( false, $message );
		}

		$info = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid payloads are rejected below.
		$mime = ( is_array( $info ) && ! empty( $info['mime'] ) ) ? strtolower( (string) $info['mime'] ) : '';
		if ( ! LPW_Media::is_allowed_mime( $mime ) ) {
			$message = __( 'The capture service returned an unreadable image.', 'lookit-page-watch' );
			LPW_Store::add_capture( $page_id, '', null, 'failed', $message );
			return self::result( false, $message );
		}
		$page_label = sanitize_title( $page->label );
		$stamp      = gmdate( 'Ymd-His' );

		$file          = '';
		$attachment_id = 0;

		if ( LPW_Media::enabled() ) {
			$stored = LPW_Media::store(
				$binary,
				$mime,
				sprintf( 'page-watch-%s-%s', $page_label ? $page_label : $page_id, $stamp ),
				(int) $page_id,
				'capture'
			);

			if ( is_wp_error( $stored ) ) {
				$message = $stored->get_error_message();
				LPW_Store::add_capture( $page_id, '', null, 'failed', $message );
				return self::result( false, $message );
			}

			$attachment_id = (int) $stored;
			$current_path  = LPW_Media::path( $attachment_id );
		} else {
			$dir  = lookit_page_watch_storage_dir();
			$file = sprintf( 'page-%d-%s.%s', (int) $page_id, $stamp, LPW_Media::extension_for( $mime ) );

			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $dir['path'] . $file, $binary, FS_CHMOD_FILE ) ) {
				$message = __( 'Could not write the capture file to the uploads folder.', 'lookit-page-watch' );
				LPW_Store::add_capture( $page_id, '', null, 'failed', $message );
				return self::result( false, $message );
			}

			$current_path = $dir['path'] . $file;
		}

		// First successful capture becomes the baseline automatically. After
		// that the baseline only changes when someone chooses to replace it.
		$baseline_path     = LPW_Store::baseline_path( $page );
		$is_first_baseline = '' === $baseline_path || ! file_exists( $baseline_path );

		$diff   = null;
		$region = null;

		if ( ! $is_first_baseline ) {
			$scores = LPW_Diff::compare( $baseline_path, $current_path );
			if ( is_array( $scores ) ) {
				$diff   = $scores['overall'];
				$region = $scores['region'];
			}
		}

		$provider   = isset( $body['provider'] ) ? (string) $body['provider'] : '';
		$capture_id = LPW_Store::add_capture( $page_id, $file, $diff, 'ok', null, $attachment_id, $region, $provider );

		if ( $is_first_baseline ) {
			LPW_Store::set_baseline( $page_id, $capture_id );
			return self::result( true, __( 'Captured and saved as the baseline.', 'lookit-page-watch' ), null, $capture_id );
		}

		return self::result( true, __( 'Captured.', 'lookit-page-watch' ), $diff, $capture_id );
	}

	/**
	 * Post to the capture service, retrying the failures that are worth retrying.
	 *
	 * Two things go wrong often enough to be worth handling rather than
	 * reporting. The renderer sits silent for several seconds while it works,
	 * and a connection idle that long is sometimes dropped by something in
	 * between, which surfaces as a cURL transport error even though the
	 * workflow ran fine. Separately, mShots may still be rendering on the
	 * first ask. Both clear on a second attempt, so a failure is only reported
	 * once the retries are used up.
	 *
	 * @param string              $endpoint Webhook URL.
	 * @param array<string,mixed> $payload  Request body.
	 * @return array{ok:bool,message:string,body:array<string,mixed>|null}
	 */
	private static function request( $endpoint, array $payload ) {
		$attempts = 3;
		$last     = __( 'The capture service could not be reached.', 'lookit-page-watch' );

		for ( $try = 1; $try <= $attempts; $try++ ) {
			if ( $try > 1 ) {
				sleep( 3 );
			}

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'     => 40,
					'httpversion' => '1.1',
					'headers'     => array(
						'Content-Type'   => 'application/json',
						'X-Lookit-Token' => (string) lookit_page_watch_setting( 'token' ),
						'Accept'         => 'application/json',
						'Connection'     => 'close',
					),
					'body'        => wp_json_encode( $payload ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last = $response->get_error_message();
				if ( self::is_transient( $last ) ) {
					continue;
				}
				break;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $body ) && ! empty( $body['ok'] ) && ! empty( $body['image_base64'] ) ) {
				return array(
					'ok'      => true,
					'message' => '',
					'body'    => $body,
				);
			}

			if ( 401 === $code ) {
				$last = __( 'The capture service rejected the token. Check that the shared token here matches the one in the n8n Config node.', 'lookit-page-watch' );
				break;
			}

			if ( is_array( $body ) && ! empty( $body['error'] ) ) {
				$last = (string) $body['error'];
			} else {
				/* translators: %d: HTTP status code. */
				$last = sprintf( __( 'Capture service returned status %d.', 'lookit-page-watch' ), $code );
			}

			// The workflow flags a render that has not finished yet.
			if ( is_array( $body ) && ! empty( $body['retry'] ) ) {
				continue;
			}

			break;
		}

		return array(
			'ok'      => false,
			'message' => 1 === $attempts ? $last : sprintf(
				/* translators: 1: error text, 2: number of attempts. */
				__( '%1$s (tried %2$d times)', 'lookit-page-watch' ),
				$last,
				$attempts
			),
			'body'    => null,
		);
	}

	/**
	 * Is this a transport failure that a retry might get past.
	 *
	 * @param string $message Error text from WP_Error.
	 * @return bool
	 */
	private static function is_transient( $message ) {
		$signals = array(
			'unexpected eof',
			'Empty reply',
			'transfer closed',
			'Connection reset',
			'Operation timed out',
			'Recv failure',
			'timed out',
			'error:0A000126',
		);

		foreach ( $signals as $signal ) {
			if ( false !== stripos( $message, $signal ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capture every active page in sequence. Used by cron.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function run_all() {
		$out = array();
		foreach ( LPW_Store::get_pages( true ) as $page ) {
			$out[ (int) $page->id ] = self::run( (int) $page->id );
		}
		return $out;
	}

	/**
	 * Send a no-op request to confirm the endpoint answers.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test_endpoint() {
		$endpoint = trim( (string) lookit_page_watch_setting( 'endpoint' ) );
		if ( empty( $endpoint ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Add the webhook URL first.', 'lookit-page-watch' ),
			);
		}

		$attempt = self::request(
			$endpoint,
			array(
				'url'       => home_url( '/' ),
				'width'     => 800,
				'full_page' => false,
			)
		);

		if ( $attempt['ok'] ) {
			return array(
				'ok'      => true,
				'message' => __( 'The capture service answered and returned an image.', 'lookit-page-watch' ),
			);
		}

		return array(
			'ok'      => false,
			'message' => $attempt['message'],
		);
	}

	/**
	 * Shape a result array.
	 *
	 * @param bool       $ok         Success flag.
	 * @param string     $message    Human readable text.
	 * @param float|null $diff       Difference percentage.
	 * @param int        $capture_id Capture row ID.
	 * @return array{ok:bool,message:string,diff:float|null,capture_id:int}
	 */
	private static function result( $ok, $message, $diff = null, $capture_id = 0 ) {
		return array(
			'ok'         => (bool) $ok,
			'message'    => $message,
			'diff'       => $diff,
			'capture_id' => (int) $capture_id,
		);
	}
}
