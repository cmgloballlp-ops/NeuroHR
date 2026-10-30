<?php
/**
 * Sections: separate areas of the hub, each with its own address, home page and topics.
 *
 *   knowledge  /knowledge/            Articles             HR topics
 *   cases      /legal-case-insights/  Case studies         Case topics (legal categories)
 *   faq        /faq/                  FAQs                 (no topics)
 *   templates  /templates/            Downloads            HR topics (shared with articles)
 *   videos     /videos/               Videos               HR topics
 *
 * "all" is a virtual section for searching everything (/knowledge/search/?q=…), used by the member dashboard.
 * A resource belongs to the section of its Resource Type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Sections {

	const QUERY_VAR = 'nh_section';

	public static function all() {
		return array(
			'knowledge' => array(
				'label'    => __( 'Knowledge Hub', 'neurohr-hub' ),
				'base'     => 'knowledge',
				'types'    => array( 'article' ),
				'taxonomy' => NH_Post_Types::TAX_TOPIC,
				'item'     => __( 'articles', 'neurohr-hub' ),
			),
			'cases'     => array(
				'label'    => __( 'Legal Case Insights', 'neurohr-hub' ),
				'base'     => 'legal-case-insights',
				'types'    => array( 'case-study' ),
				'taxonomy' => NH_Post_Types::TAX_CASE_TOPIC,
				'item'     => __( 'cases', 'neurohr-hub' ),
			),
			'faq'       => array(
				'label'    => __( 'FAQ', 'neurohr-hub' ),
				'base'     => 'faq',
				'types'    => array( 'faq' ),
				'taxonomy' => '',
				'item'     => __( 'questions', 'neurohr-hub' ),
			),
			'templates' => array(
				'label'    => __( 'Templates', 'neurohr-hub' ),
				'base'     => 'templates',
				'types'    => array( 'download' ),
				'taxonomy' => NH_Post_Types::TAX_TOPIC,
				'item'     => __( 'templates', 'neurohr-hub' ),
			),
			'videos'    => array(
				'label'    => __( 'Videos', 'neurohr-hub' ),
				'base'     => 'videos',
				'types'    => array( 'video' ),
				'taxonomy' => NH_Post_Types::TAX_TOPIC,
				'item'     => __( 'videos', 'neurohr-hub' ),
			),
		);
	}

	/** Search across every section (results at /knowledge/search/). Not listed in all(). */
	public static function all_sections() {
		$types = array();
		foreach ( self::all() as $section ) {
			$types = array_merge( $types, $section['types'] );
		}
		return array(
			'label'    => __( 'Knowledge Hub', 'neurohr-hub' ),
			'base'     => NH_Post_Types::BASE . '/search',
			'types'    => $types,
			'taxonomy' => '',
			'item'     => __( 'results', 'neurohr-hub' ),
		);
	}

	public static function get( $key ) {
		if ( 'all' === $key ) {
			return self::all_sections();
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Section key for a Resource Type slug ('knowledge' when unknown). */
	public static function for_type( $type_slug ) {
		foreach ( self::all() as $key => $section ) {
			if ( in_array( $type_slug, $section['types'], true ) ) {
				return $key;
			}
		}
		return 'knowledge';
	}

	public static function of_post( $post ) {
		$type = nh_hub_get_type( $post );
		return self::for_type( $type ? $type->slug : '' );
	}

	/** Section key whose topic taxonomy is $taxonomy (first match). */
	public static function for_taxonomy( $taxonomy ) {
		foreach ( self::all() as $key => $section ) {
			if ( $section['taxonomy'] === $taxonomy ) {
				return $key;
			}
		}
		return '';
	}

	/** Section being viewed on the front end ('' when not on a section page). */
	public static function current() {
		$key = get_query_var( self::QUERY_VAR );
		if ( is_string( $key ) && self::get( $key ) ) {
			return $key;
		}
		if ( is_singular( NH_Post_Types::CPT ) ) {
			return self::of_post( get_queried_object() );
		}
		return '';
	}

	/** Topic term being viewed in the current section, or null. */
	public static function current_topic( $key ) {
		$section = self::get( $key );
		if ( ! $section || ! $section['taxonomy'] ) {
			return null;
		}
		$slug = get_query_var( self::topic_var( $section['taxonomy'] ) );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', sanitize_title( $slug ), $section['taxonomy'] );
		return $term ? $term : null;
	}

	public static function topic_var( $taxonomy ) {
		return NH_Post_Types::TAX_CASE_TOPIC === $taxonomy ? 'case_topic' : 'resource_topic';
	}

	/**
	 * Topics of a section that have published content of the section's types,
	 * ordered by their "Order" field, then name. Cached; cleared on content/topic changes.
	 */
	public static function topics( $key ) {
		$section = self::get( $key );
		if ( ! $section || ! $section['taxonomy'] ) {
			return array();
		}
		$cache_key = 'nh_section_topics_' . $key;
		$ids       = get_transient( $cache_key );
		if ( ! is_array( $ids ) ) {
			$post_ids = get_posts(
				array(
					'post_type'      => NH_Post_Types::CPT,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( self::type_clause( $key ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- cached.
				)
			);
			$terms = $post_ids ? wp_get_object_terms( $post_ids, $section['taxonomy'], array( 'fields' => 'ids' ) ) : array();
			$ids   = is_wp_error( $terms ) ? array() : array_map( 'intval', array_unique( $terms ) );
			set_transient( $cache_key, $ids, DAY_IN_SECONDS );
			NH_Frontend::remember_cache_key( $cache_key );
		}
		if ( ! $ids ) {
			return array();
		}
		$topics = get_terms( array( 'taxonomy' => $section['taxonomy'], 'include' => $ids, 'hide_empty' => false ) );
		if ( is_wp_error( $topics ) ) {
			return array();
		}
		usort(
			$topics,
			function ( $a, $b ) {
				$x = (int) get_term_meta( $a->term_id, 'nh_order', true );
				$y = (int) get_term_meta( $b->term_id, 'nh_order', true );
				return $x === $y ? strcasecmp( $a->name, $b->name ) : $x - $y;
			}
		);
		return $topics;
	}

	/** tax_query clause limiting a query to a section's resource types. */
	public static function type_clause( $key ) {
		$section = self::get( $key );
		return array(
			'taxonomy' => NH_Post_Types::TAX_TYPE,
			'field'    => 'slug',
			'terms'    => $section ? $section['types'] : array( '__none__' ),
		);
	}

	/** Number of published resources in a section (optionally within one topic). */
	public static function count( $key, $topic = null ) {
		$tax_query = array( self::type_clause( $key ) );
		if ( $topic ) {
			$tax_query[] = array( 'taxonomy' => $topic->taxonomy, 'terms' => $topic->term_id );
		}
		$q = new WP_Query(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) $q->found_posts;
	}
}
