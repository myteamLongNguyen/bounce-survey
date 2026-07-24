<?php
/**
 * Admin screens: submissions list, single-submission view, CSV export,
 * and the question wording editor.
 *
 * With 58 questions a column-per-question table is unreadable, so the list
 * shows identifying columns only and links through to a detail view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_Admin {

	const CAPABILITY = 'manage_options';

	public static function register_menu() {
		add_menu_page(
			'Bounce Survey',
			'Bounce Survey',
			self::CAPABILITY,
			'brs-submissions',
			array( __CLASS__, 'render_submissions' ),
			'dashicons-clipboard',
			30
		);

		add_submenu_page( 'brs-submissions', 'Submissions', 'Submissions', self::CAPABILITY, 'brs-submissions', array( __CLASS__, 'render_submissions' ) );
		add_submenu_page( 'brs-submissions', 'Question wording', 'Question wording', self::CAPABILITY, 'brs-wording', array( __CLASS__, 'render_wording_editor' ) );
		add_submenu_page( 'brs-submissions', 'OneDrive Sync', 'OneDrive Sync', self::CAPABILITY, 'brs-onedrive', array( __CLASS__, 'render_onedrive_settings' ) );
	}

	public static function render_submissions() {
		if ( isset( $_GET['ref'] ) ) {
			self::render_single( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
			return;
		}

		$per_page = 25;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total    = BRS_DB::count();
		$rows     = BRS_DB::get_page( $per_page, ( $paged - 1 ) * $per_page );

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=brs_export_csv' ), 'brs_export_csv' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Submissions</h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Download CSV</a>

			<p><?php echo esc_html( number_format_i18n( $total ) ); ?> submission(s) recorded.</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Reference</th>
						<th>Submitted</th>
						<th>Source</th>
						<th>School type</th>
						<th>Role</th>
						<th>Country</th>
						<th>Email</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7">No submissions yet. Once the survey page is live, responses will appear here.</td></tr>
					<?php endif; ?>

					<?php
					foreach ( $rows as $row ) :
						$a   = json_decode( $row->answers, true );
						$url = add_query_arg( array( 'page' => 'brs-submissions', 'ref' => $row->reference ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $url ); ?>"><code><?php echo esc_html( $row->reference ); ?></code></a></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->source ); ?></td>
							<td><?php echo esc_html( isset( $a['q1'] ) ? $a['q1'] : '-' ); ?></td>
							<td><?php echo esc_html( isset( $a['q2'] ) ? $a['q2'] : '-' ); ?></td>
							<td><?php echo esc_html( isset( $a['q7'] ) ? $a['q7'] : '-' ); ?></td>
							<td><?php echo esc_html( $row->email ? $row->email : '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per_page );

			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private static function render_single( $reference ) {
		$row = BRS_DB::get_by_reference( $reference );
		$back = add_query_arg( array( 'page' => 'brs-submissions' ), admin_url( 'admin.php' ) );

		if ( ! $row ) {
			echo '<div class="wrap"><h1>Submission not found</h1><p><a href="' . esc_url( $back ) . '">Back to submissions</a></p></div>';
			return;
		}

		$a = json_decode( $row->answers, true );
		?>
		<div class="wrap">
			<h1>Submission <code><?php echo esc_html( $row->reference ); ?></code></h1>
			<p>
				<a href="<?php echo esc_url( $back ); ?>">&larr; Back to submissions</a> &middot;
				Submitted <?php echo esc_html( $row->created_at ); ?>
				<?php if ( $row->email ) : ?>
					&middot; <?php echo esc_html( $row->email ); ?>
				<?php endif; ?>
			</p>

			<?php foreach ( BRS_Config::sections() as $section ) : ?>
				<h2><?php echo esc_html( 'Section ' . $section['number'] . ' - ' . $section['title'] ); ?></h2>
				<table class="widefat striped" style="margin-bottom:24px;">
					<tbody>
					<?php
					foreach ( $section['questions'] as $q ) :
						$value = isset( $a[ $q['id'] ] ) ? $a[ $q['id'] ] : null;
						?>
						<tr>
							<th style="width:45%;vertical-align:top;">
								<?php echo esc_html( $q['number'] . '. ' . $q['text'] ); ?>
							</th>
							<td>
								<?php echo wp_kses_post( self::format_answer( $q, $value ) ); ?>
								<?php if ( ! empty( $a[ $q['id'] . '_other' ] ) ) : ?>
									<p style="margin:6px 0 0;color:#646970;">
										Other: <?php echo esc_html( $a[ $q['id'] . '_other' ] ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function format_answer( $q, $value ) {
		if ( null === $value || '' === $value || ( is_array( $value ) && ! count( $value ) ) ) {
			return '<em style="color:#8c8f94;">Not answered</em>';
		}

		if ( 'likert' === $q['type'] ) {
			$out = '<ul style="margin:0;">';

			foreach ( $value as $row_index => $column ) {
				if ( isset( $q['rows'][ (int) $row_index ] ) ) {
					$out .= '<li>' . esc_html( $q['rows'][ (int) $row_index ] ) . ': <strong>' . esc_html( $column ) . '</strong></li>';
				}
			}

			return $out . '</ul>';
		}

		if ( is_array( $value ) ) {
			return '<ul style="margin:0;"><li>' . implode( '</li><li>', array_map( 'esc_html', $value ) ) . '</li></ul>';
		}

		if ( 'rating' === $q['type'] ) {
			return esc_html( $value . ' / ' . ( isset( $q['stars'] ) ? $q['stars'] : 5 ) );
		}

		return nl2br( esc_html( $value ) );
	}

	/**
	 * Lets non-technical staff fix wording - question text, help text,
	 * option/row/column labels, section titles - without touching code.
	 *
	 * Deliberately does NOT let anyone add, remove or reorder questions,
	 * sections, or options: answers are stored by question id and by option
	 * position, so changing the *shape* of a question after responses exist
	 * would make old and new responses impossible to line up. Relabelling is
	 * safe; restructuring is a developer job.
	 */
	public static function render_wording_editor() {
		$doc = BRS_Config::survey_for_display();
		?>
		<div class="wrap">
			<h1>Question wording</h1>

			<?php if ( isset( $_GET['brs_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Wording saved. The live survey now shows these changes.</p></div>
			<?php endif; ?>

			<div class="notice notice-warning">
				<p>
					<strong>Safe to edit:</strong> question wording, help text, answer/scale labels and
					section titles - fix typos or rephrase any time, including after responses start
					coming in.
				</p>
				<p>
					<strong>Do not</strong> try to add, remove or reorder questions, sections or answer
					options here - there is nowhere to do that in this screen on purpose. That kind of
					change affects how responses are stored and compared, and needs a developer.
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brs_save_wording">
				<?php wp_nonce_field( 'brs_save_wording' ); ?>

				<?php foreach ( $doc['sections'] as $section ) : ?>
					<h2 style="margin-top:2rem;">Section <?php echo esc_html( $section['number'] ); ?></h2>
					<p style="max-width:860px;">
						<label style="display:block;font-weight:600;margin-bottom:4px;">Section title</label>
						<input
							type="text"
							name="sections[<?php echo esc_attr( $section['number'] ); ?>][title]"
							value="<?php echo esc_attr( $section['title'] ); ?>"
							style="width:100%;max-width:860px;"
						>
					</p>

					<?php foreach ( $section['questions'] as $q ) : ?>
						<div style="background:#fff;border:1px solid #c3c4c7;padding:14px;margin:12px 0;max-width:860px;">
							<p style="margin:0 0 10px;color:#646970;font-size:12px;">
								Question <?php echo esc_html( $q['number'] ); ?> &middot;
								ID <code><?php echo esc_html( $q['id'] ); ?></code> &middot;
								<?php echo esc_html( $q['type'] ); ?>
							</p>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Question text</label>
							<textarea
								name="questions[<?php echo esc_attr( $q['id'] ); ?>][text]"
								rows="2"
								style="width:100%;margin-bottom:10px;"
							><?php echo esc_textarea( $q['text'] ); ?></textarea>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Help text (shown under the question, optional)</label>
							<textarea
								name="questions[<?php echo esc_attr( $q['id'] ); ?>][subtext]"
								rows="2"
								style="width:100%;"
								placeholder="Optional - leave blank to show no help text."
							><?php echo esc_textarea( $q['subtext'] ); ?></textarea>

							<?php if ( ! empty( $q['options'] ) ) : ?>
								<p style="margin:10px 0 4px;font-weight:600;">Answer options</p>
								<?php foreach ( $q['options'] as $i => $opt ) : ?>
									<input
										type="text"
										name="questions[<?php echo esc_attr( $q['id'] ); ?>][options][<?php echo esc_attr( $i ); ?>]"
										value="<?php echo esc_attr( $opt ); ?>"
										style="width:100%;margin-bottom:4px;"
									>
								<?php endforeach; ?>
								<?php if ( ! empty( $q['allowOther'] ) ) : ?>
									<p style="margin:4px 0 0;color:#646970;font-size:12px;">Plus a fixed &ldquo;Other&rdquo; option with free text - not editable here.</p>
								<?php endif; ?>
							<?php endif; ?>

							<?php if ( ! empty( $q['rows'] ) ) : ?>
								<p style="margin:10px 0 4px;font-weight:600;">Rows</p>
								<?php foreach ( $q['rows'] as $i => $row ) : ?>
									<input
										type="text"
										name="questions[<?php echo esc_attr( $q['id'] ); ?>][rows][<?php echo esc_attr( $i ); ?>]"
										value="<?php echo esc_attr( $row ); ?>"
										style="width:100%;margin-bottom:4px;"
									>
								<?php endforeach; ?>
							<?php endif; ?>

							<?php if ( ! empty( $q['columns'] ) ) : ?>
								<p style="margin:10px 0 4px;font-weight:600;">Scale columns</p>
								<div style="display:flex;gap:6px;flex-wrap:wrap;">
									<?php foreach ( $q['columns'] as $i => $col ) : ?>
										<input
											type="text"
											name="questions[<?php echo esc_attr( $q['id'] ); ?>][columns][<?php echo esc_attr( $i ); ?>]"
											value="<?php echo esc_attr( $col ); ?>"
											style="flex:1;min-width:120px;"
										>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<?php if ( isset( $q['lowLabel'] ) || isset( $q['highLabel'] ) ) : ?>
								<div style="display:flex;gap:10px;margin-top:10px;">
									<div style="flex:1;">
										<label style="display:block;font-weight:600;margin-bottom:4px;">Low-end label</label>
										<input
											type="text"
											name="questions[<?php echo esc_attr( $q['id'] ); ?>][lowLabel]"
											value="<?php echo esc_attr( isset( $q['lowLabel'] ) ? $q['lowLabel'] : '' ); ?>"
											style="width:100%;"
										>
									</div>
									<div style="flex:1;">
										<label style="display:block;font-weight:600;margin-bottom:4px;">High-end label</label>
										<input
											type="text"
											name="questions[<?php echo esc_attr( $q['id'] ); ?>][highLabel]"
											value="<?php echo esc_attr( isset( $q['highLabel'] ) ? $q['highLabel'] : '' ); ?>"
											style="width:100%;"
										>
									</div>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>

				<?php submit_button( 'Save wording' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_wording() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		check_admin_referer( 'brs_save_wording' );

		$posted_sections  = isset( $_POST['sections'] ) ? (array) wp_unslash( $_POST['sections'] ) : array();
		$posted_questions = isset( $_POST['questions'] ) ? (array) wp_unslash( $_POST['questions'] ) : array();
		$valid_ids        = BRS_Config::question_ids();

		$clean = array(
			'sections'  => array(),
			'questions' => array(),
		);

		foreach ( BRS_Config::raw_doc()['sections'] as $section ) {
			$number = $section['number'];

			if ( isset( $posted_sections[ $number ]['title'] ) ) {
				$title = sanitize_text_field( $posted_sections[ $number ]['title'] );

				if ( '' !== trim( $title ) ) {
					$clean['sections'][ $number ] = array( 'title' => $title );
				}
			}
		}

		foreach ( $posted_questions as $id => $fields ) {
			if ( ! in_array( $id, $valid_ids, true ) || ! is_array( $fields ) ) {
				continue;
			}

			$raw_q = BRS_Config::raw_question( $id );
			$entry = array();

			if ( isset( $fields['text'] ) && '' !== trim( $fields['text'] ) ) {
				$entry['text'] = sanitize_text_field( $fields['text'] );
			}

			if ( isset( $fields['subtext'] ) ) {
				$entry['subtext'] = sanitize_textarea_field( $fields['subtext'] );
			}

			foreach ( array( 'options', 'rows', 'columns' ) as $listKey ) {
				if ( empty( $fields[ $listKey ] ) || ! is_array( $fields[ $listKey ] ) || empty( $raw_q[ $listKey ] ) ) {
					continue;
				}

				// Keep only as many entries as the original has, in original
				// order - a tampered or malformed submission cannot grow the list.
				$list = array();

				foreach ( $raw_q[ $listKey ] as $i => $original ) {
					$list[ $i ] = isset( $fields[ $listKey ][ $i ] ) ? sanitize_text_field( $fields[ $listKey ][ $i ] ) : $original;
				}

				$entry[ $listKey ] = $list;
			}

			if ( ! empty( $fields['lowLabel'] ) ) {
				$entry['lowLabel'] = sanitize_text_field( $fields['lowLabel'] );
			}

			if ( ! empty( $fields['highLabel'] ) ) {
				$entry['highLabel'] = sanitize_text_field( $fields['highLabel'] );
			}

			if ( ! empty( $entry ) ) {
				$clean['questions'][ $id ] = $entry;
			}
		}

		update_option( BRS_Config::WORDING_OPTION, $clean );

		wp_safe_redirect( admin_url( 'admin.php?page=brs-wording&brs_saved=1' ) );
		exit;
	}

	/**
	 * One row per submission. Likert questions expand to one column per row,
	 * multi-select answers are pipe-joined, so the file opens flat in Excel.
	 *
	 * Shared by the manual "Download CSV" button and the OneDrive push, so
	 * both always produce exactly the same file.
	 */
	public static function build_csv_string() {
		$columns = BRS_Config::csv_columns();

		$stream = fopen( 'php://temp', 'r+' );

		// BOM so Excel opens UTF-8 correctly.
		fwrite( $stream, "\xEF\xBB\xBF" );

		$header = array( 'reference', 'created_at', 'source', 'external_id', 'email' );

		foreach ( $columns as $col ) {
			$header[] = $col['label'];
		}

		fputcsv( $stream, $header );

		foreach ( BRS_DB::get_all() as $row ) {
			$a    = json_decode( $row->answers, true );
			$line = array( $row->reference, $row->created_at, $row->source, $row->external_id, $row->email );

			foreach ( $columns as $col ) {
				$value = isset( $a[ $col['qid'] ] ) ? $a[ $col['qid'] ] : null;

				if ( null !== $col['row'] ) {
					$line[] = isset( $value[ $col['row'] ] ) ? $value[ $col['row'] ] : '';
				} elseif ( is_array( $value ) ) {
					$line[] = implode( ' | ', $value );
				} else {
					$line[] = null === $value ? '' : $value;
				}
			}

			fputcsv( $stream, $line );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );

		return $csv;
	}

	public static function handle_export_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		check_admin_referer( 'brs_export_csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bounce-survey-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		echo self::build_csv_string();
		exit;
	}

	/**
	 * OneDrive Sync settings: app credentials, the one-time Microsoft
	 * sign-in/connect step, and a manual test push. See class-brs-onedrive.php
	 * for the actual OAuth and Graph API mechanics - this class only renders
	 * the screen and hands off to it.
	 */
	public static function render_onedrive_settings() {
		if ( isset( $_GET['brs_oauth_callback'] ) ) {
			self::process_oauth_callback();
		}

		$settings  = BRS_OneDrive::settings();
		$connected = BRS_OneDrive::is_connected();
		?>
		<div class="wrap">
			<h1>OneDrive Sync</h1>

			<?php if ( isset( $_GET['brs_onedrive_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['brs_onedrive_connected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Connected to OneDrive.</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['brs_onedrive_error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['brs_onedrive_error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['brs_onedrive_test'] ) ) : ?>
				<?php if ( 'ok' === $_GET['brs_onedrive_test'] ) : ?>
					<div class="notice notice-success is-dismissible"><p>Test file pushed - check the folder.</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p>Test push failed: <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['brs_onedrive_test'] ) ) ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h2>1. App credentials</h2>
			<p>From the Azure app registration - the Overview page has the two IDs, Certificates &amp; secrets has the key.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="brs_save_onedrive_settings">
				<?php wp_nonce_field( 'brs_save_onedrive_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="brs-tenant">Directory (tenant) ID</label></th>
						<td><input type="text" id="brs-tenant" name="tenant_id" value="<?php echo esc_attr( $settings['tenant_id'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="brs-client">Application (client) ID</label></th>
						<td><input type="text" id="brs-client" name="client_id" value="<?php echo esc_attr( $settings['client_id'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="brs-secret">Client secret</label></th>
						<td>
							<input
								type="password"
								id="brs-secret"
								name="client_secret"
								value=""
								class="regular-text"
								placeholder="<?php echo $settings['client_secret'] ? esc_attr( 'Already set - leave blank to keep it' ) : ''; ?>"
								autocomplete="new-password"
							>
						</td>
					</tr>
					<tr>
						<th><label for="brs-folder">Folder link</label></th>
						<td>
							<input type="text" id="brs-folder" name="folder_link" value="<?php echo esc_attr( $settings['folder_link'] ); ?>" class="regular-text">
							<p class="description">Paste the exact web address of the destination folder - copy it straight from your browser's address bar while looking at the folder (OneDrive or SharePoint, either works, whichever account you connect below needs edit access to it).</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2>2. Connect</h2>
			<p>Status: <strong><?php echo $connected ? 'Connected' : 'Not connected'; ?></strong></p>

			<?php if ( BRS_OneDrive::is_configured() ) : ?>
				<p>
					<a href="<?php echo esc_url( BRS_OneDrive::authorize_url() ); ?>" class="button button-primary">
						<?php echo $connected ? 'Reconnect to OneDrive' : 'Connect to OneDrive'; ?>
					</a>
				</p>
			<?php else : ?>
				<p><em>Save the credentials above first.</em></p>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<h2>3. Test it</h2>
				<p>Pushes today's full submissions export to the folder right now as <code>bounce-survey-test.csv</code>, so you can confirm it lands in the right place before trusting the automatic version.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="brs_onedrive_test_push">
					<?php wp_nonce_field( 'brs_onedrive_test_push' ); ?>
					<?php submit_button( 'Push test file now', 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function process_oauth_callback() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! empty( $_GET['error'] ) ) {
			$desc = ! empty( $_GET['error_description'] )
				? sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
				: sanitize_text_field( wp_unslash( $_GET['error'] ) );

			wp_safe_redirect( add_query_arg( 'brs_onedrive_error', rawurlencode( $desc ), admin_url( 'admin.php?page=brs-onedrive' ) ) );
			exit;
		}

		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			return;
		}

		$result = BRS_OneDrive::handle_callback(
			sanitize_text_field( wp_unslash( $_GET['code'] ) ),
			sanitize_text_field( wp_unslash( $_GET['state'] ) )
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'brs_onedrive_error', rawurlencode( $result->get_error_message() ), admin_url( 'admin.php?page=brs-onedrive' ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=brs-onedrive&brs_onedrive_connected=1' ) );
		exit;
	}

	public static function handle_save_onedrive_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		check_admin_referer( 'brs_save_onedrive_settings' );

		$existing = BRS_OneDrive::settings();

		$settings = array(
			'tenant_id'     => isset( $_POST['tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_id'] ) ) : '',
			'client_id'     => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
			'client_secret' => ! empty( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : $existing['client_secret'],
			'folder_link'   => isset( $_POST['folder_link'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_link'] ) ) : '',
		);

		// Not autoloaded - this option (and the token option) hold a secret,
		// no reason to pull them into every single page load's autoload cache.
		update_option( BRS_OneDrive::SETTINGS_OPTION, $settings, false );

		wp_safe_redirect( admin_url( 'admin.php?page=brs-onedrive&brs_onedrive_saved=1' ) );
		exit;
	}

	public static function handle_onedrive_test_push() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		check_admin_referer( 'brs_onedrive_test_push' );

		$result   = BRS_OneDrive::push_csv( self::build_csv_string(), 'bounce-survey-test.csv' );
		$redirect = admin_url( 'admin.php?page=brs-onedrive' );

		$redirect = is_wp_error( $result )
			? add_query_arg( 'brs_onedrive_test', rawurlencode( $result->get_error_message() ), $redirect )
			: add_query_arg( 'brs_onedrive_test', 'ok', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}
}