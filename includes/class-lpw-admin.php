<?php
/**
 * Admin screens for Lookit Page Watch.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menus, screens, form handling and AJAX.
 */
class LPW_Admin {

	/**
	 * Hook up the admin.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_lpw_add_page', array( __CLASS__, 'handle_add_page' ) );
		add_action( 'admin_post_lpw_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lpw_send_test', array( __CLASS__, 'handle_send_test' ) );
		add_action( 'admin_post_lpw_test_endpoint', array( __CLASS__, 'handle_test_endpoint' ) );
		add_action( 'admin_post_lpw_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'wp_ajax_lpw_capture_page', array( __CLASS__, 'ajax_capture_page' ) );
		add_action( 'wp_ajax_lpw_set_baseline', array( __CLASS__, 'ajax_set_baseline' ) );
		add_action( 'wp_ajax_lpw_delete_page', array( __CLASS__, 'ajax_delete_page' ) );
		add_action( 'wp_ajax_lpw_finish_run', array( __CLASS__, 'ajax_finish_run' ) );
	}

	/**
	 * Menu entries.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Page Watch', 'lookit-page-watch' ),
			__( 'Page Watch', 'lookit-page-watch' ),
			'manage_options',
			'lookit-page-watch',
			array( __CLASS__, 'render_watchlist' ),
			'dashicons-camera-alt',
			81
		);

		add_submenu_page(
			'lookit-page-watch',
			__( 'Watchlist', 'lookit-page-watch' ),
			__( 'Watchlist', 'lookit-page-watch' ),
			'manage_options',
			'lookit-page-watch',
			array( __CLASS__, 'render_watchlist' )
		);

		add_submenu_page(
			'lookit-page-watch',
			__( 'Schedule and email', 'lookit-page-watch' ),
			__( 'Schedule and email', 'lookit-page-watch' ),
			'manage_options',
			'lookit-page-watch-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'lookit-page-watch' ) ) {
			return;
		}

		wp_enqueue_style( 'lpw-admin', LPW_URL . 'assets/admin.css', array(), LPW_VERSION );
		wp_enqueue_script( 'lpw-admin', LPW_URL . 'assets/admin.js', array(), LPW_VERSION, true );

		wp_localize_script(
			'lpw-admin',
			'lpwData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lpw_admin' ),
				'confirmBaseline' => __( 'Replace the saved baseline with this capture? The old baseline image is deleted and future comparisons use the new one.', 'lookit-page-watch' ),
				'confirmDelete'   => __( 'Remove this page from the watchlist? Its baseline and all captures are deleted.', 'lookit-page-watch' ),
				'capturing'       => __( 'Capturing…', 'lookit-page-watch' ),
				'done'            => __( 'Done', 'lookit-page-watch' ),
				'sending'         => __( 'Sending digest…', 'lookit-page-watch' ),
			)
		);
	}

	/**
	 * Watchlist screen, or the compare view when a page is selected.
	 *
	 * @return void
	 */
	public static function render_watchlist() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lookit-page-watch' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$compare = isset( $_GET['lpw_compare'] ) ? (int) $_GET['lpw_compare'] : 0;

		if ( $compare ) {
			self::render_compare( $compare );
			return;
		}

		$pages    = LPW_Store::get_pages();
		$settings = lookit_page_watch_get_settings();
		$next     = wp_next_scheduled( 'lpw_capture_event' );
		?>
		<div class="wrap lpw-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Page Watch', 'lookit-page-watch' ); ?></h1>
			<p class="lpw-sub">
				<?php esc_html_e( 'Every watched page has a locked baseline image. Each run captures a fresh shot and compares it against that baseline. The baseline only changes when you replace it.', 'lookit-page-watch' ); ?>
			</p>

			<?php self::notices(); ?>

			<?php if ( empty( $settings['endpoint'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No capture endpoint is set yet, so nothing can be captured.', 'lookit-page-watch' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=lookit-page-watch-settings' ) ); ?>"><?php esc_html_e( 'Add the n8n webhook URL', 'lookit-page-watch' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="lpw-card">
				<div class="lpw-card-h">
					<h2><?php esc_html_e( 'Watched pages', 'lookit-page-watch' ); ?></h2>
					<span class="lpw-meta">
						<?php
						if ( $next ) {
							printf(
								/* translators: %s: human readable time. */
								esc_html__( 'Next scheduled run %s', 'lookit-page-watch' ),
								esc_html( wp_date( 'g:i a, M j', $next ) )
							);
						} else {
							esc_html_e( 'No run scheduled', 'lookit-page-watch' );
						}
						?>
					</span>
					<span class="lpw-spacer"></span>
					<button type="button" class="button lpw-capture-all"><?php esc_html_e( 'Run capture now', 'lookit-page-watch' ); ?></button>
					<button type="button" class="button button-primary lpw-toggle-add"><?php esc_html_e( 'Add page', 'lookit-page-watch' ); ?></button>
				</div>

				<div class="lpw-addform" hidden>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'lpw_add_page' ); ?>
						<input type="hidden" name="action" value="lpw_add_page">
						<label for="lpw-url"><?php esc_html_e( 'Page URL', 'lookit-page-watch' ); ?></label>
						<input type="url" id="lpw-url" name="lpw_url" placeholder="https://example.com/about/" required>
						<label for="lpw-label"><?php esc_html_e( 'Name', 'lookit-page-watch' ); ?></label>
						<input type="text" id="lpw-label" name="lpw_label" placeholder="<?php esc_attr_e( 'Optional', 'lookit-page-watch' ); ?>">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add to watchlist', 'lookit-page-watch' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'The first successful capture is saved as the baseline automatically.', 'lookit-page-watch' ); ?></p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lpw_admin' ); ?>
					<input type="hidden" name="action" value="lpw_bulk">

					<table class="lpw-table widefat">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" class="lpw-check-all" aria-label="<?php esc_attr_e( 'Select all pages', 'lookit-page-watch' ); ?>"></td>
								<th><?php esc_html_e( 'Page', 'lookit-page-watch' ); ?></th>
								<th><?php esc_html_e( 'Baseline', 'lookit-page-watch' ); ?></th>
								<th><?php esc_html_e( 'Latest capture', 'lookit-page-watch' ); ?></th>
								<th><?php esc_html_e( 'Status', 'lookit-page-watch' ); ?></th>
								<th class="lpw-right"><?php esc_html_e( 'Actions', 'lookit-page-watch' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $pages ) ) : ?>
							<tr><td colspan="6" class="lpw-empty">
								<?php esc_html_e( 'Nothing is being watched yet. Add a page above and run a capture to set its baseline.', 'lookit-page-watch' ); ?>
							</td></tr>
						<?php endif; ?>

						<?php foreach ( $pages as $page ) : ?>
							<?php
							$latest    = LPW_Store::latest_capture( (int) $page->id );
							$base_url  = LPW_Store::baseline_url( $page );
							$cur_url   = ( $latest && 'ok' === $latest->state ) ? LPW_Store::capture_url( $latest ) : '';
							$state     = self::row_state( $page, $latest );
							$compare_l = admin_url( 'admin.php?page=lookit-page-watch&lpw_compare=' . (int) $page->id );
							?>
							<tr data-page="<?php echo esc_attr( (int) $page->id ); ?>">
								<th scope="row" class="check-column">
									<input type="checkbox" name="lpw_ids[]" value="<?php echo esc_attr( (int) $page->id ); ?>" aria-label="<?php echo esc_attr( $page->label ); ?>">
								</th>
								<td>
									<a class="lpw-name" href="<?php echo esc_url( $compare_l ); ?>"><?php echo esc_html( $page->label ); ?></a>
									<div class="lpw-url"><?php echo esc_html( $page->url ); ?></div>
									<?php if ( 'paused' === $page->status ) : ?>
										<span class="lpw-chip lpw-chip-paused"><?php esc_html_e( 'Paused', 'lookit-page-watch' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="lpw-shotcell">
									<?php if ( $base_url ) : ?>
										<a href="<?php echo esc_url( $base_url ); ?>" target="_blank" rel="noopener">
											<img src="<?php echo esc_url( $base_url ); ?>" alt="<?php esc_attr_e( 'Baseline screenshot', 'lookit-page-watch' ); ?>" class="lpw-thumb">
										</a>
										<div class="lpw-when"><?php echo esc_html( $page->baseline_at ? wp_date( 'M j, Y', strtotime( $page->baseline_at ) ) : '' ); ?></div>
									<?php else : ?>
										<div class="lpw-thumb lpw-thumb-empty"><?php esc_html_e( 'Not set', 'lookit-page-watch' ); ?></div>
									<?php endif; ?>
								</td>
								<td class="lpw-shotcell">
									<?php if ( $cur_url ) : ?>
										<a href="<?php echo esc_url( $cur_url ); ?>" target="_blank" rel="noopener">
											<img src="<?php echo esc_url( $cur_url ); ?>" alt="<?php esc_attr_e( 'Latest screenshot', 'lookit-page-watch' ); ?>" class="lpw-thumb">
										</a>
										<div class="lpw-when"><?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $latest->captured_at ) ) ); ?></div>
									<?php else : ?>
										<div class="lpw-thumb lpw-thumb-empty"><?php esc_html_e( 'No capture', 'lookit-page-watch' ); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<span class="lpw-chip lpw-chip-<?php echo esc_attr( $state['key'] ); ?>"><span class="lpw-dot"></span><?php echo esc_html( $state['label'] ); ?></span>
									<div class="lpw-when"><?php echo esc_html( $state['detail'] ); ?></div>
								</td>
								<td class="lpw-right lpw-actions">
									<a class="button button-small" href="<?php echo esc_url( $compare_l ); ?>"><?php esc_html_e( 'Compare', 'lookit-page-watch' ); ?></a>
									<button type="button" class="button button-small lpw-capture" data-page="<?php echo esc_attr( (int) $page->id ); ?>"><?php esc_html_e( 'Capture', 'lookit-page-watch' ); ?></button>
									<button type="button" class="button button-small lpw-baseline" data-page="<?php echo esc_attr( (int) $page->id ); ?>"><?php esc_html_e( 'New baseline', 'lookit-page-watch' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<div class="lpw-card-f">
						<select name="lpw_bulk_action" aria-label="<?php esc_attr_e( 'Bulk action', 'lookit-page-watch' ); ?>">
							<option value=""><?php esc_html_e( 'Bulk actions', 'lookit-page-watch' ); ?></option>
							<option value="baseline"><?php esc_html_e( 'Set latest capture as baseline', 'lookit-page-watch' ); ?></option>
							<option value="pause"><?php esc_html_e( 'Pause watching', 'lookit-page-watch' ); ?></option>
							<option value="resume"><?php esc_html_e( 'Resume watching', 'lookit-page-watch' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Remove from watchlist', 'lookit-page-watch' ); ?></option>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Apply', 'lookit-page-watch' ); ?></button>
					</div>
				</form>
			</div>

			<p class="lpw-foot"><?php esc_html_e( 'Lookit is a registered trademark of ZENOVA CORP.', 'lookit-page-watch' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Side by side comparison for one page.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	public static function render_compare( $page_id ) {
		$page = LPW_Store::get_page( $page_id );

		if ( ! $page ) {
			echo '<div class="wrap"><p>' . esc_html__( 'That page is not on the watchlist.', 'lookit-page-watch' ) . '</p></div>';
			return;
		}

		$captures = LPW_Store::get_captures( $page_id, 12 );
		$latest   = LPW_Store::latest_capture( $page_id );
		$base_url = LPW_Store::baseline_url( $page );
		$cur_url  = ( $latest && 'ok' === $latest->state ) ? LPW_Store::capture_url( $latest ) : '';
		$state    = self::row_state( $page, $latest );
		?>
		<div class="wrap lpw-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $page->label ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=lookit-page-watch' ) ); ?>"><?php esc_html_e( 'Back to watchlist', 'lookit-page-watch' ); ?></a>
			<p class="lpw-sub"><?php echo esc_html( $page->url ); ?></p>

			<?php self::notices(); ?>

			<div class="lpw-card">
				<div class="lpw-card-h">
					<span class="lpw-chip lpw-chip-<?php echo esc_attr( $state['key'] ); ?>"><span class="lpw-dot"></span><?php echo esc_html( $state['label'] ); ?></span>
					<span class="lpw-meta"><?php echo esc_html( $state['detail'] ); ?></span>
					<span class="lpw-spacer"></span>
					<button type="button" class="button lpw-capture" data-page="<?php echo esc_attr( (int) $page->id ); ?>"><?php esc_html_e( 'Capture now', 'lookit-page-watch' ); ?></button>
					<button type="button" class="button button-primary lpw-baseline" data-page="<?php echo esc_attr( (int) $page->id ); ?>"><?php esc_html_e( 'Set latest as new baseline', 'lookit-page-watch' ); ?></button>
				</div>
				<div class="lpw-compare">
					<div>
						<p class="lpw-shotlabel">
							<?php esc_html_e( 'Baseline', 'lookit-page-watch' ); ?>
							<?php if ( $page->baseline_at ) : ?>
								<span>&middot; <?php echo esc_html( wp_date( 'M j, Y', strtotime( $page->baseline_at ) ) ); ?></span>
							<?php endif; ?>
						</p>
						<?php if ( $base_url ) : ?>
							<a href="<?php echo esc_url( $base_url ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $base_url ); ?>" alt="<?php esc_attr_e( 'Baseline screenshot', 'lookit-page-watch' ); ?>" class="lpw-full"></a>
						<?php else : ?>
							<div class="lpw-thumb lpw-thumb-empty lpw-tall"><?php esc_html_e( 'No baseline saved yet', 'lookit-page-watch' ); ?></div>
						<?php endif; ?>
					</div>
					<div>
						<p class="lpw-shotlabel">
							<?php esc_html_e( 'Latest capture', 'lookit-page-watch' ); ?>
							<?php if ( $latest ) : ?>
								<span>&middot; <?php echo esc_html( wp_date( 'M j, g:i a', strtotime( $latest->captured_at ) ) ); ?></span>
							<?php endif; ?>
						</p>
						<?php if ( $cur_url ) : ?>
							<a href="<?php echo esc_url( $cur_url ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $cur_url ); ?>" alt="<?php esc_attr_e( 'Latest screenshot', 'lookit-page-watch' ); ?>" class="lpw-full"></a>
						<?php else : ?>
							<div class="lpw-thumb lpw-thumb-empty lpw-tall"><?php esc_html_e( 'No capture yet', 'lookit-page-watch' ); ?></div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="lpw-card">
				<div class="lpw-card-h"><h2><?php esc_html_e( 'Capture history', 'lookit-page-watch' ); ?></h2></div>
				<table class="lpw-table widefat">
					<thead><tr>
						<th><?php esc_html_e( 'Captured', 'lookit-page-watch' ); ?></th>
						<th><?php esc_html_e( 'Whole page', 'lookit-page-watch' ); ?></th>
						<th><?php esc_html_e( 'Worst area', 'lookit-page-watch' ); ?></th>
						<th><?php esc_html_e( 'Captured by', 'lookit-page-watch' ); ?></th>
						<th><?php esc_html_e( 'Result', 'lookit-page-watch' ); ?></th>
						<th class="lpw-right"><?php esc_html_e( 'Image', 'lookit-page-watch' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $captures as $capture ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $capture->captured_at ) ) ); ?></td>
							<td><?php echo null === $capture->diff_pct ? '&mdash;' : esc_html( number_format_i18n( (float) $capture->diff_pct, 2 ) . '%' ); ?></td>
							<td><?php echo null === $capture->region_pct ? '&mdash;' : esc_html( number_format_i18n( (float) $capture->region_pct, 0 ) . '%' ); ?></td>
							<td><?php echo $capture->provider ? esc_html( $capture->provider ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( 'ok' === $capture->state ? __( 'Captured', 'lookit-page-watch' ) : ( $capture->message ? $capture->message : __( 'Failed', 'lookit-page-watch' ) ) ); ?></td>
							<td class="lpw-right">
								<?php if ( 'ok' === $capture->state && LPW_Store::capture_url( $capture ) ) : ?>
									<a href="<?php echo esc_url( LPW_Store::capture_url( $capture ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'lookit-page-watch' ); ?></a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $captures ) ) : ?>
						<tr><td colspan="6" class="lpw-empty"><?php esc_html_e( 'No captures yet.', 'lookit-page-watch' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lookit-page-watch' ) );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$s     = lookit_page_watch_get_settings();
		$usage = size_format( LPW_Store::disk_usage(), 1 );
		$smtp  = defined( 'FLUENTMAIL' ) || is_plugin_active( 'fluent-smtp/fluent-smtp.php' );
		?>
		<div class="wrap lpw-wrap">
			<h1><?php esc_html_e( 'Schedule and email', 'lookit-page-watch' ); ?></h1>
			<p class="lpw-sub"><?php esc_html_e( 'Capture frequency and the digest are set separately, so you can capture hourly but only get one email a day.', 'lookit-page-watch' ); ?></p>

			<?php self::notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lpw_settings' ); ?>
				<input type="hidden" name="action" value="lpw_save_settings">

				<div class="lpw-card">
					<div class="lpw-card-h"><h2><?php esc_html_e( 'Capture service', 'lookit-page-watch' ); ?></h2></div>
					<div class="lpw-card-b">
						<div class="lpw-field">
							<label for="lpw-endpoint"><?php esc_html_e( 'n8n webhook URL', 'lookit-page-watch' ); ?></label>
							<div>
								<input type="url" id="lpw-endpoint" name="endpoint" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://n8n.lookitai.com/webhook/page-watch-capture" class="regular-text">
								<p class="description"><?php esc_html_e( 'WordPress cannot render a screenshot, so capture runs on the platform. This plugin sends a URL and stores the image that comes back.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-token"><?php esc_html_e( 'Shared token', 'lookit-page-watch' ); ?></label>
							<div>
								<input type="password" id="lpw-token" name="token" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $s['token'] ? esc_attr__( 'Leave blank to keep the saved token', 'lookit-page-watch' ) : ''; ?>">
								<p class="description">
									<?php if ( $s['token'] ) : ?>
										<?php esc_html_e( 'A token is already saved. Leave this blank to keep it, or paste a new value to replace it. Sent as the X-Lookit-Token header and must match the token in the n8n workflow.', 'lookit-page-watch' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Sent as the X-Lookit-Token header. Must match the token in the n8n workflow.', 'lookit-page-watch' ); ?>
									<?php endif; ?>
								</p>
							</div>
						</div>
						<div class="lpw-field">
							<label><?php esc_html_e( 'Connection', 'lookit-page-watch' ); ?></label>
							<div>
								<p class="description"><?php esc_html_e( 'Save first, then use Test the capture service at the bottom of this screen. It requests a small screenshot of this site to confirm the workflow answers.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="lpw-card">
					<div class="lpw-card-h"><h2><?php esc_html_e( 'Capture schedule', 'lookit-page-watch' ); ?></h2></div>
					<div class="lpw-card-b">
						<div class="lpw-field">
							<label for="lpw-interval"><?php esc_html_e( 'Capture every', 'lookit-page-watch' ); ?></label>
							<div>
								<select id="lpw-interval" name="interval">
									<?php
									$intervals = array(
										'lpw_1h'  => __( '1 hour', 'lookit-page-watch' ),
										'lpw_6h'  => __( '6 hours', 'lookit-page-watch' ),
										'lpw_12h' => __( '12 hours', 'lookit-page-watch' ),
										'lpw_24h' => __( '24 hours', 'lookit-page-watch' ),
									);
									foreach ( $intervals as $key => $label ) {
										printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['interval'], $key, false ), esc_html( $label ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Runs on WP-Cron, which fires on the first page load after the interval passes. On a quiet site a real server cron is more reliable.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-anchor"><?php esc_html_e( 'First run of the day', 'lookit-page-watch' ); ?></label>
							<div>
								<input type="time" id="lpw-anchor" name="anchor" value="<?php echo esc_attr( $s['anchor'] ); ?>">
								<p class="description">
									<?php
									printf(
										/* translators: %s: site time zone. */
										esc_html__( 'Site time zone (%s).', 'lookit-page-watch' ),
										esc_html( wp_timezone_string() )
									);
									?>
								</p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-width"><?php esc_html_e( 'Capture width', 'lookit-page-watch' ); ?></label>
							<div>
								<select id="lpw-width" name="width">
									<?php
									$widths = array(
										1440 => __( 'Desktop, 1440px', 'lookit-page-watch' ),
										1280 => __( 'Laptop, 1280px', 'lookit-page-watch' ),
										768  => __( 'Tablet, 768px', 'lookit-page-watch' ),
										390  => __( 'Mobile, 390px', 'lookit-page-watch' ),
									);
									foreach ( $widths as $key => $label ) {
										printf( '<option value="%d" %s>%s</option>', (int) $key, selected( (int) $s['width'], $key, false ), esc_html( $label ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'mShots caps every image at 1280 by 960 and always shows the top of the page, so a wider setting has no effect there. Browserless honours the width and captures the full page.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-threshold"><?php esc_html_e( 'Flag when the page changes by', 'lookit-page-watch' ); ?></label>
							<div>
								<input type="number" id="lpw-threshold" name="threshold" value="<?php echo esc_attr( $s['threshold'] ); ?>" step="0.1" min="0" max="100" class="small-text"> %
								<p class="description"><?php esc_html_e( 'Measured across the whole screenshot. Good for redesigns and layout shifts. Low numbers also catch rotating sliders and ad slots.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-region"><?php esc_html_e( 'Or when one area changes by', 'lookit-page-watch' ); ?></label>
							<div>
								<input type="number" id="lpw-region" name="region_threshold" value="<?php echo esc_attr( $s['region_threshold'] ); ?>" step="1" min="0" max="100" class="small-text"> %
								<p class="description"><?php esc_html_e( 'The screenshot is divided into a grid and each square scored on its own. Editing one paragraph barely moves the whole-page number but lights up its own square, so this is what catches small content edits. Lower it to catch more, raise it if a moving element keeps tripping it.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="lpw-card">
					<div class="lpw-card-h"><h2><?php esc_html_e( 'Email digest', 'lookit-page-watch' ); ?></h2></div>
					<div class="lpw-card-b">
						<div class="lpw-field">
							<label><?php esc_html_e( 'Send', 'lookit-page-watch' ); ?></label>
							<div>
								<?php
								$modes = array(
									'daily_always'  => __( 'Once a day, whether or not anything changed', 'lookit-page-watch' ),
									'daily_changes' => __( 'Once a day, only when something changed', 'lookit-page-watch' ),
									'every_run'     => __( 'After every capture run, scheduled or manual', 'lookit-page-watch' ),
								);
								foreach ( $modes as $key => $label ) :
									?>
									<label class="lpw-radio">
										<input type="radio" name="digest_mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['digest_mode'], $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'The last option ignores the send time and mails straight after each run finishes, including when you use Run capture now.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label><?php esc_html_e( 'Last digest', 'lookit-page-watch' ); ?></label>
							<div>
								<?php
								$last = LPW_Mailer::last_attempt();
								if ( ! $last ) {
									echo '<p style="margin:5px 0 0;">' . esc_html__( 'No digest has been attempted yet.', 'lookit-page-watch' ) . '</p>';
								} else {
									$when = wp_date( 'M j, Y g:i a', (int) $last['time'] );
									if ( ! empty( $last['sent'] ) ) {
										printf(
											'<p style="margin:5px 0 0;"><span class="lpw-chip lpw-chip-same"><span class="lpw-dot"></span>%s</span> %s</p>',
											esc_html__( 'Sent', 'lookit-page-watch' ),
											esc_html(
												sprintf(
													/* translators: 1: date and time, 2: number of recipients. */
													__( '%1$s, to %2$d recipients', 'lookit-page-watch' ),
													$when,
													(int) $last['recipients']
												)
											)
										);
									} else {
										printf(
											'<p style="margin:5px 0 0;"><span class="lpw-chip lpw-chip-failed"><span class="lpw-dot"></span>%s</span> %s</p>',
											esc_html__( 'Not sent', 'lookit-page-watch' ),
											esc_html( $when . ( ! empty( $last['note'] ) ? ' — ' . $last['note'] : '' ) )
										);
									}
								}
								?>
								<p class="description"><?php esc_html_e( 'Records what happened on the last attempt, so a missing email can be told apart from one that was never sent.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-digest-time"><?php esc_html_e( 'Send at', 'lookit-page-watch' ); ?></label>
							<div><input type="time" id="lpw-digest-time" name="digest_time" value="<?php echo esc_attr( $s['digest_time'] ); ?>"></div>
						</div>
						<div class="lpw-field">
							<label for="lpw-recipients"><?php esc_html_e( 'Send to', 'lookit-page-watch' ); ?></label>
							<div>
								<textarea id="lpw-recipients" name="recipients" rows="2" class="large-text"><?php echo esc_textarea( $s['recipients'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Comma separated. Falls back to the site admin email if empty.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label><?php esc_html_e( 'Sending', 'lookit-page-watch' ); ?></label>
							<div>
								<?php if ( $smtp ) : ?>
									<span class="lpw-chip lpw-chip-same"><span class="lpw-dot"></span><?php esc_html_e( 'FluentSMTP active', 'lookit-page-watch' ); ?></span>
								<?php else : ?>
									<span class="lpw-chip lpw-chip-failed"><span class="lpw-dot"></span><?php esc_html_e( 'FluentSMTP not detected', 'lookit-page-watch' ); ?></span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Page Watch hands the email to WordPress and FluentSMTP routes it, so there is nothing to configure here. Without FluentSMTP the message falls back to the server mail function.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="lpw-card">
					<div class="lpw-card-h"><h2><?php esc_html_e( 'Storage', 'lookit-page-watch' ); ?></h2></div>
					<div class="lpw-card-b">
						<div class="lpw-field">
							<label for="lpw-media"><?php esc_html_e( 'Where captures go', 'lookit-page-watch' ); ?></label>
							<div>
								<label class="lpw-radio">
									<input type="checkbox" id="lpw-media" name="use_media_library" value="1" <?php checked( (int) $s['use_media_library'], 1 ); ?>>
									<?php esc_html_e( 'Store captures in the Media Library', 'lookit-page-watch' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Registers each capture as a real attachment, so WordPress generates thumbnails and the images open from the Media screen. Turn this off to keep captures in a private uploads folder instead, which stays out of the way on a large watchlist.', 'lookit-page-watch' ); ?></p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-preserve"><?php esc_html_e( 'If the plugin is deleted', 'lookit-page-watch' ); ?></label>
							<div>
								<label class="lpw-radio">
									<input type="checkbox" id="lpw-preserve" name="preserve_on_uninstall" value="1" <?php checked( (int) $s['preserve_on_uninstall'], 1 ); ?>>
									<?php esc_html_e( 'Keep the watchlist, settings and baselines', 'lookit-page-watch' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Leave this on while testing. Deleting a plugin normally erases everything it stored, which means re-entering the webhook URL, token and watchlist every time a new version goes on. With this on, deleting the plugin leaves the data in place and reinstalling picks it back up. Turn it off when you genuinely want a clean removal.', 'lookit-page-watch' ); ?></p>
								<p class="description">
									<strong><?php esc_html_e( 'You do not need to delete the plugin to update it.', 'lookit-page-watch' ); ?></strong>
									<?php esc_html_e( 'Upload the new zip at Plugins, Add New, Upload Plugin and WordPress will offer to replace the installed copy, which keeps everything regardless of this setting.', 'lookit-page-watch' ); ?>
								</p>
							</div>
						</div>
						<div class="lpw-field">
							<label for="lpw-retain"><?php esc_html_e( 'Keep captures for', 'lookit-page-watch' ); ?></label>
							<div>
								<select id="lpw-retain" name="retain_days">
									<?php
									$retain = array(
										7  => __( '7 days', 'lookit-page-watch' ),
										30 => __( '30 days', 'lookit-page-watch' ),
										90 => __( '90 days', 'lookit-page-watch' ),
										0  => __( 'Forever', 'lookit-page-watch' ),
									);
									foreach ( $retain as $key => $label ) {
										printf( '<option value="%d" %s>%s</option>', (int) $key, selected( (int) $s['retain_days'], $key, false ), esc_html( $label ) );
									}
									?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Baselines are never deleted by cleanup.', 'lookit-page-watch' ); ?>
									<?php
									printf(
										/* translators: %s: formatted file size. */
										esc_html__( 'Currently using %s.', 'lookit-page-watch' ),
										esc_html( $usage )
									);
									?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'lookit-page-watch' ); ?></button>
				</p>
			</form>

			<div class="lpw-testrow">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lpw_settings' ); ?>
					<input type="hidden" name="action" value="lpw_test_endpoint">
					<button type="submit" class="button"><?php esc_html_e( 'Test the capture service', 'lookit-page-watch' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'lpw_settings' ); ?>
					<input type="hidden" name="action" value="lpw_send_test">
					<button type="submit" class="button"><?php esc_html_e( 'Send a test digest now', 'lookit-page-watch' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Work out the status chip for a row.
	 *
	 * @param object      $page   Page row.
	 * @param object|null $latest Latest capture row.
	 * @return array{key:string,label:string,detail:string}
	 */
	private static function row_state( $page, $latest ) {
		if ( ! $latest ) {
			return array(
				'key'    => 'none',
				'label'  => __( 'Not captured', 'lookit-page-watch' ),
				'detail' => __( 'Run a capture to set the baseline', 'lookit-page-watch' ),
			);
		}

		if ( 'failed' === $latest->state ) {
			return array(
				'key'    => 'failed',
				'label'  => __( 'Capture failed', 'lookit-page-watch' ),
				'detail' => $latest->message ? $latest->message : '',
			);
		}

		if ( null === $latest->diff_pct ) {
			return array(
				'key'    => 'same',
				'label'  => __( 'Baseline set', 'lookit-page-watch' ),
				'detail' => __( 'Comparison starts from the next capture', 'lookit-page-watch' ),
			);
		}

		$overall = (float) $latest->diff_pct;
		$region  = null === $latest->region_pct ? 0.0 : (float) $latest->region_pct;

		$overall_limit = (float) $page->threshold;
		$region_limit  = (float) lookit_page_watch_setting( 'region_threshold', 10 );

		$overall_text = number_format_i18n( $overall, 2 ) . '%';
		$region_text  = number_format_i18n( $region, 0 ) . '%';

		// A single edited block barely moves the whole-page number, so the
		// worst area is checked separately and can flag on its own.
		if ( $region >= $region_limit && $overall < $overall_limit ) {
			return array(
				'key'    => 'changed',
				'label'  => __( 'Changed in one area', 'lookit-page-watch' ),
				'detail' => sprintf(
					/* translators: 1: worst area percentage, 2: whole page percentage. */
					__( '%1$s of one area, %2$s of the page', 'lookit-page-watch' ),
					$region_text,
					$overall_text
				),
			);
		}

		if ( $overall >= $overall_limit ) {
			return array(
				'key'    => 'changed',
				'label'  => __( 'Changed', 'lookit-page-watch' ),
				'detail' => sprintf(
					/* translators: 1: whole page percentage, 2: worst area percentage. */
					__( '%1$s of the page, up to %2$s in one area', 'lookit-page-watch' ),
					$overall_text,
					$region_text
				),
			);
		}

		return array(
			'key'    => 'same',
			'label'  => __( 'No change', 'lookit-page-watch' ),
			'detail' => sprintf(
				/* translators: 1: whole page percentage, 2: worst area percentage. */
				__( '%1$s of the page, up to %2$s in one area', 'lookit-page-watch' ),
				$overall_text,
				$region_text
			),
		);
	}

	/**
	 * Render admin notices carried in the query string.
	 *
	 * @return void
	 */
	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['lpw_msg'] ) ) {
			$type = isset( $_GET['lpw_type'] ) && 'error' === $_GET['lpw_type'] ? 'error' : 'success';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['lpw_msg'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $message Text.
	 * @param string $type    success|error.
	 * @param string $page    Menu slug.
	 * @return void
	 */
	private static function redirect( $message, $type = 'success', $page = 'lookit-page-watch' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => $page,
					'lpw_msg'  => rawurlencode( $message ),
					'lpw_type' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Add a page from the form.
	 *
	 * @return void
	 */
	public static function handle_add_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lookit-page-watch' ) );
		}
		check_admin_referer( 'lpw_add_page' );

		$url   = isset( $_POST['lpw_url'] ) ? esc_url_raw( wp_unslash( $_POST['lpw_url'] ) ) : '';
		$label = isset( $_POST['lpw_label'] ) ? sanitize_text_field( wp_unslash( $_POST['lpw_label'] ) ) : '';

		$result = LPW_Store::add_page( $url, $label );

		if ( is_wp_error( $result ) ) {
			self::redirect( $result->get_error_message(), 'error' );
		}

		self::redirect( __( 'Page added. Run a capture to save its baseline.', 'lookit-page-watch' ) );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lookit-page-watch' ) );
		}
		check_admin_referer( 'lpw_settings' );

		$new = self::sanitize_settings( wp_unslash( $_POST ), lookit_page_watch_get_settings() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_settings.

		update_option( 'lookit_page_watch_settings', $new, false );
		LPW_Cron::reschedule();

		self::redirect( __( 'Settings saved and the schedule updated.', 'lookit-page-watch' ), 'success', 'lookit-page-watch-settings' );
	}

	/**
	 * Sanitize posted settings over the current stored values.
	 *
	 * @param array<string,mixed> $posted  Raw request data.
	 * @param array<string,mixed> $current Current settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $posted, $current ) {
		$submitted_token = isset( $posted['token'] ) ? sanitize_text_field( (string) $posted['token'] ) : '';

		$new = array(
			'endpoint'              => isset( $posted['endpoint'] ) ? esc_url_raw( (string) $posted['endpoint'] ) : '',
			'token'                 => '' !== $submitted_token ? $submitted_token : (string) $current['token'],
			'interval'              => isset( $posted['interval'] ) ? sanitize_key( (string) $posted['interval'] ) : 'lpw_24h',
			'anchor'                => isset( $posted['anchor'] ) ? sanitize_text_field( (string) $posted['anchor'] ) : '06:00',
			'width'                 => isset( $posted['width'] ) ? (int) $posted['width'] : 1440,
			'full_page'             => (int) $current['full_page'],
			'threshold'             => isset( $posted['threshold'] ) ? (float) $posted['threshold'] : 2.0,
			'region_threshold'      => isset( $posted['region_threshold'] ) ? (float) $posted['region_threshold'] : 10.0,
			'digest_mode'           => isset( $posted['digest_mode'] ) ? sanitize_key( (string) $posted['digest_mode'] ) : 'daily_changes',
			'digest_time'           => isset( $posted['digest_time'] ) ? sanitize_text_field( (string) $posted['digest_time'] ) : '08:00',
			'recipients'            => isset( $posted['recipients'] ) ? sanitize_textarea_field( (string) $posted['recipients'] ) : '',
			'retain_days'           => isset( $posted['retain_days'] ) ? (int) $posted['retain_days'] : 30,
			'use_media_library'     => isset( $posted['use_media_library'] ) ? 1 : 0,
			'preserve_on_uninstall' => isset( $posted['preserve_on_uninstall'] ) ? 1 : 0,
			'dir_key'               => $current['dir_key'],
		);

		$allowed_intervals = array( 'lpw_1h', 'lpw_6h', 'lpw_12h', 'lpw_24h' );
		if ( ! in_array( $new['interval'], $allowed_intervals, true ) ) {
			$new['interval'] = 'lpw_24h';
		}

		$allowed_modes = array( 'daily_always', 'daily_changes', 'every_run' );
		if ( ! in_array( $new['digest_mode'], $allowed_modes, true ) ) {
			$new['digest_mode'] = 'daily_changes';
		}

		$new['threshold']        = max( 0, min( 100, $new['threshold'] ) );
		$new['region_threshold'] = max( 0, min( 100, $new['region_threshold'] ) );

		return $new;
	}

	/**
	 * Send a test digest.
	 *
	 * @return void
	 */
	public static function handle_send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lookit-page-watch' ) );
		}
		check_admin_referer( 'lpw_settings' );
		$result = LPW_Mailer::send_digest( true );
		self::redirect( $result['message'], $result['sent'] ? 'success' : 'error', 'lookit-page-watch-settings' );
	}

	/**
	 * Test the capture endpoint.
	 *
	 * @return void
	 */
	public static function handle_test_endpoint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lookit-page-watch' ) );
		}
		check_admin_referer( 'lpw_settings' );
		$result = LPW_Capture::test_endpoint();
		self::redirect( $result['message'], $result['ok'] ? 'success' : 'error', 'lookit-page-watch-settings' );
	}

	/**
	 * Bulk actions from the watchlist.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lookit-page-watch' ) );
		}
		check_admin_referer( 'lpw_admin' );

		$action = isset( $_POST['lpw_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['lpw_bulk_action'] ) ) : '';
		$ids    = isset( $_POST['lpw_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['lpw_ids'] ) ) : array();

		if ( empty( $action ) || empty( $ids ) ) {
			self::redirect( __( 'Pick an action and at least one page.', 'lookit-page-watch' ), 'error' );
		}

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'baseline':
					LPW_Store::set_baseline( $id );
					break;
				case 'pause':
					LPW_Store::update_page( $id, array( 'status' => 'paused' ) );
					break;
				case 'resume':
					LPW_Store::update_page( $id, array( 'status' => 'active' ) );
					break;
				case 'delete':
					LPW_Store::delete_page( $id );
					break;
			}
		}

		self::redirect( __( 'Done.', 'lookit-page-watch' ) );
	}

	/**
	 * AJAX: capture one page.
	 *
	 * @return void
	 */
	public static function ajax_capture_page() {
		check_ajax_referer( 'lpw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'lookit-page-watch' ) ), 403 );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
		$result  = LPW_Capture::run( $page_id );

		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: promote the latest capture to baseline.
	 *
	 * @return void
	 */
	public static function ajax_set_baseline() {
		check_ajax_referer( 'lpw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'lookit-page-watch' ) ), 403 );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
		$result  = LPW_Store::set_baseline( $page_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Baseline replaced.', 'lookit-page-watch' ) ) );
	}

	/**
	 * AJAX: remove a page.
	 *
	 * @return void
	 */
	public static function ajax_delete_page() {
		check_ajax_referer( 'lpw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'lookit-page-watch' ) ), 403 );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
		LPW_Store::delete_page( $page_id );

		wp_send_json_success( array( 'message' => __( 'Page removed.', 'lookit-page-watch' ) ) );
	}

	/**
	 * AJAX: called once a manual capture run has finished.
	 *
	 * Captures triggered from the watchlist happen one page at a time over
	 * AJAX, so there is no single point where the run ends. Without this the
	 * "after every capture run" digest would only ever fire on the scheduled
	 * run, which makes the setting look broken to anyone testing it by hand.
	 *
	 * @return void
	 */
	public static function ajax_finish_run() {
		check_ajax_referer( 'lpw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'lookit-page-watch' ) ), 403 );
		}

		if ( 'every_run' !== lookit_page_watch_setting( 'digest_mode' ) ) {
			wp_send_json_success(
				array(
					'sent'    => false,
					'message' => __( 'Run finished. The digest is set to send on a schedule, not after each run.', 'lookit-page-watch' ),
				)
			);
		}

		$result = LPW_Mailer::send_digest();

		wp_send_json_success(
			array(
				'sent'    => $result['sent'],
				'message' => $result['message'],
			)
		);
	}
}
