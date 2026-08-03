<?php
/**
 * Scheduled Response Overview PDF, emailed on a recurring basis.
 *
 * Renders a *separate*, dompdf-based version of the Response Overview report
 * rather than reusing the browser/Chart.js one (assets/admin-reports.js):
 * that one only ever runs in a live browser tab a person has open, and a
 * WP-Cron job runs with no browser at all, so it cannot be reused here. This
 * renderer shares the exact same data source (BRS_Reports::aggregate()) so
 * the numbers always match; only the visual presentation differs (plain
 * tables/CSS bars here, vs. Chart.js canvases there).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_Scheduled_Report {

	const OPTION       = 'brs_scheduled_report';
	const CRON_HOOK     = 'brs_send_scheduled_report';
	const CAPABILITY    = 'manage_options';

	const PALETTE = array( '#2a78d6', '#eb6834', '#769c28', '#c98900', '#83465b', '#5c6b7a', '#b13232', '#0a8b0a' );
	const NAVY    = '#16294f';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_scheduled_email' ) );
	}

	public static function register_cron_schedules( $schedules ) {
		$schedules['brs_weekly']  = array( 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'Weekly' );
		$schedules['brs_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Monthly (every 30 days)' );

		return $schedules;
	}

	public static function render_settings() {
		$settings   = self::settings();
		$next       = wp_next_scheduled( self::CRON_HOOK );
		$recipients = implode( "\n", $settings['recipients'] );
		?>
		<div class="wrap">
			<h1>Scheduled Report</h1>
			<p class="description">
				Emails a PDF version of the Response Overview on a recurring schedule. It's a plain,
				table-and-bars layout rather than the interactive dashboard's charts, generated on the
				server without a browser involved, so it works reliably on standard hosting.
			</p>

			<?php if ( isset( $_GET['brs_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['brs_test_sent'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Test email sent.</p></div>
			<?php elseif ( isset( $_GET['brs_test_failed'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>Test email could not be sent - check your site's outgoing mail configuration.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brs_save_scheduled_report">
				<?php wp_nonce_field( 'brs_save_scheduled_report' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">Enabled</th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> Send this report automatically</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brs-frequency">Frequency</label></th>
						<td>
							<select name="frequency" id="brs-frequency">
								<option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>>Daily</option>
								<option value="brs_weekly" <?php selected( $settings['frequency'], 'brs_weekly' ); ?>>Weekly</option>
								<option value="brs_monthly" <?php selected( $settings['frequency'], 'brs_monthly' ); ?>>Monthly</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brs-recipients">Recipients</label></th>
						<td>
							<textarea name="recipients" id="brs-recipients" rows="4" style="width:100%;max-width:400px;" placeholder="one email per line"><?php echo esc_textarea( $recipients ); ?></textarea>
							<p class="description">One email address per line. Invalid addresses are ignored when saving.</p>
						</td>
					</tr>
					<?php if ( $next ) : ?>
					<tr>
						<th scope="row">Next scheduled send</th>
						<td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'j F Y, g:i a' ) ); ?></td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>

			<h2>Send a test email now</h2>
			<p class="description">Sends today's report immediately to the recipients above (saved settings, not what's currently typed in the form) - useful to confirm formatting and that mail delivery actually works before relying on the schedule.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brs_send_test_report">
				<?php wp_nonce_field( 'brs_send_test_report' ); ?>
				<?php submit_button( 'Send test email now', 'secondary', 'submit', false, empty( $settings['recipients'] ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
				<?php if ( empty( $settings['recipients'] ) ) : ?>
					<p class="description">Add and save at least one recipient first.</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( 'brs_save_scheduled_report' ) ) {
			wp_die( 'Not allowed.' );
		}

		$enabled    = ! empty( $_POST['enabled'] );
		$frequency  = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : 'brs_weekly';
		$raw        = isset( $_POST['recipients'] ) ? wp_unslash( $_POST['recipients'] ) : '';
		$recipients = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) );
		$recipients = array_map( 'sanitize_email', $recipients );

		self::save_settings( $enabled, $frequency, $recipients );

		wp_safe_redirect( add_query_arg( array( 'page' => 'brs-scheduled-report', 'brs_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_test_send() {
		if ( ! current_user_can( self::CAPABILITY ) || ! check_admin_referer( 'brs_send_test_report' ) ) {
			wp_die( 'Not allowed.' );
		}

		$settings = self::settings();
		$result   = ! empty( $settings['recipients'] ) ? self::send_to( $settings['recipients'] ) : false;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                                      => 'brs-scheduled-report',
					$result ? 'brs_test_sent' : 'brs_test_failed' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function settings() {
		$defaults = array(
			'enabled'    => false,
			'frequency'  => 'weekly', // 'daily' | 'brs_weekly' | 'brs_monthly'
			'recipients' => array(),
		);

		$saved = get_option( self::OPTION, array() );

		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Applies new settings and (re)schedules or clears the cron event to
	 * match - the single place that keeps get_option() and wp_next_scheduled()
	 * in sync, so nothing else needs to reason about that relationship.
	 */
	public static function save_settings( $enabled, $frequency, array $recipients ) {
		update_option(
			self::OPTION,
			array(
				'enabled'    => (bool) $enabled,
				'frequency'  => in_array( $frequency, array( 'daily', 'brs_weekly', 'brs_monthly' ), true ) ? $frequency : 'brs_weekly',
				'recipients' => array_values( array_filter( $recipients, 'is_email' ) ),
			)
		);

		self::reschedule();
	}

	private static function reschedule() {
		$existing = wp_next_scheduled( self::CRON_HOOK );

		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		$settings = self::settings();

		if ( $settings['enabled'] && ! empty( $settings['recipients'] ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $settings['frequency'], self::CRON_HOOK );
		}
	}

	/** Cron callback - also reusable directly for the admin "send test email now" button. */
	public static function send_scheduled_email() {
		$settings = self::settings();

		if ( empty( $settings['recipients'] ) ) {
			return false;
		}

		return self::send_to( $settings['recipients'] );
	}

	public static function send_to( array $recipients ) {
		$pdf_bytes = self::generate_pdf();

		$tmp_file = wp_tempnam( 'brs-response-overview.pdf' );
		file_put_contents( $tmp_file, $pdf_bytes );

		$site_name = get_bloginfo( 'name' );
		$today     = current_time( 'Y-m-d' );

		$sent = wp_mail(
			$recipients,
			sprintf( '%s - Response Overview (%s)', $site_name, $today ),
			"Attached is the latest Response Overview report for the Operational Resilience Survey.\n\nThis is an automated message.",
			array(),
			array( $tmp_file )
		);

		wp_delete_file( $tmp_file );

		return $sent;
	}

	/**
	 * TCPDF, not dompdf - dompdf 3.x (the only branch with its known security
	 * advisories patched) requires PHP 8.1+, which is newer than this site
	 * runs; every dompdf 2.x release remains flagged by Composer's security
	 * audit and was never patched. TCPDF has no such advisories and supports
	 * PHP back to 7.x, at the cost of a much simpler HTML/CSS subset - most
	 * relevantly, no CSS position:absolute support, which is why the stacked
	 * likert bars below use a single-row percentage-width table instead.
	 */
	public static function generate_pdf() {
		require_once BRS_PATH . 'vendor/autoload.php';

		$data = BRS_Reports::aggregate();
		$html = self::render_html( $data );

		$pdf = new \TCPDF( 'P', 'pt', 'A4', true, 'UTF-8', false );
		$pdf->SetCreator( 'Bounce Resilience Survey' );
		$pdf->SetPrintHeader( false );
		$pdf->SetPrintFooter( false );
		$pdf->SetMargins( 28, 28, 28 );
		$pdf->SetAutoPageBreak( true, 28 );
		$pdf->AddPage();
		$pdf->writeHTML( $html, true, false, true, false, '' );

		return $pdf->Output( '', 'S' );
	}

	private static function color_at( $i ) {
		return self::PALETTE[ $i % count( self::PALETTE ) ];
	}

	/** A single labelled horizontal bar row, width proportional to $count/$max. */
	private static function bar_row( $label, $count, $max, $color ) {
		$pct = $max > 0 ? round( ( $count / $max ) * 100, 1 ) : 0;

		return '<tr>'
			. '<td class="bar-label">' . esc_html( $label ) . '</td>'
			. '<td class="bar-cell"><div class="bar-track"><div class="bar-fill" style="width:' . $pct . '%;background-color:' . esc_attr( $color ) . ';"></div></div></td>'
			. '<td class="bar-count">' . esc_html( number_format_i18n( $count ) ) . '</td>'
			. '</tr>';
	}

	/**
	 * Deliberately text-only, no embedded logo. dompdf decodes/rasterises
	 * embedded images in pure PHP with no hardware acceleration - measured
	 * at 70-90s just to embed the branding logo (a ~1MB source PNG) here,
	 * regardless of how much report content there was. This report runs
	 * unattended on a cron job on shared hosting with tight execution-time
	 * limits, so that cost isn't worth it for a header image on an internal
	 * analytics email (vs. the participant-facing dashboard, where the logo
	 * renders instantly in a browser and belongs there).
	 */
	private static function render_html( $data ) {
		ob_start();
		?>
		<html>
		<head>
		<style>
			body { font-family: Helvetica, sans-serif; font-size: 11px; color: #1d2327; }
			h1 { color: #fff; margin: 0; font-size: 20px; }
			h2 { color: <?php echo self::NAVY; ?>; font-size: 15px; margin: 24px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
			h3 { font-size: 12px; margin: 14px 0 4px; }
			.header { background-color: <?php echo self::NAVY; ?>; padding: 14px 18px; }
			.meta { color: #666; font-size: 10px; margin: 0 0 16px; }
			.cards { width: 100%; margin-bottom: 10px; }
			.cards td { width: 33%; padding: 8px 12px; }
			.card { border: 1px solid #ccc; padding: 8px 12px; }
			.card-value { font-size: 18px; font-weight: bold; color: <?php echo self::NAVY; ?>; display: block; }
			.card-label { font-size: 9px; color: #666; }
			table.bars { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
			table.bars td { padding: 3px 4px; vertical-align: middle; }
			.bar-label { width: 45%; font-size: 10px; }
			.bar-cell { width: 40%; }
			.bar-count { width: 15%; text-align: right; font-size: 10px; }
			.bar-track { background-color: #eeeeee; height: 10px; width: 100%; }
			.bar-fill { height: 10px; }
			.answered { color: #666; font-size: 9px; margin: 0 0 4px; }
			.question-block { margin-bottom: 12px; }
			table.stacked { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
			table.stacked td { height: 14px; }
			.legend { font-size: 8px; color: #555; margin-bottom: 10px; }
			.legend span { margin-right: 10px; }
			.legend-swatch { font-size: 6px; }
			ul.responses { margin: 0 0 8px 16px; padding: 0; }
			ul.responses li { margin-bottom: 4px; }
		</style>
		</head>
		<body>

		<div class="header">
			<h1>Response Overview</h1>
		</div>

		<p class="meta">
			Generated <?php echo esc_html( current_time( 'j F Y, g:i a' ) ); ?> &middot;
			<?php echo esc_html( number_format_i18n( $data['totalResponses'] ) ); ?> submission(s) &middot;
			Identifying fields (name, email, phone, school name) are not included in this report.
		</p>

		<table class="cards">
			<tr>
				<td><div class="card"><span class="card-value"><?php echo esc_html( number_format_i18n( $data['totalResponses'] ) ); ?></span><span class="card-label">TOTAL RESPONSES</span></div></td>
				<td><div class="card"><span class="card-value"><?php echo esc_html( null !== $data['avgScore'] ? number_format_i18n( $data['avgScore'], 1 ) : '-' ); ?></span><span class="card-label">AVERAGE SCORE</span></div></td>
				<td><div class="card"><span class="card-value"><?php echo esc_html( number_format_i18n( $data['scoredCount'] ) ); ?></span><span class="card-label">SCORED RESPONSES</span></div></td>
			</tr>
		</table>

		<h2>Maturity level distribution</h2>
		<table class="bars">
			<?php
			$level_max = max( 1, max( array_column( $data['levelDistribution'], 'count' ) ) );
			foreach ( $data['levelDistribution'] as $level ) {
				echo self::bar_row( 'Level ' . $level['level'] . ' - ' . $level['label'], $level['count'], $level_max, self::NAVY ); // phpcs:ignore
			}
			?>
		</table>

		<h2>Average score by domain</h2>
		<table class="bars">
			<?php
			foreach ( $data['sectionScores'] as $i => $section ) {
				echo self::bar_row( $section['name'], $section['avgPct'], 100, self::color_at( $i ) ); // phpcs:ignore
			}
			?>
		</table>

		<?php foreach ( $data['sections'] as $section ) : ?>
			<h2><?php echo esc_html( 'Section ' . $section['number'] . ' - ' . $section['title'] ); ?></h2>

			<?php foreach ( $section['questions'] as $q ) : ?>
				<div class="question-block">
					<h3><?php echo esc_html( $q['number'] . '. ' . $q['text'] ); ?></h3>
					<p class="answered"><?php echo esc_html( $q['answered'] . ' of ' . $data['totalResponses'] . ' responded' ); ?></p>

					<?php if ( 'choice' === $q['kind'] ) : ?>
						<?php
						$counts = $q['counts'];
						if ( $q['notAnswered'] > 0 ) {
							$counts['Not answered'] = $q['notAnswered'];
						}
						$max = max( 1, max( $counts ) );
						?>
						<table class="bars">
							<?php
							$i = 0;
							foreach ( $counts as $label => $count ) {
								$color = ( 'Not answered' === $label ) ? '#c3c4c7' : self::color_at( $i );
								echo self::bar_row( $label, $count, $max, $color ); // phpcs:ignore
								++$i;
							}
							?>
						</table>

					<?php elseif ( 'likert' === $q['kind'] ) : ?>
						<div class="legend">
							<?php foreach ( $q['columns'] as $i => $col ) : ?>
								<span><span class="legend-swatch" style="background-color:<?php echo esc_attr( self::color_at( $i ) ); ?>;">&nbsp;&nbsp;</span> <?php echo esc_html( $col ); ?></span>
							<?php endforeach; ?>
						</div>
						<?php foreach ( $q['rows'] as $row ) : ?>
							<p style="margin:4px 0 1px;font-size:10px;"><?php echo esc_html( $row['label'] ); ?></p>
							<?php
							// A single-row table with percentage-width cells, not
							// position:absolute divs - TCPDF's HTML support has no
							// CSS positioning, absolutely-positioned segments just
							// collapse into normal block flow (stacking vertically
							// instead of overlaying horizontally). A table's cell
							// widths are TCPDF-native and tolerate the same rounding
							// slop harmlessly (browsers/TCPDF both just fit cells to
							// the row rather than wrapping or overflowing).
							$segments = array();
							foreach ( $q['columns'] as $i => $col ) {
								$n   = isset( $row['counts'][ $col ] ) ? $row['counts'][ $col ] : 0;
								$pct = $row['answered'] > 0 ? round( ( $n / $row['answered'] ) * 100, 2 ) : 0;
								if ( $pct > 0 ) {
									$segments[] = array( 'pct' => $pct, 'color' => self::color_at( $i ) );
								}
							}
							if ( empty( $segments ) ) {
								$segments[] = array( 'pct' => 100, 'color' => '#eeeeee' );
							}
							?>
							<table class="stacked" cellspacing="0" cellpadding="0">
								<tr>
									<?php foreach ( $segments as $seg ) : ?>
										<td width="<?php echo esc_attr( $seg['pct'] ); ?>%" style="background-color:<?php echo esc_attr( $seg['color'] ); ?>;">&nbsp;</td>
									<?php endforeach; ?>
								</tr>
							</table>
						<?php endforeach; ?>

					<?php elseif ( 'rating' === $q['kind'] ) : ?>
						<p class="answered">Average: <strong><?php echo esc_html( $q['answered'] > 0 ? number_format_i18n( $q['sum'] / $q['answered'], 1 ) : '-' ); ?></strong> / <?php echo esc_html( $q['stars'] ); ?></p>
						<table class="bars">
							<?php
							$max = max( 1, max( $q['counts'] ) );
							for ( $star = 1; $star <= $q['stars']; $star++ ) {
								$n = isset( $q['counts'][ $star ] ) ? $q['counts'][ $star ] : 0;
								echo self::bar_row( $star . ( 1 === $star ? ' star' : ' stars' ), $n, $max, self::NAVY ); // phpcs:ignore
							}
							?>
						</table>

					<?php elseif ( 'text' === $q['kind'] ) : ?>
						<?php if ( empty( $q['responses'] ) ) : ?>
							<p class="answered">No responses yet.</p>
						<?php else : ?>
							<ul class="responses">
								<?php foreach ( $q['responses'] as $response ) : ?>
									<li><?php echo esc_html( $response ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>

		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}
