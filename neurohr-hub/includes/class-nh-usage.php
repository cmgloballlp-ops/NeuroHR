<?php
/**
 * What each logged-in member opened and downloaded, for refund checks in NeuroHR Members.
 *
 * Table wp_nh_usage: user_id, post_id, action (view | download), created_at. Rows older than
 * 90 days are deleted every day. Staff are not logged. NeuroHR Members reads it through the
 * filter nhm_member_usage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Usage {

	const DB_VERSION = 1;
	const KEEP_DAYS  = 90;
	const CRON       = 'nh_usage_cleanup';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ), 5 );
		add_action( self::CRON, array( __CLASS__, 'cleanup' ) );
		add_filter( 'nhm_member_usage', array( __CLASS__, 'member_usage' ), 10, 3 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nh_usage';
	}

	public static function install() {
		if ( (int) get_option( 'nh_usage_db' ) < self::DB_VERSION ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta(
				"CREATE TABLE $t (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					user_id bigint(20) unsigned NOT NULL,
					post_id bigint(20) unsigned NOT NULL,
					action varchar(20) NOT NULL DEFAULT 'view',
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY user_time (user_id, created_at)
				) " . $wpdb->get_charset_collate() . ';'
			);
			update_option( 'nh_usage_db', self::DB_VERSION );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Log one view or download of the current member (not staff, not guests). */
	public static function log( $post_id, $action = 'view' ) {
		$uid = get_current_user_id();
		if ( ! $uid || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		// One view per resource per hour is enough.
		if ( 'view' === $action ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS );
			$dup   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d AND post_id = %d AND action = 'view' AND created_at >= %s LIMIT 1", $uid, (int) $post_id, $since ) ); // phpcs:ignore WordPress.DB
			if ( $dup ) {
				return;
			}
		}
		$wpdb->insert( $t, array( 'user_id' => $uid, 'post_id' => (int) $post_id, 'action' => $action, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function cleanup() {
		global $wpdb;
		$t      = self::table();
		$before = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE created_at < %s", $before ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array{views:int,downloads:int,items:array} since $since (Y-m-d H:i:s)
	 */
	public static function member_usage( $usage, $user_id, $since ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, action, created_at FROM $t WHERE user_id = %d AND created_at >= %s ORDER BY created_at DESC LIMIT 500", (int) $user_id, (string) $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array( 'views' => 0, 'downloads' => 0, 'items' => array() );
		foreach ( (array) $rows as $r ) {
			'download' === $r['action'] ? $out['downloads']++ : $out['views']++;
			if ( count( $out['items'] ) < 50 ) {
				$out['items'][] = array(
					'time'   => $r['created_at'],
					'action' => $r['action'],
					'title'  => get_the_title( (int) $r['post_id'] ),
					'url'    => get_permalink( (int) $r['post_id'] ),
				);
			}
		}
		return $out;
	}
}
