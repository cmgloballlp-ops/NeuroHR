<?php
/**
 * Sections: separate areas of the hub, each with its own address, home page and topics.
 * Managed in Settings → Sections (option nh_hub_sections):
 *   name, address (base), Resource Types it contains (by type ID, so types can be renamed),
 *   topic group (HR Topics, Case Topics, a group of its own or none), page heading texts,
 *   order and visibility. New sections and topic groups can be added there.
 * Built-in keys: knowledge, cases, faq, templates, videos (they can be renamed, not deleted).
 * "all" is a virtual section for searching everything (/{knowledge base}/search/?q=…).
 * A resource belongs to the section of its Resource Type; a type belongs to one section.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Sections {

	const QUERY_VAR     = 'nh_section';
	const OPTION        = 'nh_hub_sections';
	const OPTION_GROUPS = 'nh_hub_topic_groups'; // Own topic groups: taxonomy => name.
	const OPTION_OLD    = 'nh_hub_old_bases';    // Old address => section key (301).

	private static $cache = null;

	/** Built-in sections as they were before 0.12 (also the defaults). */
	public static function builtins() {
		return array(
			'knowledge' => array( 'label' => __( 'Knowledge Hub', 'neurohr-hub' ), 'base' => 'knowledge', 'kind' => 'article', 'taxonomy' => NH_Post_Types::TAX_TOPIC ),
			'cases'     => array( 'label' => __( 'Legal Case Insights', 'neurohr-hub' ), 'base' => 'legal-case-insights', 'kind' => 'case-study', 'taxonomy' => NH_Post_Types::TAX_CASE_TOPIC ),
			'faq'       => array( 'label' => __( 'FAQ', 'neurohr-hub' ), 'base' => 'faq', 'kind' => 'faq', 'taxonomy' => '' ),
			'templates' => array( 'label' => __( 'Templates', 'neurohr-hub' ), 'base' => 'templates', 'kind' => 'download', 'taxonomy' => NH_Post_Types::TAX_TOPIC ),
			'videos'    => array( 'label' => __( 'Videos', 'neurohr-hub' ), 'base' => 'videos', 'kind' => 'video', 'taxonomy' => NH_Post_Types::TAX_TOPIC ),
		);
	}

	/** Heading texts of a built-in section, from the settings of 0.11 and before. */
	private static function legacy_hero( $key ) {
		$s = NH_Display::get();
		$t = array(
			'knowledge' => array( $s['eyebrow'], $s['title'], $s['intro'], (int) $s['image_id'] ),
			'cases'     => array( $s['eyebrow'], $s['cases_title'], $s['cases_intro'], 0 ),
			'faq'       => array( '', $s['faq_title'], $s['faq_intro'], 0 ),
			'templates' => array( '', $s['templates_title'], $s['templates_intro'], 0 ),
			'videos'    => array( '', $s['videos_title'], $s['videos_intro'], 0 ),
		);
		$h = isset( $t[ $key ] ) ? $t[ $key ] : array( '', '', '', 0 );
		return array( 'eyebrow' => $h[0], 'title' => $h[1], 'intro' => $h[2], 'image_id' => $h[3] );
	}

	/** Stored sections, or the built-ins when nothing was saved yet. */
	public static function stored() {
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) && $saved ) {
			return $saved;
		}
		$out = array();
		foreach ( self::builtins() as $key => $b ) {
			$ids         = NH_Types::ids_of_kind( $b['kind'] );
			$out[ $key ] = array_merge(
				array(
					'label'    => $b['label'],
					'base'     => $b['base'],
					'types'    => $ids ? array( $ids[0] ) : array(),
					'taxonomy' => $b['taxonomy'],
					'visible'  => 1,
					'builtin'  => 1,
				),
				self::legacy_hero( $key )
			);
		}
		return $out;
	}

	/**
	 * Every section: key => label, base, type_ids, types (current slugs), taxonomy, item,
	 * eyebrow, title, intro, image_id, visible, builtin. In display order.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out = array();
		foreach ( self::stored() as $key => $s ) {
			$ids   = array_values( array_filter( array_map( 'intval', isset( $s['types'] ) ? (array) $s['types'] : array() ) ) );
			$slugs = array();
			foreach ( $ids as $id ) {
				$term = get_term( $id, NH_Post_Types::TAX_TYPE );
				if ( $term && ! is_wp_error( $term ) ) {
					$slugs[] = $term->slug;
				}
			}
			$label       = isset( $s['label'] ) ? (string) $s['label'] : $key;
			$out[ $key ] = array(
				'label'    => $label,
				'base'     => isset( $s['base'] ) && '' !== $s['base'] ? (string) $s['base'] : $key,
				'type_ids' => $ids,
				'types'    => $slugs,
				'taxonomy' => isset( $s['taxonomy'] ) && isset( self::topic_taxonomies()[ (string) $s['taxonomy'] ] ) ? (string) $s['taxonomy'] : '',
				'item'     => function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label ),
				'eyebrow'  => isset( $s['eyebrow'] ) ? (string) $s['eyebrow'] : '',
				'title'    => isset( $s['title'] ) && '' !== $s['title'] ? (string) $s['title'] : $label,
				'intro'    => isset( $s['intro'] ) ? (string) $s['intro'] : '',
				'image_id' => isset( $s['image_id'] ) ? (int) $s['image_id'] : 0,
				'visible'  => ! isset( $s['visible'] ) || ! empty( $s['visible'] ),
				'builtin'  => ! empty( $s['builtin'] ) || isset( self::builtins()[ $key ] ),
			);
		}
		self::$cache = $out;
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}

	/** Sections shown to readers. */
	public static function visible() {
		return array_filter( self::all(), function ( $s ) { return $s['visible']; } );
	}

	/** Search across every section (results at /{knowledge base}/search/). Not listed in all(). */
	public static function all_sections() {
		$types = array();
		$ids   = array();
		foreach ( self::visible() as $section ) {
			$types = array_merge( $types, $section['types'] );
			$ids   = array_merge( $ids, $section['type_ids'] );
		}
		$home = self::all();
		$base = isset( $home['knowledge'] ) ? $home['knowledge']['base'] : NH_Post_Types::BASE;
		return array(
			'label'    => isset( $home['knowledge'] ) ? $home['knowledge']['label'] : __( 'Knowledge Hub', 'neurohr-hub' ),
			'base'     => $base . '/search',
			'type_ids' => $ids,
			'types'    => $types,
			'taxonomy' => '',
			'item'     => __( 'results', 'neurohr-hub' ),
			'eyebrow'  => '',
			'title'    => '',
			'intro'    => '',
			'image_id' => 0,
			'visible'  => true,
			'builtin'  => true,
		);
	}

	public static function get( $key ) {
		if ( 'all' === $key ) {
			return self::all_sections();
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Section key of a type ID ('' when the type is in no section). */
	public static function for_type_id( $type_id ) {
		foreach ( self::all() as $key => $section ) {
			if ( in_array( (int) $type_id, $section['type_ids'], true ) ) {
				return $key;
			}
		}
		return '';
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
		$key  = $type ? self::for_type_id( $type->term_id ) : '';
		return '' !== $key ? $key : 'knowledge';
	}

	/** Does the section hold types of this kind (e.g. 'faq', 'download')? */
	public static function has_kind( $key, $kind ) {
		$section = self::get( $key );
		if ( ! $section ) {
			return false;
		}
		foreach ( $section['type_ids'] as $id ) {
			if ( NH_Types::kind( $id ) === $kind ) {
				return true;
			}
		}
		return false;
	}

	/** A FAQ section (only FAQ types) lists its questions instead of topic cards. */
	public static function is_faq( $key ) {
		$section = 'all' === $key ? null : self::get( $key );
		if ( ! $section || ! $section['type_ids'] ) {
			return false;
		}
		foreach ( $section['type_ids'] as $id ) {
			if ( 'faq' !== NH_Types::kind( $id ) ) {
				return false;
			}
		}
		return true;
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

	/* ---------- Topic groups ---------- */

	/** Own topic groups: taxonomy => name. */
	public static function topic_groups() {
		$groups = get_option( self::OPTION_GROUPS, array() );
		return is_array( $groups ) ? $groups : array();
	}

	/** Every taxonomy that can be a section's topics: taxonomy => name. */
	public static function topic_taxonomies() {
		// Also read while plugins load (to hook into each taxonomy): translate only once WordPress is ready.
		$ready = did_action( 'init' );
		return array_merge(
			array(
				NH_Post_Types::TAX_TOPIC      => $ready ? __( 'HR Topics', 'neurohr-hub' ) : 'HR Topics',
				NH_Post_Types::TAX_CASE_TOPIC => $ready ? __( 'Case Topics', 'neurohr-hub' ) : 'Case Topics',
			),
			self::topic_groups()
		);
	}

	/** Register the own topic groups (called before the post type, like the other taxonomies). */
	public static function register_topic_groups() {
		foreach ( self::topic_groups() as $taxonomy => $name ) {
			register_taxonomy(
				$taxonomy,
				NH_Post_Types::CPT,
				array(
					'labels'            => array( 'name' => $name, 'singular_name' => $name, 'menu_name' => $name ),
					'hierarchical'      => true,
					'show_admin_column' => true,
					'show_in_rest'      => true,
					'query_var'         => $taxonomy,
					'rewrite'           => false,
				)
			);
		}
	}

	/* ---------- Front end ---------- */

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
		if ( NH_Post_Types::TAX_CASE_TOPIC === $taxonomy ) {
			return 'case_topic';
		}
		if ( NH_Post_Types::TAX_TOPIC === $taxonomy ) {
			return 'resource_topic';
		}
		return $taxonomy; // Own topic groups use their taxonomy name.
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
					'nh_all_langs'   => true,
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
			'field'    => 'term_id',
			'terms'    => ( $section && $section['type_ids'] ) ? $section['type_ids'] : array( 0 ),
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

	/* ---------- Saving (Settings → Sections) ---------- */

	/** Old address of a section that must keep working: old base => key. */
	public static function old_bases() {
		$old = get_option( self::OPTION_OLD, array() );
		return is_array( $old ) ? $old : array();
	}

	/**
	 * Why an address cannot be used ('' = fine): taken by another section, a page,
	 * or a word WordPress / the hub needs.
	 */
	public static function base_problem( $base, $key ) {
		if ( '' === $base ) {
			return __( 'the address is empty', 'neurohr-hub' );
		}
		$words = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'search', 'tag', 'category', 'author', 'comments', 'embed', 'attachment', 'type', 'topic', 'dashboard', 'my-account', 'login', 'register' );
		if ( in_array( $base, $words, true ) ) {
			/* translators: %s: address */
			return sprintf( __( '"%s" is used by WordPress or the member area', 'neurohr-hub' ), $base );
		}
		if ( NH_Post_Types::BASE === $base && 'knowledge' !== $key ) {
			return __( '"knowledge" is kept for the Knowledge Hub feed and old links', 'neurohr-hub' );
		}
		foreach ( self::all() as $other => $section ) {
			if ( $other !== $key && $section['base'] === $base ) {
				/* translators: %s: section */
				return sprintf( __( 'already the address of %s', 'neurohr-hub' ), $section['label'] );
			}
		}
		$page = get_page_by_path( $base, OBJECT, array( 'page', 'post' ) );
		if ( $page && 'trash' !== $page->post_status ) {
			/* translators: %s: page title */
			return sprintf( __( 'a page "%s" already has this address', 'neurohr-hub' ), get_the_title( $page ) );
		}
		return '';
	}

	/**
	 * Save sections. Returns a list of messages for problems that were not saved.
	 * Keeps a section's old address for redirects, and rebuilds the addresses.
	 */
	public static function save( $sections ) {
		$messages = array();
		$before   = self::all();
		$clean    = array();
		$taken    = array(); // Type ID => section key: a type belongs to one section.
		foreach ( $sections as $key => $s ) {
			$key = sanitize_key( $key );
			if ( '' === $key || 'all' === $key ) {
				continue;
			}
			$label = sanitize_text_field( isset( $s['label'] ) ? $s['label'] : '' );
			$label = '' !== $label ? $label : ( isset( $before[ $key ] ) ? $before[ $key ]['label'] : $key );
			$base  = sanitize_title( isset( $s['base'] ) ? $s['base'] : '' );
			$old   = isset( $before[ $key ] ) ? $before[ $key ]['base'] : '';
			if ( $base !== $old ) {
				self::$cache = null;
				$problem     = self::base_problem( $base, $key );
				foreach ( $clean as $other => $c ) {
					if ( $c['base'] === $base ) {
						$problem = sprintf( /* translators: %s: section */ __( 'already the address of %s', 'neurohr-hub' ), $c['label'] );
					}
				}
				if ( $problem ) {
					/* translators: 1: section, 2: address, 3: reason */
					$messages[] = sprintf( __( '%1$s: address "%2$s" not saved — %3$s.', 'neurohr-hub' ), $label, $base, $problem );
					$base       = '' !== $old ? $old : $key;
				}
			}
			$types = array();
			foreach ( isset( $s['types'] ) ? (array) $s['types'] : array() as $id ) {
				$id = absint( $id );
				if ( $id && term_exists( $id, NH_Post_Types::TAX_TYPE ) && ! isset( $taken[ $id ] ) ) {
					$types[]      = $id;
					$taken[ $id ] = $key;
				}
			}
			$tax           = isset( $s['taxonomy'] ) ? sanitize_key( $s['taxonomy'] ) : '';
			$clean[ $key ] = array(
				'label'    => $label,
				'base'     => $base,
				'types'    => $types,
				'taxonomy' => isset( self::topic_taxonomies()[ $tax ] ) ? $tax : '',
				'eyebrow'  => sanitize_text_field( isset( $s['eyebrow'] ) ? $s['eyebrow'] : '' ),
				'title'    => sanitize_text_field( isset( $s['title'] ) ? $s['title'] : '' ),
				'intro'    => sanitize_textarea_field( isset( $s['intro'] ) ? $s['intro'] : '' ),
				'image_id' => absint( isset( $s['image_id'] ) ? $s['image_id'] : 0 ),
				'visible'  => empty( $s['visible'] ) ? 0 : 1,
				'builtin'  => isset( self::builtins()[ $key ] ) ? 1 : 0,
				'order'    => isset( $s['order'] ) ? (int) $s['order'] : 0,
			);
		}
		// Built-in sections cannot disappear.
		foreach ( $before as $key => $section ) {
			if ( $section['builtin'] && ! isset( $clean[ $key ] ) ) {
				$clean[ $key ] = array_merge( self::stored()[ $key ], array( 'order' => 99 ) );
			}
		}
		uasort( $clean, function ( $a, $b ) { return ( isset( $a['order'] ) ? $a['order'] : 0 ) - ( isset( $b['order'] ) ? $b['order'] : 0 ); } );

		// Old addresses keep working (301 to the new one).
		$old = self::old_bases();
		foreach ( $clean as $key => $c ) {
			if ( isset( $before[ $key ] ) && $before[ $key ]['base'] !== $c['base'] ) {
				$old[ $before[ $key ]['base'] ] = $key;
			}
			unset( $old[ $c['base'] ] ); // An address in use again is no longer "old".
		}
		update_option( self::OPTION_OLD, $old, false );
		update_option( self::OPTION, $clean, true );
		self::$cache = null;
		NH_Frontend::flush_topic_cache();
		NH_Rewrite::schedule_rebuild();
		return $messages;
	}

	/** New topic group (its own list of topics, e.g. for a Guides section). Returns the taxonomy. */
	public static function add_topic_group( $name ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return '';
		}
		$base = substr( sanitize_key( str_replace( '-', '_', sanitize_title( $name ) ) ), 0, 20 );
		$tax  = 'nh_tg_' . ( '' !== $base ? $base : wp_generate_password( 6, false, false ) );
		$i    = 2;
		while ( taxonomy_exists( $tax ) || isset( self::topic_groups()[ $tax ] ) ) {
			$tax = 'nh_tg_' . substr( $base, 0, 17 ) . '_' . $i++;
		}
		$groups         = self::topic_groups();
		$groups[ $tax ] = $name;
		update_option( self::OPTION_GROUPS, $groups, true );
		return $tax;
	}

	/** Remove an own topic group that no section uses and that has no topics. */
	public static function delete_topic_group( $taxonomy ) {
		$groups = self::topic_groups();
		if ( ! isset( $groups[ $taxonomy ] ) || self::for_taxonomy( $taxonomy ) ) {
			return false;
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( is_array( $terms ) && $terms ) {
			return false;
		}
		unset( $groups[ $taxonomy ] );
		update_option( self::OPTION_GROUPS, $groups, true );
		return true;
	}
}
