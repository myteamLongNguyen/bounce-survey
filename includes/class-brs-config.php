<?php
/**
 * Survey definition.
 *
 * Questions live in data/questions.json — 58 questions across 11 sections,
 * transcribed from the Microsoft Form. The *structure* is fixed there: the
 * set of question ids, how many questions/sections exist, and — for
 * choice/likert questions — how many options, rows or columns each one has.
 * That structure must never change once responses start arriving, because
 * answers are stored by id and by option position, not by wording.
 *
 * The *wording* (question text, subtext, option/row/column labels, section
 * titles) is editable from the WordPress admin (Bounce Survey → Question
 * wording) by non-technical staff, stored as an override keyed by question
 * id / section number. Overrides are applied once here so every consumer —
 * the front end, the REST validator, the CSV export, the admin submission
 * view — sees the same effective wording.
 *
 * Phase 1 captures raw answers only. No scoring: the maturity model has not
 * been supplied by Bounce yet, and inventing score values now would bake in
 * guesses that are painful to unpick later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_Config {

	const WORDING_OPTION = 'brs_wording_overrides';

	private static $raw = null;
	private static $doc = null;

	/** The untouched contents of questions.json — no overrides applied. Used to know the original shape (option counts etc.) when validating admin edits. */
	public static function raw_doc() {
		if ( null === self::$raw ) {
			$raw       = file_get_contents( BRS_PATH . 'data/questions.json' );
			self::$raw = json_decode( $raw, true );

			if ( ! is_array( self::$raw ) ) {
				self::$raw = array( 'title' => '', 'subtitle' => '', 'sections' => array() );
			}
		}

		return self::$raw;
	}

	public static function raw_question( $id ) {
		foreach ( self::raw_doc()['sections'] as $section ) {
			foreach ( $section['questions'] as $q ) {
				if ( $q['id'] === $id ) {
					return $q;
				}
			}
		}

		return null;
	}

	private static function doc() {
		if ( null === self::$doc ) {
			// json_decode + serialize round trip gives us a deep copy, so
			// mutating $doc below never touches the cached raw_doc().
			$doc       = json_decode( wp_json_encode( self::raw_doc() ), true );
			self::$doc = self::apply_overrides( $doc );
		}

		return self::$doc;
	}

	/**
	 * Layers the admin-edited wording on top of the original structure.
	 * A field is only overridden when the admin actually set a non-blank
	 * value and — for options/rows/columns — the count still matches the
	 * original, so a stale override left over from a since-changed question
	 * can never desync the form from validation.
	 */
	private static function apply_overrides( $doc ) {
		$overrides    = get_option( self::WORDING_OPTION, array() );
		$question_ov  = isset( $overrides['questions'] ) ? $overrides['questions'] : array();
		$section_ov   = isset( $overrides['sections'] ) ? $overrides['sections'] : array();

		foreach ( $doc['sections'] as &$section ) {
			if ( ! empty( $section_ov[ $section['number'] ]['title'] ) ) {
				$section['title'] = $section_ov[ $section['number'] ]['title'];
			}

			foreach ( $section['questions'] as &$q ) {
				if ( empty( $question_ov[ $q['id'] ] ) ) {
					continue;
				}

				$o = $question_ov[ $q['id'] ];

				if ( isset( $o['text'] ) && '' !== trim( $o['text'] ) ) {
					$q['text'] = $o['text'];
				}

				if ( isset( $o['subtext'] ) ) {
					$q['subtext'] = $o['subtext'];
				}

				foreach ( array( 'options', 'rows', 'columns' ) as $listKey ) {
					if ( empty( $o[ $listKey ] ) || empty( $q[ $listKey ] ) ) {
						continue;
					}

					if ( count( $o[ $listKey ] ) !== count( $q[ $listKey ] ) ) {
						continue; // Original question changed shape since this override was saved - ignore it.
					}

					foreach ( $o[ $listKey ] as $i => $label ) {
						if ( '' !== trim( $label ) ) {
							$q[ $listKey ][ $i ] = $label;
						}
					}
				}

				if ( isset( $q['lowLabel'] ) && ! empty( $o['lowLabel'] ) ) {
					$q['lowLabel'] = $o['lowLabel'];
				}

				if ( isset( $q['highLabel'] ) && ! empty( $o['highLabel'] ) ) {
					$q['highLabel'] = $o['highLabel'];
				}
			}
		}

		return $doc;
	}

	public static function sections() {
		return self::doc()['sections'];
	}

	/** Flat list of every question, in form order. */
	public static function questions() {
		$out = array();

		foreach ( self::sections() as $section ) {
			foreach ( $section['questions'] as $q ) {
				$out[] = $q;
			}
		}

		return $out;
	}

	public static function question_ids() {
		return array_column( self::questions(), 'id' );
	}

	public static function question( $id ) {
		foreach ( self::questions() as $q ) {
			if ( $q['id'] === $id ) {
				return $q;
			}
		}

		return null;
	}

	/**
	 * The whole survey with wording overrides applied — this is what the
	 * front end receives.
	 */
	public static function survey_for_display() {
		return self::doc();
	}

	/**
	 * Column headers for the CSV export. Likert questions expand to one
	 * column per row so the file opens flat in Excel.
	 */
	public static function csv_columns() {
		$cols = array();

		foreach ( self::questions() as $q ) {
			if ( 'likert' === $q['type'] ) {
				foreach ( $q['rows'] as $i => $row ) {
					$cols[] = array(
						'key'   => $q['id'] . '_r' . $i,
						'label' => $q['id'] . ' - ' . $row,
						'qid'   => $q['id'],
						'row'   => $i,
					);
				}
			} else {
				$cols[] = array(
					'key'   => $q['id'],
					'label' => $q['id'] . ' - ' . wp_strip_all_tags( $q['text'] ),
					'qid'   => $q['id'],
					'row'   => null,
				);
			}

			if ( ! empty( $q['allowOther'] ) ) {
				$cols[] = array(
					'key'   => $q['id'] . '_other',
					'label' => $q['id'] . ' - Other (specified)',
					'qid'   => $q['id'] . '_other',
					'row'   => null,
				);
			}
		}

		return $cols;
	}

	public static function copy() {
		return array(
			'nextLabel'       => 'Next',
			'backLabel'       => 'Back',
			'submitLabel'     => 'Submit responses',
			'submittingText'  => 'Submitting...',
			'successTitle'    => 'Thank you - your responses have been recorded.',
			'successBody'     => 'Your reference number is below. Keep it if you would like to retrieve your results later.',
			'errorGeneric'    => 'Something went wrong and your responses were not saved. Please try again.',
			'errorNetwork'    => 'Could not reach the server. Check your connection and try again.',
			'errorIncomplete' => 'Please answer every required question in this section before continuing.',
		);
	}
}