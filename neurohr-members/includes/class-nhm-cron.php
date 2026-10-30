<?php
/**
 * Daily run: reminder emails before a plan ends, "plan ended" email after it ends,
 * cleaning up old invitations.
 *
 * Access is never waiting for this run: an ended plan stops giving access on the day after
 * its end date because every check compares the date (NHM_Members::membership()). The run
 * only sends the emails and writes the history. Each email goes out once per end date; if
 * the run was missed for some days, only the most recent reminder is sent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Cron {

	const HOOK = 'nhm_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// 08:00 site time tomorrow (or today if still before 8).
			$tz   = wp_timezone();
			$next = new DateTimeImmutable( 'today 08:00', $tz );
			if ( $next->getTimestamp() < time() ) {
				$next = $next->modify( '+1 day' );
			}
			wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		update_option( 'nhm_cron_last_run', current_time( 'mysql' ), false );
		NHM_Renew::run();
		self::people();
		self::companies();
		self::online_invoices();
		NHM_Companies::cleanup_invites();
	}

	/**
	 * Sign-up and upgrade invoices that are not paid: a reminder the day before the due date,
	 * cancelled the day after it. A sign-up that never paid is removed too, so the person can
	 * register again with the same email. Invoices with a payment being checked or processed stay.
	 */
	public static function online_invoices() {
		global $wpdb;
		$t     = NHM_Invoices::table();
		$today = NHM_Settings::today();
		$tom   = DateTimeImmutable::createFromFormat( '!Y-m-d', $today )->modify( '+1 day' )->format( 'Y-m-d' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'unpaid' AND kind IN ('signup','upgrade') AND due_date IS NOT NULL AND due_date <= %s", $tom ) ); // phpcs:ignore WordPress.DB
		$sent  = (array) get_option( 'nhm_due_reminded', array() );
		foreach ( $ids as $id ) {
			$inv = NHM_Invoices::get( (int) $id );
			if ( ! $inv || NHM_Payments::query( array( 'invoice_id' => $inv['id'], 'status' => array( 'submitted', 'processing' ), 'limit' => 1 ) ) ) {
				continue;
			}
			if ( $inv['due_date'] === $tom ) {
				if ( empty( $sent[ $inv['id'] ] ) ) {
					NHM_Emails::invoice_email( 'invoice_due_soon', $inv );
					$sent[ $inv['id'] ] = $today;
				}
				continue;
			}
			if ( $inv['due_date'] < $today ) {
				NHM_Emails::send( 'invoice_expired', NHM_Invoices::email_to( $inv ), NHM_Emails::invoice_placeholders( $inv ) );
				NHM_Invoices::void( $inv['id'] );
				unset( $sent[ $inv['id'] ] );
				$uid = (int) $inv['user_id'];
				if ( $uid && (int) get_user_meta( $uid, 'nhm_pay_pending', true ) === (int) $inv['id'] && ! user_can( $uid, 'edit_posts' ) ) {
					if ( ! function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					NHM_Log::add( array( 'user_id' => 0, 'event' => 'deleted', 'actor_id' => 0, 'message' => 'Unpaid sign-up removed: ' . $inv['bill_to']['email'] . ' · ' . $inv['number'] ) );
					wp_delete_user( $uid );
				}
			}
		}
		update_option( 'nhm_due_reminded', array_slice( $sent, -500, null, true ), false );
	}

	/**
	 * Which email is due, given days left and what was already sent for this end date.
	 *
	 * @param int|null $days     Days until the end date (negative = ended).
	 * @param string[] $sent     Markers already sent: "r30", "r7", "ended".
	 * @param int[]    $reminder Reminder days, largest first.
	 * @return array{0:string|null,1:string[]} [ 'reminder' | 'expired' | null, markers to add ]
	 */
	public static function due( $days, array $sent, array $reminder ) {
		if ( null === $days ) {
			return array( null, array() );
		}
		if ( $days < 0 ) {
			return in_array( 'ended', $sent, true ) ? array( null, array() ) : array( 'expired', array( 'ended' ) );
		}
		// The smallest reminder window we are inside of.
		$hit = null;
		foreach ( $reminder as $d ) {
			if ( $days <= $d ) {
				$hit = $d;
			}
		}
		if ( null === $hit || in_array( 'r' . $hit, $sent, true ) ) {
			return array( null, array() );
		}
		// Mark this one and every larger window, so a late run does not send old reminders.
		$mark = array();
		foreach ( $reminder as $d ) {
			if ( $d >= $hit ) {
				$mark[] = 'r' . $d;
			}
		}
		return array( 'reminder', $mark );
	}

	/**
	 * Recurring plans: the renewal invoice (made once, then re-used), or null.
	 */
	public static function renewal_invoice( $level_id, $user_id, $company_id ) {
		$level = NHM_Levels::get( $level_id );
		if ( ! NHM_Settings::get( 'renewal_invoice' ) || ! $level || 'recurring' !== $level['billing'] || (float) $level['amount'] <= 0 ) {
			return null;
		}
		$id = NHM_Invoices::for_plan( (int) $user_id, (int) $level_id, 'renewal', (int) $company_id );
		return is_wp_error( $id ) ? null : NHM_Invoices::get( $id );
	}

	private static function people() {
		$days_list = NHM_Settings::reminder_days();
		$horizon   = wp_date( 'Y-m-d', strtotime( '+' . ( ( $days_list ? max( $days_list ) : 0 ) + 1 ) . ' days' ) );
		$users     = get_users(
			array(
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => NHM_Members::META_EXPIRY,
						'value'   => $horizon,
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
			)
		);
		$base = NHM_Levels::base_id();
		foreach ( $users as $user_id ) {
			$p = NHM_Members::personal( $user_id );
			if ( $p['level'] === $base || '' === $p['expiry'] ) {
				continue;
			}
			// Auto-renew sends its own reminders; "ended" only once it is off.
			if ( NHM_Renew::is_on( $user_id ) || NHM_Renew::in_grace( $user_id ) ) {
				continue;
			}
			$sent = array_filter( (array) get_user_meta( $user_id, NHM_Members::META_REMINDED, true ) );
			list( $email, $mark ) = self::due( NHM_Members::days_left( $p['expiry'] ), $sent, $days_list );
			if ( ! $email ) {
				continue;
			}
			update_user_meta( $user_id, NHM_Members::META_REMINDED, array_values( array_unique( array_merge( $sent, $mark ) ) ) );
			// No email when the person still has the same or a better plan through a company.
			$m = NHM_Members::membership( $user_id );
			if ( 'company' === $m['source'] && $m['rank'] >= (int) NHM_Levels::rank( $p['level'] ) ) {
				continue;
			}
			NHM_Emails::user_plan( $email, $user_id, $p['level'], $p['expiry'], 'reminder' === $email ? self::renewal_invoice( $p['level'], $user_id, 0 ) : null );
			NHM_Log::add(
				array(
					'user_id'    => $user_id,
					'event'      => 'expired' === $email ? 'expired' : 'reminder',
					'level_from' => $p['level'],
					'level_to'   => 'expired' === $email ? $base : $p['level'],
					'expiry'     => $p['expiry'],
					'actor_id'   => 0,
				)
			);
			do_action( 'expired' === $email ? 'nhm_plan_expired' : 'nhm_plan_reminder', $user_id, $p['level'], $p['expiry'] );
		}
	}

	private static function companies() {
		$days_list = NHM_Settings::reminder_days();
		foreach ( NHM_Companies::query( array( 'limit' => 100000 ) ) as $company ) {
			if ( ! $company['expiry_date'] || $company['level_id'] === NHM_Levels::base_id() ) {
				continue;
			}
			$sent = array_filter( explode( ',', (string) $company['reminded'] ) );
			list( $email, $mark ) = self::due( NHM_Members::days_left( $company['expiry_date'] ), $sent, $days_list );
			if ( ! $email ) {
				continue;
			}
			NHM_Companies::update( $company['id'], array( 'reminded' => implode( ',', array_unique( array_merge( $sent, $mark ) ) ) ) );
			NHM_Emails::company_plan( $email, $company, 'reminder' === $email ? self::renewal_invoice( $company['level_id'], $company['owner_id'], $company['id'] ) : null );
			NHM_Log::add(
				array(
					'company_id' => $company['id'],
					'event'      => 'expired' === $email ? 'expired' : 'reminder',
					'level_from' => $company['level_id'],
					'level_to'   => 'expired' === $email ? NHM_Levels::base_id() : $company['level_id'],
					'expiry'     => $company['expiry_date'],
					'actor_id'   => 0,
				)
			);
			do_action( 'expired' === $email ? 'nhm_company_expired' : 'nhm_company_reminder', $company['id'] );
		}
	}
}
