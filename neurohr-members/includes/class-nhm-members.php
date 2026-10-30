<?php
/**
 * A person's membership.
 *
 * Personal plan (user meta): nhm_level, nhm_start, nhm_expiry ('' = no expiry), nhm_note,
 * nhm_reminded. Company membership: nhm_company (company ID) and nhm_company_role
 * (owner | member). The plan that counts is the higher of the personal plan and the
 * company's plan, each only while it has not expired. Anyone logged in has at least the
 * base level (Free).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Members {

	const META_LEVEL    = 'nhm_level';
	const META_START    = 'nhm_start';
	const META_EXPIRY   = 'nhm_expiry';
	const META_NOTE     = 'nhm_note';
	const META_REMINDED = 'nhm_reminded';
	const META_COMPANY  = 'nhm_company';
	const META_ROLE     = 'nhm_company_role';
	const META_LOGIN    = 'nhm_last_login';

	private static $cache = array();

	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
	}

	public static function flush( $user_id = null ) {
		if ( null === $user_id ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ (int) $user_id ] );
		}
	}

	public static function record_login( $login, $user ) {
		update_user_meta( $user->ID, self::META_LOGIN, current_time( 'mysql' ) );
	}

	/** A deleted user frees their company seat; a deleted owner leaves the company without owner. */
	public static function on_delete_user( $user_id ) {
		$company_id = (int) get_user_meta( $user_id, self::META_COMPANY, true );
		if ( $company_id ) {
			NHM_Log::add( array( 'user_id' => $user_id, 'company_id' => $company_id, 'event' => 'removed', 'message' => 'User account deleted' ) );
			$company = NHM_Companies::get( $company_id );
			if ( $company && (int) $company['owner_id'] === (int) $user_id ) {
				NHM_Companies::update( $company_id, array( 'owner_id' => 0 ) );
			}
		}
	}

	/* ---------- Reading ---------- */

	/** True when an expiry date (Y-m-d or '') is still valid today. The last day counts. */
	public static function date_active( $expiry ) {
		return '' === (string) $expiry || null === $expiry || (string) $expiry >= NHM_Settings::today();
	}

	/** Whole days from today until $date (negative when past). */
	public static function days_left( $date ) {
		if ( ! $date ) {
			return null;
		}
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( NHM_Settings::today(), $tz );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( (string) $date, 0, 10 ), $tz );
		if ( ! $end ) {
			return null;
		}
		return (int) $today->diff( $end )->format( '%r%a' );
	}

	/** Personal plan as stored (whether or not it is still active). */
	public static function personal( $user_id ) {
		$level = (int) get_user_meta( $user_id, self::META_LEVEL, true );
		if ( ! $level || ! NHM_Levels::get( $level ) ) {
			$level = NHM_Levels::base_id();
		}
		return array(
			'level'  => $level,
			'start'  => (string) get_user_meta( $user_id, self::META_START, true ),
			'expiry' => (string) get_user_meta( $user_id, self::META_EXPIRY, true ),
			'note'   => (string) get_user_meta( $user_id, self::META_NOTE, true ),
		);
	}

	/**
	 * Everything about a user's current membership.
	 *
	 * @return array level (ID, 0 = guest), rank, name, source (guest|base|personal|company),
	 *               expiry ('' = none), days_left, company_id, role, personal (stored), expired_level
	 *               (ID of a plan that has ended, for "renew" messages).
	 */
	public static function membership( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return array( 'level' => 0, 'rank' => 0, 'name' => __( 'Guest', 'neurohr-members' ), 'source' => 'guest', 'expiry' => '', 'days_left' => null, 'company_id' => 0, 'role' => '', 'personal' => null, 'expired_level' => 0 );
		}
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}

		$base     = NHM_Levels::base();
		$personal = self::personal( $user_id );
		// Refund being reviewed, or a chargeback: members-only content is paused.
		if ( NHM_Refunds::on_hold( $user_id ) ) {
			$result = array( 'level' => (int) $base['id'], 'rank' => (int) $base['rank'], 'source' => 'base', 'expiry' => '', 'name' => NHM_Levels::name( $base['id'] ), 'days_left' => null, 'company_id' => (int) get_user_meta( $user_id, self::META_COMPANY, true ), 'role' => '', 'personal' => $personal, 'expired_level' => 0, 'hold' => true );
			$result = apply_filters( 'nhm_membership', $result, $user_id );
			self::$cache[ $user_id ] = $result;
			return $result;
		}
		$best     = array( 'level' => (int) $base['id'], 'rank' => (int) $base['rank'], 'source' => 'base', 'expiry' => '' );
		$expired  = 0;

		if ( $personal['level'] !== (int) $base['id'] ) {
			if ( self::date_active( $personal['expiry'] ) || NHM_Renew::in_grace( $user_id ) ) {
				$best = array( 'level' => $personal['level'], 'rank' => (int) NHM_Levels::rank( $personal['level'] ), 'source' => 'personal', 'expiry' => $personal['expiry'] );
			} else {
				$expired = $personal['level'];
			}
		}

		$company_id = (int) get_user_meta( $user_id, self::META_COMPANY, true );
		$role       = '';
		if ( $company_id ) {
			$company = NHM_Companies::get( $company_id );
			if ( $company ) {
				$role = 'owner' === get_user_meta( $user_id, self::META_ROLE, true ) ? 'owner' : 'member';
				$rank = (int) NHM_Levels::rank( $company['level_id'] );
				if ( NHM_Levels::get( $company['level_id'] ) && self::date_active( $company['expiry_date'] ) ) {
					if ( $rank > $best['rank'] ) {
						$best = array( 'level' => (int) $company['level_id'], 'rank' => $rank, 'source' => 'company', 'expiry' => (string) $company['expiry_date'] );
					}
				} elseif ( ! $expired && $rank > $best['rank'] ) {
					$expired = (int) $company['level_id'];
				}
			} else {
				$company_id = 0;
			}
		}

		$result = array_merge(
			$best,
			array(
				'name'          => NHM_Levels::name( $best['level'] ),
				'days_left'     => self::days_left( $best['expiry'] ),
				'company_id'    => $company_id,
				'role'          => $role,
				'personal'      => $personal,
				'expired_level' => $expired,
			)
		);
		$result = apply_filters( 'nhm_membership', $result, $user_id );

		self::$cache[ $user_id ] = $result;
		return $result;
	}

	public static function level( $user_id = null ) {
		$m = self::membership( $user_id );
		return (int) $m['level'];
	}

	public static function rank( $user_id = null ) {
		$m = self::membership( $user_id );
		return (int) $m['rank'];
	}

	/** Staff (editors and admins) can see everything. */
	public static function is_staff( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $user_id && user_can( $user_id, 'edit_others_posts' );
	}

	/**
	 * May the user open content that needs $level (ID, slug or name)? 0 / '' = everyone.
	 */
	public static function can( $level, $user_id = null ) {
		if ( empty( $level ) ) {
			return true;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( self::is_staff( $user_id ) ) {
			return true;
		}
		$required = NHM_Levels::resolve( $level );
		// Unknown level: closed to everyone except staff.
		$need = $required ? (int) $required['rank'] : PHP_INT_MAX;
		return self::rank( $user_id ) >= $need;
	}

	/* ---------- Writing ---------- */

	/**
	 * Set a person's own plan.
	 *
	 * @param int    $level  Level ID (base level = no personal plan).
	 * @param string $start  Y-m-d or ''.
	 * @param string $expiry Y-m-d or '' for no expiry.
	 */
	public static function set_plan( $user_id, $level, $start = '', $expiry = '', $note = null, $message = '' ) {
		$user_id = (int) $user_id;
		$before  = self::personal( $user_id );
		$level   = NHM_Levels::get( $level ) ? (int) $level : NHM_Levels::base_id();
		$start   = self::clean_date( $start );
		$expiry  = self::clean_date( $expiry );

		if ( $level === NHM_Levels::base_id() ) {
			delete_user_meta( $user_id, self::META_LEVEL );
			delete_user_meta( $user_id, self::META_START );
			delete_user_meta( $user_id, self::META_EXPIRY );
			$start  = '';
			$expiry = '';
		} else {
			update_user_meta( $user_id, self::META_LEVEL, $level );
			$start ? update_user_meta( $user_id, self::META_START, $start ) : delete_user_meta( $user_id, self::META_START );
			$expiry ? update_user_meta( $user_id, self::META_EXPIRY, $expiry ) : delete_user_meta( $user_id, self::META_EXPIRY );
		}
		if ( null !== $note ) {
			$note = sanitize_textarea_field( $note );
			$note ? update_user_meta( $user_id, self::META_NOTE, $note ) : delete_user_meta( $user_id, self::META_NOTE );
		}
		if ( $before['expiry'] !== $expiry || $before['level'] !== $level ) {
			delete_user_meta( $user_id, self::META_REMINDED );
		}
		self::flush( $user_id );

		if ( $before['level'] !== $level || $before['expiry'] !== $expiry || $before['start'] !== $start ) {
			NHM_Log::add(
				array(
					'user_id'    => $user_id,
					'event'      => 'level_set',
					'level_from' => $before['level'],
					'level_to'   => $level,
					'expiry'     => $expiry,
					'message'    => $message,
				)
			);
			do_action( 'nhm_plan_changed', $user_id, $before['level'], $level, $expiry );
		}
	}

	/** Y-m-d or ''. */
	public static function clean_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return ( $d && $d->format( 'Y-m-d' ) === $date ) ? $date : '';
	}

	/** One year after max(today, $expiry): the "+1 year" renewal. */
	public static function plus_year( $expiry ) {
		$from = ( $expiry && $expiry > NHM_Settings::today() ) ? $expiry : NHM_Settings::today();
		$d    = DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		return $d->modify( '+1 year' )->format( 'Y-m-d' );
	}

	/** Used when a level is deleted. */
	public static function move_level( $from, $to ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_LEVEL, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (int) $from,      // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'     => 'ID',
				'number'     => -1,
			)
		);
		foreach ( $users as $user_id ) {
			$p = self::personal( $user_id );
			self::set_plan( $user_id, $to, $p['start'], $p['expiry'], null, sprintf( 'Level "%s" was deleted', NHM_Levels::name( $from ) ) );
		}
	}

	/**
	 * Where account emails go: the confirmed second email when the member chose it,
	 * else the login email.
	 */
	public static function contact_email( $user ) {
		$user = is_object( $user ) ? $user : get_userdata( $user );
		if ( ! $user ) {
			return '';
		}
		$second = (string) get_user_meta( $user->ID, 'nhm_email2', true );
		if ( $second && is_email( $second ) && 'second' === get_user_meta( $user->ID, 'nhm_email_pref', true ) ) {
			return $second;
		}
		return $user->user_email;
	}

	/** Profile fields (kept for older code): see NHM_Fields::all(). */
	public static function profile_fields() {
		return NHM_Fields::all();
	}
}
