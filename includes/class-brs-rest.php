<?php
/**
 * Public submission endpoint.
 *
 * Phase 1: capture only. Every answer is validated for shape and against the
 * permitted options, then stored raw. No scoring is applied.
 *
 * The survey is anonymous, so the nonce is not a real security boundary -
 * anyone can load the page and read one. The actual protections are
 * server-side validation, a honeypot field and IP rate limiting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_REST {

	const REST_NS     = 'bounce/v1';
	const RATE_LIMIT  = 5;    // submissions
	const RATE_WINDOW = 3600; // per hour, per IP
	const TEXT_MAX    = 5000;
	const OTHER       = 'Other';

	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_submit' ),
				// Public endpoint. Required since WP 5.5 - omitting it triggers a notice.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NS,
			'/results/(?P<reference>[A-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_results' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The reference (10 random characters, unguessable) is the only access
	 * control here - same trust model as the rest of this anonymous survey.
	 * Rate limited separately from /submit so a burst of legitimate people
	 * viewing their own results right after submitting doesn't get confused
	 * with someone trying to enumerate references.
	 */
	public static function handle_results( WP_REST_Request $request ) {
		if ( self::is_results_rate_limited() ) {
			return new WP_Error(
				'brs_rate_limited',
				'Too many requests from this connection. Please try again later.',
				array( 'status' => 429 )
			);
		}

		self::record_results_rate_hit();

		$reference = sanitize_text_field( $request->get_param( 'reference' ) );
		$row       = BRS_DB::get_by_reference( $reference );

		if ( ! $row || null === $row->total_score ) {
			return new WP_Error( 'brs_not_found', 'No results found for that reference.', array( 'status' => 404 ) );
		}

		$scored  = json_decode( $row->category_scores, true );
		$answers = json_decode( $row->answers, true );

		if ( ! is_array( $scored ) ) {
			return new WP_Error( 'brs_not_found', 'No results found for that reference.', array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'reference'      => $row->reference,
				'schoolName'     => isset( $answers['q59'] ) ? $answers['q59'] : '',
				'submittedAt'    => $row->created_at,
				'score'          => $scored,
				'maturityLevels' => BRS_Scoring::model()['maturityLevels'],
				'actions'        => self::actions_with_labels( BRS_Scoring::recommended_actions( $scored, 4 ) ),
				'peers'          => BRS_Scoring::peer_summary( $row->reference, $row->total_score ),
			),
			200
		);
	}

	/**
	 * BRS_Scoring doesn't know current question wording (it only reads the
	 * structural scoring config) - fill in each recommended action's label
	 * here from BRS_Config, which layers admin wording overrides on top.
	 */
	private static function actions_with_labels( $items ) {
		return array_map(
			function ( $item ) {
				if ( null === $item['label'] ) {
					$q = BRS_Config::question( $item['id'] );
					// q16a/q16b/q16c aren't real question ids - fall back to the
					// row label already carried on the item in that case.
					$item['label'] = $q ? $q['text'] : $item['label'];
				}

				return $item;
			},
			$items
		);
	}

	private static function results_rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'brs_results_rate_' . md5( $ip );
	}

	private static function is_results_rate_limited() {
		return (int) get_transient( self::results_rate_key() ) >= 30;
	}

	private static function record_results_rate_hit() {
		$key = self::results_rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	public static function handle_submit( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'brs_bad_request', 'Malformed request.', array( 'status' => 400 ) );
		}

		// Honeypot: hidden from people, tempting to bots. Return success so
		// the bot does not learn it was caught.
		if ( ! empty( $body['website'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'reference' => 'IGNORED' ), 200 );
		}

		if ( self::is_rate_limited() ) {
			return new WP_Error(
				'brs_rate_limited',
				'Too many submissions from this connection. Please try again later.',
				array( 'status' => 429 )
			);
		}

		$answers = self::validate( isset( $body['answers'] ) ? $body['answers'] : array() );

		if ( is_wp_error( $answers ) ) {
			return $answers;
		}

		// q57 is the email address field in the Follow-up Participation
		// section (only visible/required once the respondent has opted into
		// at least one of the follow-up options) - it doubles as the `email`
		// column on the submission row, same as the CSV/admin views expect.
		$email = null;

		if ( ! empty( $answers['q57'] ) ) {
			$candidate = sanitize_email( $answers['q57'] );

			if ( ! is_email( $candidate ) ) {
				return new WP_Error( 'brs_bad_email', 'That email address does not look valid.', array( 'status' => 400, 'question' => 'q57' ) );
			}

			$email             = $candidate;
			$answers['q57']    = $email;
		}

		$scored    = BRS_Scoring::score( $answers );
		$reference = BRS_DB::insert( $answers, $email, 'web', null, $scored );

		if ( is_wp_error( $reference ) ) {
			return new WP_Error( 'brs_save_failed', 'Could not save your responses.', array( 'status' => 500 ) );
		}

		self::record_rate_hit();

		// Fire-and-forget: the respondent's request returns now, the
		// OneDrive push (a call to Microsoft that can take a couple of
		// seconds) happens moments later in the background.
		if ( BRS_OneDrive::is_connected() ) {
			wp_schedule_single_event( time(), 'brs_push_onedrive' );
		}

		return new WP_REST_Response( array( 'ok' => true, 'reference' => $reference ), 201 );
	}

	/**
	 * Type-aware validation. Answers arrive keyed by question id; the shape
	 * expected depends on the question type. Anything unrecognised is dropped
	 * rather than stored, so a tampered payload cannot inject arbitrary data.
	 *
	 * Conditional questions (visibleIf) are only required when their condition
	 * is met - otherwise a New Zealand school could never submit, because the
	 * Australian state question is marked required in the source form.
	 */
	private static function validate( $submitted ) {
		if ( ! is_array( $submitted ) ) {
			return new WP_Error( 'brs_bad_answers', 'Answers must be an object.', array( 'status' => 400 ) );
		}

		$clean = array();

		foreach ( BRS_Config::questions() as $q ) {
			$id      = $q['id'];
			$type    = $q['type'];
			$visible = self::is_visible( $q, $submitted );
			$given   = isset( $submitted[ $id ] ) ? $submitted[ $id ] : null;

			if ( ! $visible ) {
				continue;
			}

			$empty = ( null === $given || '' === $given || ( is_array( $given ) && 0 === count( $given ) ) );

			if ( $empty ) {
				if ( ! empty( $q['required'] ) ) {
					return new WP_Error(
						'brs_missing_answer',
						'Please answer every required question before submitting.',
						array( 'status' => 400, 'question' => $id )
					);
				}

				continue;
			}

			switch ( $type ) {
				case 'single':
					$permitted = self::permitted_options( $q );

					if ( ! in_array( $given, $permitted, true ) ) {
						return self::invalid( $id );
					}

					$clean[ $id ] = $given;

					if ( self::OTHER === $given ) {
						$other = self::other_text( $submitted, $id );

						if ( '' === $other && ! empty( $q['required'] ) ) {
							return self::missing( $id );
						}

						if ( '' !== $other ) {
							$clean[ $id . '_other' ] = $other;
						}
					}
					break;

				case 'multiple':
					if ( ! is_array( $given ) ) {
						return self::invalid( $id );
					}

					$picked    = array_values( array_unique( array_filter( $given, 'is_string' ) ) );
					$permitted = self::permitted_options( $q );

					foreach ( $picked as $choice ) {
						if ( ! in_array( $choice, $permitted, true ) ) {
							return self::invalid( $id );
						}
					}

					if ( ! empty( $q['maxSelect'] ) && count( $picked ) > (int) $q['maxSelect'] ) {
						return new WP_Error(
							'brs_too_many',
							'Please select at most ' . (int) $q['maxSelect'] . ' options.',
							array( 'status' => 400, 'question' => $id )
						);
					}

					$clean[ $id ] = $picked;

					if ( in_array( self::OTHER, $picked, true ) ) {
						$other = self::other_text( $submitted, $id );

						if ( '' === $other && ! empty( $q['required'] ) ) {
							return self::missing( $id );
						}

						if ( '' !== $other ) {
							$clean[ $id . '_other' ] = $other;
						}
					}
					break;

				case 'likert':
					if ( ! is_array( $given ) ) {
						return self::invalid( $id );
					}

					$grid = array();

					foreach ( $given as $row_index => $column ) {
						$row_index = (int) $row_index;

						if ( ! isset( $q['rows'][ $row_index ] ) ) {
							return self::invalid( $id );
						}

						if ( ! in_array( $column, $q['columns'], true ) ) {
							return self::invalid( $id );
						}

						$grid[ $row_index ] = $column;
					}

					if ( ! empty( $q['required'] ) && count( $grid ) !== count( $q['rows'] ) ) {
						return new WP_Error(
							'brs_missing_answer',
							'Please complete every row of this question.',
							array( 'status' => 400, 'question' => $id )
						);
					}

					ksort( $grid );
					$clean[ $id ] = $grid;
					break;

				case 'rating':
					$value = (int) $given;
					$max   = isset( $q['stars'] ) ? (int) $q['stars'] : 5;

					if ( $value < 1 || $value > $max ) {
						return self::invalid( $id );
					}

					$clean[ $id ] = $value;
					break;

				case 'text':
				case 'textarea':
					if ( ! is_string( $given ) ) {
						return self::invalid( $id );
					}

					$clean[ $id ] = mb_substr( sanitize_textarea_field( $given ), 0, self::TEXT_MAX );
					break;
			}
		}

		return $clean;
	}

	/**
	 * A question with a visibleIf clause is only in play when its controlling
	 * answer matches.
	 */
	private static function is_visible( $q, $submitted ) {
		if ( empty( $q['visibleIf'] ) ) {
			return true;
		}

		$rule       = $q['visibleIf'];
		$controller = isset( $submitted[ $rule['question'] ] ) ? $submitted[ $rule['question'] ] : null;

		if ( isset( $rule['equals'] ) ) {
			return $controller === $rule['equals'];
		}

		if ( isset( $rule['in'] ) ) {
			return in_array( $controller, $rule['in'], true );
		}

		// Hidden only when the controlling (multi-select) answer contains one
		// of the excluded values - e.g. hide the contact fields once "None of
		// the above" is ticked, however many of the other boxes are also ticked.
		if ( isset( $rule['notIn'] ) ) {
			$selected = is_array( $controller ) ? $controller : array( $controller );

			foreach ( $rule['notIn'] as $excluded ) {
				if ( in_array( $excluded, $selected, true ) ) {
					return false;
				}
			}

			return true;
		}

		return true;
	}

	/**
	 * "Other" is rendered by the front end rather than listed in the option
	 * array, so it has to be permitted explicitly.
	 */
	private static function permitted_options( $q ) {
		$options = isset( $q['options'] ) ? $q['options'] : array();

		if ( ! empty( $q['allowOther'] ) ) {
			$options[] = self::OTHER;
		}

		return $options;
	}

	private static function other_text( $submitted, $id ) {
		$key = $id . '_other';
		$raw = isset( $submitted[ $key ] ) && is_string( $submitted[ $key ] ) ? $submitted[ $key ] : '';

		return trim( mb_substr( sanitize_text_field( $raw ), 0, 500 ) );
	}

	private static function missing( $id ) {
		return new WP_Error(
			'brs_missing_answer',
			'Please complete the "Other" field for this question.',
			array( 'status' => 400, 'question' => $id )
		);
	}

	private static function invalid( $id ) {
		return new WP_Error(
			'brs_invalid_answer',
			'One of the answers was not a valid option.',
			array( 'status' => 400, 'question' => $id )
		);
	}

	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'brs_rate_' . md5( $ip );
	}

	private static function is_rate_limited() {
		return (int) get_transient( self::rate_key() ) >= self::RATE_LIMIT;
	}

	private static function record_rate_hit() {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}
}