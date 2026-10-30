<?php
/**
 * "My account" in tabs: Profile · Security · Notifications · Company · Plan & billing.
 * [nhm_account] — optional tabs="profile,security,notifications,company,billing".
 * The tab is in the address (?tab=security), so each tab can be linked to and works without JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Account {

	public static function init() {
		add_filter( 'nhm_form_handlers', array( __CLASS__, 'handlers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'confirm_email2' ), 4 );
		add_action( 'wp_login', array( __CLASS__, 'keep_previous_login' ), 5, 2 );
	}

	public static function handlers( $h ) {
		$h['contact']         = array( __CLASS__, 'handle_contact' );
		$h['prefs']           = array( __CLASS__, 'handle_prefs' );
		$h['notifications']   = array( __CLASS__, 'handle_notifications' );
		$h['sessions']        = array( __CLASS__, 'handle_sessions' );
		$h['company_billing'] = array( __CLASS__, 'handle_company_billing' );
		return $h;
	}

	private static function back( $anchor = '' ) {
		$url = NHM_Forms::current_url( array( 'nhm_msg', 'nhm_invite' ) );
		return $anchor ? $url . '#' . $anchor : $url;
	}

	/** The login before this one (nhm_last_login is overwritten on every login). */
	public static function keep_previous_login( $login, $user ) {
		$last = (string) get_user_meta( $user->ID, NHM_Members::META_LOGIN, true );
		if ( '' !== $last ) {
			update_user_meta( $user->ID, 'nhm_prev_login', $last );
		}
	}

	/* ---------- Contact details, preferences, notifications, sessions ---------- */

	public static function handle_contact() {
		if ( ! self::require_login( 'contact' ) ) {
			return;
		}
		$user   = wp_get_current_user();
		$posted = NHM_Forms::posted();
		$email2 = isset( $posted['nhm_email2'] ) ? sanitize_email( (string) $posted['nhm_email2'] ) : '';
		$pref   = isset( $posted['nhm_email_pref'] ) && 'second' === $posted['nhm_email_pref'] ? 'second' : 'main';
		$old    = (string) get_user_meta( $user->ID, 'nhm_email2', true );
		$msg    = 'contact_saved';
		if ( '' !== trim( (string) ( isset( $posted['nhm_email2'] ) ? $posted['nhm_email2'] : '' ) ) && ! is_email( $email2 ) ) {
			NHM_Forms::error( 'contact', __( 'Enter a valid second email address.', 'neurohr-members' ) );
			return;
		}
		if ( '' !== $email2 && strtolower( $email2 ) === strtolower( $user->user_email ) ) {
			NHM_Forms::error( 'contact', __( 'The second email address must be different from your login email.', 'neurohr-members' ) );
			return;
		}
		if ( '' === $email2 ) {
			delete_user_meta( $user->ID, 'nhm_email2' );
			delete_user_meta( $user->ID, 'nhm_email2_pending' );
			delete_user_meta( $user->ID, 'nhm_email2_hash' );
			$pref = 'main';
		} elseif ( strtolower( $email2 ) !== strtolower( $old ) ) {
			if ( NHM_Forms::limited( 'email2', 5, 60 ) ) {
				NHM_Forms::error( 'contact', __( 'Too many tries. Please wait an hour.', 'neurohr-members' ) );
				return;
			}
			NHM_Forms::hit( 'email2', 60 );
			// Kept apart until confirmed; account emails keep going to the login email.
			$token = wp_generate_password( 32, false, false );
			update_user_meta( $user->ID, 'nhm_email2_pending', $email2 );
			update_user_meta( $user->ID, 'nhm_email2_hash', hash( 'sha256', $token ) );
			$url = add_query_arg( array( 'nhm_email2' => $token, 'u' => (int) $user->ID ), nhm_account_url() ? nhm_account_url() : home_url( '/' ) );
			NHM_Emails::send( 'verify_second', $email2, array( '{name}' => $user->display_name, '{email}' => $email2, '{verify_url}' => $url ) );
			$msg = 'email2_sent';
		}
		$confirmed = '' !== (string) get_user_meta( $user->ID, 'nhm_email2', true );
		update_user_meta( $user->ID, 'nhm_email_pref', $confirmed && 'second' === $pref ? 'second' : 'main' );
		NHM_Forms::done( self::back(), $msg );
	}

	/** Link from the "Confirm Second Email" email. */
	public static function confirm_email2() {
		// phpcs:disable WordPress.Security.NonceVerification -- the token is the check.
		if ( ! isset( $_GET['nhm_email2'], $_GET['u'] ) ) {
			return;
		}
		$uid   = absint( $_GET['u'] );
		$token = sanitize_text_field( wp_unslash( $_GET['nhm_email2'] ) );
		// phpcs:enable
		$hash    = (string) get_user_meta( $uid, 'nhm_email2_hash', true );
		$pending = (string) get_user_meta( $uid, 'nhm_email2_pending', true );
		$target  = remove_query_arg( array( 'nhm_email2', 'u' ), NHM_Forms::current_url() );
		if ( '' === $hash || '' === $pending || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			NHM_Forms::done( $target, 'verify_bad' );
		}
		update_user_meta( $uid, 'nhm_email2', $pending );
		delete_user_meta( $uid, 'nhm_email2_pending' );
		delete_user_meta( $uid, 'nhm_email2_hash' );
		NHM_Log::add( array( 'user_id' => $uid, 'event' => 'email2', 'actor_id' => $uid, 'message' => $pending ) );
		NHM_Forms::done( add_query_arg( 'tab', 'profile', $target ), 'email2_ok' );
	}

	public static function languages() {
		return array( 'en' => 'English', 'ms' => 'Bahasa Melayu', 'zh' => '中文' );
	}

	public static function timezones() {
		return array( 'Asia/Kuala_Lumpur', 'Asia/Singapore', 'Asia/Jakarta', 'Asia/Bangkok', 'Asia/Manila', 'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Tokyo', 'Asia/Kolkata', 'Asia/Dubai', 'Australia/Sydney', 'Europe/London', 'America/New_York', 'UTC' );
	}

	public static function handle_prefs() {
		if ( ! self::require_login( 'prefs' ) ) {
			return;
		}
		$posted = NHM_Forms::posted();
		$lang   = isset( $posted['nhm_lang'] ) ? sanitize_key( (string) $posted['nhm_lang'] ) : 'en';
		$tz     = isset( $posted['nhm_tz'] ) ? sanitize_text_field( (string) $posted['nhm_tz'] ) : 'Asia/Kuala_Lumpur';
		update_user_meta( get_current_user_id(), 'nhm_lang', isset( self::languages()[ $lang ] ) ? $lang : 'en' );
		update_user_meta( get_current_user_id(), 'nhm_tz', in_array( $tz, self::timezones(), true ) ? $tz : 'Asia/Kuala_Lumpur' );
		NHM_Forms::done( self::back(), 'profile_saved' );
	}

	/** Notification switches: key => [ title, text, MailerLite group setting ]. */
	public static function notification_types() {
		$types = array(
			'newsletter' => array( __( 'Newsletter', 'neurohr-members' ), __( 'HR news, law updates and tips, about twice a month.', 'neurohr-members' ), 'ml_group' ),
			'content'    => array( __( 'New content', 'neurohr-members' ), __( 'When new guides, templates and legal case insights are published.', 'neurohr-members' ), 'ml_group_content' ),
			'events'     => array( __( 'Events & webinars', 'neurohr-members' ), __( 'Invitations to our talks, trainings and webinars.', 'neurohr-members' ), 'ml_group_events' ),
		);
		foreach ( $types as $key => $t ) {
			if ( 'newsletter' !== $key && '' === trim( (string) NHM_Settings::get( $t[2] ) ) ) {
				unset( $types[ $key ] );
			}
		}
		if ( '' === trim( (string) NHM_Settings::get( 'ml_api_key' ) ) ) {
			return array();
		}
		return $types;
	}

	public static function handle_notifications() {
		if ( ! self::require_login( 'notifications' ) ) {
			return;
		}
		$user   = wp_get_current_user();
		$posted = NHM_Forms::posted();
		$failed = false;
		foreach ( self::notification_types() as $key => $t ) {
			$on  = ! empty( $posted[ 'nhm_n_' . $key ] );
			$was = (bool) get_user_meta( $user->ID, 'nhm_notify_' . $key, true );
			if ( $on === $was ) {
				continue;
			}
			$group = preg_replace( '/[^0-9]/', '', (string) NHM_Settings::get( $t[2] ) );
			$r     = NHM_MailerLite::set_group( NHM_Members::contact_email( $user ), $user->display_name, $group, $on );
			if ( is_wp_error( $r ) ) {
				$failed = true;
				continue;
			}
			update_user_meta( $user->ID, 'nhm_notify_' . $key, $on ? 1 : 0 );
		}
		if ( $failed ) {
			NHM_Forms::error( 'notifications', __( 'Some settings could not be saved just now. Please try again later.', 'neurohr-members' ) );
			return;
		}
		NHM_Forms::done( self::back(), 'notifications_saved' );
	}

	public static function handle_sessions() {
		if ( ! self::require_login( 'sessions' ) ) {
			return;
		}
		$manager = WP_Session_Tokens::get_instance( get_current_user_id() );
		$manager->destroy_others( wp_get_session_token() );
		NHM_Log::add( array( 'user_id' => get_current_user_id(), 'event' => 'sessions_ended', 'actor_id' => get_current_user_id() ) );
		NHM_Forms::done( self::back(), 'sessions_ended' );
	}

	/** Billing fields a company owner may change: key => [ label, type ]. */
	public static function company_billing_fields() {
		return array(
			'name'           => array( __( 'Company name', 'neurohr-members' ), 'text' ),
			'reg_no'         => array( __( 'Registration no. (SSM)', 'neurohr-members' ), 'text' ),
			'tin'            => array( __( 'TIN', 'neurohr-members' ), 'text' ),
			'sst_no'         => array( __( 'SST no.', 'neurohr-members' ), 'text' ),
			'contact_person' => array( __( 'Contact person', 'neurohr-members' ), 'text' ),
			'billing_email'  => array( __( 'Send invoices to', 'neurohr-members' ), 'email' ),
			'phone'          => array( __( 'Phone', 'neurohr-members' ), 'text' ),
			'address'        => array( __( 'Address', 'neurohr-members' ), 'textarea' ),
			'postcode'       => array( __( 'Postcode', 'neurohr-members' ), 'text' ),
			'city'           => array( __( 'City', 'neurohr-members' ), 'text' ),
			'state'          => array( __( 'State', 'neurohr-members' ), 'text' ),
			'country'        => array( __( 'Country', 'neurohr-members' ), 'text' ),
		);
	}

	public static function handle_company_billing() {
		if ( ! self::require_login( 'company_billing' ) ) {
			return;
		}
		$company = self::owned_company();
		if ( ! $company ) {
			NHM_Forms::error( 'company_billing', __( 'Only the company account owner can change these details.', 'neurohr-members' ) );
			return;
		}
		$posted  = NHM_Forms::posted();
		$data    = array();
		$changes = array();
		foreach ( self::company_billing_fields() as $key => $f ) {
			$raw = isset( $posted[ 'nhm_c_' . $key ] ) ? (string) $posted[ 'nhm_c_' . $key ] : '';
			if ( 'email' === $f[1] ) {
				$value = sanitize_email( $raw );
				if ( '' !== trim( $raw ) && ! is_email( $value ) ) {
					NHM_Forms::error( 'company_billing', __( 'Enter a valid email address for invoices.', 'neurohr-members' ) );
					return;
				}
			} else {
				$value = 'textarea' === $f[1] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			}
			if ( 'name' === $key && '' === $value ) {
				NHM_Forms::error( 'company_billing', __( 'Enter the company name.', 'neurohr-members' ) );
				return;
			}
			$data[ $key ] = $value;
			if ( trim( (string) $company[ $key ] ) !== trim( $value ) ) {
				$changes[] = $f[0] . ': ' . ( '' === trim( (string) $company[ $key ] ) ? '—' : str_replace( "\n", ', ', trim( (string) $company[ $key ] ) ) ) . ' → ' . ( '' === trim( $value ) ? '—' : str_replace( "\n", ', ', trim( $value ) ) );
			}
		}
		if ( ! $changes ) {
			NHM_Forms::done( self::back(), 'company_saved' );
		}
		NHM_Companies::update( $company['id'], $data );
		$user = wp_get_current_user();
		NHM_Log::add( array( 'user_id' => $user->ID, 'company_id' => $company['id'], 'event' => 'company_billing', 'actor_id' => $user->ID, 'message' => implode( '; ', $changes ) ) );
		NHM_Emails::send(
			'admin_company_billing',
			'',
			array(
				'{name}'              => $user->display_name,
				'{email}'             => $user->user_email,
				'{company}'           => $data['name'],
				'{changes}'           => implode( "\n", $changes ),
				'{company_admin_url}' => NHM_Companies::edit_url( $company['id'] ),
			)
		);
		NHM_Forms::done( self::back(), 'company_saved' );
	}

	private static function require_login( $form ) {
		if ( ! is_user_logged_in() ) {
			NHM_Forms::error( $form, __( 'Please log in again.', 'neurohr-members' ) );
			return false;
		}
		return true;
	}

	/** Company the current user owns, or null. */
	private static function owned_company() {
		$m = NHM_Members::membership();
		if ( ! $m['company_id'] || 'owner' !== $m['role'] ) {
			return null;
		}
		$company = NHM_Companies::get( $m['company_id'] );
		return ( $company && (int) $company['owner_id'] === get_current_user_id() ) ? $company : null;
	}

	/* ---------- Handlers ---------- */

	public static function handle_profile() {
		if ( ! self::require_login( 'profile' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the dispatcher.
		$user  = wp_get_current_user();
		$name  = isset( $_POST['nhm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_name'] ) ) : '';
		$email = isset( $_POST['nhm_email'] ) ? sanitize_email( wp_unslash( $_POST['nhm_email'] ) ) : '';
		$pass  = isset( $_POST['nhm_current'] ) ? (string) wp_unslash( $_POST['nhm_current'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$data   = array();
		$posted = NHM_Forms::posted();
		foreach ( NHM_Fields::for_account() as $key => $f ) {
			list( $value, $err ) = NHM_Fields::read( $f, $posted );
			if ( $err ) {
				NHM_Forms::error( 'profile', $err );
			}
			$data[ $key ] = $value;
		}
		if ( '' === $name ) {
			NHM_Forms::error( 'profile', __( 'Enter your name.', 'neurohr-members' ) );
		}
		$email_changed = strtolower( $email ) !== strtolower( $user->user_email );
		if ( ! is_email( $email ) ) {
			NHM_Forms::error( 'profile', __( 'Enter a valid email address.', 'neurohr-members' ) );
		} elseif ( $email_changed ) {
			$other = email_exists( $email );
			if ( NHM_Block::email_blocked( $email ) ) {
				NHM_Forms::error( 'profile', __( 'This email address can\'t be used. Please choose another one.', 'neurohr-members' ) );
			} elseif ( $other && (int) $other !== (int) $user->ID ) {
				NHM_Forms::error( 'profile', __( 'Another account already uses this email address.', 'neurohr-members' ) );
			} elseif ( ! wp_check_password( $pass, $user->user_pass, $user->ID ) ) {
				NHM_Forms::error( 'profile', __( 'To change your email address, enter your current password.', 'neurohr-members' ) );
			}
		}
		if ( NHM_Forms::has_errors( 'profile' ) ) {
			return;
		}
		$update = array(
			'ID'           => $user->ID,
			'display_name' => $name,
			'first_name'   => $name,
			'last_name'    => '',
		);
		if ( $email_changed ) {
			$update['user_email'] = $email;
		}
		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			NHM_Forms::error( 'profile', $result->get_error_message() );
			return;
		}
		$all = NHM_Fields::all();
		foreach ( $data as $key => $value ) {
			NHM_Fields::store( $user->ID, $all[ $key ], $value );
		}
		NHM_Forms::done( self::back( 'nhm-profile' ), 'profile_saved' );
	}

	/** Billing details for invoices (optional for people; companies use the company record). */
	public static function billing_fields() {
		return array(
			'nhm_bill_name'    => array( __( 'Name on invoice', 'neurohr-members' ), 'text', 'name' ),
			'nhm_bill_company' => array( __( 'Company name on invoice', 'neurohr-members' ), 'text', 'organization' ),
			'nhm_bill_reg'     => array( __( 'Registration no. (SSM)', 'neurohr-members' ), 'text', '' ),
			'nhm_bill_tin'     => array( __( 'TIN (tax no.)', 'neurohr-members' ), 'text', '' ),
			'nhm_bill_email'   => array( __( 'Send invoices to', 'neurohr-members' ), 'email', 'email' ),
			'nhm_bill_address' => array( __( 'Billing address', 'neurohr-members' ), 'textarea', 'street-address' ),
		);
	}

	public static function handle_billing() {
		if ( ! self::require_login( 'billing' ) ) {
			return;
		}
		$posted = NHM_Forms::posted();
		$uid    = get_current_user_id();
		foreach ( self::billing_fields() as $key => $f ) {
			$raw   = isset( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
			$value = 'textarea' === $f[1] ? sanitize_textarea_field( $raw ) : ( 'email' === $f[1] ? sanitize_email( $raw ) : sanitize_text_field( $raw ) );
			if ( 'email' === $f[1] && '' !== trim( $raw ) && ! is_email( $value ) ) {
				NHM_Forms::error( 'billing', __( 'Enter a valid email address for invoices.', 'neurohr-members' ) );
				return;
			}
			'' === $value ? delete_user_meta( $uid, $key ) : update_user_meta( $uid, $key, $value );
		}
		NHM_Forms::done( self::back( 'nhm-billing' ), 'billing_saved' );
	}

	public static function handle_password() {
		if ( ! self::require_login( 'password' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- verified by the dispatcher; passwords are not sanitised.
		$user    = wp_get_current_user();
		$current = isset( $_POST['nhm_current'] ) ? (string) wp_unslash( $_POST['nhm_current'] ) : '';
		$new     = isset( $_POST['nhm_new'] ) ? (string) wp_unslash( $_POST['nhm_new'] ) : '';
		// phpcs:enable
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			NHM_Forms::error( 'password', __( 'Your current password is not correct.', 'neurohr-members' ) );
			return;
		}
		$min = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		if ( strlen( $new ) < $min ) {
			/* translators: %d: number of characters */
			NHM_Forms::error( 'password', sprintf( __( 'Choose a password of at least %d characters.', 'neurohr-members' ), $min ) );
			return;
		}
		// wp_update_user() logs the current user in again with the new password.
		wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $new ) );
		NHM_Forms::done( self::back( 'nhm-password' ), 'password_changed' );
	}

	public static function handle_invite() {
		if ( ! self::require_login( 'company' ) ) {
			return;
		}
		$company = self::owned_company();
		if ( ! $company ) {
			NHM_Forms::error( 'company', __( 'Only the company account owner can invite people.', 'neurohr-members' ) );
			return;
		}
		$email  = isset( $_POST['nhm_invite_email'] ) ? sanitize_email( wp_unslash( $_POST['nhm_invite_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$result = NHM_Companies::invite( $company['id'], $email, 'member', get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			NHM_Forms::error( 'company', $result->get_error_message() );
			return;
		}
		NHM_Forms::done( self::back( 'nhm-company' ), 'invited' );
	}

	/** Invitation row of the owner's company, or null. */
	private static function own_invite() {
		$company = self::owned_company();
		$id      = isset( $_POST['nhm_invite_id'] ) ? absint( $_POST['nhm_invite_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$invite  = $id ? NHM_Companies::invite_row( $id ) : null;
		return ( $company && $invite && (int) $invite['company_id'] === $company['id'] ) ? $invite : null;
	}

	public static function handle_resend() {
		$invite = self::own_invite();
		if ( ! $invite ) {
			NHM_Forms::error( 'company', __( 'Invitation not found.', 'neurohr-members' ) );
			return;
		}
		NHM_Companies::resend( $invite['id'], get_current_user_id() );
		NHM_Forms::done( self::back( 'nhm-company' ), 'resent' );
	}

	public static function handle_cancel_invite() {
		$invite = self::own_invite();
		if ( ! $invite ) {
			NHM_Forms::error( 'company', __( 'Invitation not found.', 'neurohr-members' ) );
			return;
		}
		NHM_Companies::cancel_invite( $invite['id'] );
		NHM_Forms::done( self::back( 'nhm-company' ), 'invite_cancelled' );
	}

	public static function handle_remove_member() {
		$company = self::owned_company();
		$user_id = isset( $_POST['nhm_user'] ) ? absint( $_POST['nhm_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $company || ! $user_id || $user_id === get_current_user_id()
			|| (int) get_user_meta( $user_id, NHM_Members::META_COMPANY, true ) !== $company['id'] ) {
			NHM_Forms::error( 'company', __( 'That person cannot be removed.', 'neurohr-members' ) );
			return;
		}
		NHM_Companies::remove_member( $company['id'], $user_id );
		NHM_Forms::done( self::back( 'nhm-company' ), 'member_removed' );
	}

	public static function handle_leave() {
		if ( ! self::require_login( 'company' ) ) {
			return;
		}
		$m = NHM_Members::membership();
		if ( ! $m['company_id'] ) {
			return;
		}
		if ( 'owner' === $m['role'] ) {
			NHM_Forms::error( 'company', __( 'The account owner cannot leave the company. Contact us to hand it over.', 'neurohr-members' ) );
			return;
		}
		NHM_Companies::remove_member( $m['company_id'], get_current_user_id(), 'left' );
		NHM_Emails::left( $m['company_id'], get_current_user_id() );
		NHM_Forms::done( self::back(), 'left' );
	}

	public static function handle_accept_invite() {
		if ( ! self::require_login( 'invite' ) ) {
			return;
		}
		$token  = isset( $_POST['nhm_invite'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$result = NHM_Companies::accept( $token, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			NHM_Forms::error( 'invite', $result->get_error_message() );
			return;
		}
		$target = nhm_account_url() ? nhm_account_url() : self::back();
		NHM_Forms::done( $target, 'joined' );
	}

	/* ---------- Rendering ---------- */

	/** Box for a logged-in person who opened an invitation link. */
	public static function render_invite_box( $token ) {
		$invite = NHM_Companies::find_invite( $token );
		$html   = NHM_Forms::render_messages( 'invite' );
		if ( ! $invite ) {
			return $html . '<div class="nhm-alert nhm-alert--error"><p>' . esc_html__( 'This invitation link is not valid any more. Ask your company to send a new one.', 'neurohr-members' ) . '</p></div>';
		}
		$company = NHM_Companies::get( $invite['company_id'] );
		$user    = wp_get_current_user();
		$html   .= '<div class="nhm-card nhm-invite">';
		$html   .= '<h3 class="nhm-card__title">' . esc_html__( 'Invitation', 'neurohr-members' ) . '</h3>';
		/* translators: 1: company, 2: plan */
		$html .= '<p>' . wp_kses( sprintf( __( 'You have been invited to join <strong>%1$s</strong> with the %2$s plan.', 'neurohr-members' ), esc_html( $company['name'] ), esc_html( NHM_Levels::name( $company['level_id'] ) ) ), array( 'strong' => array() ) ) . '</p>';
		if ( strtolower( $user->user_email ) !== strtolower( $invite['email'] ) ) {
			/* translators: 1: invited email, 2: current email */
			$html .= '<p class="nhm-muted">' . esc_html( sprintf( __( 'The invitation was sent to %1$s, but you are logged in as %2$s. Log out and log in with the invited address, or ask for an invitation to your own address.', 'neurohr-members' ), $invite['email'], $user->user_email ) ) . '</p>';
			$html .= '<p class="nhm-actions"><a class="nhm-btn" href="' . esc_url( wp_logout_url( NHM_Auth::login_url( NHM_Companies::invite_url( $token ) ) ) ) . '">' . esc_html__( 'Log out', 'neurohr-members' ) . '</a></p>';
		} else {
			$html .= NHM_Forms::open( 'accept_invite' );
			$html .= '<input type="hidden" name="nhm_invite" value="' . esc_attr( $token ) . '">';
			$html .= NHM_Forms::button( __( 'Accept and join', 'neurohr-members' ) );
			$html .= '</form>';
		}
		return $html . '</div>';
	}

	public static function tabs() {
		return array(
			'profile'       => __( 'Profile', 'neurohr-members' ),
			'security'      => __( 'Security', 'neurohr-members' ),
			'notifications' => __( 'Notifications', 'neurohr-members' ),
			'company'       => __( 'Company', 'neurohr-members' ),
			'billing'       => __( 'Plan & billing', 'neurohr-members' ),
		);
	}

	/** Which tab a form belongs to (so errors open the right tab). */
	private static function form_tab( $form ) {
		$map = array(
			'profile' => 'profile', 'avatar' => 'profile', 'contact' => 'profile', 'prefs' => 'profile',
			'password' => 'security', 'sessions' => 'security',
			'notifications' => 'notifications',
			'company' => 'company', 'company_billing' => 'company',
			'billing' => 'billing', 'autorenew' => 'billing', 'refund' => 'billing',
		);
		return isset( $map[ $form ] ) ? $map[ $form ] : '';
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'tabs' => 'profile,security,notifications,company,billing', 'sections' => '', 'logout' => 'no' ), $atts, 'nhm_account' );
		NHM_Forms::no_cache();
		// A pay link (?nhm_pay=…) opens the pay page here when there is no separate payment page.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['nhm_pay'] ) || NHM_Forms::has_errors( 'pay_proof' ) || NHM_Forms::has_errors( 'pay_stripe' ) ) {
			return NHM_Payments::shortcode();
		}
		if ( ! is_user_logged_in() ) {
			$html = '<p class="nhm-lead">' . esc_html__( 'Please log in to see your account.', 'neurohr-members' ) . '</p>';
			return NHM_Forms::wrap( $html, 'nhm-account' ) . NHM_Auth::render_login( array( 'redirect' => NHM_Forms::current_url( array( 'nhm_msg' ) ) ) );
		}
		$m    = NHM_Members::membership();
		$all  = self::tabs();
		$want = array_filter( array_map( 'trim', explode( ',', strtolower( $atts['tabs'] ) ) ) );
		$tabs = array();
		foreach ( $want as $t ) {
			if ( isset( $all[ $t ] ) && ( 'company' !== $t || $m['company_id'] ) && ( 'notifications' !== $t || self::notification_types() ) ) {
				$tabs[ $t ] = $all[ $t ];
			}
		}
		if ( ! $tabs ) {
			$tabs = array( 'profile' => $all['profile'] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// A form with errors opens its own tab.
		foreach ( array( 'profile', 'avatar', 'contact', 'prefs', 'password', 'sessions', 'notifications', 'company', 'company_billing', 'billing' ) as $form ) {
			if ( NHM_Forms::has_errors( $form ) && isset( $tabs[ self::form_tab( $form ) ] ) ) {
				$tab = self::form_tab( $form );
				break;
			}
		}
		$tab = isset( $tabs[ $tab ] ) ? $tab : key( $tabs );

		$html = '';
		// phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_GET['nhm_invite'] ) ? sanitize_text_field( wp_unslash( $_GET['nhm_invite'] ) ) : '';
		if ( $token ) {
			$html .= self::render_invite_box( $token );
		}
		$html .= NHM_Forms::render_messages( 'account' );

		if ( count( $tabs ) > 1 ) {
			$html .= '<nav class="nhm-tabs" aria-label="' . esc_attr__( 'Account sections', 'neurohr-members' ) . '">';
			$base  = remove_query_arg( array( 'nhm_msg', 'nhm_invite', 'tab' ), NHM_Forms::current_url() );
			foreach ( $tabs as $key => $label ) {
				$html .= '<a class="nhm-tab' . ( $key === $tab ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $key, $base ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			$html .= '</nav>';
		}

		$html .= '<div class="nhm-tabpanel nhm-tabpanel--' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'security':
				$html .= self::password_card() . self::sessions_card();
				break;
			case 'notifications':
				$html .= self::notifications_card();
				break;
			case 'company':
				$html .= self::company_card() . self::company_billing_card();
				break;
			case 'billing':
				$html .= self::hold_notice() . self::plan_card() . NHM_Renew::card( get_current_user_id() ) . self::invoices_card() . self::billing_card();
				break;
			default:
				$html .= self::profile_card() . self::contact_card() . self::prefs_card();
		}
		$html .= '</div>';
		if ( 'yes' === $atts['logout'] ) {
			$html .= '<p class="nhm-logout"><a href="' . esc_url( wp_logout_url() ) . '">' . esc_html__( 'Log out', 'neurohr-members' ) . '</a></p>';
		}
		return NHM_Forms::wrap( $html, 'nhm-account' );
	}

	public static function status_text( $m ) {
		if ( 'base' === $m['source'] ) {
			return __( 'No end date', 'neurohr-members' );
		}
		if ( '' === $m['expiry'] ) {
			return __( 'No end date', 'neurohr-members' );
		}
		$date = NHM_Emails::format_date( $m['expiry'] );
		if ( 0 === (int) $m['days_left'] ) {
			/* translators: %s: date */
			return sprintf( __( 'Valid until %s (last day today)', 'neurohr-members' ), $date );
		}
		/* translators: 1: date, 2: number of days */
		return sprintf( _n( 'Valid until %1$s (%2$d day left)', 'Valid until %1$s (%2$d days left)', (int) $m['days_left'], 'neurohr-members' ), $date, (int) $m['days_left'] );
	}

	/**
	 * The plan card (My account → Plan & billing, and [nhm_plan_card] on any page).
	 * Renew shows from 30 days before the last day or after it ended; Upgrade below the top plan.
	 *
	 * @param array $args style: dark | light; title.
	 */
	public static function plan_card( $args = array() ) {
		$args    = array_merge( array( 'style' => 'dark', 'title' => __( 'Your plan', 'neurohr-members' ) ), (array) $args );
		$m       = NHM_Members::membership();
		$company = $m['company_id'] ? NHM_Companies::get( $m['company_id'] ) : null;
		$renew   = NHM_Settings::url( 'renew_url', NHM_Levels::upgrade_url() );

		$cls = 'nhm-card nhm-plan' . ( 'light' === $args['style'] ? ' nhm-plan--light' : ' nhm-plan--dark' );

		// A plan waiting for payment takes over the whole card.
		$open = NHM_Invoices::pending_for( get_current_user_id() );
		if ( $open ) {
			$inv     = $open[0];
			$waiting = NHM_Payments::waiting_for( $inv['id'] );
			$h       = '<section class="' . esc_attr( $cls ) . ' nhm-plan--pending">';
			$h      .= '' !== (string) $args['title'] ? '<h3 class="nhm-card__title">' . esc_html( $args['title'] ) . '</h3>' : '';
			$h      .= '<div class="nhm-plan__head"><span class="nhm-plan__name">' . esc_html( NHM_Levels::name( $inv['level_id'] ) ) . '</span>';
			$h      .= '<span class="nhm-pill nhm-pill--wait">' . esc_html( $waiting ? __( 'Checking payment', 'neurohr-members' ) : __( 'Payment pending', 'neurohr-members' ) ) . '</span></div>';
			if ( $waiting ) {
				$h .= '<p class="nhm-plan__status">' . esc_html__( 'We received your payment details and will confirm within 3 working days.', 'neurohr-members' ) . '</p>';
				return $h . '</section>';
			}
			/* translators: 1: amount, 2: plan */
			$h .= '<p class="nhm-plan__status">' . esc_html( sprintf( __( 'Complete your %1$s payment to start your %2$s plan.', 'neurohr-members' ), NHM_Settings::money( $inv['total'] ), NHM_Levels::name( $inv['level_id'] ) ) ) . '</p>';
			$h .= '<p class="nhm-actions">';
			if ( NHM_Payments::payable( $inv ) ) {
				$h .= '<a class="nhm-btn nhm-btn--primary" href="' . esc_url( NHM_Payments::pay_url( $inv ) ) . '">' . esc_html__( 'Complete payment', 'neurohr-members' ) . '</a> ';
			}
			$h .= '<a class="nhm-plan__link" href="' . esc_url( NHM_Invoices::download_url( $inv ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download invoice', 'neurohr-members' ) . '</a></p>';
			return $h . '</section>';
		}

		$h  = '<section class="' . esc_attr( $cls ) . '">';
		$h .= '' !== (string) $args['title'] ? '<h3 class="nhm-card__title">' . esc_html( $args['title'] ) . '</h3>' : '';
		$h .= '<div class="nhm-plan__head"><span class="nhm-plan__name">' . esc_html( $m['name'] ) . '</span>';
		if ( 'company' === $m['source'] && $company ) {
			/* translators: %s: company */
			$h .= '<span class="nhm-pill">' . esc_html( sprintf( __( 'via %s', 'neurohr-members' ), $company['name'] ) ) . '</span>';
		}
		$h .= '</div>';
		$h .= '<p class="nhm-plan__status">' . esc_html( self::status_text( $m ) ) . '</p>';

		if ( $m['expired_level'] ) {
			$p      = $m['personal'];
			$ended  = $p && (int) $p['level'] === (int) $m['expired_level'] ? $p['expiry'] : ( $company ? $company['expiry_date'] : '' );
			/* translators: 1: plan, 2: date */
			$h .= '<div class="nhm-alert nhm-alert--warning"><p>' . esc_html( sprintf( __( 'Your %1$s plan ended on %2$s.', 'neurohr-members' ), NHM_Levels::name( $m['expired_level'] ), NHM_Emails::format_date( $ended ) ) ) . '</p></div>';
		}

		$buttons = '';
		$soon    = null !== $m['days_left'] && $m['days_left'] <= 30;
		if ( $m['expired_level'] || $soon ) {
			$renew_level = $m['expired_level'] ? $m['expired_level'] : $m['level'];
			if ( 'company' !== $m['source'] && NHM_Levels::is_paid( $renew_level ) && NHM_Settings::page_url( 'register' ) ) {
				$renew = NHM_Levels::register_url( $renew_level );
			}
			$buttons .= '<a class="nhm-btn nhm-btn--primary" href="' . esc_url( $renew ) . '">' . esc_html__( 'Renew', 'neurohr-members' ) . '</a> ';
		}
		if ( (int) $m['rank'] < NHM_Levels::max_rank() ) {
			$buttons .= '<a class="nhm-btn' . ( $buttons ? '' : ' nhm-btn--primary' ) . '" href="' . esc_url( NHM_Levels::upgrade_from( $m['level'] ) ) . '">' . esc_html__( 'Upgrade', 'neurohr-members' ) . '</a>';
		}
		if ( $buttons ) {
			$h .= '<p class="nhm-actions">' . $buttons . '</p>';
		}
		return $h . '</section>';
	}

	private static function company_card() {
		$m = NHM_Members::membership();
		if ( ! $m['company_id'] ) {
			return '';
		}
		$company = NHM_Companies::get( $m['company_id'] );
		if ( ! $company ) {
			return '';
		}
		$is_owner = 'owner' === $m['role'] && (int) $company['owner_id'] === get_current_user_id();
		$h        = '<section class="nhm-card nhm-company" id="nhm-company">';
		$h       .= '<h3 class="nhm-card__title">' . esc_html( $company['name'] ) . '</h3>';
		$h       .= NHM_Forms::render_messages( 'company' );
		$status   = NHM_Companies::is_active( $company )
			? ( $company['expiry_date'] ? sprintf( /* translators: %s: date */ __( 'until %s', 'neurohr-members' ), NHM_Emails::format_date( $company['expiry_date'] ) ) : __( 'no end date', 'neurohr-members' ) )
			: sprintf( /* translators: %s: date */ __( 'ended %s', 'neurohr-members' ), NHM_Emails::format_date( $company['expiry_date'] ) );
		$h       .= '<p class="nhm-muted">' . esc_html( NHM_Levels::name( $company['level_id'] ) . ' · ' . $status ) . '</p>';

		if ( ! $is_owner ) {
			$owner = $company['owner_id'] ? get_userdata( $company['owner_id'] ) : null;
			if ( $owner ) {
				/* translators: 1: name, 2: email */
				$h .= '<p>' . esc_html( sprintf( __( 'Your company account is managed by %1$s (%2$s).', 'neurohr-members' ), $owner->display_name, $owner->user_email ) ) . '</p>';
			}
			$h .= NHM_Forms::open( 'leave', 'nhm-inline' );
			$h .= '<button type="submit" class="nhm-btn nhm-btn--small" data-nhm-confirm="' . esc_attr__( 'Leave the company? You will lose the company plan.', 'neurohr-members' ) . '">' . esc_html__( 'Leave company', 'neurohr-members' ) . '</button></form>';
			return $h . '</section>';
		}

		$used = NHM_Companies::seats_used( $company['id'] );
		/* translators: 1: used, 2: total */
		$h .= '<p class="nhm-seats"><strong>' . esc_html( sprintf( __( '%1$d of %2$d seats used', 'neurohr-members' ), $used, $company['seats'] ) ) . '</strong></p>';

		$h .= '<div class="nhm-table-wrap"><table class="nhm-table"><thead><tr><th>' . esc_html__( 'Name', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Email', 'neurohr-members' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( NHM_Companies::members( $company['id'] ) as $member ) {
			$h .= '<tr><td>' . esc_html( $member->display_name );
			if ( (int) $member->ID === (int) $company['owner_id'] ) {
				$h .= ' <span class="nhm-pill">' . esc_html__( 'Owner', 'neurohr-members' ) . '</span>';
			}
			$h .= '</td><td>' . esc_html( $member->user_email ) . '</td><td class="nhm-table__actions">';
			if ( (int) $member->ID !== (int) $company['owner_id'] ) {
				$h .= NHM_Forms::open( 'remove_member', 'nhm-inline' ) . '<input type="hidden" name="nhm_user" value="' . (int) $member->ID . '">';
				/* translators: %s: name */
				$h .= '<button type="submit" class="nhm-btn nhm-btn--small" data-nhm-confirm="' . esc_attr( sprintf( __( 'Remove %s from the company?', 'neurohr-members' ), $member->display_name ) ) . '">' . esc_html__( 'Remove', 'neurohr-members' ) . '</button></form>';
			}
			$h .= '</td></tr>';
		}
		foreach ( NHM_Companies::invites( $company['id'] ) as $invite ) {
			$h .= '<tr class="nhm-table__pending"><td><span class="nhm-pill nhm-pill--muted">' . esc_html__( 'Invited', 'neurohr-members' ) . '</span></td><td>' . esc_html( $invite['email'] ) . '</td><td class="nhm-table__actions">';
			$h .= NHM_Forms::open( 'resend', 'nhm-inline' ) . '<input type="hidden" name="nhm_invite_id" value="' . (int) $invite['id'] . '"><button type="submit" class="nhm-btn nhm-btn--small">' . esc_html__( 'Send again', 'neurohr-members' ) . '</button></form> ';
			$h .= NHM_Forms::open( 'cancel_invite', 'nhm-inline' ) . '<input type="hidden" name="nhm_invite_id" value="' . (int) $invite['id'] . '"><button type="submit" class="nhm-btn nhm-btn--small">' . esc_html__( 'Cancel', 'neurohr-members' ) . '</button></form>';
			$h .= '</td></tr>';
		}
		$h .= '</tbody></table></div>';

		if ( $used < $company['seats'] ) {
			$h .= NHM_Forms::open( 'invite', 'nhm-invite-form' );
			$h .= NHM_Forms::field( array( 'name' => 'nhm_invite_email', 'label' => __( 'Invite a colleague by email', 'neurohr-members' ), 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'value' => NHM_Forms::has_errors( 'company' ) ? NHM_Forms::value( 'invite', 'nhm_invite_email' ) : '' ) );
			$h .= NHM_Forms::button( __( 'Send invitation', 'neurohr-members' ) );
			$h .= '</form>';
		} else {
			$h .= '<p class="nhm-muted">' . esc_html__( 'All seats are in use. Remove someone to invite another person, or contact us for more seats.', 'neurohr-members' ) . '</p>';
		}
		return $h . '</section>';
	}

	private static function profile_card() {
		$user = wp_get_current_user();
		$h    = '<section class="nhm-card nhm-profile" id="nhm-profile"><div class="nhm-profile__grid"><div class="nhm-profile__main">';
		$h   .= '<h3 class="nhm-card__title">' . esc_html__( 'Profile information', 'neurohr-members' ) . '</h3>';
		$h   .= '<p class="nhm-card__intro">' . esc_html__( 'Keep your personal information up to date.', 'neurohr-members' ) . '</p>';
		$h   .= NHM_Forms::render_messages( 'profile' );
		$err  = NHM_Forms::has_errors( 'profile' );
		$h   .= NHM_Forms::open( 'profile' );
		$h   .= '<div class="nhm-grid">';
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_name', 'label' => __( 'Full name', 'neurohr-members' ), 'value' => $err ? NHM_Forms::value( 'profile', 'nhm_name' ) : $user->display_name, 'required' => true, 'autocomplete' => 'name' ) );
		$fields = NHM_Fields::for_account();
		if ( isset( $fields['job'] ) ) {
			$h .= self::account_field( $fields['job'], $err, $user->ID );
			unset( $fields['job'] );
		}
		$h .= NHM_Forms::field( array( 'name' => 'nhm_email', 'label' => __( 'Email address (login)', 'neurohr-members' ), 'type' => 'email', 'value' => $err ? NHM_Forms::value( 'profile', 'nhm_email' ) : $user->user_email, 'required' => true, 'autocomplete' => 'email' ) );
		foreach ( $fields as $f ) {
			$h .= self::account_field( $f, $err, $user->ID );
		}
		$h .= '</div>';
		$h .= '<details class="nhm-more"' . ( $err && NHM_Forms::value( 'profile', 'nhm_current' ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Changing your login email? Confirm with your password', 'neurohr-members' ) . '</summary>';
		$h .= NHM_Forms::field( array( 'name' => 'nhm_current', 'label' => __( 'Current password', 'neurohr-members' ), 'type' => 'password', 'autocomplete' => 'current-password' ) );
		$h .= '</details>';
		$h .= NHM_Forms::button( __( 'Save changes', 'neurohr-members' ) );
		$h .= '</form></div>';
		$h .= self::photo_panel( $user );
		return $h . '</div></section>';
	}

	private static function account_field( array $f, $err, $user_id ) {
		$value = $err ? NHM_Forms::raw( 'profile', 'nhm_' . $f['key'], 'checkboxes' === $f['type'] ? array() : '' ) : NHM_Fields::value( $user_id, $f );
		if ( $err && 'tel' === $f['type'] ) {
			$value = NHM_Fields::join_phone( NHM_Forms::raw( 'profile', 'nhm_' . $f['key'] . '_cc', '+60' ), $value );
		}
		return NHM_Fields::render( $f, $value );
	}

	private static function photo_panel( $user ) {
		$has = '' !== NHM_Avatar::url( $user->ID );
		$h   = '<div class="nhm-photo">';
		$h  .= '<div class="nhm-photo__img">' . NHM_Avatar::img( $user->ID, 132, 'nhm-photo__pic' ) . '</div>';
		$h  .= '<strong class="nhm-photo__title">' . esc_html__( 'Profile photo', 'neurohr-members' ) . '</strong>';
		$h  .= '<span class="nhm-muted">' . esc_html__( 'JPG or PNG, up to 2 MB. Square photos look best.', 'neurohr-members' ) . '</span>';
		$h  .= NHM_Forms::render_messages( 'avatar' );
		$h  .= NHM_Forms::open( 'avatar', 'nhm-photo__form', true );
		$h  .= '<input type="hidden" name="MAX_FILE_SIZE" value="' . (int) NHM_Avatar::MAX_BYTES . '">';
		$h  .= '<label class="nhm-btn nhm-btn--small nhm-photo__pick">' . esc_html( $has ? __( 'Change photo', 'neurohr-members' ) : __( 'Upload photo', 'neurohr-members' ) ) . '<input type="file" name="nhm_photo" accept="image/jpeg,image/png" data-nhm-photo-input=".nhm-photo__pic" onchange="this.form.querySelector(\'.nhm-photo__save\').hidden=false"></label>';
		$h  .= '<button type="submit" class="nhm-btn nhm-btn--small nhm-btn--primary nhm-photo__save" hidden>' . esc_html__( 'Save photo', 'neurohr-members' ) . '</button>';
		$h  .= '</form>';
		if ( $has ) {
			$h .= NHM_Forms::open( 'avatar_remove', 'nhm-inline' ) . '<button type="submit" class="nhm-link nhm-link--muted">' . esc_html__( 'Remove photo', 'neurohr-members' ) . '</button></form>';
		}
		return $h . '</div>';
	}

	private static function contact_card() {
		$user    = wp_get_current_user();
		$email2  = (string) get_user_meta( $user->ID, 'nhm_email2', true );
		$pending = (string) get_user_meta( $user->ID, 'nhm_email2_pending', true );
		$pref    = 'second' === get_user_meta( $user->ID, 'nhm_email_pref', true ) && $email2 ? 'second' : 'main';
		$h       = '<section class="nhm-card nhm-contact" id="nhm-contact">';
		$h      .= '<h3 class="nhm-card__title">' . esc_html__( 'Contact details', 'neurohr-members' ) . '</h3>';
		$h      .= '<p class="nhm-card__intro">' . esc_html__( 'Add a second email address, for example a personal one, and choose where account emails go. You always log in with your login email.', 'neurohr-members' ) . '</p>';
		$h      .= NHM_Forms::render_messages( 'contact' );
		$h      .= NHM_Forms::open( 'contact' ) . '<div class="nhm-grid">';
		$h      .= NHM_Forms::field( array( 'name' => 'nhm_email2', 'label' => __( 'Second email address', 'neurohr-members' ), 'type' => 'email', 'value' => NHM_Forms::has_errors( 'contact' ) ? NHM_Forms::value( 'contact', 'nhm_email2' ) : ( $pending ? $pending : $email2 ), 'autocomplete' => 'email', 'help' => $pending ? __( 'Waiting for confirmation: open the link we sent to this address.', 'neurohr-members' ) : ( $email2 ? __( 'Confirmed.', 'neurohr-members' ) : '' ) ) );
		$h      .= '</div>';
		$h      .= '<fieldset class="nhm-radios"><legend>' . esc_html__( 'Send account emails to', 'neurohr-members' ) . '</legend>';
		$h      .= '<label class="nhm-check"><input type="radio" name="nhm_email_pref" value="main"' . checked( $pref, 'main', false ) . '> <span>' . esc_html( $user->user_email ) . ' <span class="nhm-muted">(' . esc_html__( 'login email', 'neurohr-members' ) . ')</span></span></label>';
		$h      .= '<label class="nhm-check"><input type="radio" name="nhm_email_pref" value="second"' . checked( $pref, 'second', false ) . disabled( '' === $email2, true, false ) . '> <span>' . ( $email2 ? esc_html( $email2 ) : esc_html__( 'Second email (after it is confirmed)', 'neurohr-members' ) ) . '</span></label>';
		$h      .= '</fieldset>';
		$h      .= NHM_Forms::button( __( 'Save contact details', 'neurohr-members' ), 'nhm-btn' ) . '</form>';
		return $h . '</section>';
	}

	private static function prefs_card() {
		$uid  = get_current_user_id();
		$lang = (string) get_user_meta( $uid, 'nhm_lang', true );
		$tz   = (string) get_user_meta( $uid, 'nhm_tz', true );
		$h    = '<section class="nhm-card nhm-prefs" id="nhm-prefs">';
		$h   .= '<h3 class="nhm-card__title">' . esc_html__( 'Preferences', 'neurohr-members' ) . '</h3>';
		$h   .= '<p class="nhm-card__intro">' . esc_html__( 'Saved for later: the site will use these when more languages are available.', 'neurohr-members' ) . '</p>';
		$h   .= NHM_Forms::render_messages( 'prefs' );
		$h   .= NHM_Forms::open( 'prefs' ) . '<div class="nhm-grid">';
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_lang', 'label' => __( 'Language', 'neurohr-members' ), 'type' => 'select', 'options' => array_values( self::languages() ), 'value' => isset( self::languages()[ $lang ] ) ? self::languages()[ $lang ] : 'English', 'required' => true ) );
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_tz', 'label' => __( 'Time zone', 'neurohr-members' ), 'type' => 'select', 'options' => self::timezones(), 'value' => $tz ? $tz : 'Asia/Kuala_Lumpur', 'required' => true ) );
		$h   .= '</div>' . NHM_Forms::button( __( 'Save preferences', 'neurohr-members' ), 'nhm-btn' ) . '</form>';
		return $h . '</section>';
	}

	private static function sessions_card() {
		$uid      = get_current_user_id();
		$sessions = WP_Session_Tokens::get_instance( $uid )->get_all();
		$prev     = (string) get_user_meta( $uid, 'nhm_prev_login', true );
		$h        = '<section class="nhm-card nhm-sessions" id="nhm-sessions">';
		$h       .= '<h3 class="nhm-card__title">' . esc_html__( 'Where you are logged in', 'neurohr-members' ) . '</h3>';
		if ( $prev ) {
			/* translators: %s: date and time */
			$h .= '<p class="nhm-card__intro">' . esc_html( sprintf( __( 'Your previous login: %s', 'neurohr-members' ), mysql2date( 'j M Y, g:i a', $prev ) ) ) . '</p>';
		}
		$h .= NHM_Forms::render_messages( 'sessions' );
		$h .= '<ul class="nhm-sessions__list">';
		$current = wp_get_session_token() ? hash( 'sha256', wp_get_session_token() ) : '';
		$tokens  = get_user_meta( $uid, 'session_tokens', true );
		$tokens  = is_array( $tokens ) ? $tokens : array();
		uasort( $tokens, function ( $a, $b ) { return (int) $b['login'] - (int) $a['login']; } );
		foreach ( $tokens as $verifier => $sess ) {
			if ( ! isset( $sess['expiration'] ) || $sess['expiration'] < time() ) {
				continue;
			}
			$h .= '<li><strong>' . esc_html( self::device_name( isset( $sess['ua'] ) ? $sess['ua'] : '' ) ) . '</strong>';
			if ( $verifier === $current ) {
				$h .= ' <span class="nhm-pill nhm-pill--ok">' . esc_html__( 'This device', 'neurohr-members' ) . '</span>';
			}
			/* translators: %s: date and time */
			$h .= '<span class="nhm-muted">' . esc_html( sprintf( __( 'Logged in %s', 'neurohr-members' ), wp_date( 'j M Y, g:i a', (int) $sess['login'] ) ) ) . '</span></li>';
		}
		$h .= '</ul>';
		if ( count( $sessions ) > 1 ) {
			$h .= NHM_Forms::open( 'sessions' ) . NHM_Forms::button( __( 'Log out on all other devices', 'neurohr-members' ), 'nhm-btn' ) . '</form>';
		}
		return $h . '</section>';
	}

	/** "Chrome on Windows" from a browser string (rough, for recognising your devices). */
	public static function device_name( $ua ) {
		$ua      = (string) $ua;
		$browser = __( 'Browser', 'neurohr-members' );
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = '';
		foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Linux' => 'Linux' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		/* translators: 1: browser, 2: system */
		return $os ? sprintf( __( '%1$s on %2$s', 'neurohr-members' ), $browser, $os ) : $browser;
	}

	private static function notifications_card() {
		$uid = get_current_user_id();
		$h   = '<section class="nhm-card nhm-notify" id="nhm-notifications">';
		$h  .= '<h3 class="nhm-card__title">' . esc_html__( 'Email notifications', 'neurohr-members' ) . '</h3>';
		$h  .= '<p class="nhm-card__intro">' . esc_html__( 'Choose what we may email you. You can change this any time.', 'neurohr-members' ) . '</p>';
		$h  .= NHM_Forms::render_messages( 'notifications' );
		$h  .= NHM_Forms::open( 'notifications' ) . '<ul class="nhm-switches">';
		foreach ( self::notification_types() as $key => $t ) {
			$on = (bool) get_user_meta( $uid, 'nhm_notify_' . $key, true );
			$h .= '<li><span class="nhm-toggle-text"><strong>' . esc_html( $t[0] ) . '</strong><span class="nhm-muted">' . esc_html( $t[1] ) . '</span></span>';
			$h .= '<label class="nhm-toggle-switch"><input type="checkbox" role="switch" name="nhm_n_' . esc_attr( $key ) . '" value="1"' . checked( $on, true, false ) . '><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $t[0] ) . '</span></label></li>';
		}
		$h .= '<li class="is-locked"><span class="nhm-toggle-text"><strong>' . esc_html__( 'Plan and invoice emails', 'neurohr-members' ) . '</strong><span class="nhm-muted">' . esc_html__( 'Renewal reminders, invoices and receipts. Always on, so your plan does not end by surprise.', 'neurohr-members' ) . '</span></span>';
		$h .= '<label class="nhm-toggle-switch"><input type="checkbox" checked disabled><span aria-hidden="true"></span></label></li>';
		$h .= '</ul>' . NHM_Forms::button( __( 'Save notifications', 'neurohr-members' ) ) . '</form>';
		return $h . '</section>';
	}

	private static function company_billing_card() {
		$company = self::owned_company();
		if ( ! $company ) {
			return '';
		}
		$err = NHM_Forms::has_errors( 'company_billing' );
		$h   = '<section class="nhm-card nhm-company-billing" id="nhm-company-billing">';
		$h  .= '<h3 class="nhm-card__title">' . esc_html__( 'Company billing details', 'neurohr-members' ) . '</h3>';
		$h  .= '<p class="nhm-card__intro">' . esc_html__( 'Shown on your company\'s invoices. Changes apply to new invoices; invoices already issued stay as they are.', 'neurohr-members' ) . '</p>';
		$h  .= NHM_Forms::render_messages( 'company_billing' );
		$h  .= NHM_Forms::open( 'company_billing' ) . '<div class="nhm-grid">';
		foreach ( self::company_billing_fields() as $key => $f ) {
			if ( 'address' === $key ) {
				continue;
			}
			$value = $err ? NHM_Forms::value( 'company_billing', 'nhm_c_' . $key ) : (string) $company[ $key ];
			$h    .= NHM_Forms::field( array( 'name' => 'nhm_c_' . $key, 'label' => $f[0], 'type' => $f[1], 'value' => $value, 'required' => 'name' === $key ) );
			if ( 'phone' === $key ) {
				$h .= '</div>' . NHM_Forms::field( array( 'name' => 'nhm_c_address', 'label' => __( 'Address', 'neurohr-members' ), 'type' => 'textarea', 'value' => $err ? NHM_Forms::value( 'company_billing', 'nhm_c_address' ) : (string) $company['address'], 'autocomplete' => 'street-address' ) ) . '<div class="nhm-grid">';
			}
		}
		$h .= '</div>';
		if ( '' !== (string) $company['client_code'] ) {
			/* translators: %s: client code */
			$h .= '<p class="nhm-muted">' . esc_html( sprintf( __( 'Client code: %s', 'neurohr-members' ), $company['client_code'] ) ) . '</p>';
		}
		$h .= NHM_Forms::button( __( 'Save company details', 'neurohr-members' ) ) . '</form>';
		return $h . '</section>';
	}

	private static function hold_notice() {
		$h = NHM_Refunds::on_hold( get_current_user_id() );
		if ( ! $h ) {
			return '';
		}
		$text = 'refund' === $h['why'] ? __( 'We are reviewing your refund request. Access to members-only content is paused until we reply by email.', 'neurohr-members' ) : __( 'Access to members-only content is paused because of a dispute on a payment. Please contact us.', 'neurohr-members' );
		return '<div class="nhm-alert nhm-alert--warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	private static function invoices_card() {
		$list = NHM_Invoices::query( array( 'for_user' => get_current_user_id(), 'limit' => 50 ) );
		$list = array_values( array_filter( $list, function ( $i ) { return 'void' !== $i['status']; } ) );
		if ( ! $list ) {
			return '';
		}
		$h  = '<section class="nhm-card nhm-invoices" id="nhm-invoices">';
		$h .= '<h3 class="nhm-card__title">' . esc_html__( 'Invoices', 'neurohr-members' ) . '</h3>' . NHM_Forms::render_messages( 'refund' );
		$h .= '<div class="nhm-table-wrap"><table class="nhm-table"><thead><tr><th>' . esc_html__( 'Invoice', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Date', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Total', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $list as $inv ) {
			$status = NHM_Invoices::status_label( $inv );
			$h     .= '<tr><td>' . esc_html( $inv['number'] ) . ( $inv['company_id'] && ! empty( $inv['bill_to']['name'] ) ? '<br><span class="nhm-muted">' . esc_html( $inv['bill_to']['name'] ) . '</span>' : '' ) . '</td>';
			$h     .= '<td>' . esc_html( NHM_Emails::format_date( $inv['issue_date'] ) ) . '</td>';
			$h     .= '<td>' . esc_html( NHM_Settings::money( $inv['total'] ) ) . '</td>';
			$h     .= '<td><span class="nhm-status nhm-status--' . esc_attr( $inv['status'] ) . '">' . esc_html( $status ) . '</span></td>';
			$pay    = NHM_Payments::payable( $inv ) && ! NHM_Payments::waiting_for( $inv['id'] ) ? '<a class="nhm-btn nhm-btn--small nhm-btn--primary" href="' . esc_url( NHM_Payments::pay_url( $inv ) ) . '">' . esc_html__( 'Pay now', 'neurohr-members' ) . '</a> ' : '';
			$h     .= '<td class="nhm-table__actions">' . $pay . '<a class="nhm-btn nhm-btn--small" href="' . esc_url( NHM_Invoices::download_url( $inv ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'PDF', 'neurohr-members' ) . '</a>' . NHM_Refunds::invoice_actions( $inv, get_current_user_id() ) . '</td></tr>';
		}
		return $h . '</tbody></table></div></section>';
	}

	private static function billing_card() {
		$uid = get_current_user_id();
		$m   = NHM_Members::membership();
		$h   = '<section class="nhm-card nhm-billing" id="nhm-billing">';
		$h  .= '<h3 class="nhm-card__title">' . esc_html__( 'Personal billing details', 'neurohr-members' ) . '</h3>';
		$h  .= NHM_Forms::render_messages( 'billing' );
		if ( 'owner' === $m['role'] ) {
			$h .= '<p class="nhm-muted">' . esc_html__( 'Invoices for your company account use the company details (Company tab). The details below are for invoices to you personally.', 'neurohr-members' ) . '</p>';
		} else {
			$h .= '<p class="nhm-muted">' . esc_html__( 'Optional. Shown on your invoices.', 'neurohr-members' ) . '</p>';
		}
		$err = NHM_Forms::has_errors( 'billing' );
		$h  .= NHM_Forms::open( 'billing' ) . '<div class="nhm-grid">';
		foreach ( self::billing_fields() as $key => $f ) {
			if ( 'textarea' === $f[1] ) {
				continue;
			}
			$h .= NHM_Forms::field( array( 'name' => $key, 'label' => $f[0], 'type' => $f[1], 'autocomplete' => $f[2], 'value' => $err ? NHM_Forms::value( 'billing', $key ) : (string) get_user_meta( $uid, $key, true ) ) );
		}
		$h .= '</div>';
		$h .= NHM_Forms::field( array( 'name' => 'nhm_bill_address', 'label' => __( 'Billing address', 'neurohr-members' ), 'type' => 'textarea', 'autocomplete' => 'street-address', 'value' => $err ? NHM_Forms::value( 'billing', 'nhm_bill_address' ) : (string) get_user_meta( $uid, 'nhm_bill_address', true ) ) );
		$h .= NHM_Forms::button( __( 'Save billing details', 'neurohr-members' ) ) . '</form></section>';
		return $h;
	}

	private static function password_card() {
		$min = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		$h   = '<section class="nhm-card nhm-password-card" id="nhm-password">';
		$h  .= '<h3 class="nhm-card__title">' . esc_html__( 'Change password', 'neurohr-members' ) . '</h3>';
		$h  .= NHM_Forms::render_messages( 'password' );
		$h  .= NHM_Forms::open( 'password' );
		$h  .= '<div class="nhm-grid">';
		$h  .= NHM_Forms::field( array( 'name' => 'nhm_current', 'label' => __( 'Current password', 'neurohr-members' ), 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password' ) );
		/* translators: %d: number of characters */
		$h .= NHM_Forms::field( array( 'name' => 'nhm_new', 'label' => __( 'New password', 'neurohr-members' ), 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => sprintf( __( 'At least %d characters.', 'neurohr-members' ), $min ), 'attrs' => 'minlength="' . (int) $min . '"' ) );
		$h .= '</div>';
		$h .= NHM_Forms::button( __( 'Change password', 'neurohr-members' ) );
		$h .= '</form></section>';
		return $h;
	}
}
