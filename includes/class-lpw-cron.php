<?php
/**
 * Scheduling for Lookit Page Watch.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers intervals and runs the scheduled work.
 */
class LPW_Cron {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'lpw_capture_event', array( __CLASS__, 'do_capture' ) );
		add_action( 'lpw_digest_event', array( __CLASS__, 'do_digest' ) );
	}

	/**
	 * Custom intervals.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function schedules( $schedules ) {
		$schedules['lpw_1h']  = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every hour (Page Watch)', 'lookit-page-watch' ),
		);
		$schedules['lpw_6h']  = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (Page Watch)', 'lookit-page-watch' ),
		);
		$schedules['lpw_12h'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 hours (Page Watch)', 'lookit-page-watch' ),
		);
		$schedules['lpw_24h'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Every 24 hours (Page Watch)', 'lookit-page-watch' ),
		);
		return $schedules;
	}

	/**
	 * Convert an HH:MM string in site time into the next UTC timestamp.
	 *
	 * @param string $time HH:MM.
	 * @return int
	 */
	public static function next_run_for( $time ) {
		$tz    = wp_timezone();
		$parts = explode( ':', (string) $time );
		$hour  = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 0;
		$min   = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

		$target = new DateTime( 'now', $tz );
		$target->setTime( $hour, $min, 0 );

		if ( $target->getTimestamp() <= time() ) {
			$target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}

	/**
	 * Clear and re-add both events from current settings.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::unschedule();

		$interval = (string) lookit_page_watch_setting( 'interval', 'lpw_24h' );
		$allowed  = array( 'lpw_1h', 'lpw_6h', 'lpw_12h', 'lpw_24h' );
		if ( ! in_array( $interval, $allowed, true ) ) {
			$interval = 'lpw_24h';
		}

		wp_schedule_event( self::next_run_for( lookit_page_watch_setting( 'anchor', '06:00' ) ), $interval, 'lpw_capture_event' );

		if ( 'every_run' !== lookit_page_watch_setting( 'digest_mode' ) ) {
			wp_schedule_event( self::next_run_for( lookit_page_watch_setting( 'digest_time', '08:00' ) ), 'daily', 'lpw_digest_event' );
		}
	}

	/**
	 * Remove scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'lpw_capture_event' );
		wp_clear_scheduled_hook( 'lpw_digest_event' );
	}

	/**
	 * Scheduled capture run.
	 *
	 * @return void
	 */
	public static function do_capture() {
		LPW_Capture::run_all();
		LPW_Store::prune();

		if ( 'every_run' === lookit_page_watch_setting( 'digest_mode' ) ) {
			LPW_Mailer::send_digest();
		}
	}

	/**
	 * Scheduled digest.
	 *
	 * @return void
	 */
	public static function do_digest() {
		LPW_Mailer::send_digest();
	}
}
