<?php
/**
 * Plugin Name:       Lookit Page Watch
 * Plugin URI:        https://lookitdesign.com/
 * Description:       Captures scheduled screenshots of selected pages, keeps a locked baseline image for each one, and emails a side-by-side comparison so changes can be spotted by eye.
 * Version:           0.8.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lookit Design
 * Author URI:        https://lookitdesign.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lookit-page-watch
 *
 * Lookit is a registered trademark of ZENOVA CORP.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * DO NOT RENAME — these identifiers are load-bearing on live installs.
 *   Option key ......... lookit_page_watch_settings
 *   Tables ............. {prefix}lookit_page_watch_pages
 *                        {prefix}lookit_page_watch_captures
 *   Menu slugs ......... lookit-page-watch, lookit-page-watch-settings
 *   Cron hooks ......... lpw_capture_event, lpw_digest_event
 *   AJAX actions ....... lpw_capture_page, lpw_set_baseline, lpw_delete_page
 *   Nonce actions ...... lpw_admin, lpw_settings, lpw_add_page
 *   CSS/JS prefix ...... lpw-
 * ---------------------------------------------------------------------------
 */

define( 'LPW_VERSION', '0.8.2' );
define( 'LPW_FILE', __FILE__ );
define( 'LPW_DIR', plugin_dir_path( __FILE__ ) );
define( 'LPW_URL', plugin_dir_url( __FILE__ ) );

require_once LPW_DIR . 'includes/class-lpw-store.php';
require_once LPW_DIR . 'includes/class-lpw-media.php';
require_once LPW_DIR . 'includes/class-lpw-diff.php';
require_once LPW_DIR . 'includes/class-lpw-capture.php';
require_once LPW_DIR . 'includes/class-lpw-cron.php';
require_once LPW_DIR . 'includes/class-lpw-mailer.php';
require_once LPW_DIR . 'includes/class-lpw-admin.php';

/**
 * Default settings.
 *
 * @return array<string,mixed>
 */
function lookit_page_watch_default_settings() {
	return array(
		'endpoint'              => '',
		'token'                 => '',
		'interval'              => 'lpw_24h',
		'anchor'                => '06:00',
		'width'                 => 1440,
		'full_page'             => 1,
		'threshold'             => 2.0,
		'region_threshold'      => 10.0,
		'digest_mode'           => 'daily_changes',
		'digest_time'           => '08:00',
		'recipients'            => '',
		'retain_days'           => 30,
		'use_media_library'     => 1,
		'preserve_on_uninstall' => 1,
		'dir_key'               => '',
	);
}

/**
 * Read settings, merged over defaults.
 *
 * @return array<string,mixed>
 */
function lookit_page_watch_get_settings() {
	$saved = get_option( 'lookit_page_watch_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, lookit_page_watch_default_settings() );
}

/**
 * Read a single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $fallback Fallback.
 * @return mixed
 */
function lookit_page_watch_setting( $key, $fallback = null ) {
	$settings = lookit_page_watch_get_settings();
	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
}

/**
 * Move settings created by older versions out of the autoloaded option set.
 *
 * @return void
 */
function lookit_page_watch_maybe_disable_settings_autoload() {
	$alloptions = wp_load_alloptions();
	if ( ! isset( $alloptions['lookit_page_watch_settings'] ) ) {
		return;
	}

	$settings = get_option( 'lookit_page_watch_settings' );
	delete_option( 'lookit_page_watch_settings' );
	add_option( 'lookit_page_watch_settings', $settings, '', false );
}

/**
 * Absolute path to the capture storage directory, created if missing.
 *
 * @return array{path:string,url:string}
 */
function lookit_page_watch_storage_dir() {
	$settings = lookit_page_watch_get_settings();
	$key      = $settings['dir_key'];

	if ( empty( $key ) ) {
		$key                 = wp_generate_password( 20, false, false );
		$settings['dir_key'] = $key;
		update_option( 'lookit_page_watch_settings', $settings, false );
	}

	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['basedir'] ) . 'lookit-page-watch/' . $key;
	$url     = trailingslashit( $uploads['baseurl'] ) . 'lookit-page-watch/' . $key;

	if ( ! file_exists( $path ) ) {
		wp_mkdir_p( $path );
	}

	$index = trailingslashit( $path ) . 'index.php';
	if ( ! file_exists( $index ) ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $wp_filesystem ) {
			$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}

	return array(
		'path' => trailingslashit( $path ),
		'url'  => trailingslashit( $url ),
	);
}

/**
 * Boot the plugin.
 *
 * @return void
 */
function lookit_page_watch_init() {
	lookit_page_watch_maybe_disable_settings_autoload();

	// Run dbDelta when the stored schema version is behind the plugin, so
	// updating over an older install picks up new columns.
	if ( get_option( 'lookit_page_watch_db_version' ) !== LPW_VERSION ) {
		LPW_Store::install();
		update_option( 'lookit_page_watch_db_version', LPW_VERSION, false );
	}

	LPW_Cron::init();
	if ( is_admin() ) {
		LPW_Admin::init();
	}
}
add_action( 'plugins_loaded', 'lookit_page_watch_init' );

/**
 * Activation: create tables, seed settings, schedule events.
 *
 * @return void
 */
function lookit_page_watch_activate() {
	LPW_Store::install();
	update_option( 'lookit_page_watch_db_version', LPW_VERSION, false );

	if ( false === get_option( 'lookit_page_watch_settings' ) ) {
		add_option( 'lookit_page_watch_settings', lookit_page_watch_default_settings(), '', false );
	}

	lookit_page_watch_storage_dir();
	LPW_Cron::reschedule();
}
register_activation_hook( __FILE__, 'lookit_page_watch_activate' );

/**
 * Deactivation: clear scheduled events. Data is left in place.
 *
 * @return void
 */
function lookit_page_watch_deactivate() {
	LPW_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'lookit_page_watch_deactivate' );
