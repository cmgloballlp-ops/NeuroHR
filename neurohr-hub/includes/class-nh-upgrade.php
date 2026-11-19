<?php
/**
 * The NeuroHR topic plan (used by the BetterDocs import) and version upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Upgrade {

	/* ---------- Topic plan ---------- */

	/** HR topics: Knowledge Hub articles (later also templates and videos). */
	public static function hr_topics() {
		return array(
			'employment-law-compliance'      => 'Employment Law & Compliance',
			'employment-contracts'           => 'Employment Contracts',
			'termination-dismissal'          => 'Termination & Dismissal',
			'discipline-conduct'             => 'Discipline & Conduct',
			'probation-confirmation'         => 'Probation & Confirmation',
			'workplace-safety-health'        => 'Workplace Safety & Health',
			'occupational-health-psychology' => 'Occupational Health Psychology',
			'talent-acquisition'             => 'Talent Acquisition',
			'performance-growth'             => 'Performance & Growth',
			'learning-capability-building'   => 'Learning & Capability Building',
			'total-rewards'                  => 'Total Rewards',
			'workforce-talent-management'    => 'Workforce & Talent Management',
			'culture-engagement'             => 'Culture & Engagement',
			'hr-strategy-function'           => 'HR Strategy & Function',
			'hr-tech-analytics'              => 'HR Tech & Analytics',
		);
	}

	/** Old BetterDocs HR category => HR topic. */
	public static function hr_category_map() {
		return array(
			'employment-law-compliance'      => 'employment-law-compliance',
			'occupational-health-psychology' => 'occupational-health-psychology',
			'talent-acquisition'             => 'talent-acquisition',
			'performance-growth'             => 'performance-growth',
			'learning-capability-building'   => 'learning-capability-building',
			'total-rewards'                  => 'total-rewards',
			'hr-tech-analytics'              => 'hr-tech-analytics',
			'hr-competencies'                => 'hr-strategy-function',
			'hr-for-smes'                    => 'hr-strategy-function',
			'people-talent-management'       => 'workforce-talent-management',
			'culture-change-engagement'      => 'culture-engagement',
			'workplace-culture-conduct'      => 'culture-engagement',
		);
	}

	/** Articles that move to a different HR topic than their old category suggests. */
	public static function post_overrides() {
		return array(
			'employment-contract-stamping'                                          => 'employment-contracts',
			'stamping-of-employment-contracts-hr-documents-in-malaysia'             => 'employment-contracts',
			'stamp-duty-for-hr-documents-in-malaysia-what-employers-must-get-right' => 'employment-contracts',
			'key-changes-to-the-occupational-safety-and-health-act-1994'            => 'workplace-safety-health',
			'constructive-dismissal-what-employers-and-employees-need-to-know'      => 'termination-dismissal',
			'performance-management-for-probation-employees'                        => 'probation-confirmation',
			'how-to-create-a-code-of-conduct-a-practical-guide-for-smes'            => 'discipline-conduct',
			'handling-employee-misconduct-legally-a-guide-for-smes'                 => 'discipline-conduct',
		);
	}

	/** Case topics: the original BetterDocs legal categories, with short slugs. In display order. */
	public static function case_topics() {
		return array(
			'dismissal-cases'                 => 'Dismissal Cases',
			'misconduct-disciplinary-actions' => 'Misconduct & Disciplinary Actions',
			'probation-confirmation'          => 'Probation & Confirmation',
			'employment-contract-disputes'    => 'Employment Contract Disputes',
			'discrimination-harassment'       => 'Discrimination & Harassment',
			'human-rights'                    => 'Human Rights',
			'workplace-safety-forced-labour'  => 'Workplace Safety & Forced Labour',
		);
	}

	/** Old BetterDocs legal category => case topic. */
	public static function case_category_map() {
		return array(
			'legal-case-dismissal-cases'                 => 'dismissal-cases',
			'cs-human-right'                             => 'human-rights',
			'legal-case-discrimination-harassment'       => 'discrimination-harassment',
			'legal-case-employment-contract-disputes'    => 'employment-contract-disputes',
			'legal-case-misconduct-disciplinary-actions' => 'misconduct-disciplinary-actions',
			'legal-case-probation-confirmation'          => 'probation-confirmation',
			'legal-case-workplace-safety-forced-labour'  => 'workplace-safety-forced-labour',
		);
	}

	/** Create the case topics (with display order) if missing. Returns slug => term ID. */
	public static function ensure_case_topics( $descriptions = array() ) {
		$ids   = array();
		$order = 0;
		foreach ( self::case_topics() as $slug => $name ) {
			$order += 10;
			$term   = get_term_by( 'slug', $slug, NH_Post_Types::TAX_CASE_TOPIC );
			if ( $term ) {
				$ids[ $slug ] = (int) $term->term_id;
				continue;
			}
			$created = wp_insert_term( $name, NH_Post_Types::TAX_CASE_TOPIC, array( 'slug' => $slug, 'description' => isset( $descriptions[ $slug ] ) ? $descriptions[ $slug ] : '' ) );
			if ( ! is_wp_error( $created ) ) {
				$ids[ $slug ] = (int) $created['term_id'];
				update_term_meta( $ids[ $slug ], 'nh_order', $order );
			}
		}
		return $ids;
	}

	/* ---------- Upgrades ---------- */

	public static function run( $from ) {
		if ( '' !== $from && version_compare( $from, '0.4.0', '<' ) ) {
			self::to_040();
		}
		if ( '' !== $from && version_compare( $from, '0.12.0', '<' ) ) {
			self::to_0120();
		}
		if ( '' !== $from && version_compare( $from, '0.11.0', '<' ) ) {
			self::to_0110();
		}
		if ( '' !== $from && version_compare( $from, '0.10.0', '<' ) ) {
			self::to_0100();
			self::index_refs();
		}
		// Every install: the protected folder for template files.
		NH_Media::ensure_dir();
	}

	/**
	 * 0.10.0: level 0 now means "Everyone, no login". Before, while the hub needed a login,
	 * a stored 0 counted as Free — keep those resources Free so nothing becomes public.
	 */
	private static function to_0100() {
		if ( ! NH_Login::required() ) {
			return; // 0 already meant public.
		}
		$ids = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => NH_Access::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				'nh_all_langs'   => true,
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( $id, NH_Access::META, NH_Access::base_level() );
		}
	}

	/**
	 * 0.12.0: types are known by ID and behaviour; sections become editable.
	 * - Each type gets its behaviour (also a type whose name/slug was changed, e.g. HR Insights).
	 * - Type default levels move from slug keys to type IDs.
	 * - Sections are saved with their types; a built-in section whose type was renamed takes
	 *   the type's name and address (HR Insights → /hr-insights/). Old addresses redirect.
	 */
	public static function to_0120() {
		$defaults = array( 'article' => 'Article', 'case-study' => 'Case Study', 'faq' => 'FAQ', 'video' => 'Video', 'download' => 'Download' );
		$by_kind  = array();
		foreach ( array_keys( NH_Types::kinds() ) as $kind ) {
			$by_kind[ $kind ] = NH_Types::find_or_create( $kind );
		}

		// Type levels: slug => level  becomes  ID => level.
		$levels = get_option( NH_Access::OPTION_TYPES, null );
		if ( is_array( $levels ) ) {
			$new = array();
			foreach ( $levels as $key => $level ) {
				if ( is_numeric( $key ) ) {
					$new[ (int) $key ] = (int) $level;
					continue;
				}
				$term = get_term_by( 'slug', $key, NH_Post_Types::TAX_TYPE );
				$id   = $term ? (int) $term->term_id : ( isset( $by_kind[ $key ] ) ? (int) $by_kind[ $key ] : 0 );
				if ( $id ) {
					$new[ $id ] = (int) $level;
				}
			}
			update_option( NH_Access::OPTION_TYPES, $new );
		}

		if ( is_array( get_option( NH_Sections::OPTION ) ) ) {
			return; // Already set up.
		}
		$sections = NH_Sections::stored(); // Built-ins with their types and heading texts.
		$labels   = NH_Sections::builtins();
		$order    = 0;
		foreach ( $sections as $key => $section ) {
			$sections[ $key ]['order'] = $order++;
			$kind                      = $labels[ $key ]['kind'];
			$term                      = $by_kind[ $kind ] ? get_term( $by_kind[ $kind ], NH_Post_Types::TAX_TYPE ) : null;
			if ( ! $term || is_wp_error( $term ) || $term->name === $defaults[ $kind ] ) {
				continue;
			}
			// The type was renamed: the section follows (name and address).
			$old_label                 = $sections[ $key ]['label'];
			$sections[ $key ]['label'] = $term->name;
			$sections[ $key ]['base']  = $term->slug !== $kind ? $term->slug : sanitize_title( $term->name );
			if ( $sections[ $key ]['title'] === $old_label ) {
				$sections[ $key ]['title'] = $term->name;
			}
		}
		// Save as if done in Settings → Sections: addresses are checked, old ones redirect.
		update_option( NH_Sections::OPTION, NH_Sections::stored(), true ); // Current state first, so old addresses are known.
		NH_Sections::flush();
		$messages = NH_Sections::save( $sections );
		if ( $messages ) {
			update_option( 'nh_hub_section_notices', $messages, false );
		}
	}

	/** 0.11.0: everything written before languages existed is English. */
	private static function to_0110() {
		$ids = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'nh_all_langs'   => true,
				'meta_query'     => array( array( 'key' => NH_Lang::META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( $id, NH_Lang::META, 'en' );
		}
	}

	/** 0.10.0: index existing references so "Referenced in" works for old content too. */
	private static function index_refs() {
		$ids = get_posts( array( 'post_type' => NH_Post_Types::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'nh_all_langs' => true ) );
		foreach ( $ids as $id ) {
			NH_Related::sync_refs( $id );
		}
	}

	/**
	 * 0.4.0: sections. Case studies move from shared HR topics to Case Topics;
	 * News and Regulation types are dropped (they are WordPress posts now).
	 */
	private static function to_040() {
		$case_ids = self::ensure_case_topics();

		// HR topic a case had in 0.3 => case topic.
		$hr_to_case = array(
			'termination-dismissal'                  => 'dismissal-cases',
			'discrimination-harassment-human-rights' => 'human-rights',
			'discipline-conduct'                     => 'misconduct-disciplinary-actions',
			'probation-confirmation'                 => 'probation-confirmation',
			'employment-contracts'                   => 'employment-contract-disputes',
			'workplace-safety-health'                => 'workplace-safety-forced-labour',
		);

		$cases = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'field' => 'slug', 'terms' => 'case-study' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time upgrade.
			)
		);
		foreach ( $cases as $case_id ) {
			$hr = wp_get_object_terms( $case_id, NH_Post_Types::TAX_TOPIC, array( 'fields' => 'slugs' ) );
			$hr = is_wp_error( $hr ) ? array() : $hr;
			if ( ! wp_get_object_terms( $case_id, NH_Post_Types::TAX_CASE_TOPIC, array( 'fields' => 'ids' ) ) ) {
				$new = array();
				foreach ( $hr as $slug ) {
					if ( isset( $hr_to_case[ $slug ], $case_ids[ $hr_to_case[ $slug ] ] ) ) {
						$new[] = $case_ids[ $hr_to_case[ $slug ] ];
					}
				}
				if ( $new ) {
					wp_set_object_terms( $case_id, $new, NH_Post_Types::TAX_CASE_TOPIC );
				}
			}
			wp_set_object_terms( $case_id, array(), NH_Post_Types::TAX_TOPIC );
		}

		// HR topic that only ever held cases.
		$orphan = get_term_by( 'slug', 'discrimination-harassment-human-rights', NH_Post_Types::TAX_TOPIC );
		if ( $orphan && 0 === (int) $orphan->count ) {
			wp_delete_term( $orphan->term_id, NH_Post_Types::TAX_TOPIC );
		}

		foreach ( array( 'news', 'regulation' ) as $slug ) {
			$type = get_term_by( 'slug', $slug, NH_Post_Types::TAX_TYPE );
			if ( $type && 0 === (int) $type->count ) {
				wp_delete_term( $type->term_id, NH_Post_Types::TAX_TYPE );
			}
		}

		NH_Frontend::flush_topic_cache();
	}
}
