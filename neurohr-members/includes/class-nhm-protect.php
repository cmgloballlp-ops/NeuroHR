<?php
/**
 * "Who can see this page": a box in the page editor (replaces MemberPress rules).
 * Guests are sent to the login page; members below the level see an upgrade box
 * instead of the content (or are sent to the pricing page, see Settings).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Protect {

	const META = '_nhm_min_level';

	private static $in_gate = false;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 999, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
		add_action( 'nhm_level_deleted', array( __CLASS__, 'level_deleted' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	public static function post_types() {
		return array_values( array_filter( (array) NHM_Settings::get( 'protect_types' ), 'post_type_exists' ) );
	}

	/** Level ID a post needs, 0 = everyone. */
	public static function required( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return 0;
		}
		return (int) get_post_meta( $post->ID, self::META, true );
	}

	public static function can_view( $post, $user_id = null ) {
		$need = self::required( $post );
		if ( ! $need ) {
			return true;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		return NHM_Members::can( $need, $user_id );
	}

	/** Pages that must stay open, whatever their setting says. */
	private static function always_open( $post_id ) {
		foreach ( array( 'login', 'register', 'lost', 'pricing', 'pay' ) as $key ) {
			if ( (int) NHM_Settings::get( 'page_' . $key ) === (int) $post_id ) {
				return true;
			}
		}
		// A pay link (?nhm_pay=…) opens the account page for people who are not logged in.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['nhm_pay'] ) && (int) NHM_Settings::get( 'page_account' ) === (int) $post_id ) {
			return true;
		}
		return false;
	}

	public static function gate() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::required( $post ) || self::always_open( $post->ID ) || self::can_view( $post ) ) {
			return;
		}
		NHM_Forms::no_cache();
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( NHM_Auth::login_url( get_permalink( $post ) ) );
			exit;
		}
		if ( 'redirect' === NHM_Settings::get( 'protect_mode' ) ) {
			$url = NHM_Levels::upgrade_url( self::required( $post ) );
			if ( $url && untrailingslashit( $url ) !== untrailingslashit( get_permalink( $post ) ) ) {
				wp_safe_redirect( $url );
				exit;
			}
		}
	}

	public static function filter_content( $content ) {
		if ( self::$in_gate || is_admin() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || ! self::required( $post ) || self::always_open( $post->ID ) || self::can_view( $post ) ) {
			return $content;
		}
		self::$in_gate = true;
		$html          = self::locked_html( $post );
		self::$in_gate = false;
		return $html;
	}

	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( is_admin() || ! $post || ! self::required( $post ) || self::can_view( $post ) ) {
			return $excerpt;
		}
		return has_excerpt( $post ) ? $excerpt : '';
	}

	public static function locked_html( $post ) {
		$need  = self::required( $post );
		$level = NHM_Levels::get( $need );
		$name  = $level ? $level['name'] : '';
		$h     = '<div class="nhm-locked">';
		if ( has_excerpt( $post ) ) {
			$h .= '<p class="nhm-locked__teaser">' . esc_html( $post->post_excerpt ) . '</p>';
		}
		/* translators: %s: level */
		$h .= '<p class="nhm-locked__title">' . esc_html( $level && ! $level['is_base'] ? sprintf( __( 'This page is for %s members and above.', 'neurohr-members' ), $name ) : __( 'Please log in to see this page.', 'neurohr-members' ) ) . '</p>';
		$h .= '<p class="nhm-actions">';
		if ( ! is_user_logged_in() ) {
			$h .= '<a class="nhm-btn nhm-btn--primary" href="' . esc_url( NHM_Auth::login_url( get_permalink( $post ) ) ) . '">' . esc_html__( 'Log in', 'neurohr-members' ) . '</a>';
		} else {
			/* translators: %s: level */
			$h .= '<a class="nhm-btn nhm-btn--primary" href="' . esc_url( NHM_Levels::upgrade_url( $need ) ) . '">' . esc_html( sprintf( __( 'Upgrade to %s', 'neurohr-members' ), $name ) ) . '</a>';
		}
		$h .= '</p></div>';
		return NHM_Forms::wrap( $h, 'nhm-locked-wrap' );
	}

	public static function noindex( $robots ) {
		if ( is_singular() && self::required( get_queried_object_id() ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/** "Members: Pro +" next to the page title in the Pages list. */
	public static function post_state( $states, $post ) {
		$need = self::required( $post );
		if ( $need ) {
			$level = NHM_Levels::get( $need );
			/* translators: %s: level */
			$states['nhm'] = $level ? sprintf( __( 'Members: %s', 'neurohr-members' ), $level['name'] . ( $level['rank'] < NHM_Levels::max_rank() ? ' +' : '' ) ) : __( 'Members only', 'neurohr-members' );
		}
		return $states;
	}

	/* ---------- Editor box ---------- */

	public static function meta_box() {
		if ( ! current_user_can( NHM_CAP ) && ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'nhm-protect', __( 'Who can see this page', 'neurohr-members' ), array( __CLASS__, 'render_box' ), $type, 'side', 'high' );
		}
	}

	public static function render_box( $post ) {
		$current = (int) get_post_meta( $post->ID, self::META, true );
		wp_nonce_field( 'nhm_protect', '_nhm_protect_nonce' );
		echo '<select name="nhm_min_level" style="width:100%">';
		echo '<option value="0">' . esc_html__( 'Everyone (public)', 'neurohr-members' ) . '</option>';
		$max = NHM_Levels::max_rank();
		foreach ( NHM_Levels::all() as $level ) {
			$label = $level['is_base'] ? sprintf( /* translators: %s: base level */ __( 'Logged-in members (%s +)', 'neurohr-members' ), $level['name'] ) : $level['name'] . ( $level['rank'] < $max ? ' +' : '' );
			echo '<option value="' . (int) $level['id'] . '"' . selected( $current, (int) $level['id'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( $current && self::always_open( $post->ID ) ) {
			echo '<p class="description">' . esc_html__( 'This is the login, registration, lost-password or pricing page, so it always stays open.', 'neurohr-members' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Guests go to the login page. Members below the level see an upgrade box. Editors and admins always see the page.', 'neurohr-members' ) . '</p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['_nhm_protect_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nhm_protect_nonce'] ) ), 'nhm_protect' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$level = isset( $_POST['nhm_min_level'] ) ? absint( $_POST['nhm_min_level'] ) : 0;
		if ( $level && NHM_Levels::get( $level ) ) {
			update_post_meta( $post_id, self::META, $level );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/** Pages that needed a deleted level now need the next level up. */
	public static function level_deleted( $deleted, $content_to ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::META, (string) (int) $deleted ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META, (int) $content_to );
		}
	}
}
