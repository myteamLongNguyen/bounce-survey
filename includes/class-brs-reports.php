<?php
/**
 * "Response Overview" admin screen - an aggregate, chart-based summary of
 * every submission, in the spirit of Microsoft Forms' built-in "View
 * results" page: total responses, a maturity-level breakdown, and a
 * bar/likert-style chart per question.
 *
 * Deliberately excludes every free-text field that can identify a person
 * (email, school name, full name, phone - see PII_QUESTION_IDS) from this
 * bulk view. That data is still visible per-submission (Bounce Survey ->
 * Submissions), which is the existing, already-audited place for it; this
 * screen only adds a new *aggregate* view and should never become a new way
 * to bulk-browse identifying details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_Reports {

	const CAPABILITY = 'manage_options';

	const PII_QUESTION_IDS = array( 'q57', 'q59', 'q60', 'q61' ); // Email, school name, full name, phone.

	public static function render() {
		$data = self::aggregate();

		wp_enqueue_style( 'brs-reports', BRS_URL . 'assets/admin-reports.css', array(), BRS_VERSION );
		wp_enqueue_script( 'brs-chartjs', BRS_URL . 'assets/vendor/chart.umd.min.js', array(), '4.5.1', true );
		wp_enqueue_script( 'brs-html2canvas', BRS_URL . 'assets/vendor/html2canvas.min.js', array(), '1.4.1', true );
		wp_enqueue_script( 'brs-jspdf', BRS_URL . 'assets/vendor/jspdf.umd.min.js', array(), '4.2.1', true );
		wp_enqueue_script( 'brs-reports', BRS_URL . 'assets/admin-reports.js', array( 'brs-chartjs', 'brs-html2canvas', 'brs-jspdf' ), BRS_VERSION, true );
		wp_add_inline_script( 'brs-reports', 'window.BRS_REPORT_DATA = ' . wp_json_encode( $data ) . ';', 'before' );
		?>
		<div class="wrap brs-reports-wrap">
			<h1 class="wp-heading-inline">Response Overview</h1>

			<?php if ( $data['totalResponses'] > 0 ) : ?>
				<button type="button" id="brs-report-download" class="page-title-action">Download PDF</button>
			<?php endif; ?>

			<p class="description">
				A live summary across all <?php echo esc_html( number_format_i18n( $data['totalResponses'] ) ); ?> submission(s) -
				identifying fields (name, email, phone, school name) are never included here; view an individual
				submission under <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'brs-submissions' ), admin_url( 'admin.php' ) ) ); ?>">Submissions</a> for those.
			</p>

			<?php if ( 0 === $data['totalResponses'] ) : ?>
				<p>No submissions yet. Once the survey page is live, this overview will populate automatically.</p>
				<?php return; ?>
			<?php endif; ?>

			<div class="brs-report-cards">
				<div class="brs-report-card">
					<span class="brs-report-card-value"><?php echo esc_html( number_format_i18n( $data['totalResponses'] ) ); ?></span>
					<span class="brs-report-card-label">Total responses</span>
				</div>
				<div class="brs-report-card">
					<span class="brs-report-card-value"><?php echo esc_html( null !== $data['avgScore'] ? number_format_i18n( $data['avgScore'], 1 ) : '—' ); ?></span>
					<span class="brs-report-card-label">Average score</span>
				</div>
				<div class="brs-report-card">
					<span class="brs-report-card-value"><?php echo esc_html( number_format_i18n( $data['scoredCount'] ) ); ?></span>
					<span class="brs-report-card-label">Scored responses</span>
				</div>
			</div>

			<div class="brs-report-grid brs-report-grid--top">
				<div class="brs-report-panel">
					<h2>Maturity level distribution</h2>
					<div class="brs-report-chart-wrap"><canvas data-brs-chart="levelDistribution"></canvas></div>
				</div>
				<div class="brs-report-panel">
					<h2>Average score by domain</h2>
					<div class="brs-report-chart-wrap"><canvas data-brs-chart="sectionScores"></canvas></div>
				</div>
			</div>

			<nav class="brs-report-nav">
				<?php foreach ( $data['sections'] as $section ) : ?>
					<a href="#brs-report-section-<?php echo esc_attr( $section['number'] ); ?>">
						<?php echo esc_html( $section['number'] . '. ' . $section['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php foreach ( $data['sections'] as $section ) : ?>
				<section id="brs-report-section-<?php echo esc_attr( $section['number'] ); ?>" class="brs-report-section">
					<h2><?php echo esc_html( 'Section ' . $section['number'] . ' - ' . $section['title'] ); ?></h2>

					<?php foreach ( $section['questions'] as $q ) : ?>
						<div class="brs-report-panel">
							<h3><?php echo esc_html( $q['number'] . '. ' . $q['text'] ); ?></h3>
							<p class="brs-report-answered">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number answered, 2: total responses */
										'%1$s of %2$s responded',
										number_format_i18n( $q['answered'] ),
										number_format_i18n( $data['totalResponses'] )
									)
								);
								?>
							</p>

							<?php if ( 'choice' === $q['kind'] ) : ?>
								<div class="brs-report-chart-wrap"><canvas data-brs-chart="question" data-brs-question="<?php echo esc_attr( $q['id'] ); ?>"></canvas></div>
							<?php elseif ( 'likert' === $q['kind'] ) : ?>
								<div class="brs-report-chart-wrap brs-report-chart-wrap--tall"><canvas data-brs-chart="question" data-brs-question="<?php echo esc_attr( $q['id'] ); ?>"></canvas></div>
							<?php elseif ( 'rating' === $q['kind'] ) : ?>
								<p class="brs-report-avg">
									Average: <strong><?php echo esc_html( $q['answered'] > 0 ? number_format_i18n( $q['sum'] / $q['answered'], 1 ) : '—' ); ?></strong> / <?php echo esc_html( $q['stars'] ); ?>
								</p>
								<div class="brs-report-chart-wrap"><canvas data-brs-chart="question" data-brs-question="<?php echo esc_attr( $q['id'] ); ?>"></canvas></div>
							<?php elseif ( 'text' === $q['kind'] ) : ?>
								<?php if ( empty( $q['responses'] ) ) : ?>
									<p class="brs-report-empty">No responses yet.</p>
								<?php else : ?>
									<ul class="brs-report-text-list">
										<?php foreach ( $q['responses'] as $response ) : ?>
											<li><?php echo nl2br( esc_html( $response ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One pass over every submission, building per-question tallies plus the
	 * two overview charts (maturity level distribution, per-domain average).
	 * Small enough a scale (tens to low hundreds of submissions) that a
	 * single in-memory pass is simpler and fast enough, vs. SQL aggregation
	 * over a JSON blob column.
	 */
	private static function aggregate() {
		$rows  = BRS_DB::get_all();
		$total = count( $rows );

		$level_counts = array();
		$section_pct  = array();
		$score_sum    = 0.0;
		$score_n      = 0;

		$agg = array();
		foreach ( BRS_Config::questions() as $q ) {
			if ( in_array( $q['id'], self::PII_QUESTION_IDS, true ) ) {
				continue;
			}
			$agg[ $q['id'] ] = self::init_question( $q );
		}

		foreach ( $rows as $row ) {
			$answers = json_decode( $row->answers, true );
			if ( ! is_array( $answers ) ) {
				$answers = array();
			}

			if ( null !== $row->total_score ) {
				$score_sum += (float) $row->total_score;
				++$score_n;

				$scored = $row->category_scores ? json_decode( $row->category_scores, true ) : null;

				if ( is_array( $scored ) ) {
					if ( isset( $scored['band']['level'] ) ) {
						$lvl                   = (int) $scored['band']['level'];
						$level_counts[ $lvl ]  = ( isset( $level_counts[ $lvl ] ) ? $level_counts[ $lvl ] : 0 ) + 1;
					}

					if ( ! empty( $scored['sections'] ) && is_array( $scored['sections'] ) ) {
						foreach ( $scored['sections'] as $name => $s ) {
							if ( ! isset( $section_pct[ $name ] ) ) {
								$section_pct[ $name ] = array( 'sum' => 0, 'n' => 0 );
							}
							$section_pct[ $name ]['sum'] += isset( $s['pct'] ) ? (float) $s['pct'] : 0;
							++$section_pct[ $name ]['n'];
						}
					}
				}
			}

			foreach ( $agg as $id => &$q_agg ) {
				self::tally( $q_agg, isset( $answers[ $id ] ) ? $answers[ $id ] : null );
			}
			unset( $q_agg );
		}

		// "Not answered" is derived, not tallied directly - it's always
		// (total responses - however many gave this question a value),
		// which also correctly covers conditionally-hidden questions
		// (q8/q9 by country, etc.) without any special-casing above.
		foreach ( $agg as &$q_agg ) {
			$q_agg['notAnswered'] = max( 0, $total - $q_agg['answered'] );

			if ( 'likert' === $q_agg['kind'] ) {
				foreach ( $q_agg['rows'] as &$row_agg ) {
					$row_agg['notAnswered'] = max( 0, $total - $row_agg['answered'] );
				}
				unset( $row_agg );
			}
		}
		unset( $q_agg );

		$section_scores = array();
		foreach ( $section_pct as $name => $s ) {
			$section_scores[] = array(
				'name'   => $name,
				'avgPct' => $s['n'] > 0 ? round( $s['sum'] / $s['n'], 1 ) : 0,
			);
		}

		$level_distribution = array();
		foreach ( BRS_Scoring::model()['maturityLevels'] as $level ) {
			$level_distribution[] = array(
				'level' => $level['level'],
				'label' => $level['label'],
				'count' => isset( $level_counts[ $level['level'] ] ) ? $level_counts[ $level['level'] ] : 0,
			);
		}

		// Re-walk in survey section order (the loop above is keyed by
		// question id, order not guaranteed) and drop any section left with
		// no non-PII questions at all.
		$sections = array();
		foreach ( BRS_Config::sections() as $section ) {
			$qs = array();
			foreach ( $section['questions'] as $q ) {
				if ( isset( $agg[ $q['id'] ] ) ) {
					$qs[] = $agg[ $q['id'] ];
				}
			}

			if ( empty( $qs ) ) {
				continue;
			}

			$sections[] = array(
				'number'    => $section['number'],
				'title'     => $section['title'],
				'questions' => $qs,
			);
		}

		return array(
			'totalResponses'    => $total,
			'avgScore'          => $score_n > 0 ? round( $score_sum / $score_n, 1 ) : null,
			'scoredCount'       => $score_n,
			'levelDistribution' => $level_distribution,
			'sectionScores'     => $section_scores,
			'sections'          => $sections,
		);
	}

	private static function init_question( $q ) {
		$base = array(
			'id'       => $q['id'],
			'number'   => $q['number'],
			'text'     => wp_strip_all_tags( $q['text'] ),
			'answered' => 0,
		);

		if ( in_array( $q['type'], array( 'single', 'multiple' ), true ) ) {
			$options = $q['options'];

			if ( ! empty( $q['allowOther'] ) ) {
				$options[] = 'Other';
			}

			$counts = array();
			foreach ( $options as $opt ) {
				$counts[ $opt ] = 0;
			}

			$base['kind']   = 'choice';
			$base['counts'] = $counts;
		} elseif ( 'likert' === $q['type'] ) {
			$rows = array();

			foreach ( $q['rows'] as $label ) {
				$counts = array();
				foreach ( $q['columns'] as $col ) {
					$counts[ $col ] = 0;
				}
				$rows[] = array(
					'label'    => $label,
					'counts'   => $counts,
					'answered' => 0,
				);
			}

			$base['kind']    = 'likert';
			$base['columns'] = $q['columns'];
			$base['rows']    = $rows;
		} elseif ( 'rating' === $q['type'] ) {
			$stars = isset( $q['stars'] ) ? (int) $q['stars'] : 5;

			$base['kind']   = 'rating';
			$base['stars']  = $stars;
			$base['counts'] = array_fill( 1, $stars, 0 );
			$base['sum']    = 0;
		} else { // 'text' / 'textarea', minus the PII ids excluded before this is ever called.
			$base['kind']      = 'text';
			$base['responses'] = array();
		}

		return $base;
	}

	private static function tally( &$agg, $value ) {
		if ( null === $value || '' === $value || ( is_array( $value ) && ! count( $value ) ) ) {
			return;
		}

		++$agg['answered'];

		if ( 'choice' === $agg['kind'] ) {
			$picked = is_array( $value ) ? $value : array( $value );

			foreach ( $picked as $opt ) {
				if ( isset( $agg['counts'][ $opt ] ) ) {
					++$agg['counts'][ $opt ];
				}
			}
		} elseif ( 'likert' === $agg['kind'] ) {
			if ( ! is_array( $value ) ) {
				return;
			}

			foreach ( $value as $row_index => $column ) {
				$i = (int) $row_index;

				if ( isset( $agg['rows'][ $i ]['counts'][ $column ] ) ) {
					++$agg['rows'][ $i ]['counts'][ $column ];
					++$agg['rows'][ $i ]['answered'];
				}
			}
		} elseif ( 'rating' === $agg['kind'] ) {
			$v = (int) $value;

			if ( isset( $agg['counts'][ $v ] ) ) {
				++$agg['counts'][ $v ];
				$agg['sum'] += $v;
			}
		} elseif ( 'text' === $agg['kind'] ) {
			$agg['responses'][] = is_string( $value ) ? $value : wp_json_encode( $value );
		}
	}
}
