<?php
/**
 * Live search: results appear under the search box while typing (2+ characters).
 * Searches one section, optionally one topic. Enter opens the full results page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Search {

	const ACTION = 'nh_hub_live';
	const LIMIT  = 8;

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		wp_register_script( 'nh-live-search', NH_HUB_URL . 'assets/live-search.js', array(), NH_HUB_VERSION, true );
		wp_localize_script(
			'nh-live-search',
			'nhLiveSearch',
			array(
				'url'     => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'min'     => 2,
				'strings' => array(
					'none'    => __( 'No results. Press Enter to search everything.', 'neurohr-hub' ),
					/* translators: %d: number of results */
					'all'     => __( 'See all %d results', 'neurohr-hub' ),
					'loading' => __( 'Searching…', 'neurohr-hub' ),
				),
			)
		);
	}

	/** Called by every search box. Late calls still load the script in the footer. */
	public static function enqueue() {
		if ( ! wp_script_is( 'nh-live-search', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_script( 'nh-live-search' );
	}

	/**
	 * Read-only search, so no nonce: pages may be cached, which would make nonces stale.
	 * When the hub requires login, guests get nothing.
	 */
	public static function ajax() {
		if ( NH_Login::required() && ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login' ), 401 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, see above.
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'knowledge';
		$topic   = isset( $_GET['topic'] ) ? absint( $_GET['topic'] ) : 0;
		// phpcs:enable

		$data = NH_Sections::get( $section );
		if ( ! $data || mb_strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'items' => array(), 'total' => 0, 'all' => '' ) );
		}

		$tax_query = array( NH_Sections::type_clause( $section ) );
		$term      = null;
		if ( $topic && $data['taxonomy'] ) {
			$term = get_term( $topic, $data['taxonomy'] );
			if ( $term && ! is_wp_error( $term ) ) {
				$tax_query[] = array( 'taxonomy' => $data['taxonomy'], 'terms' => $term->term_id );
			} else {
				$term = null;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				's'              => $q,
				'posts_per_page' => self::LIMIT,
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- small hub.
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$meta = '';
			if ( 'all' === $section ) {
				$meta = NH_Sections::get( NH_Sections::of_post( $p ) )['label'];
			} elseif ( $data['taxonomy'] ) {
				$terms = get_the_terms( $p, $data['taxonomy'] );
				$meta  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
			}
			$can     = NH_Access::can_view( $p );
			$items[] = array(
				'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'    => get_permalink( $p ),
				'meta'   => html_entity_decode( $meta, ENT_QUOTES, 'UTF-8' ),
				'locked' => $can ? '' : nh_hub_level_label( NH_Access::get_post_level( $p ) ),
			);
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'all'   => NH_Rewrite::url( $section, $term ? $term->slug : '', array( 'q' => $q ) ),
			)
		);
	}
}
