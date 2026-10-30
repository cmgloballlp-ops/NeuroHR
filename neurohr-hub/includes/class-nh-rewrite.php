<?php
/**
 * Addresses for sections, topics and resources.
 *
 *   /{base}/                 section home        e.g. /knowledge/, /legal-case-insights/, /faq/
 *   /{base}/{topic}/         topic page          e.g. /legal-case-insights/dismissal-cases/
 *   /{base}/?q=…             search results
 *   /{base}/{slug}/          resource            (the base follows the resource's type)
 *   /knowledge/tag/{tag}/    tag page (all sections)
 *
 * Topic rules only match real topic slugs (kept in an option), so resources at
 * /{base}/{slug}/ keep working. Old BetterDocs and v0.3 addresses redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Rewrite {

	const OPTION_SLUGS  = 'nh_hub_route_slugs';
	const OPTION_FLUSH  = 'nh_hub_needs_flush';
	const OPTION_LEGACY = 'nh_hub_legacy_topics';

	/** Words a resource slug may never use, because the address would clash with a hub page. */
	const RESERVED = array( 'tag', 'page', 'feed', 'type', 'topic', 'embed', 'search' );

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		// add_rules() is called from NH_Post_Types::register(), before the post type's own rules.
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 100 );

		add_filter( 'post_type_link', array( __CLASS__, 'post_link' ), 10, 2 );
		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 10, 3 );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'avoid_reserved_slug' ), 10, 6 );
		add_action( 'template_redirect', array( __CLASS__, 'redirects' ), 1 );

		foreach ( array( NH_Post_Types::TAX_TYPE, NH_Post_Types::TAX_TOPIC, NH_Post_Types::TAX_CASE_TOPIC ) as $taxonomy ) {
			add_action( "created_{$taxonomy}", array( __CLASS__, 'schedule_rebuild' ) );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'schedule_rebuild' ) );
			add_action( "delete_{$taxonomy}", array( __CLASS__, 'schedule_rebuild' ) );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = NH_Sections::QUERY_VAR;
		return $vars;
	}

	/* ---------- Topic slugs per taxonomy (cached in an option) ---------- */

	public static function slugs() {
		$slugs = get_option( self::OPTION_SLUGS );
		if ( ! is_array( $slugs ) || ! isset( $slugs[ NH_Post_Types::TAX_CASE_TOPIC ] ) ) {
			$slugs = self::rebuild_slugs();
		}
		return $slugs;
	}

	public static function rebuild_slugs() {
		$slugs = array();
		foreach ( array( NH_Post_Types::TAX_TOPIC, NH_Post_Types::TAX_CASE_TOPIC ) as $taxonomy ) {
			$terms              = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs' ) );
			$slugs[ $taxonomy ] = is_wp_error( $terms ) ? array() : array_values( $terms );
		}
		update_option( self::OPTION_SLUGS, $slugs, true );
		return $slugs;
	}

	public static function schedule_rebuild() {
		self::rebuild_slugs();
		update_option( self::OPTION_FLUSH, 1, true );
	}

	/**
	 * Save the rules again after topics changed. The rules of this request were built before
	 * the change, so add them again (with the new topic list) before saving.
	 */
	public static function maybe_flush() {
		if ( get_option( self::OPTION_FLUSH ) ) {
			delete_option( self::OPTION_FLUSH );
			self::add_rules();
			flush_rewrite_rules( false );
		}
	}

	/** Slugs a resource cannot use: fixed words, section bases and every topic slug. */
	public static function reserved_slugs() {
		$reserved = self::RESERVED;
		foreach ( NH_Sections::all() as $section ) {
			$reserved[] = $section['base'];
		}
		foreach ( self::slugs() as $list ) {
			$reserved = array_merge( $reserved, (array) $list );
		}
		return array_values( array_unique( $reserved ) );
	}

	/* ---------- Rules ---------- */

	public static function add_rules() {
		$slugs = self::slugs();
		$pt    = 'post_type=' . NH_Post_Types::CPT;
		$sv    = NH_Sections::QUERY_VAR;

		foreach ( NH_Sections::all() as $key => $section ) {
			$base = preg_quote( $section['base'], '#' );
			$tax  = $section['taxonomy'];

			if ( $tax && ! empty( $slugs[ $tax ] ) ) {
				$topics = implode( '|', array_map( 'preg_quote', $slugs[ $tax ] ) );
				$var    = NH_Sections::topic_var( $tax );
				add_rewrite_rule( "^{$base}/({$topics})/page/([0-9]+)/?$", "index.php?{$pt}&{$sv}={$key}&{$var}=\$matches[1]&paged=\$matches[2]", 'top' );
				add_rewrite_rule( "^{$base}/({$topics})/?$", "index.php?{$pt}&{$sv}={$key}&{$var}=\$matches[1]", 'top' );
			}
			add_rewrite_rule( "^{$base}/page/([0-9]+)/?$", "index.php?{$pt}&{$sv}={$key}&paged=\$matches[1]", 'top' );
			add_rewrite_rule( "^{$base}/?$", "index.php?{$pt}&{$sv}={$key}", 'top' );

			if ( 'knowledge' === $key ) {
				// Search across all sections (member dashboard). Before the topic/resource rules of /knowledge/.
				add_rewrite_rule( '^' . $base . '/search/page/([0-9]+)/?$', "index.php?{$pt}&{$sv}=all&paged=\$matches[1]", 'top' );
				add_rewrite_rule( '^' . $base . '/search/?$', "index.php?{$pt}&{$sv}=all", 'top' );
			}

			// Resources in sections other than /knowledge/ (whose rules WordPress already adds).
			if ( NH_Post_Types::BASE !== $section['base'] ) {
				add_rewrite_rule( "^{$base}/([^/]+)/?$", 'index.php?' . NH_Post_Types::CPT . '=$matches[1]', 'top' );
			}
		}
	}

	/* ---------- Links ---------- */

	/** Address of a section, optionally a topic in it, plus query args (e.g. q). */
	public static function url( $section = 'knowledge', $topic_slug = '', $args = array() ) {
		$data  = NH_Sections::get( $section );
		$base  = $data ? $data['base'] : NH_Post_Types::BASE;
		$path  = $topic_slug ? $base . '/' . $topic_slug : $base;
		$url   = home_url( user_trailingslashit( $path ) );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** /knowledge/{slug}/, /legal-case-insights/{slug}/ or /faq/{slug}/ depending on the type. */
	public static function post_link( $link, $post ) {
		if ( NH_Post_Types::CPT !== $post->post_type || '' === $post->post_name
			|| in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return $link;
		}
		$section = NH_Sections::get( NH_Sections::of_post( $post ) );
		return home_url( user_trailingslashit( $section['base'] . '/' . $post->post_name ) );
	}

	public static function term_link( $link, $term, $taxonomy ) {
		if ( NH_Post_Types::TAX_TOPIC === $taxonomy ) {
			return self::url( 'knowledge', $term->slug );
		}
		if ( NH_Post_Types::TAX_CASE_TOPIC === $taxonomy ) {
			return self::url( 'cases', $term->slug );
		}
		if ( NH_Post_Types::TAX_TYPE === $taxonomy ) {
			return self::url( NH_Sections::for_type( $term->slug ) );
		}
		return $link;
	}

	public static function avoid_reserved_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( NH_Post_Types::CPT !== $post_type || ! in_array( $slug, self::reserved_slugs(), true ) ) {
			return $slug;
		}
		$suffix = 2;
		do {
			$candidate = $slug . '-' . $suffix++;
		} while ( in_array( $candidate, self::reserved_slugs(), true ) || get_page_by_path( $candidate, OBJECT, NH_Post_Types::CPT ) );
		return $candidate;
	}

	/* ---------- Redirects ---------- */

	private static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}

	public static function redirects() {
		if ( 'all' === get_query_var( NH_Sections::QUERY_VAR ) && '' === NH_Frontend::keyword() ) {
			wp_safe_redirect( self::url( 'knowledge' ) );
			exit;
		}

		// A resource opened under the wrong section (e.g. an old /knowledge/{case}/ link): send it home.
		if ( is_singular( NH_Post_Types::CPT ) && ! is_preview() ) {
			$target = get_permalink( get_queried_object() );
			$want   = (string) wp_parse_url( $target, PHP_URL_PATH );
			if ( $want && untrailingslashit( self::request_path() ) !== untrailingslashit( $want ) ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}
			return;
		}

		if ( ! is_404() ) {
			return;
		}

		$target = self::legacy_target( self::request_path() );
		if ( $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/** Old BetterDocs and v0.3 listing addresses => new section/topic address ('' when unknown). */
	public static function legacy_target( $path ) {
		$base = preg_quote( NH_Post_Types::BASE, '#' );
		if ( ! preg_match( '#^/' . $base . '/([^/]+)(?:/([^/]+))?/?$#', $path, $m ) ) {
			return '';
		}
		$first  = sanitize_title( $m[1] );
		$second = isset( $m[2] ) ? sanitize_title( $m[2] ) : '';

		// BetterDocs knowledge bases.
		if ( 'knowledge-hub' === $first || 'legal-case-insights' === $first ) {
			$section = 'knowledge-hub' === $first ? 'knowledge' : 'cases';
			if ( '' === $second ) {
				return self::url( $section );
			}
			if ( 'cases' === $section ) {
				$map = NH_Upgrade::case_category_map();
				$tax = NH_Post_Types::TAX_CASE_TOPIC;
			} else {
				$map = array_merge( NH_Upgrade::hr_category_map(), (array) get_option( self::OPTION_LEGACY, array() ) );
				$tax = NH_Post_Types::TAX_TOPIC;
			}
			$topic = isset( $map[ $second ] ) ? $map[ $second ] : $second;
			return get_term_by( 'slug', $topic, $tax ) ? self::url( $section, $topic ) : self::url( $section );
		}

		// v0.3 type addresses: /knowledge/article/, /knowledge/case-study/…, /knowledge/faq/…
		$types = array( 'article' => 'knowledge', 'case-study' => 'cases', 'faq' => 'faq' );
		if ( isset( $types[ $first ] ) ) {
			$section = $types[ $first ];
			if ( 'knowledge' === $section && $second && get_term_by( 'slug', $second, NH_Post_Types::TAX_TOPIC ) ) {
				return self::url( 'knowledge', $second );
			}
			return self::url( $section );
		}
		return '';
	}
}
