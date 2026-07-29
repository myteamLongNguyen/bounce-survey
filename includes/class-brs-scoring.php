<?php
/**
 * Scoring engine for the ASBA x Bounce maturity model (data/scoring.json).
 *
 * Options are matched by POSITION within the raw question's options/columns
 * (BRS_Config::raw_question), never by label text - wording overrides and
 * minor label drift between the scoring spreadsheet and questions.json would
 * otherwise silently break matching. "Other" is the one exception, since it
 * never appears in the raw options array.
 *
 * q20 and q27 are likert grids in the live survey (15 and 9 rows) but the
 * client's scoring model only has a single column scale for each, sized to
 * match one answer (max 40 / max 20 - confirmed by the section anchors
 * summing correctly only under that assumption). Per client direction, each
 * grid is collapsed to one score by averaging the column position across all
 * answered rows, then rounding to the nearest column.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_Scoring {

	/**
	 * Admin-edited point values / feedback text, layered on top of the
	 * shipped data/scoring.json at read time - same pattern as
	 * BRS_Config::WORDING_OPTION for question wording. The JSON file always
	 * remains the default/fallback and defines the fixed *shape* (how many
	 * ratings a question has, how many maturity levels exist, etc); this
	 * option can only override values within that shape, never change it.
	 */
	const OPTION = 'brs_scoring_overrides';

	private static $raw_model = null;
	private static $model     = null;

	/** The untouched contents of scoring.json - no overrides applied. Used to know the original shape (array lengths etc.) when validating admin edits. */
	public static function raw_model() {
		if ( null === self::$raw_model ) {
			$raw             = file_get_contents( BRS_PATH . 'data/scoring.json' );
			self::$raw_model = json_decode( $raw, true );

			if ( ! is_array( self::$raw_model ) ) {
				self::$raw_model = array( 'denominator' => 0, 'maturityLevels' => array(), 'sections' => array(), 'questions' => array() );
			}
		}

		return self::$raw_model;
	}

	public static function model() {
		if ( null === self::$model ) {
			// json_decode + serialize round trip gives a deep copy, so
			// mutating $model below never touches the cached raw_model().
			$model       = json_decode( wp_json_encode( self::raw_model() ), true );
			self::$model = self::apply_overrides( $model );
		}

		return self::$model;
	}

	/**
	 * Layers admin-edited point values and feedback text on top of the
	 * original model. A value is only overridden when it is numeric (for
	 * ratings/thresholds) or non-blank (for feedback text) and - for arrays -
	 * the count still matches the original, so a stale override left over
	 * from a since-changed question can never desync scoring from the
	 * question definitions in questions.json.
	 */
	private static function apply_overrides( $model ) {
		$overrides = get_option( self::OPTION, array() );

		if ( empty( $overrides ) || ! is_array( $overrides ) ) {
			return $model;
		}

		if ( ! empty( $overrides['maturityLevels'] ) && is_array( $overrides['maturityLevels'] ) ) {
			foreach ( $model['maturityLevels'] as &$level ) {
				$key = (string) $level['level'];

				// Level 1 always starts at 0 - not editable.
				if ( $level['level'] > 1 && isset( $overrides['maturityLevels'][ $key ] ) && is_numeric( $overrides['maturityLevels'][ $key ] ) ) {
					$level['minInclusive'] = (float) $overrides['maturityLevels'][ $key ];
				}
			}
			unset( $level );
		}

		if ( isset( $overrides['denominator'] ) && is_numeric( $overrides['denominator'] ) ) {
			$model['denominator'] = (float) $overrides['denominator'];

			foreach ( $model['maturityLevels'] as &$level ) {
				if ( 5 === $level['level'] ) {
					$level['maxInclusive'] = $model['denominator'];
				}
			}
			unset( $level );
		}

		// Each level's exclusive ceiling is the next level's floor - keep the
		// chain consistent after any minInclusive overrides above.
		usort(
			$model['maturityLevels'],
			function ( $a, $b ) {
				return $a['level'] <=> $b['level'];
			}
		);

		for ( $i = 0, $n = count( $model['maturityLevels'] ); $i < $n - 1; $i++ ) {
			$model['maturityLevels'][ $i ]['maxExclusive'] = $model['maturityLevels'][ $i + 1 ]['minInclusive'];
		}

		if ( ! empty( $overrides['sections'] ) && is_array( $overrides['sections'] ) ) {
			foreach ( $model['sections'] as $name => &$section ) {
				if ( empty( $overrides['sections'][ $name ] ) || ! is_array( $overrides['sections'][ $name ] ) ) {
					continue;
				}

				foreach ( array( 'L1', 'L2', 'L3', 'L4', 'L5' ) as $anchor_key ) {
					if ( isset( $overrides['sections'][ $name ][ $anchor_key ] ) && is_numeric( $overrides['sections'][ $name ][ $anchor_key ] ) ) {
						$section['anchors'][ $anchor_key ] = (float) $overrides['sections'][ $name ][ $anchor_key ];
					}
				}
			}
			unset( $section );
		}

		if ( ! empty( $overrides['questions'] ) && is_array( $overrides['questions'] ) ) {
			foreach ( $model['questions'] as $id => &$q ) {
				if ( empty( $overrides['questions'][ $id ] ) || ! is_array( $overrides['questions'][ $id ] ) ) {
					continue;
				}

				self::apply_question_overrides( $q, $overrides['questions'][ $id ] );
			}
			unset( $q );
		}

		return $model;
	}

	private static function apply_question_overrides( &$q, $ov ) {
		switch ( $q['kind'] ) {
			case 'single':
			case 'rating_position':
			case 'erp_conditional':
				self::apply_numeric_array( $q['ratings'], isset( $ov['ratings'] ) ? $ov['ratings'] : null );
				self::apply_text_array( $q['feedback'], isset( $ov['feedback'] ) ? $ov['feedback'] : null );
				break;

			case 'multi_sum':
				self::apply_numeric_array( $q['ratings'], isset( $ov['ratings'] ) ? $ov['ratings'] : null );

				if ( isset( $ov['feedback'] ) && is_string( $ov['feedback'] ) && '' !== trim( $ov['feedback'] ) ) {
					$q['feedback'] = $ov['feedback'];
				}
				break;

			case 'grid_average':
				self::apply_numeric_array( $q['columnRatings'], isset( $ov['columnRatings'] ) ? $ov['columnRatings'] : null );
				self::apply_text_array( $q['feedback'], isset( $ov['feedback'] ) ? $ov['feedback'] : null );
				break;

			case 'likert_rows':
				if ( ! empty( $ov['rows'] ) && is_array( $ov['rows'] ) ) {
					foreach ( $q['rows'] as &$row ) {
						$row_ov = isset( $ov['rows'][ $row['row'] ] ) ? $ov['rows'][ $row['row'] ] : null;

						if ( ! is_array( $row_ov ) ) {
							continue;
						}

						self::apply_numeric_array( $row['ratings'], isset( $row_ov['ratings'] ) ? $row_ov['ratings'] : null );
						self::apply_text_array( $row['feedback'], isset( $row_ov['feedback'] ) ? $row_ov['feedback'] : null );
					}
					unset( $row );
				}
				break;
		}

		if ( isset( $q['otherRating'] ) && isset( $ov['otherRating'] ) && is_numeric( $ov['otherRating'] ) ) {
			$q['otherRating'] = (float) $ov['otherRating'];
		}

		if ( isset( $q['otherFeedback'] ) && isset( $ov['otherFeedback'] ) && is_string( $ov['otherFeedback'] ) && '' !== trim( $ov['otherFeedback'] ) ) {
			$q['otherFeedback'] = $ov['otherFeedback'];
		}
	}

	/** Overwrites $target in place, index by index, only where $ov has a numeric value at that index and the lengths match. */
	private static function apply_numeric_array( &$target, $ov ) {
		if ( ! is_array( $ov ) || count( $ov ) !== count( $target ) ) {
			return;
		}

		foreach ( $target as $i => $v ) {
			if ( isset( $ov[ $i ] ) && is_numeric( $ov[ $i ] ) ) {
				$target[ $i ] = (float) $ov[ $i ];
			}
		}
	}

	/** Same as apply_numeric_array() but for feedback text - only overwrites with non-blank strings. */
	private static function apply_text_array( &$target, $ov ) {
		if ( ! is_array( $ov ) || count( $ov ) !== count( $target ) ) {
			return;
		}

		foreach ( $target as $i => $v ) {
			if ( isset( $ov[ $i ] ) && is_string( $ov[ $i ] ) && '' !== trim( $ov[ $i ] ) ) {
				$target[ $i ] = $ov[ $i ];
			}
		}
	}

	/**
	 * @param array $answers Cleaned answers as stored on the submission row
	 *                        (question id => value, same shape BRS_REST::validate produces).
	 *
	 * @return array {
	 *   total, pct, band (maturityLevel entry),
	 *   sections: [ name => { total, max, pct, band } ],
	 *   items: [ { id, label, section, earned, max, feedback } ] - one per
	 *          scored item (q16's three rows count as three items).
	 * }
	 */
	public static function score( array $answers ) {
		$model   = self::model();
		$items   = array();
		$section_totals = array();

		foreach ( $model['questions'] as $id => $q ) {
			$given = isset( $answers[ $id ] ) ? $answers[ $id ] : null;

			if ( 'likert_rows' === $q['kind'] ) {
				foreach ( $q['rows'] as $row ) {
					$row_id  = $id . chr( 97 + $row['row'] ); // 0 -> 'a', 1 -> 'b', ...
					$column  = is_array( $given ) && isset( $given[ $row['row'] ] ) ? $given[ $row['row'] ] : null;
					$result  = self::score_single_from_columns( $id, $row['row'], $column, $row['ratings'], $row['feedback'] );

					$items[] = array(
						'id'       => $row_id,
						'label'    => $row['label'],
						'section'  => $q['section'],
						'earned'   => $result['earned'],
						'max'      => $row['max'],
						'feedback' => $result['feedback'],
					);

					self::add( $section_totals, $q['section'], $result['earned'] );
				}
				continue;
			}

			$result = self::score_question( $id, $q, $given );

			if ( null === $result ) {
				continue;
			}

			$items[] = array(
				'id'       => $id,
				'label'    => null, // filled in by the caller from BRS_Config, which knows current wording.
				'section'  => $q['section'],
				'earned'   => $result['earned'],
				'max'      => $q['max'],
				'feedback' => $result['feedback'],
			);

			self::add( $section_totals, $q['section'], $result['earned'] );
		}

		$total = array_sum( $section_totals );

		$sections = array();

		foreach ( $model['sections'] as $name => $section ) {
			$earned = isset( $section_totals[ $name ] ) ? $section_totals[ $name ] : 0;
			$max    = $section['anchors']['L5'];

			$sections[ $name ] = array(
				'total' => $earned,
				'max'   => $max,
				'pct'   => $max > 0 ? round( ( $earned / $max ) * 100, 1 ) : 0,
				'band'  => self::band_for( $earned, self::anchors_as_levels( $section['anchors'] ) ),
			);
		}

		return array(
			'total'    => $total,
			'max'      => $model['denominator'],
			'pct'      => $model['denominator'] > 0 ? round( ( $total / $model['denominator'] ) * 100, 1 ) : 0,
			'band'     => self::band_for( $total, $model['maturityLevels'] ),
			'sections' => $sections,
			'items'    => $items,
		);
	}

	/**
	 * Every scored item whose given answer carries feedback text, ranked by
	 * relative gap (1 - earned/max), for the "Recommended Actions" panel.
	 * Items with no feedback (nulls in the source data) are skipped since
	 * there is nothing actionable to show - everything else is included, so
	 * a respondent with many gaps sees all of them, not just a top few.
	 */
	public static function recommended_actions( array $scored ) {
		$candidates = array_filter(
			$scored['items'],
			function ( $item ) {
				return ! empty( $item['feedback'] ) && $item['max'] > 0;
			}
		);

		usort(
			$candidates,
			function ( $a, $b ) {
				$gap_a = 1 - ( $a['earned'] / $a['max'] );
				$gap_b = 1 - ( $b['earned'] / $b['max'] );
				return $gap_b <=> $gap_a;
			}
		);

		return array_values( $candidates );
	}

	/**
	 * All-respondent peer comparison for the results dashboard's bell curve.
	 * Positions are expressed on the same 0-5 "Level" axis as the mockup:
	 * a score sitting a third of the way through the Level 3 band lands at
	 * position 2.33, so the front end can place it directly on an axis whose
	 * ticks are the five maturity levels.
	 *
	 * @param string $exclude_reference This submission's own reference, so it
	 *                                   doesn't get counted as its own peer.
	 */
	public static function peer_summary( $exclude_reference, $my_total ) {
		$rows   = BRS_DB::get_scored_totals();
		$totals = array();

		foreach ( $rows as $row ) {
			if ( $row['reference'] !== $exclude_reference ) {
				$totals[] = $row['total'];
			}
		}

		$count = count( $totals );
		$mean  = $count > 0 ? array_sum( $totals ) / $count : null;
		$stdev = null;

		if ( $count > 1 ) {
			$variance = array_sum(
				array_map(
					function ( $v ) use ( $mean ) {
						return ( $v - $mean ) ** 2;
					},
					$totals
				)
			) / ( $count - 1 );
			$stdev = sqrt( $variance );
		}

		$model = self::model();

		return array(
			'sampleSize'       => $count,
			'mean'             => $mean,
			'stdev'            => $stdev,
			'myTotal'          => $my_total,
			'myPosition'       => self::level_position( $my_total, $model['maturityLevels'] ),
			'meanPosition'     => null === $mean ? null : self::level_position( $mean, $model['maturityLevels'] ),
			// Position-space stdev, approximated from the (possibly non-linear)
			// score->position mapping so the bell curve's width is drawn on the
			// same axis as the two markers above.
			'stdevPosition'    => ( null === $mean || null === $stdev )
				? null
				: ( self::level_position( $mean + $stdev, $model['maturityLevels'] ) - self::level_position( $mean, $model['maturityLevels'] ) ),
		);
	}

	/**
	 * Maps a raw score onto a continuous 0-5 position: level N's band covers
	 * [N-1, N), so a score a third of the way through Level 3 (index 2 in the
	 * zero-based levels array) lands at position 2.33.
	 */
	private static function level_position( $score, $levels ) {
		foreach ( $levels as $i => $level ) {
			$min = $level['minInclusive'];
			$max = isset( $level['maxExclusive'] ) ? $level['maxExclusive'] : ( isset( $level['maxInclusive'] ) ? $level['maxInclusive'] : $min );

			if ( $score >= $min && ( $score < $max || $i === count( $levels ) - 1 ) ) {
				$span = $max - $min;
				return $span > 0 ? $i + ( ( $score - $min ) / $span ) : $i;
			}
		}

		return 0;
	}

	private static function add( &$totals, $section, $amount ) {
		$totals[ $section ] = ( isset( $totals[ $section ] ) ? $totals[ $section ] : 0 ) + $amount;
	}

	private static function score_question( $id, $q, $given ) {
		switch ( $q['kind'] ) {
			case 'single':
				return self::score_single( $id, $q, $given );

			case 'rating_position':
				return self::score_rating( $q, $given );

			case 'multi_sum':
				return self::score_multi_sum( $id, $q, $given );

			case 'erp_conditional':
				return self::score_erp_conditional( $id, $q, $given );

			case 'grid_average':
				return self::score_grid_average( $id, $q, $given );
		}

		return null;
	}

	private static function raw_options( $id ) {
		$q = BRS_Config::raw_question( $id );
		return $q && ! empty( $q['options'] ) ? $q['options'] : array();
	}

	private static function raw_columns( $id ) {
		$q = BRS_Config::raw_question( $id );
		return $q && ! empty( $q['columns'] ) ? $q['columns'] : array();
	}

	private static function score_single( $id, $q, $given ) {
		if ( null === $given ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		if ( BRS_REST::OTHER === $given ) {
			return array(
				'earned'   => isset( $q['otherRating'] ) ? $q['otherRating'] : 0,
				'feedback' => isset( $q['otherFeedback'] ) ? $q['otherFeedback'] : null,
			);
		}

		$index = array_search( $given, self::raw_options( $id ), true );

		if ( false === $index || ! isset( $q['ratings'][ $index ] ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		return array(
			'earned'   => (float) $q['ratings'][ $index ],
			'feedback' => isset( $q['feedback'][ $index ] ) ? $q['feedback'][ $index ] : null,
		);
	}

	private static function score_single_from_columns( $id, $row_index, $given_column, $ratings, $feedback ) {
		if ( null === $given_column ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$index = array_search( $given_column, self::raw_columns( $id ), true );

		if ( false === $index || ! isset( $ratings[ $index ] ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		return array(
			'earned'   => (float) $ratings[ $index ],
			'feedback' => isset( $feedback[ $index ] ) ? $feedback[ $index ] : null,
		);
	}

	private static function score_rating( $q, $given ) {
		if ( null === $given ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$index = (int) $given - 1;

		if ( ! isset( $q['ratings'][ $index ] ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		return array(
			'earned'   => (float) $q['ratings'][ $index ],
			'feedback' => isset( $q['feedback'][ $index ] ) ? $q['feedback'][ $index ] : null,
		);
	}

	private static function score_multi_sum( $id, $q, $given ) {
		if ( ! is_array( $given ) || empty( $given ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$options = self::raw_options( $id );
		$earned  = 0;

		foreach ( $given as $choice ) {
			$index = array_search( $choice, $options, true );

			if ( false !== $index && isset( $q['ratings'][ $index ] ) ) {
				$earned += (float) $q['ratings'][ $index ];
			}
		}

		return array(
			'earned'   => $earned,
			// Single canonical checklist feedback, not per-option - only shown when something is missing.
			'feedback' => $earned < $q['max'] ? $q['feedback'] : null,
		);
	}

	private static function score_erp_conditional( $id, $q, $given ) {
		if ( ! is_array( $given ) || empty( $given ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$options = self::raw_options( $id );
		$override_index = $q['overrideIndex'];

		if ( isset( $options[ $override_index ] ) && in_array( $options[ $override_index ], $given, true ) ) {
			return array(
				'earned'   => (float) $q['ratings'][ $override_index ],
				'feedback' => null, // Override selected - the strongest answer, nothing to recommend.
			);
		}

		$earned   = 0;
		$feedback = null;

		foreach ( $given as $choice ) {
			$index = array_search( $choice, $options, true );

			if ( false !== $index && $index !== $override_index && isset( $q['ratings'][ $index ] ) ) {
				$earned += (float) $q['ratings'][ $index ];

				if ( null === $feedback && ! empty( $q['feedback'][ $index ] ) ) {
					$feedback = $q['feedback'][ $index ];
				}
			}
		}

		// Nothing scoreable was picked (e.g. only "None of the above") - fall
		// back to the last option's feedback, which is the general "develop a
		// plan" guidance.
		if ( null === $feedback ) {
			$feedback = end( $q['feedback'] );
		}

		return array( 'earned' => $earned, 'feedback' => $feedback );
	}

	private static function score_grid_average( $id, $q, $given ) {
		if ( ! is_array( $given ) || empty( $given ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$columns   = self::raw_columns( $id );
		$positions = array();

		foreach ( $given as $column ) {
			$index = array_search( $column, $columns, true );

			if ( false !== $index ) {
				$positions[] = $index;
			}
		}

		if ( empty( $positions ) ) {
			return array( 'earned' => 0, 'feedback' => null );
		}

		$avg_index = (int) round( array_sum( $positions ) / count( $positions ) );
		$avg_index = max( 0, min( count( $q['columnRatings'] ) - 1, $avg_index ) );

		return array(
			'earned'   => (float) $q['columnRatings'][ $avg_index ],
			'feedback' => isset( $q['feedback'][ $avg_index ] ) ? $q['feedback'][ $avg_index ] : null,
		);
	}

	/**
	 * @param array $levels Either the full maturityLevels array, or a
	 *                       synthetic one built by anchors_as_levels() for a section.
	 */
	private static function band_for( $score, $levels ) {
		foreach ( $levels as $level ) {
			$min = $level['minInclusive'];
			$max = isset( $level['maxExclusive'] ) ? $level['maxExclusive'] : ( isset( $level['maxInclusive'] ) ? $level['maxInclusive'] + 0.0001 : PHP_INT_MAX );

			if ( $score >= $min && $score < $max ) {
				return $level;
			}
		}

		// Above every band (e.g. exactly at the ceiling) - return the top one.
		return end( $levels );
	}

	/**
	 * Builds section-scoped maturityLevel-shaped bands from that section's L1..L5
	 * anchors, reusing the same [min, next) boundary convention as the overall model.
	 */
	private static function anchors_as_levels( $anchors ) {
		$order  = array( 1, 2, 3, 4, 5 );
		$levels = array();
		$prev   = 0;

		foreach ( $order as $i => $level ) {
			$key = 'L' . $level;
			$max = $anchors[ $key ];

			$levels[] = array(
				'level'        => $level,
				'minInclusive' => $prev,
				'maxExclusive' => $level === 5 ? null : $max,
				'maxInclusive' => $level === 5 ? $max : null,
			);

			$prev = $max;
		}

		return $levels;
	}
}
