<?php
/**
 * Members → Dashboard: the numbers and the to-do list at a glance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Dashboard {

	public static function init() {
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		list( $rows, $counts ) = NHM_Admin_Members::rows( array( 'view' => 'all', 'level' => 0, 'company' => 0, 's' => '', 'paged' => 1 ) );
		$by_level = array();
		$expiring = array();
		foreach ( $rows as $r ) {
			$by_level[ $r['m']['level'] ] = ( isset( $by_level[ $r['m']['level'] ] ) ? $by_level[ $r['m']['level'] ] : 0 ) + 1;
			if ( 'base' !== $r['m']['source'] && null !== $r['m']['days_left'] && $r['m']['days_left'] <= 30 ) {
				$expiring[] = $r;
			}
		}
		usort( $expiring, function ( $a, $b ) { return $a['m']['days_left'] - $b['m']['days_left']; } );
		$recent    = array_slice( $rows, 0, 8 );
		$pending   = NHM_Invoices::query( array( 'pending' => true, 'limit' => 10 ) );
		$unpaid    = NHM_Invoices::query( array( 'status' => 'unpaid', 'limit' => 10 ) );
		$year      = current_time( 'Y' );
		$sum_year  = NHM_Invoices::summary( $year );
		$money     = NHM_Refunds::totals( $year );
		$renew     = NHM_Renew::upcoming( 30 );
		$sum_all   = NHM_Invoices::summary();
		$companies = NHM_Companies::query( array( 'limit' => 100000 ) );
		$active_c  = count( array_filter( $companies, array( 'NHM_Companies', 'is_active' ) ) );

		echo '<div class="wrap nhm-admin nhm-dash">';
		NHM_Admin::header(
			__( 'Members', 'neurohr-members' ),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices&action=new' ) ) . '">' . esc_html__( 'New invoice', 'neurohr-members' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-companies&action=new' ) ) . '">' . esc_html__( 'Add company', 'neurohr-members' ) . '</a>'
		);
		$tiles = array(
			array( 'groups', $counts['all'], __( 'Accounts', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-members' ) ),
			array( 'awards', $counts['paid'], __( 'On a paid plan', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-members&view=paid' ) ),
			array( 'building', $active_c, __( 'Active companies', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-companies' ) ),
			array( 'clock', $counts['expiring'], __( 'Ending within 30 days', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-members&view=expiring' ) ),
			array( 'hourglass', count( NHM_Invoices::query( array( 'pending' => true, 'limit' => 999 ) ) ), __( 'Waiting for payment', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-invoices&status=pending' ) ),
			/* translators: %s: year */
			array( 'chart-line', NHM_Settings::money( $money['received'] ), sprintf( __( 'Received in %s', 'neurohr-members' ), $year ), admin_url( 'admin.php?page=nhm-invoices&status=paid' ) ),
			/* translators: %s: year */
			array( 'undo', NHM_Settings::money( $money['refunded'] ), sprintf( __( 'Refunded in %s', 'neurohr-members' ), $year ), admin_url( 'admin.php?page=nhm-payments&status=refunds' ) ),
			/* translators: %s: year */
			array( 'money-alt', NHM_Settings::money( $money['net'] ), sprintf( __( 'Net in %s', 'neurohr-members' ), $year ), admin_url( 'admin.php?page=nhm-invoices&status=paid' ) ),
			/* translators: %s: amount */
			array( 'update', $renew['count'] . ' · ' . NHM_Settings::money( $renew['total'] ), __( 'Auto-renew in the next 30 days', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-members&view=expiring' ) ),
			array( 'warning', NHM_Settings::money( $sum_all['unpaid']['amount'] ), __( 'Unpaid', 'neurohr-members' ), admin_url( 'admin.php?page=nhm-invoices&status=unpaid' ) ),
		);
		echo '<div class="nhm-tiles">';
		foreach ( $tiles as $t ) {
			echo '<a class="nhm-tile" href="' . esc_url( $t[3] ) . '"><span class="dashicons dashicons-' . esc_attr( $t[0] ) . '"></span><strong>' . esc_html( (string) $t[1] ) . '</strong><span>' . esc_html( $t[2] ) . '</span></a>';
		}
		echo '</div>';

		echo '<div class="nhm-admin-grid">';
		// Waiting for payment.
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-hourglass"></span>' . esc_html__( 'Plan applications waiting for payment', 'neurohr-members' ) . '</h2>';
		if ( $pending ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $pending as $inv ) {
				echo '<tr><td><a href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) ) . '">' . esc_html( $inv['number'] ) . '</a><br><span class="nhm-muted">' . esc_html( isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ) . '</span></td><td>' . NHM_Admin::level_badge( $inv['level_id'] ) . '</td><td class="num">' . esc_html( NHM_Settings::money( $inv['total'] ) ) . '</td><td>' . NHM_Admin_Invoices::status_badge( $inv ) . '</td><td class="nhm-actions-cell"><a class="button button-small button-primary" href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) . '#nhm-pay' ) . '">' . esc_html__( 'Mark paid', 'neurohr-members' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="nhm-muted">' . esc_html__( 'Nothing waiting. When someone signs up for a paid plan, the invoice shows up here.', 'neurohr-members' ) . '</p>';
		}
		echo '</section>';

		// Ending soon.
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-clock"></span>' . esc_html__( 'Plans ending within 30 days', 'neurohr-members' ) . '</h2>';
		$soon_c = array_filter( $companies, function ( $c ) { $d = NHM_Members::days_left( $c['expiry_date'] ); return null !== $d && $d >= 0 && $d <= 30; } );
		if ( $expiring || $soon_c ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $soon_c as $c ) {
				echo '<tr><td><span class="dashicons dashicons-building"></span> <a href="' . esc_url( NHM_Companies::edit_url( $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a></td><td>' . NHM_Admin::level_badge( $c['level_id'] ) . '</td><td>' . NHM_Admin::expiry_html( $c['expiry_date'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			foreach ( array_slice( $expiring, 0, 12 ) as $r ) {
				if ( 'company' === $r['m']['source'] ) {
					continue;
				}
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( $r['user']->ID ) . '#nhm' ) . '">' . esc_html( $r['user']->display_name ) . '</a></td><td>' . NHM_Admin::level_badge( $r['m']['level'] ) . '</td><td>' . NHM_Admin::expiry_html( $r['m']['expiry'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="nhm-muted">' . esc_html__( 'No plan ends in the next 30 days.', 'neurohr-members' ) . '</p>';
		}
		echo '</section>';

		// People per level.
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-chart-bar"></span>' . esc_html__( 'People per plan (now)', 'neurohr-members' ) . '</h2><table class="nhm-admin-table"><tbody>';
		$max = max( 1, max( $by_level ? $by_level : array( 1 ) ) );
		foreach ( NHM_Levels::all() as $id => $level ) {
			$n = isset( $by_level[ $id ] ) ? $by_level[ $id ] : 0;
			echo '<tr><td style="width:140px">' . esc_html( $level['name'] ) . '</td><td><span class="nhm-bar" style="width:' . esc_attr( (string) max( 2, round( $n / $max * 100 ) ) ) . '%"></span></td><td class="num" style="width:60px"><strong>' . (int) $n . '</strong></td></tr>';
		}
		echo '</tbody></table></section>';

		// Recent sign-ups.
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-admin-users"></span>' . esc_html__( 'Newest accounts', 'neurohr-members' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $recent as $r ) {
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( $r['user']->ID ) . '#nhm' ) . '">' . esc_html( $r['user']->display_name ) . '</a><br><span class="nhm-muted">' . esc_html( $r['user']->user_email ) . '</span></td><td>' . NHM_Admin::level_badge( $r['m']['level'] ) . '</td><td>' . esc_html( mysql2date( 'j M Y', get_date_from_gmt( $r['user']->user_registered ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></section>';
		echo '</div>';

		if ( $unpaid ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %d: number */ _n( '%d unpaid invoice in total.', '%d unpaid invoices in total.', $sum_all['unpaid']['n'], 'neurohr-members' ), $sum_all['unpaid']['n'] ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices&status=unpaid' ) ) . '">' . esc_html__( 'See all', 'neurohr-members' ) . '</a></p>';
		}
		echo '</div>';
	}
}
