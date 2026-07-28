<?php
/**
 * Storage for survey submissions.
 *
 * Answers are stored as JSON keyed by question id, never by question text.
 * That is what allows wording and subtext to be edited later without
 * invalidating responses already collected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_DB {

	const SCHEMA_VERSION = 3;
	const SCHEMA_OPTION  = 'brs_schema_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . BRS_TABLE;
	}

	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is fussy: two spaces after PRIMARY KEY, KEY not INDEX,
		// one field per line, and no backticks around the table name.
		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reference varchar(20) NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'web',
			external_id varchar(191) DEFAULT NULL,
			email varchar(191) DEFAULT NULL,
			answers longtext NOT NULL,
			total_score decimal(6,1) DEFAULT NULL,
			category_scores longtext DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY external_id (external_id),
			KEY created_at (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	public static function maybe_upgrade() {
		$previous = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $previous >= self::SCHEMA_VERSION ) {
			return;
		}

		self::install();

		// v3 added scoring - anything submitted before that (total_score still
		// NULL) never got a score, so it 404s on the results page and is
		// silently excluded from the peer comparison. Score it now, once,
		// using the current scoring model - same as any newly-imported row.
		if ( $previous < 3 ) {
			self::backfill_scores();
		}
	}

	/**
	 * @return int Number of rows scored.
	 */
	private static function backfill_scores() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT id, answers FROM ' . self::table() . ' WHERE total_score IS NULL' );
		$done = 0;

		foreach ( $rows as $row ) {
			$answers = json_decode( $row->answers, true );

			if ( ! is_array( $answers ) ) {
				continue;
			}

			$scored = BRS_Scoring::score( $answers );

			$wpdb->update(
				self::table(),
				array(
					'total_score'     => $scored['total'],
					'category_scores' => wp_json_encode( $scored ),
				),
				array( 'id' => $row->id ),
				array( '%f', '%s' ),
				array( '%d' )
			);

			++$done;
		}

		return $done;
	}

	/**
	 * Human-readable, non-sequential reference. Given to the respondent and
	 * used later to look up their result.
	 */
	public static function generate_reference() {
		// No ambiguous characters (0/O, 1/I) — people will read these aloud.
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$ref      = '';

		for ( $i = 0; $i < 10; $i++ ) {
			$ref .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return substr( $ref, 0, 5 ) . '-' . substr( $ref, 5, 5 );
	}

	/**
	 * @param array       $answers     question id => answer. Shape varies by
	 *                                 question type: string for single choice,
	 *                                 array for multiple, row index => column
	 *                                 for likert, int for rating, string for text.
	 * @param string|null $email
	 * @param string      $source      'web' or 'msforms'
	 * @param string|null $external_id Microsoft Forms response id, when importing
	 * @param array|null  $scored      Output of BRS_Scoring::score(), snapshotted at
	 *                                 submit time so later scoring-model edits never
	 *                                 retroactively change an already-issued report.
	 *
	 * @return string|WP_Error The reference on success.
	 */
	public static function insert( array $answers, $email = null, $source = 'web', $external_id = null, $scored = null ) {
		global $wpdb;

		$reference = self::generate_reference();

		$result = $wpdb->insert(
			self::table(),
			array(
				'reference'       => $reference,
				'source'          => $source,
				'external_id'     => $external_id,
				'email'           => $email ? $email : null,
				'answers'         => wp_json_encode( $answers ),
				'total_score'     => $scored ? $scored['total'] : null,
				'category_scores' => $scored ? wp_json_encode( $scored ) : null,
				'ip_hash'         => self::hash_ip(),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'brs_insert_failed', 'Could not save submission.' );
		}

		return $reference;
	}

	/**
	 * Hashed rather than stored raw — we only need it for rate limiting and
	 * duplicate detection, never to identify anyone.
	 */
	private static function hash_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return $ip ? hash( 'sha256', $ip . wp_salt() ) : null;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	public static function get_page( $per_page = 25, $offset = 0 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			)
		);
	}

	public static function get_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at ASC' );
	}

	/**
	 * All scored submissions' total/section scores, for the peer comparison
	 * on the results dashboard. Unscored rows (total_score IS NULL - imported
	 * MS Forms rows before scoring existed, or rows that predate this feature)
	 * are excluded so they don't drag the peer average down artificially.
	 */
	public static function get_scored_totals() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT reference, total_score, category_scores FROM ' . self::table() . ' WHERE total_score IS NOT NULL'
		);

		return array_map(
			function ( $row ) {
				$scored = json_decode( $row->category_scores, true );

				return array(
					'reference' => $row->reference,
					'total'     => (float) $row->total_score,
					'sections'  => is_array( $scored ) && isset( $scored['sections'] ) ? $scored['sections'] : array(),
				);
			},
			$rows
		);
	}

	public static function get_by_reference( $reference ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reference = %s', $reference )
		);
	}

	/**
	 * @return bool True if a row was deleted.
	 */
	public static function delete_by_reference( $reference ) {
		global $wpdb;

		$deleted = $wpdb->delete( self::table(), array( 'reference' => $reference ), array( '%s' ) );

		return (bool) $deleted;
	}
}
