<?php
/**
 * Front end: which template a hub page uses, what it lists, and the stylesheet.
 *
 * Templates (a theme can override each by adding neurohr-hub/{file}):
 *   section-home.php     /{base}/                 hero, live search, topic cards (FAQ: question list)
 *   section-list.php     /{base}/{topic}/, ?q=,   topic page, search results, tag page
 *                        /knowledge/tag/{tag}/
 *   single-resource.php  /{base}/{slug}/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Frontend {

	const PER_PAGE = 20;

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );

		// Cached topic lists / topic cards: clear when content or topics change.
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'flush_topic_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_topic_cache' ) );
		foreach ( array( NH_Post_Types::TAX_TOPIC, NH_Post_Types::TAX_CASE_TOPIC ) as $taxonomy ) {
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'flush_topic_cache' ) );
			add_action( 'delete_' . $taxonomy, array( __CLASS__, 'flush_topic_cache' ) );
		}
	}

	/* ---------- Where are we? ---------- */

	/** A section page: home, topic or search results. */
	public static function is_section_page() {
		$key = get_query_var( NH_Sections::QUERY_VAR );
		return is_string( $key ) && null !== NH_Sections::get( $key );
	}

	public static function is_tag_page() {
		return is_tax( NH_Post_Types::TAX_TAG );
	}

	/** Pages that list resources (used for search logging). */
	public static function is_listing() {
		return ( self::is_section_page() || self::is_tag_page() ) && ! is_feed();
	}

	public static function is_section_home() {
		$key = NH_Sections::current();
		return self::is_section_page() && ! NH_Sections::current_topic( $key ) && '' === self::keyword() && ! is_paged();
	}

	public static function is_hub() {
		return is_singular( NH_Post_Types::CPT ) || self::is_section_page() || self::is_tag_page()
			|| ( is_feed() && is_post_type_archive( NH_Post_Types::CPT ) );
	}

	public static function keyword() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		return isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	}

	/* ---------- Templates and assets ---------- */

	public static function template_include( $template ) {
		if ( is_feed() ) {
			return $template;
		}
		if ( is_singular( NH_Post_Types::CPT ) ) {
			return self::locate( 'single-resource.php', $template );
		}
		if ( self::is_section_page() ) {
			return self::locate( self::is_section_home() ? 'section-home.php' : 'section-list.php', $template );
		}
		if ( self::is_tag_page() ) {
			return self::locate( 'section-list.php', $template );
		}
		return $template;
	}

	public static function locate( $file, $fallback ) {
		$theme = locate_template( 'neurohr-hub/' . $file );
		if ( $theme ) {
			return $theme;
		}
		$plugin = NH_HUB_DIR . 'templates/' . $file;
		return file_exists( $plugin ) ? $plugin : $fallback;
	}

	public static function enqueue() {
		if ( self::is_hub() || NH_Blog::is_styled() ) {
			wp_enqueue_style( 'nh-hub', NH_HUB_URL . 'assets/hub.css', array(), NH_HUB_VERSION );
		}
	}

	/* ---------- Queries ---------- */

	public static function pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_feed() ) {
			return;
		}

		$key = $query->get( NH_Sections::QUERY_VAR );
		if ( is_string( $key ) && NH_Sections::get( $key ) ) {
			$query->set( 'post_type', NH_Post_Types::CPT );
			$tax_query   = (array) $query->get( 'tax_query' );
			$tax_query[] = NH_Sections::type_clause( $key );
			$query->set( 'tax_query', $tax_query );

			$keyword = self::keyword();
			if ( '' !== $keyword ) {
				$query->set( 's', $keyword );
				$query->set( 'posts_per_page', self::PER_PAGE );
			} elseif ( $query->get( NH_Sections::topic_var( (string) NH_Sections::get( $key )['taxonomy'] ) ) ) {
				// Topic page: alphabetical, like the topic cards.
				$query->set( 'posts_per_page', self::PER_PAGE );
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
			} else {
				// Section home builds its own lists; keep the main query tiny.
				$query->set( 'posts_per_page', $query->get( 'paged' ) ? self::PER_PAGE : 1 );
			}
			return;
		}

		if ( $query->is_tax( NH_Post_Types::TAX_TAG ) ) {
			$query->set( 'post_type', NH_Post_Types::CPT );
			$query->set( 'posts_per_page', self::PER_PAGE );
		}
	}

	/** Sort Resource Type terms in the default order (Article, Case Study, FAQ, …). Used by admin screens. */
	public static function sort_types( $terms ) {
		$terms = is_array( $terms ) ? array_filter( $terms, function ( $t ) { return $t instanceof WP_Term; } ) : array();
		$order = array_flip( array_keys( NH_Post_Types::default_types() ) );
		usort(
			$terms,
			function ( $a, $b ) use ( $order ) {
				$x = isset( $order[ $a->slug ] ) ? $order[ $a->slug ] : 99;
				$y = isset( $order[ $b->slug ] ) ? $order[ $b->slug ] : 99;
				return $x === $y ? strcmp( $a->name, $b->name ) : $x - $y;
			}
		);
		return $terms;
	}

	/* ---------- Cache bookkeeping ---------- */

	public static function remember_cache_key( $key ) {
		$keys = get_option( 'nh_hub_topic_cache_keys', array() );
		if ( ! in_array( $key, (array) $keys, true ) ) {
			$keys[] = $key;
			update_option( 'nh_hub_topic_cache_keys', $keys, false );
		}
	}

	public static function flush_topic_cache() {
		foreach ( (array) get_option( 'nh_hub_topic_cache_keys', array() ) as $key ) {
			delete_transient( $key );
		}
	}
}
