<?php
/**
 * "Login required" for the whole Knowledge Hub (Settings → General, on by default).
 *
 * Guests are sent to the login page and back afterwards. Hub content is also kept out of
 * the REST API, site search and sitemaps for guests. The RSS feed stays readable with a
 * secret key, so MailerLite can still send new-content emails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Login {

	const OPTION_FEED_KEY = 'nh_hub_feed_key';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_search' ) );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'sitemap_taxonomies' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
	}

	public static function required() {
		return (bool) NH_Display::get( 'require_login' );
	}

	private static function guest_blocked() {
		return self::required() && ! is_user_logged_in();
	}

	/* ---------- Feed key ---------- */

	public static function feed_key() {
		$key = get_option( self::OPTION_FEED_KEY );
		if ( ! $key ) {
			$key = wp_generate_password( 24, false, false );
			update_option( self::OPTION_FEED_KEY, $key, false );
		}
		return $key;
	}

	public static function feed_url() {
		$url = home_url( '/' . NH_Post_Types::BASE . '/feed/' );
		return self::required() ? add_query_arg( 'key', self::feed_key(), $url ) : $url;
	}

	private static function is_hub_feed() {
		return is_feed() && ( is_post_type_archive( NH_Post_Types::CPT ) || is_tax( NH_Post_Types::taxonomies() ) );
	}

	/* ---------- Login page ---------- */

	/**
	 * NeuroHR Members login page, else the MemberPress login page, else the WordPress login.
	 * Either way the visitor comes back to the page they asked for.
	 */
	public static function login_url( $redirect_to ) {
		$url = '';
		if ( function_exists( 'nhm_login_url' ) ) {
			$url = nhm_login_url( $redirect_to );
		} elseif ( class_exists( 'MeprOptions' ) ) {
			$options = MeprOptions::fetch();
			if ( ! empty( $options->login_page_id ) && 'publish' === get_post_status( $options->login_page_id ) ) {
				$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), get_permalink( $options->login_page_id ) );
			}
		}
		if ( ! $url ) {
			$url = wp_login_url( $redirect_to );
		}
		return apply_filters( 'nh_hub_login_url', $url, $redirect_to );
	}

	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	/* ---------- Gates ---------- */

	public static function gate() {
		if ( ! self::guest_blocked() ) {
			return;
		}

		if ( self::is_hub_feed() ) {
			$given = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $given && hash_equals( self::feed_key(), $given ) ) {
				return; // MailerLite. Locked items still only show their excerpt.
			}
			status_header( 403 );
			nocache_headers();
			exit;
		}

		if ( NH_Frontend::is_hub() || self::is_old_hub_url() ) {
			nocache_headers();
			wp_safe_redirect( self::login_url( self::current_url() ), 302 );
			exit;
		}
	}

	/** Any 404 under a section address (old BetterDocs/v0.3 links redirect only after login). */
	private static function is_old_hub_url() {
		if ( ! is_404() ) {
			return false;
		}
		$path = (string) wp_parse_url( self::current_url(), PHP_URL_PATH );
		foreach ( NH_Sections::all() as $section ) {
			if ( 0 === strpos( $path, '/' . $section['base'] . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Guests get nothing from /wp/v2/nh_resource (the block editor still uses it when logged in). */
	public static function gate_rest( $result, $server, $request ) {
		if ( ! self::guest_blocked() ) {
			return $result;
		}
		$route = $request->get_route();
		foreach ( array_merge( array( NH_Post_Types::CPT ), NH_Post_Types::taxonomies() ) as $base ) {
			if ( 0 === strpos( $route, '/wp/v2/' . $base ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Please log in.', 'neurohr-hub' ), array( 'status' => 401 ) );
			}
		}
		return $result;
	}

	/** The site-wide search (?s=) must not list hub resources to guests. */
	public static function exclude_from_search( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || ! self::guest_blocked() ) {
			return;
		}
		if ( NH_Post_Types::CPT === $query->get( 'post_type' ) ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}
		$types = get_post_types( array( 'exclude_from_search' => false ) );
		unset( $types[ NH_Post_Types::CPT ] );
		$query->set( 'post_type', array_values( $types ) );
	}

	public static function sitemap_post_types( $types ) {
		if ( self::required() ) {
			unset( $types[ NH_Post_Types::CPT ] );
		}
		return $types;
	}

	public static function sitemap_taxonomies( $taxonomies ) {
		if ( self::required() ) {
			foreach ( NH_Post_Types::taxonomies() as $tax ) {
				unset( $taxonomies[ $tax ] );
			}
		}
		return $taxonomies;
	}

	public static function noindex( $robots ) {
		if ( self::required() && NH_Frontend::is_hub() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
}
