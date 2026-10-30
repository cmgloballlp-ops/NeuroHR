<?php
/**
 * Lightweight stats for the dashboard.
 *
 * Views: counted by a tiny beacon sent from the browser, because LiteSpeed serves
 * cached pages to guests without running PHP — a server-side counter would miss them.
 * Searches: hub searches with no results are logged, to show what content is missing.
 * Upgrade interest: when a member opens a resource above their level, the view is also
 * counted per member level, and clicks on its upgrade button are counted. This shows
 * which content makes members want to upgrade.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stats {

	const VIEWS_META     = '_nh_views';
	const LOCKED_META    = '_nh_locked_views';  // array: member level => views while locked
	const CLICKS_META    = '_nh_upgrade_clicks'; // array: member level => upgrade button clicks
	const ZERO_SEARCHES  = 'nh_hub_zero_searches';
	const MAX_SEARCHES   = 200;
	const BOT_PATTERN    = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless/i';

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'print_beacon' ) );
		add_action( 'wp_ajax_nh_hub_view', array( __CLASS__, 'record_view' ) );
		add_action( 'wp_ajax_nopriv_nh_hub_view', array( __CLASS__, 'record_view' ) );
		add_action( 'wp_ajax_nh_hub_upgrade', array( __CLASS__, 'record_upgrade_click' ) );
		add_action( 'wp_ajax_nopriv_nh_hub_upgrade', array( __CLASS__, 'record_upgrade_click' ) );
		add_action( 'wp', array( __CLASS__, 'log_zero_search' ) );
	}

	private static function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $agent || (bool) preg_match( self::BOT_PATTERN, $agent );
	}

	public static function print_beacon() {
		if ( ! is_singular( NH_Post_Types::CPT ) ) {
			return;
		}
		$data = array(
			'url' => admin_url( 'admin-ajax.php' ),
			'id'  => get_queried_object_id(),
		);
		?>
		<script>
		( function ( d ) {
			if ( ! navigator.sendBeacon ) { return; }
			var send = function ( action ) {
				var f = new FormData(); f.append( 'action', action ); f.append( 'id', d.id );
				navigator.sendBeacon( d.url, f );
			};
			send( 'nh_hub_view' );
			document.addEventListener( 'click', function ( e ) {
				if ( e.target.closest && e.target.closest( '[data-nh-upgrade]' ) ) { send( 'nh_hub_upgrade' ); }
			} );
		} )( <?php echo wp_json_encode( $data ); ?> );
		</script>
		<?php
	}

	/**
	 * No nonce on purpose: the page may be served from cache, where a nonce would be stale.
	 * The worst case is an inflated view count, which is acceptable for this feature.
	 */
	public static function record_view() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ! self::is_bot() && ! current_user_can( 'edit_others_posts' )
			&& NH_Post_Types::CPT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			update_post_meta( $id, self::VIEWS_META, self::get_views( $id ) + 1 );
			if ( ! NH_Access::can_view( $id ) ) {
				self::bump( $id, self::LOCKED_META );
			} else {
				NH_Usage::log( $id, 'view' );
			}
		}
		status_header( 204 );
		exit;
	}

	/** Upgrade / join button clicked on a locked resource. */
	public static function record_upgrade_click() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ! self::is_bot() && ! current_user_can( 'edit_others_posts' )
			&& NH_Post_Types::CPT === get_post_type( $id ) && ! NH_Access::can_view( $id ) ) {
			self::bump( $id, self::CLICKS_META );
		}
		status_header( 204 );
		exit;
	}

	/** Add one to the counter of the current member's level. */
	private static function bump( $id, $meta ) {
		$counts = self::level_counts( $id, $meta );
		$level  = NH_Access::get_user_level();
		$counts[ $level ] = ( isset( $counts[ $level ] ) ? $counts[ $level ] : 0 ) + 1;
		update_post_meta( $id, $meta, $counts );
	}

	/** Level => count. */
	public static function level_counts( $id, $meta ) {
		$counts = get_post_meta( $id, $meta, true );
		return is_array( $counts ) ? array_map( 'intval', $counts ) : array();
	}

	public static function total( $id, $meta ) {
		return array_sum( self::level_counts( $id, $meta ) );
	}

	public static function get_views( $post_id ) {
		return (int) get_post_meta( $post_id, self::VIEWS_META, true );
	}

	public static function log_zero_search() {
		if ( is_admin() || ! NH_Frontend::is_listing() || self::is_bot() ) {
			return;
		}
		$keyword = NH_Frontend::keyword();
		global $wp_query;
		if ( '' === $keyword || $wp_query->found_posts > 0 || is_paged() ) {
			return;
		}
		$keyword = mb_strtolower( mb_substr( trim( $keyword ), 0, 60 ) );
		$log     = get_option( self::ZERO_SEARCHES, array() );
		$log     = is_array( $log ) ? $log : array();

		$log[ $keyword ] = ( isset( $log[ $keyword ] ) ? (int) $log[ $keyword ] : 0 ) + 1;
		if ( count( $log ) > self::MAX_SEARCHES ) {
			arsort( $log );
			$log = array_slice( $log, 0, self::MAX_SEARCHES, true );
		}
		update_option( self::ZERO_SEARCHES, $log, false );
	}

	public static function zero_searches( $limit = 20 ) {
		$log = get_option( self::ZERO_SEARCHES, array() );
		$log = is_array( $log ) ? $log : array();
		arsort( $log );
		return array_slice( $log, 0, $limit, true );
	}
}
