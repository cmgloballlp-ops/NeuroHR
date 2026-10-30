<?php
/**
 * Block and delete members.
 *
 * Block: the account stays (so it can be unblocked), the person is logged out everywhere,
 * cannot log in, their own plan goes back to free and their email joins the block list,
 * so it cannot be used to register again. The block list (Settings → Registration) can also
 * hold whole domains such as @spam-domain.com.
 *
 * Delete: removes the WordPress account. Paid invoices stay (with the name and email printed
 * on them) unless "also delete invoices and payments" is ticked; unpaid ones are voided.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Block {

	const META = 'nhm_blocked';

	public static function init() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'stop_login' ), 5 );
		add_action( 'init', array( __CLASS__, 'kick_out' ), 1 );
		add_filter( 'registration_errors', array( __CLASS__, 'wp_register_check' ), 10, 3 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 5 );
	}

	/* ---------- Block list ---------- */

	/** @return string[] lower-case emails and @domains */
	public static function list_entries() {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) NHM_Settings::get( 'blocklist' ) ) as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( '*@' === substr( $line, 0, 2 ) ) {
				$line = substr( $line, 1 );
			}
			$out[] = $line;
		}
		return array_values( array_unique( $out ) );
	}

	private static function save_list( array $entries ) {
		NHM_Settings::update( array( 'blocklist' => implode( "\n", array_values( array_unique( array_filter( $entries ) ) ) ) ) );
	}

	public static function email_blocked( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return false;
		}
		$at     = strrpos( $email, '@' );
		$domain = false === $at ? '' : substr( $email, $at );
		foreach ( self::list_entries() as $entry ) {
			if ( $entry === $email || ( '@' === $entry[0] && $entry === $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/** The polite text shown at login and registration. */
	public static function message() {
		$contact = NHM_Settings::get( 'biz_email' ) ? NHM_Settings::get( 'biz_email' ) : get_option( 'admin_email' );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: contact email */
		return sprintf( __( 'This account can\'t be used on %1$s. If you think this is a mistake, please contact us at %2$s.', 'neurohr-members' ), $site, $contact );
	}

	/* ---------- Blocked people ---------- */

	public static function is_blocked( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::META, true );
	}

	/** @return array{date:string,reason:string,by:int}|null */
	public static function info( $user_id ) {
		$v = get_user_meta( (int) $user_id, self::META, true );
		return is_array( $v ) ? array_merge( array( 'date' => '', 'reason' => '', 'by' => 0 ), $v ) : ( $v ? array( 'date' => '', 'reason' => '', 'by' => 0 ) : null );
	}

	/** Staff and the person doing the action cannot be blocked or deleted here. */
	public static function protected_user( $user_id ) {
		return (int) $user_id === get_current_user_id() || user_can( (int) $user_id, NHM_CAP ) || user_can( (int) $user_id, 'manage_options' );
	}

	/** @return true|WP_Error */
	public static function block( $user_id, $reason = '' ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'nhm_block', __( 'This person no longer exists.', 'neurohr-members' ) );
		}
		if ( self::protected_user( $user->ID ) ) {
			return new WP_Error( 'nhm_block', __( 'Staff accounts and your own account cannot be blocked here.', 'neurohr-members' ) );
		}
		update_user_meta( $user->ID, self::META, array( 'date' => current_time( 'mysql' ), 'reason' => (string) $reason, 'by' => get_current_user_id() ) );
		// Out everywhere, now.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}
		// Own plan back to free; a company seat is freed (the company keeps its plan).
		$p = NHM_Members::personal( $user->ID );
		if ( (int) $p['level'] !== (int) NHM_Levels::base_id() ) {
			NHM_Members::set_plan( $user->ID, NHM_Levels::base_id(), '', '', null, 'Blocked' );
		}
		$company = (int) get_user_meta( $user->ID, NHM_Members::META_COMPANY, true );
		if ( $company && 'owner' !== get_user_meta( $user->ID, NHM_Members::META_ROLE, true ) ) {
			NHM_Companies::remove_member( $company, $user->ID );
		}
		self::void_unpaid( $user->ID );
		$entries = self::list_entries();
		$entries[] = strtolower( $user->user_email );
		self::save_list( $entries );
		NHM_Log::add( array( 'user_id' => $user->ID, 'event' => 'blocked', 'message' => '' !== $reason ? $reason : 'Blocked' ) );
		NHM_Members::flush( $user->ID );
		return true;
	}

	public static function unblock( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		delete_user_meta( $user->ID, self::META );
		$email = strtolower( $user->user_email );
		self::save_list( array_diff( self::list_entries(), array( $email ) ) );
		NHM_Log::add( array( 'user_id' => $user->ID, 'event' => 'unblocked', 'message' => 'Unblocked' ) );
		NHM_Members::flush( $user->ID );
	}

	/** Remove an email (or @domain) from the list, e.g. after deleting a blocked account. */
	public static function unlist( $entry ) {
		self::save_list( array_diff( self::list_entries(), array( strtolower( trim( (string) $entry ) ) ) ) );
	}

	/* ---------- Hooks ---------- */

	public static function stop_login( $user ) {
		if ( $user instanceof WP_User && ( self::is_blocked( $user->ID ) || ( self::email_blocked( $user->user_email ) && ! user_can( $user, 'manage_options' ) ) ) ) {
			return new WP_Error( 'nhm_blocked', self::message() );
		}
		return $user;
	}

	/** A blocked person who still has a session cookie is logged out on the next page. */
	public static function kick_out() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( self::is_blocked( $uid ) ) {
			wp_logout();
			if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				wp_safe_redirect( NHM_Settings::page_url( 'login' ) ? NHM_Settings::page_url( 'login' ) : home_url( '/' ) );
				exit;
			}
		}
	}

	/** wp-login.php?action=register, in case it is used. */
	public static function wp_register_check( $errors, $login, $email ) {
		if ( self::email_blocked( $email ) ) {
			$errors->add( 'nhm_blocked', self::message() );
		}
		return $errors;
	}

	/* ---------- Delete ---------- */

	private static function void_unpaid( $user_id ) {
		foreach ( NHM_Invoices::query( array( 'user_id' => (int) $user_id, 'status' => 'unpaid', 'limit' => 500 ) ) as $inv ) {
			if ( empty( $inv['company_id'] ) ) {
				NHM_Invoices::void( $inv['id'] );
			}
		}
	}

	/** Any account deletion (here, Users screen, cron): void their unpaid personal invoices. */
	public static function on_delete_user( $user_id ) {
		self::void_unpaid( $user_id );
	}

	/**
	 * @param bool $with_records also delete their personal invoices, payments, slips and history.
	 * @return true|WP_Error
	 */
	public static function delete_member( $user_id, $with_records = false ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'nhm_delete', __( 'This person no longer exists.', 'neurohr-members' ) );
		}
		if ( self::protected_user( $user->ID ) ) {
			return new WP_Error( 'nhm_delete', __( 'Staff accounts and your own account cannot be deleted here.', 'neurohr-members' ) );
		}
		global $wpdb;
		if ( $with_records ) {
			foreach ( NHM_Invoices::query( array( 'user_id' => $user->ID, 'limit' => 5000 ) ) as $inv ) {
				if ( ! empty( $inv['company_id'] ) ) {
					continue; // Company invoices belong to the company.
				}
				foreach ( NHM_Payments::query( array( 'invoice_id' => $inv['id'], 'limit' => 500 ) ) as $pay ) {
					$path = NHM_Payments::slip_path( $pay );
					if ( $path && is_file( $path ) ) {
						wp_delete_file( $path );
					}
					$wpdb->delete( NHM_Payments::table(), array( 'id' => (int) $pay['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				$wpdb->delete( NHM_Invoices::table(), array( 'id' => (int) $inv['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$wpdb->delete( NHM_DB::table( 'log' ), array( 'user_id' => $user->ID, 'company_id' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$email = $user->user_email;
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $user->ID );
		if ( ! $with_records ) {
			NHM_Log::add( array( 'user_id' => $user->ID, 'event' => 'deleted', 'message' => $email ) );
		}
		// A blocked email stays on the list after deletion, so it still cannot sign up.
		return true;
	}
}
