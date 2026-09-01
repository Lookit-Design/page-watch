<?php
/**
 * Digest email for Lookit Page Watch.
 *
 * Mail is handed to wp_mail(). FluentSMTP hooks that and routes it through
 * whichever connection the site already uses, so there is nothing to configure
 * here. If FluentSMTP is switched off the message falls back to PHP mail.
 *
 * @package LookitPageWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the comparison digest.
 */
class LPW_Mailer {

	/**
	 * Send the digest to the configured recipients.
	 *
	 * @param bool $force Send even if nothing changed.
	 * @return array{sent:bool,message:string}
	 */
	public static function send_digest( $force = false ) {
		$recipients = self::recipients();
		if ( empty( $recipients ) ) {
			self::record_attempt( false, '', 0, __( 'No recipients are set.', 'lookit-page-watch' ) );
			return array(
				'sent'    => false,
				'message' => __( 'No recipients are set.', 'lookit-page-watch' ),
			);
		}

		$rows    = self::collect();
		$changed = 0;
		$failed  = 0;

		foreach ( $rows as $row ) {
			if ( 'changed' === $row['status'] ) {
				++$changed;
			}
			if ( 'failed' === $row['status'] ) {
				++$failed;
			}
		}

		$mode = (string) lookit_page_watch_setting( 'digest_mode', 'daily_changes' );
		if ( ! $force && 'daily_changes' === $mode && 0 === $changed && 0 === $failed ) {
			self::record_attempt( false, '', 0, __( 'Nothing changed, so no email was sent.', 'lookit-page-watch' ) );
			return array(
				'sent'    => false,
				'message' => __( 'Nothing changed, so no email was sent.', 'lookit-page-watch' ),
			);
		}

		$site = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $changed > 0 ) {
			$subject = sprintf(
				/* translators: 1: number of pages, 2: site host. */
				_n( 'Page Watch: %1$d page changed on %2$s', 'Page Watch: %1$d pages changed on %2$s', $changed, 'lookit-page-watch' ),
				$changed,
				$site
			);
		} elseif ( $failed > 0 ) {
			$subject = sprintf(
				/* translators: 1: number of pages, 2: site host. */
				_n( 'Page Watch: %1$d capture failed on %2$s', 'Page Watch: %1$d captures failed on %2$s', $failed, 'lookit-page-watch' ),
				$failed,
				$site
			);
		} else {
			/* translators: %s: site host. */
			$subject = sprintf( __( 'Page Watch: no changes on %s', 'lookit-page-watch' ), $site );
		}

		$html = self::build_html( $rows, $changed, $failed );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		$sent = wp_mail( $recipients, $subject, $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );

		if ( $sent ) {
			self::mark_notified( $rows );
		}

		self::record_attempt( (bool) $sent, $subject, count( $recipients ) );

		return array(
			'sent'    => (bool) $sent,
			'message' => $sent
				? __( 'Digest sent.', 'lookit-page-watch' )
				: __( 'WordPress could not hand the message to the mailer. Check the FluentSMTP log.', 'lookit-page-watch' ),
		);
	}

	/**
	 * Remember the outcome of the last digest attempt.
	 *
	 * Worth storing because "no email arrived" has several possible causes:
	 * nothing changed, no recipients, or the mailer refused it. Without this
	 * the three look identical from the settings screen.
	 *
	 * @param bool   $sent       Whether wp_mail accepted the message.
	 * @param string $subject    Subject line used.
	 * @param int    $recipients Number of recipients.
	 * @param string $note       Reason when nothing was sent.
	 * @return void
	 */
	private static function record_attempt( $sent, $subject = '', $recipients = 0, $note = '' ) {
		update_option(
			'lookit_page_watch_last_digest',
			array(
				'time'       => time(),
				'sent'       => (bool) $sent,
				'subject'    => $subject,
				'recipients' => (int) $recipients,
				'note'       => $note,
			),
			false
		);
	}

	/**
	 * The last digest attempt, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last_attempt() {
		$last = get_option( 'lookit_page_watch_last_digest' );
		return is_array( $last ) && ! empty( $last['time'] ) ? $last : null;
	}

	/**
	 * Force HTML email.
	 *
	 * @return string
	 */
	public static function content_type() {
		return 'text/html';
	}

	/**
	 * Recipient list.
	 *
	 * @return array<int,string>
	 */
	private static function recipients() {
		$raw  = (string) lookit_page_watch_setting( 'recipients', '' );
		$list = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', $raw ) ) );
		$out  = array();

		foreach ( $list as $email ) {
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}

		if ( empty( $out ) ) {
			$fallback = get_option( 'admin_email' );
			if ( is_email( $fallback ) ) {
				$out[] = $fallback;
			}
		}

		return $out;
	}

	/**
	 * Gather one row per watched page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect() {
		$rows = array();

		foreach ( LPW_Store::get_pages( true ) as $page ) {
			$latest = LPW_Store::latest_capture( (int) $page->id );

			if ( ! $latest ) {
				continue;
			}

			$region_limit = (float) lookit_page_watch_setting( 'region_threshold', 10 );
			$region       = null === $latest->region_pct ? 0.0 : (float) $latest->region_pct;

			if ( 'failed' === $latest->state ) {
				$status = 'failed';
			} elseif ( null === $latest->diff_pct ) {
				$status = 'same';
			} elseif ( (float) $latest->diff_pct >= (float) $page->threshold || $region >= $region_limit ) {
				$status = 'changed';
			} else {
				$status = 'same';
			}

			$rows[] = array(
				'page'       => $page,
				'capture'    => $latest,
				'status'     => $status,
				'baseline'   => LPW_Store::baseline_url( $page ),
				'current'    => LPW_Store::capture_url( $latest ),
				'diff'       => $latest->diff_pct,
				'region'     => $latest->region_pct,
			);
		}

		return $rows;
	}

	/**
	 * Flag rows as reported.
	 *
	 * @param array<int,array<string,mixed>> $rows Digest rows.
	 * @return void
	 */
	private static function mark_notified( $rows ) {
		global $wpdb;
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, marking rows as reported.
			$wpdb->update(
				LPW_Store::captures_table(),
				array( 'notified' => 1 ),
				array( 'id' => (int) $row['capture']->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Build the HTML body.
	 *
	 * Table based, inline styles, no external CSS. Every row states its status
	 * in text so the email still reads correctly with images blocked.
	 *
	 * @param array<int,array<string,mixed>> $rows    Digest rows.
	 * @param int                            $changed Changed count.
	 * @param int                            $failed  Failed count.
	 * @return string
	 */
	private static function build_html( $rows, $changed, $failed ) {
		$teal    = '#028673';
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$admin   = admin_url( 'admin.php?page=lookit-page-watch' );
		$when    = wp_date( 'g:i a, D M j' );
		$total   = count( $rows );

		$summary = sprintf(
			/* translators: 1: host, 2: time, 3: total pages, 4: changed count. */
			__( '%1$s, captured %2$s, %3$d pages watched, %4$d changed', 'lookit-page-watch' ),
			$site,
			$when,
			$total,
			$changed
		);

		$html  = '<div style="background:#f0f0f1;padding:24px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">';
		$html .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" align="center" style="width:640px;max-width:100%;background:#ffffff;border:1px solid #dcdcde;border-radius:4px;">';

		$html .= '<tr><td style="background:' . $teal . ';padding:20px 24px;color:#ffffff;">';
		$html .= '<div style="font-size:18px;font-weight:600;">' . esc_html__( 'Daily page check', 'lookit-page-watch' ) . '</div>';
		$html .= '<div style="font-size:12px;opacity:.85;margin-top:3px;">' . esc_html( $summary ) . '</div>';
		$html .= '</td></tr>';

		if ( empty( $rows ) ) {
			$html .= '<tr><td style="padding:24px;font-size:14px;">' . esc_html__( 'No pages are being watched yet. Add one in wp-admin to start the comparison.', 'lookit-page-watch' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			if ( 'same' === $row['status'] ) {
				continue;
			}
			$html .= self::row_html( $row, $teal );
		}

		$unchanged = array();
		foreach ( $rows as $row ) {
			if ( 'same' === $row['status'] ) {
				$unchanged[] = $row['page']->label;
			}
		}

		if ( ! empty( $unchanged ) ) {
			$html .= '<tr><td style="padding:16px 24px;background:#fbfbfc;border-top:1px solid #eaeaea;">';
			$html .= '<div style="font-size:13px;font-weight:600;color:#646970;">' . esc_html__( 'Unchanged', 'lookit-page-watch' ) . '</div>';
			$html .= '<div style="font-size:12px;color:#646970;margin-top:3px;">' . esc_html( implode( ', ', $unchanged ) ) . ' — ' . esc_html__( 'matched their baselines.', 'lookit-page-watch' ) . '</div>';
			$html .= '</td></tr>';
		}

		if ( $failed > 0 ) {
			$html .= '<tr><td style="padding:14px 24px;background:#fff8e5;border-top:1px solid #f3e0aa;font-size:12px;color:#8a6100;">';
			$html .= esc_html(
				sprintf(
					/* translators: %d: number of failed captures. */
					_n( '%d capture failed this run.', '%d captures failed this run.', $failed, 'lookit-page-watch' ),
					$failed
				)
			);
			$html .= '</td></tr>';
		}

		$html .= '<tr><td style="padding:18px 24px;background:#fbfbfc;border-top:1px solid #eaeaea;text-align:center;font-size:11px;color:#646970;">';
		$html .= '<a href="' . esc_url( $admin ) . '" style="color:' . $teal . ';">' . esc_html__( 'Open Page Watch in wp-admin', 'lookit-page-watch' ) . '</a>';
		$html .= '<br><br>' . esc_html__( 'Sent by Lookit Page Watch.', 'lookit-page-watch' ) . ' ' . esc_html__( 'Lookit is a registered trademark of ZENOVA CORP.', 'lookit-page-watch' );
		$html .= '</td></tr>';

		$html .= '</table></div>';

		return $html;
	}

	/**
	 * One comparison row.
	 *
	 * @param array<string,mixed> $row  Row data.
	 * @param string              $teal Accent colour.
	 * @return string
	 */
	private static function row_html( $row, $teal ) {
		$page  = $row['page'];
		$admin = admin_url( 'admin.php?page=lookit-page-watch&lpw_compare=' . (int) $page->id );

		if ( 'failed' === $row['status'] ) {
			$badge_bg   = '#fff8e5';
			$badge_fg   = '#8a6100';
			$badge_text = __( 'Capture failed', 'lookit-page-watch' );
			$note       = $row['capture']->message ? $row['capture']->message : __( 'The capture service did not return an image.', 'lookit-page-watch' );
		} else {
			$badge_bg   = '#fcf0f0';
			$badge_fg   = '#a02222';
			$badge_text = __( 'Changed', 'lookit-page-watch' );
			$note = sprintf(
				/* translators: 1: whole page percentage, 2: worst area percentage. */
				__( '%1$s of the page differs from the baseline, and up to %2$s within a single area.', 'lookit-page-watch' ),
				number_format_i18n( (float) $row['diff'], 1 ) . '%',
				number_format_i18n( null === $row['region'] ? 0 : (float) $row['region'], 0 ) . '%'
			);
		}

		$baseline_date = $page->baseline_at ? wp_date( 'M j, Y', strtotime( $page->baseline_at ) ) : __( 'not set', 'lookit-page-watch' );
		$current_date  = wp_date( 'g:i a', strtotime( $row['capture']->captured_at ) );

		$html  = '<tr><td style="padding:20px 24px;border-top:1px solid #eaeaea;">';
		$html .= '<div style="font-size:15px;font-weight:600;">' . esc_html( $page->label );
		$html .= ' <span style="font-size:11px;font-weight:600;background:' . $badge_bg . ';color:' . $badge_fg . ';padding:2px 8px;border-radius:10px;">' . esc_html( $badge_text ) . '</span></div>';
		$html .= '<div style="font-size:11px;color:#646970;margin:3px 0 12px;word-break:break-all;">' . esc_html( $page->url ) . ' &middot; ' . esc_html( $note ) . '</div>';

		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';

		$html .= '<td width="50%" valign="top" style="padding-right:6px;">';
		$html .= '<div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:#646970;margin-bottom:5px;">' . esc_html__( 'Baseline', 'lookit-page-watch' ) . ' &middot; ' . esc_html( $baseline_date ) . '</div>';
		if ( $row['baseline'] ) {
			$html .= '<img src="' . esc_url( $row['baseline'] ) . '" width="290" style="width:100%;max-width:290px;border:1px solid #c3c4c7;display:block;" alt="' . esc_attr__( 'Baseline screenshot', 'lookit-page-watch' ) . '">';
		} else {
			$html .= '<div style="border:1px dashed #c3c4c7;padding:24px;text-align:center;font-size:12px;color:#646970;">' . esc_html__( 'No baseline saved', 'lookit-page-watch' ) . '</div>';
		}
		$html .= '</td>';

		$html .= '<td width="50%" valign="top" style="padding-left:6px;">';
		$html .= '<div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:#646970;margin-bottom:5px;">' . esc_html__( 'Today', 'lookit-page-watch' ) . ' &middot; ' . esc_html( $current_date ) . '</div>';
		if ( $row['current'] ) {
			$html .= '<img src="' . esc_url( $row['current'] ) . '" width="290" style="width:100%;max-width:290px;border:1px solid #c3c4c7;display:block;" alt="' . esc_attr__( 'Latest screenshot', 'lookit-page-watch' ) . '">';
		} else {
			$html .= '<div style="border:1px dashed #c3c4c7;padding:24px;text-align:center;font-size:12px;color:#646970;">' . esc_html__( 'No capture', 'lookit-page-watch' ) . '</div>';
		}
		$html .= '</td>';

		$html .= '</tr></table>';
		$html .= '<div style="margin-top:12px;font-size:12px;"><a href="' . esc_url( $admin ) . '" style="color:' . $teal . ';">' . esc_html__( 'Compare full size in wp-admin', 'lookit-page-watch' ) . '</a></div>';
		$html .= '</td></tr>';

		return $html;
	}
}
