<?php
/**
 * History of membership changes (who, what, when, by whom).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Log {

	public static function add( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'user_id'    => 0,
				'company_id' => 0,
				'event'      => '',
				'level_from' => 0,
				'level_to'   => 0,
				'expiry'     => null,
				'message'    => '',
				'actor_id'   => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			$row
		);
		$row['expiry']  = $row['expiry'] ? $row['expiry'] : null;
		$row['message'] = mb_substr( (string) $row['message'], 0, 250 );
		$wpdb->insert( NHM_DB::table( 'log' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array $args user_id, company_id, limit, offset.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'user_id' => 0, 'company_id' => 0, 'limit' => 50, 'offset' => 0 ), $args );
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['user_id'] ) {
			$where[] = 'user_id = %d';
			$vals[]  = (int) $args['user_id'];
		}
		if ( $args['company_id'] ) {
			$where[] = 'company_id = %d';
			$vals[]  = (int) $args['company_id'];
		}
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$table  = NHM_DB::table( 'log' );
		$sql    = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function event_label( $event ) {
		$labels = array(
			'level_set'      => __( 'Plan changed', 'neurohr-members' ),
			'expired'        => __( 'Plan ended', 'neurohr-members' ),
			'reminder'       => __( 'Reminder sent', 'neurohr-members' ),
			'registered'     => __( 'Registered', 'neurohr-members' ),
			'company_set'    => __( 'Company plan changed', 'neurohr-members' ),
			'company_new'    => __( 'Company created', 'neurohr-members' ),
			'company_delete' => __( 'Company deleted', 'neurohr-members' ),
			'joined'         => __( 'Joined company', 'neurohr-members' ),
			'left'           => __( 'Left company', 'neurohr-members' ),
			'removed'        => __( 'Removed from company', 'neurohr-members' ),
			'invited'        => __( 'Invited', 'neurohr-members' ),
			'owner'          => __( 'Company owner changed', 'neurohr-members' ),
			'level_deleted'  => __( 'Level deleted', 'neurohr-members' ),
			'level_moved'    => __( 'Moved to another level', 'neurohr-members' ),
			'invoice'        => __( 'Invoice created', 'neurohr-members' ),
			'paid'           => __( 'Invoice paid', 'neurohr-members' ),
			'void'           => __( 'Invoice voided', 'neurohr-members' ),
			'applied'        => __( 'Applied for a plan', 'neurohr-members' ),
			'blocked'        => __( 'Blocked', 'neurohr-members' ),
			'unblocked'      => __( 'Unblocked', 'neurohr-members' ),
			'deleted'        => __( 'Account deleted', 'neurohr-members' ),
			'autorenew_on'   => __( 'Auto-renew on', 'neurohr-members' ),
			'autorenew_off'  => __( 'Auto-renew off', 'neurohr-members' ),
			'renew_failed'   => __( 'Auto-renew payment failed', 'neurohr-members' ),
			'card'           => __( 'Card saved', 'neurohr-members' ),
			'refund_request' => __( 'Refund requested', 'neurohr-members' ),
			'refund'         => __( 'Refund', 'neurohr-members' ),
			'dispute'        => __( 'Chargeback', 'neurohr-members' ),
			'hold_off'       => __( 'Access restored', 'neurohr-members' ),
		);
		return isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
	}

	/** Small HTML table used on the user profile, company page and History screen. */
	public static function render( array $rows, $show_who = false ) {
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No changes recorded yet.', 'neurohr-members' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped nhm-log"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'neurohr-members' ) . '</th>';
		if ( $show_who ) {
			echo '<th>' . esc_html__( 'Member / company', 'neurohr-members' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'What', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Plan', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Until', 'neurohr-members' ) . '</th><th>' . esc_html__( 'By', 'neurohr-members' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$from  = $row['level_from'] ? NHM_Levels::name( $row['level_from'] ) : '';
			$to    = $row['level_to'] ? NHM_Levels::name( $row['level_to'] ) : '';
			$plan  = trim( ( $from && $from !== $to ? $from . ' → ' : '' ) . $to );
			$actor = $row['actor_id'] ? get_userdata( $row['actor_id'] ) : null;
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $row['created_at'] ) ) . '</td>';
			if ( $show_who ) {
				$who = '';
				if ( $row['user_id'] ) {
					$u   = get_userdata( $row['user_id'] );
					$who = $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '#nhm">' . esc_html( $u->display_name ) . '</a>' : '#' . (int) $row['user_id'];
				}
				if ( $row['company_id'] ) {
					$c    = NHM_Companies::get( $row['company_id'] );
					$who .= ( $who ? ' · ' : '' ) . ( $c ? '<a href="' . esc_url( NHM_Companies::edit_url( $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a>' : esc_html__( '(deleted company)', 'neurohr-members' ) );
				}
				echo '<td>' . wp_kses_post( $who ) . '</td>';
			}
			echo '<td>' . esc_html( self::event_label( $row['event'] ) ) . ( $row['message'] ? '<br><span class="description">' . esc_html( $row['message'] ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $plan ) . '</td>';
			echo '<td>' . esc_html( $row['expiry'] ? mysql2date( 'j M Y', $row['expiry'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( $actor ? $actor->display_name : __( 'System', 'neurohr-members' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
