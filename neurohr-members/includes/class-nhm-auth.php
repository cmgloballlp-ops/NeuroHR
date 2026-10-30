<?php
/**
 * Login, registration and password reset with WordPress's own functions (wp_signon,
 * wp_insert_user, retrieve_password, reset_password), shown on the owner's own pages
 * through shortcodes. Also points WordPress's login links to those pages.
 *
 * Emergency way in if a login page breaks: /wp-login.php?nhm_core=1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Auth {

	public static function init() {
		add_action( 'nhm_invoice_paid', array( __CLASS__, 'signup_paid' ), 5 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_unverified' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'verify_requests' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'logged_in_redirects' ), 3 );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 20, 3 );
		add_filter( 'register_url', array( __CLASS__, 'filter_register_url' ), 20 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'filter_lost_url' ), 20, 2 );
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 20, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'reset_message' ), 20, 4 );
		add_action( 'login_init', array( __CLASS__, 'replace_wp_login' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ), 1 );
	}

	/* ---------- URLs ---------- */

	public static function login_url( $redirect = '' ) {
		$page = NHM_Settings::page_url( 'login' );
		if ( ! $page ) {
			remove_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 20 );
			$url = wp_login_url( $redirect );
			add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 20, 3 );
			return $url;
		}
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page;
	}

	public static function filter_login_url( $url, $redirect, $force_reauth ) {
		if ( $force_reauth || ! self::own_pages() ) {
			return $url;
		}
		$page = NHM_Settings::page_url( 'login' );
		if ( ! $page ) {
			return $url;
		}
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page;
	}

	public static function filter_register_url( $url ) {
		$page = NHM_Settings::page_url( 'register' );
		return ( $page && self::own_pages() ) ? $page : $url;
	}

	/** Links to WordPress's login screens go to your own pages. */
	public static function own_pages() {
		return NHM_Settings::get( 'replace_wp_login' ) || NHM_Settings::get( 'hide_wp_login' );
	}

	/** Lost-password page if you made one, else the pop-up on the login page. */
	public static function own_lost_url() {
		$page = NHM_Settings::page_url( 'lost' );
		if ( $page ) {
			return $page;
		}
		$login = NHM_Settings::page_url( 'login' );
		return $login ? add_query_arg( 'nhm_modal', 'lost', $login ) : '';
	}

	public static function filter_lost_url( $url, $redirect ) {
		$page = self::own_lost_url();
		return ( $page && self::own_pages() ) ? $page : $url;
	}

	public static function after_login_url( $user = null ) {
		return NHM_Settings::url( 'after_login', home_url( '/' ) );
	}

	public static function logout_redirect( $redirect_to, $requested, $user ) {
		if ( $requested ) {
			return $redirect_to;
		}
		return NHM_Settings::url( 'after_logout', home_url( '/' ) );
	}

	/** Members (not staff) never land in wp-admin after a core login. */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) && NHM_Settings::get( 'block_admin' ) ) {
			if ( ! $requested || false !== strpos( $redirect_to, '/wp-admin' ) ) {
				return self::after_login_url( $user );
			}
		}
		return $redirect_to;
	}

	/** wp-login.php → own pages (when "hide WordPress login" is off; when on, NHM_HideWP gives a 404). */
	public static function replace_wp_login() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! NHM_Settings::get( 'replace_wp_login' ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! empty( $_REQUEST['interim-login'] ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		$map    = array(
			'login'            => 'login',
			'register'         => 'register',
			'lostpassword'     => 'lost',
			'retrievepassword' => 'lost',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		$page = 'lost' === $map[ $action ] ? self::own_lost_url() : NHM_Settings::page_url( $map[ $action ] );
		if ( ! $page ) {
			return;
		}
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		// phpcs:enable
		wp_safe_redirect( $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page );
		exit;
	}

	public static function admin_bar( $show ) {
		if ( NHM_Settings::get( 'hide_admin_bar' ) && is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}

	public static function block_admin() {
		if ( ! NHM_Settings::get( 'block_admin' ) || ! is_user_logged_in() || current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		$to = nhm_account_url();
		wp_safe_redirect( $to ? $to : home_url( '/' ) );
		exit;
	}

	/* ---------- Login ---------- */

	public static function redirect_target( $raw ) {
		$fallback = self::after_login_url();
		$raw      = trim( (string) $raw );
		if ( '' === $raw ) {
			return $fallback;
		}
		$url = wp_validate_redirect( $raw, $fallback );
		// Never send a member back to the login, registration or reset page.
		foreach ( array( 'login', 'register', 'lost' ) as $key ) {
			$page = NHM_Settings::page_url( $key );
			if ( $page && 0 === strpos( $url, $page ) ) {
				return $fallback;
			}
		}
		return $url;
	}

	public static function handle_login() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the dispatcher.
		$login    = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are not sanitised.
		$remember = ! empty( $_POST['rememberme'] );
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		// phpcs:enable

		if ( self::limited_login() ) {
			NHM_Forms::error( 'login', __( 'Too many failed attempts. Please wait 15 minutes, or reset your password.', 'neurohr-members' ) );
			return;
		}
		if ( '' === $login || '' === $password ) {
			NHM_Forms::error( 'login', __( 'Enter your email address and password.', 'neurohr-members' ) );
			return;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) && 'nhm_pay_pending' === $user->get_error_code() ) {
			$u = get_user_by( 'email', $login );
			$u = $u ? $u : get_user_by( 'login', $login );
			if ( $u ) {
				self::resend_pay_link( $u->ID );
			}
			NHM_Forms::error( 'login', __( 'Welcome back! You are almost there. Your account opens as soon as your payment is complete. We have sent the payment link to your email again.', 'neurohr-members' ) );
			return;
		}
		if ( is_wp_error( $user ) && 'nhm_unverified' === $user->get_error_code() ) {
			$u = get_user_by( 'email', $login );
			$u = $u ? $u : get_user_by( 'login', $login );
			NHM_Forms::error(
				'login',
				/* translators: %s: link */
				sprintf( __( 'Please confirm your email address first: open the link in the email we sent you. <a href="%s">Send it again</a>', 'neurohr-members' ), esc_url( $u ? self::resend_url( $u->ID ) : self::login_url() ) )
			);
			return;
		}
		if ( is_wp_error( $user ) ) {
			NHM_Forms::hit( 'login', 15 );
			$lost = nhm_lost_password_url();
			NHM_Forms::error(
				'login',
				/* translators: %s: lost password link */
				sprintf( __( 'The email address or password is not correct. <a href="%s">Forgot your password?</a>', 'neurohr-members' ), esc_url( $lost ) )
			);
			return;
		}
		NHM_Forms::clear_hits( 'login' );
		$target = self::redirect_target( $redirect );
		if ( ! $redirect && user_can( $user, 'edit_posts' ) && ! NHM_Settings::get( 'after_login' ) ) {
			$target = admin_url();
		}
		wp_safe_redirect( apply_filters( 'nhm_login_redirect', $target, $user ) );
		exit;
	}

	private static function limited_login() {
		return NHM_Forms::limited( 'login', 6, 15 );
	}

	/* ---------- Registration ---------- */

	public static function registration_open() {
		return (bool) NHM_Settings::get( 'registration_open' );
	}

	/** Level chosen for the registration: shortcode attribute, ?plan= or the posted form. */
	public static function chosen_level( $attr = '' ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$ref = '' !== (string) $attr ? $attr : ( isset( $_POST['nhm_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_plan'] ) ) : ( isset( $_GET['plan'] ) ? sanitize_text_field( wp_unslash( $_GET['plan'] ) ) : '' ) );
		// phpcs:enable
		$level = $ref ? NHM_Levels::resolve( $ref ) : null;
		return ( $level && ! $level['is_base'] ) ? $level : null;
	}

	/**
	 * Give a chosen level to a person: paid → unpaid invoice + application emails;
	 * price 0 (e.g. a trial) → the plan starts now. Returns 'applied', 'granted' or ''.
	 */
	/** Invoice made by the last take_level() call (for the "pay now" step). */
	public static $last_invoice = 0;

	/** After asking for a paid plan: the pay page when online payment is set up, else My account. */
	public static function after_apply_url() {
		$inv = self::$last_invoice ? NHM_Invoices::get( self::$last_invoice ) : null;
		if ( $inv && NHM_Payments::payable( $inv ) ) {
			return NHM_Payments::pay_url( $inv );
		}
		return nhm_account_url();
	}

	public static function take_level( $user_id, $level, $kind = 'signup' ) {
		if ( ! $level ) {
			return '';
		}
		if ( (float) $level['amount'] > 0 ) {
			$id = NHM_Invoices::for_plan( $user_id, $level['id'], $kind );
			if ( is_wp_error( $id ) ) {
				return '';
			}
			NHM_Emails::invoice_email( 'application', NHM_Invoices::get( $id ) );
			NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'applied', 'level_to' => $level['id'], 'actor_id' => $user_id ) );
			self::$last_invoice = (int) $id;
			return 'applied';
		}
		$p = NHM_Members::personal( $user_id );
		list( $start, $end ) = NHM_Levels::renewal_dates( $level['id'], NHM_Members::date_active( $p['expiry'] ) ? $p['level'] : 0, $p['expiry'] );
		NHM_Members::set_plan( $user_id, $level['id'], $start ? $start : $p['start'], $end, null, 'Chosen at registration' );
		return 'granted';
	}

	/**
	 * Text of the required consent tick box ('' = no tick box): your own text, or
	 * "I have read and agree to the Terms of Use and Privacy Policy" with the links.
	 */
	public static function consent_label() {
		$own = trim( (string) NHM_Settings::get( 'terms_text' ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( ! NHM_Settings::get( 'consent_on' ) ) {
			return '';
		}
		$t = NHM_Settings::legal_url( 'terms' );
		$p = NHM_Settings::legal_url( 'privacy' );
		$a = function ( $url, $label ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		};
		if ( $t && $p ) {
			/* translators: 1: Terms of Use link, 2: Privacy Policy link */
			return sprintf( __( 'I have read and agree to the %1$s and %2$s.', 'neurohr-members' ), $a( $t, __( 'Terms of Use', 'neurohr-members' ) ), $a( $p, __( 'Privacy Policy', 'neurohr-members' ) ) );
		}
		if ( $t || $p ) {
			/* translators: %s: link */
			return sprintf( __( 'I have read and agree to the %s.', 'neurohr-members' ), $t ? $a( $t, __( 'Terms of Use', 'neurohr-members' ) ) : $a( $p, __( 'Privacy Policy', 'neurohr-members' ) ) );
		}
		return '';
	}

	public static function handle_register() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the dispatcher.
		$token  = isset( $_POST['nhm_invite'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_invite'] ) ) : '';
		$invite = $token ? NHM_Companies::find_invite( $token ) : null;
		$level  = $invite ? null : self::chosen_level();
		if ( ! self::registration_open() && ! $invite ) {
			NHM_Forms::error( 'register', __( 'Registration is closed.', 'neurohr-members' ) );
			return;
		}
		if ( is_user_logged_in() ) {
			NHM_Forms::error( 'register', __( 'You are already logged in.', 'neurohr-members' ) );
			return;
		}
		$spam = NHM_Forms::spam_check();
		if ( is_wp_error( $spam ) ) {
			NHM_Forms::error( 'register', $spam->get_error_message() );
			return;
		}
		if ( NHM_Forms::limited( 'register', 5, 60 ) ) {
			NHM_Forms::error( 'register', __( 'Too many sign-ups from your network. Please try again later.', 'neurohr-members' ) );
			return;
		}

		$name     = isset( $_POST['nhm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_name'] ) ) : '';
		$email    = $invite ? $invite['email'] : ( isset( $_POST['nhm_email'] ) ? sanitize_email( wp_unslash( $_POST['nhm_email'] ) ) : '' );
		$password = isset( $_POST['nhm_password'] ) ? (string) wp_unslash( $_POST['nhm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$terms    = ! empty( $_POST['nhm_terms'] );
		$news     = ! empty( $_POST['nhm_newsletter'] );
		// phpcs:enable
		$posted   = NHM_Forms::posted();
		$profile  = array();
		foreach ( NHM_Fields::for_register() as $key => $f ) {
			list( $value, $err ) = NHM_Fields::read( $f, $posted );
			if ( $err ) {
				NHM_Forms::error( 'register', $err );
			}
			$profile[ $key ] = $value;
		}

		if ( '' === $name ) {
			NHM_Forms::error( 'register', __( 'Enter your name.', 'neurohr-members' ) );
		}
		if ( ! is_email( $email ) ) {
			NHM_Forms::error( 'register', __( 'Enter a valid email address.', 'neurohr-members' ) );
		} elseif ( NHM_Block::email_blocked( $email ) || ( email_exists( $email ) && NHM_Block::is_blocked( (int) email_exists( $email ) ) ) ) {
			NHM_Forms::error( 'register', NHM_Block::message() );
		} elseif ( email_exists( $email ) && self::pending_invoice( (int) email_exists( $email ) ) ) {
			self::resend_pay_link( (int) email_exists( $email ) );
			NHM_Forms::error( 'register', __( 'You have already started signing up with this email address. We have sent the payment link to your inbox again, so you can finish whenever you are ready.', 'neurohr-members' ) );
		} elseif ( email_exists( $email ) ) {
			$back = $invite ? NHM_Companies::invite_url( $token ) : ( $level ? NHM_Levels::register_url( $level['id'] ) : '' );
			NHM_Forms::error(
				'register',
				/* translators: 1: login link, 2: lost password link */
				sprintf( __( 'An account with this email address already exists. <a href="%1$s">Log in</a> or <a href="%2$s">reset your password</a>.', 'neurohr-members' ), esc_url( self::login_url( $back ) ), esc_url( nhm_lost_password_url() ) )
			);
		}
		$min = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		if ( strlen( $password ) < $min ) {
			/* translators: %d: number of characters */
			NHM_Forms::error( 'register', sprintf( __( 'Choose a password of at least %d characters.', 'neurohr-members' ), $min ) );
		}
		if ( '' !== self::consent_label() && ! $terms ) {
			NHM_Forms::error( 'register', __( 'Please tick the box to agree to the Terms of Use and Privacy Policy.', 'neurohr-members' ) );
		}
		if ( NHM_Forms::has_errors( 'register' ) ) {
			return;
		}

		NHM_Forms::hit( 'register', 60 );
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::username_from_email( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'nickname'     => $name,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			NHM_Forms::error( 'register', $user_id->get_error_message() );
			return;
		}
		$all = NHM_Fields::all();
		foreach ( $profile as $key => $value ) {
			NHM_Fields::store( $user_id, $all[ $key ], $value );
		}
		update_user_meta( $user_id, 'nhm_registered_ip', NHM_Forms::ip() );
		if ( $terms ) {
			update_user_meta( $user_id, 'nhm_consent', array( 'date' => current_time( 'mysql' ), 'ip' => NHM_Forms::ip(), 'terms' => NHM_Settings::legal_url( 'terms' ), 'privacy' => NHM_Settings::legal_url( 'privacy' ) ) );
		}
		NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'registered', 'level_to' => NHM_Levels::base_id(), 'actor_id' => $user_id, 'message' => $level ? 'Chose ' . $level['name'] : '' ) );

		if ( $news && NHM_MailerLite::enabled() ) {
			update_user_meta( $user_id, 'nhm_newsletter', current_time( 'mysql' ) );
			update_user_meta( $user_id, 'nhm_notify_newsletter', 1 );
			NHM_MailerLite::subscribe(
				$email,
				$name,
				array(
					'company' => isset( $profile['company'] ) ? (string) $profile['company'] : '',
					'phone'   => isset( $profile['phone'] ) ? (string) $profile['phone'] : '',
				)
			);
		}

		$joined = false;
		if ( $invite ) {
			$joined = true === NHM_Companies::accept( $token, $user_id );
		}

		// A paid plan: straight to the payment page. The account opens when the invoice is paid
		// (paying, or your approval of a transfer, also confirms the email address).
		if ( ! $invite && $level && (float) $level['amount'] > 0 && NHM_Payments::available() ) {
			$took = self::take_level( $user_id, $level );
			$inv  = self::$last_invoice ? NHM_Invoices::get( self::$last_invoice ) : null;
			if ( 'applied' === $took && $inv ) {
				update_user_meta( $user_id, 'nhm_pay_pending', (int) $inv['id'] );
				update_user_meta( $user_id, 'nhm_signup_login', time() );
				NHM_Emails::admin_signup( $user_id, $level['id'] );
				do_action( 'nhm_user_registered', $user_id, null );
				NHM_Forms::done( NHM_Payments::pay_url( $inv ), 'signup_pay' );
			}
		}

		// Confirm the email address first (an invitation link already proves it).
		if ( ! $invite && NHM_Settings::get( 'verify_email' ) ) {
			if ( $level ) {
				update_user_meta( $user_id, 'nhm_verify_plan', $level['slug'] );
			}
			self::send_verification( $user_id );
			NHM_Emails::admin_signup( $user_id, $level ? $level['id'] : 0 );
			do_action( 'nhm_user_registered', $user_id, null );
			NHM_Forms::done( NHM_Forms::current_url( array( 'nhm_msg', 'plan' ) ), 'verify_sent' );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		NHM_Members::flush( $user_id );
		do_action( 'nhm_user_registered', $user_id, $invite );
		NHM_Emails::welcome( $user_id, $level ? $level['id'] : 0 );
		NHM_Emails::admin_signup( $user_id, $level ? $level['id'] : 0 );
		$took = self::take_level( $user_id, $level );

		if ( $joined && nhm_account_url() ) {
			NHM_Forms::done( nhm_account_url(), 'joined' );
		}
		if ( 'applied' === $took && self::after_apply_url() ) {
			NHM_Forms::done( self::after_apply_url(), 'applied' );
		}
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$target   = $redirect ? self::redirect_target( $redirect ) : NHM_Settings::url( 'after_register', self::after_login_url() );
		NHM_Forms::done( $target, 'registered' );
	}

	/** A logged-in member asks for a plan (upgrade or renewal) from a ?plan= link. */
	public static function handle_apply() {
		if ( ! is_user_logged_in() ) {
			NHM_Forms::error( 'apply', __( 'Please log in again.', 'neurohr-members' ) );
			return;
		}
		$level = self::chosen_level();
		$check = self::apply_check( $level );
		if ( true !== $check ) {
			NHM_Forms::error( 'apply', $check );
			return;
		}
		$took = self::take_level( get_current_user_id(), $level, 'upgrade' );
		$back = 'applied' === $took ? self::after_apply_url() : nhm_account_url();
		$back = $back ? $back : NHM_Forms::current_url( array( 'nhm_msg', 'plan' ) );
		NHM_Forms::done( $back, 'applied' === $took ? 'applied' : 'plan_started' );
	}

	/** true, or the reason the current member cannot take $level now. */
	public static function apply_check( $level ) {
		if ( ! $level ) {
			return __( 'This plan is not available.', 'neurohr-members' );
		}
		$m = NHM_Members::membership();
		if ( (int) $m['level'] === (int) $level['id'] ) {
			if ( empty( $level['early_renew'] ) || 'expire' !== $level['access'] || '' === $m['expiry'] ) {
				/* translators: %s: level */
				return sprintf( __( 'You already have the %s plan.', 'neurohr-members' ), $level['name'] );
			}
			if ( 'company' === $m['source'] ) {
				return __( 'Your plan comes from your company account. Your company owner renews it.', 'neurohr-members' );
			}
		} elseif ( $m['rank'] > $level['rank'] ) {
			/* translators: %s: level */
			return sprintf( __( 'Your current plan already includes everything in %s.', 'neurohr-members' ), $level['name'] );
		}
		if ( (float) $level['amount'] <= 0 && $m['source'] === 'personal' ) {
			return __( 'This plan cannot be combined with your current plan. Please contact us.', 'neurohr-members' );
		}
		return true;
	}

	/** Box on the registration page for a logged-in member who opened a ?plan= link. */
	public static function render_apply( $level ) {
		$h      = NHM_Forms::render_messages( 'apply' );
		$m      = NHM_Members::membership();
		$check  = self::apply_check( $level );
		$open   = $level ? NHM_Invoices::query( array( 'pending' => true, 'level_id' => $level['id'], 'user_id' => get_current_user_id(), 'limit' => 1 ) ) : array();
		$h     .= '<div class="nhm-card nhm-apply">';
		if ( ! $level ) {
			return $h . '<p>' . esc_html__( 'This plan is not available.', 'neurohr-members' ) . '</p></div>';
		}
		$h .= self::plan_summary( $level );
		if ( $open ) {
			/* translators: %s: invoice number */
			$h .= '<p>' . esc_html( sprintf( __( 'You have already applied. Invoice %s is waiting for payment.', 'neurohr-members' ), $open[0]['number'] ) ) . '</p>';
			$h .= '<p class="nhm-actions">' . ( NHM_Payments::payable( $open[0] ) && ! NHM_Payments::waiting_for( $open[0]['id'] ) ? '<a class="nhm-btn nhm-btn--primary" href="' . esc_url( NHM_Payments::pay_url( $open[0] ) ) . '">' . esc_html__( 'Pay now', 'neurohr-members' ) . '</a> ' : '' ) . '<a class="nhm-btn" href="' . esc_url( NHM_Invoices::download_url( $open[0] ) ) . '">' . esc_html__( 'Download invoice', 'neurohr-members' ) . '</a></p>';
		} elseif ( true !== $check ) {
			$h .= '<p class="nhm-muted">' . esc_html( $check ) . '</p>';
		} else {
			$renew = (int) $m['level'] === (int) $level['id'];
			$h    .= NHM_Forms::open( 'apply' ) . '<input type="hidden" name="nhm_plan" value="' . esc_attr( $level['slug'] ) . '">';
			if ( (float) $level['amount'] > 0 ) {
				$h .= '<p class="nhm-muted">' . esc_html( NHM_Payments::available() ? __( 'Next you choose how to pay: online, bank transfer or DuitNow QR. The plan starts as soon as the payment is confirmed.', 'neurohr-members' ) : __( 'We will email you the invoice. The plan starts as soon as the payment is received.', 'neurohr-members' ) ) . '</p>';
			}
			/* translators: %s: level */
			$h .= NHM_Forms::button( $renew ? sprintf( __( 'Renew %s', 'neurohr-members' ), $level['name'] ) : sprintf( __( 'Get %s', 'neurohr-members' ), $level['name'] ) ) . '</form>';
		}
		return $h . '</div>';
	}

	/** "Pro — RM 299 per year" box. */
	public static function plan_summary( $level ) {
		$price  = NHM_Levels::price_text( $level['id'] );
		$period = NHM_Levels::period_text( $level['id'] );
		$h      = '<div class="nhm-plan-choice"><span class="nhm-plan-choice__label">' . esc_html__( 'Your plan', 'neurohr-members' ) . '</span>';
		$h     .= '<span class="nhm-plan-choice__name">' . esc_html( $level['name'] ) . '</span>';
		$h     .= '<span class="nhm-plan-choice__price">' . esc_html( trim( $price . ' ' . $period ) ) . '</span>';
		if ( $level['description'] ) {
			$h .= '<span class="nhm-plan-choice__desc">' . esc_html( $level['description'] ) . '</span>';
		}
		return $h . '</div>';
	}


	public static function username_from_email( $email ) {
		$base = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
		$base = $base ? substr( $base, 0, 50 ) : 'member';
		$name = $base;
		$n    = 2;
		while ( username_exists( $name ) ) {
			$name = $base . $n++;
		}
		return $name;
	}

	/* ---------- Email confirmation ---------- */

	public static function send_verification( $user_id ) {
		$user  = get_userdata( $user_id );
		$token = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, 'nhm_verify_hash', hash( 'sha256', $token ) );
		$page = NHM_Settings::page_url( 'login' ) ? NHM_Settings::page_url( 'login' ) : home_url( '/' );
		$url  = add_query_arg( array( 'nhm_verify' => $token, 'u' => (int) $user_id ), $page );
		return NHM_Emails::send( 'verify', $user->user_email, array( '{name}' => $user->display_name, '{email}' => $user->user_email, '{verify_url}' => $url ) );
	}

	public static function is_unverified( $user_id ) {
		return '' !== (string) get_user_meta( $user_id, 'nhm_verify_hash', true );
	}

	/** Invoice ID of a paid sign-up that is not paid yet (the account cannot log in until then). */
	public static function pending_invoice( $user_id ) {
		$id  = (int) get_user_meta( (int) $user_id, 'nhm_pay_pending', true );
		$inv = $id ? NHM_Invoices::get( $id ) : null;
		return $inv && 'unpaid' === $inv['status'] ? $id : 0;
	}

	/** Email the payment link again (at most every 10 minutes). */
	public static function resend_pay_link( $user_id ) {
		$id = self::pending_invoice( $user_id );
		if ( ! $id || get_transient( 'nhm_paylink_' . (int) $user_id ) ) {
			return false;
		}
		set_transient( 'nhm_paylink_' . (int) $user_id, 1, 10 * MINUTE_IN_SECONDS );
		return NHM_Emails::invoice_email( 'application', NHM_Invoices::get( $id ), array(), false );
	}

	/** The invoice of a paid sign-up is paid: the account opens. */
	public static function signup_paid( $invoice_id ) {
		$inv = NHM_Invoices::get( $invoice_id );
		if ( ! $inv || ! $inv['user_id'] ) {
			return;
		}
		if ( (int) get_user_meta( $inv['user_id'], 'nhm_pay_pending', true ) === (int) $invoice_id ) {
			delete_user_meta( $inv['user_id'], 'nhm_pay_pending' );
			delete_user_meta( $inv['user_id'], 'nhm_verify_hash' );
			NHM_Log::add( array( 'user_id' => $inv['user_id'], 'event' => 'verified', 'message' => $inv['number'] ) );
		}
	}

	public static function block_unverified( $user ) {
		if ( $user instanceof WP_User && self::pending_invoice( $user->ID ) ) {
			return new WP_Error( 'nhm_pay_pending', __( 'Please complete your payment first.', 'neurohr-members' ) );
		}
		if ( $user instanceof WP_User && self::is_unverified( $user->ID ) ) {
			return new WP_Error( 'nhm_unverified', __( 'Please confirm your email address first.', 'neurohr-members' ) );
		}
		return $user;
	}

	public static function resend_url( $user_id ) {
		$page = NHM_Settings::page_url( 'login' ) ? NHM_Settings::page_url( 'login' ) : home_url( '/' );
		return add_query_arg( array( 'nhm_resend' => (int) $user_id, 't' => substr( hash_hmac( 'sha256', 'resend' . (int) $user_id, wp_salt( 'nonce' ) ), 0, 20 ) ), $page );
	}

	/** ?nhm_verify=…&u=… (confirm) and ?nhm_resend=…&t=… (send the email again). */
	public static function verify_requests() {
		// phpcs:disable WordPress.Security.NonceVerification -- tokens are checked below.
		if ( isset( $_GET['nhm_resend'], $_GET['t'] ) ) {
			$uid = absint( $_GET['nhm_resend'] );
			$ok  = hash_equals( substr( hash_hmac( 'sha256', 'resend' . $uid, wp_salt( 'nonce' ) ), 0, 20 ), sanitize_text_field( wp_unslash( $_GET['t'] ) ) );
			if ( $ok && self::is_unverified( $uid ) && ! NHM_Forms::limited( 'verify', 3, 60 ) ) {
				NHM_Forms::hit( 'verify', 60 );
				self::send_verification( $uid );
			}
			NHM_Forms::done( remove_query_arg( array( 'nhm_resend', 't' ), NHM_Forms::current_url() ), 'verify_sent' );
		}
		if ( ! isset( $_GET['nhm_verify'], $_GET['u'] ) ) {
			return;
		}
		$uid   = absint( $_GET['u'] );
		$token = sanitize_text_field( wp_unslash( $_GET['nhm_verify'] ) );
		// phpcs:enable
		$hash = (string) get_user_meta( $uid, 'nhm_verify_hash', true );
		if ( '' === $hash ) {
			// Already confirmed (or unknown): just go to the login page.
			NHM_Forms::done( self::login_url(), is_user_logged_in() ? '' : 'verified' );
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			NHM_Forms::done( self::login_url(), 'verify_bad' );
		}
		delete_user_meta( $uid, 'nhm_verify_hash' );
		NHM_Log::add( array( 'user_id' => $uid, 'event' => 'verified', 'actor_id' => $uid ) );
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		NHM_Members::flush( $uid );
		$slug  = (string) get_user_meta( $uid, 'nhm_verify_plan', true );
		delete_user_meta( $uid, 'nhm_verify_plan' );
		$level = $slug ? NHM_Levels::resolve( $slug ) : null;
		$level = $level && ! $level['is_base'] ? $level : null;
		NHM_Emails::welcome( $uid, $level ? $level['id'] : 0 );
		$took = self::take_level( $uid, $level );
		if ( 'applied' === $took && self::after_apply_url() ) {
			NHM_Forms::done( self::after_apply_url(), 'applied' );
		}
		NHM_Forms::done( NHM_Settings::url( 'after_register', self::after_login_url() ), 'verified' );
	}

	/**
	 * Logged-in people: login / registration pages → the member area; the home page → Dashboard
	 * (Settings → Pages & login; staff stay, so they can check the home page).
	 */
	public static function logged_in_redirects() {
		if ( ! is_user_logged_in() || ! is_singular() && ! is_front_page() ) {
			return;
		}
		// Editors and admins may open (and edit in Elementor) the login, registration and home pages.
		if ( current_user_can( 'edit_posts' ) || NHM_Shell::elementor_editing() ) {
			return;
		}
		// Links that do something (invoice download, payment, email confirmation) are never redirected.
		foreach ( array( 'nhm_invoice', 'nhm_pay', 'nhm_stripe', 'nhm_verify', 'nhm_resend', 'nhm_email2', 'nhm_invite' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return;
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$id = (int) get_queried_object_id();
		foreach ( array( 'login', 'lost' ) as $key ) {
			if ( $id && (int) NHM_Settings::get( 'page_' . $key ) === $id && empty( $_GET['nhm_invite'] ) && empty( $_GET['action'] ) && empty( $_GET['nhm_msg'] ) ) {
				wp_safe_redirect( self::after_login_url() );
				exit;
			}
		}
		if ( $id && (int) NHM_Settings::get( 'page_register' ) === $id && empty( $_GET['plan'] ) && empty( $_GET['nhm_invite'] ) && empty( $_GET['nhm_msg'] ) ) {
			wp_safe_redirect( self::after_login_url() );
			exit;
		}
		// phpcs:enable
		$home = trim( (string) NHM_Settings::get( 'home_redirect' ) );
		if ( '' !== $home && is_front_page() && ! current_user_can( 'edit_posts' ) ) {
			$target = NHM_Settings::url( 'home_redirect' );
			if ( untrailingslashit( $target ) !== untrailingslashit( home_url( '/' ) ) ) {
				wp_safe_redirect( $target );
				exit;
			}
		}
	}

	/* ---------- Lost / reset password ---------- */

	public static function handle_lost() {
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $login ) {
			NHM_Forms::error( 'lost', __( 'Enter your email address.', 'neurohr-members' ) );
			return;
		}
		if ( NHM_Forms::limited( 'lost', 5, 15 ) ) {
			NHM_Forms::error( 'lost', __( 'Too many requests. Please wait 15 minutes.', 'neurohr-members' ) );
			return;
		}
		NHM_Forms::hit( 'lost', 15 );
		$result = retrieve_password( $login );
		if ( is_wp_error( $result ) && 'retrieve_password_email_failure' === $result->get_error_code() ) {
			NHM_Forms::error( 'lost', __( 'The email could not be sent. Please contact us.', 'neurohr-members' ) );
			return;
		}
		// Same answer whether or not the account exists.
		NHM_Forms::done( add_query_arg( 'nhm_modal', 'lost', NHM_Forms::current_url( array( 'nhm_msg', 'action', 'key', 'login', 'nhm_modal' ) ) ), 'reset_sent' );
	}

	/** Core reset email, but the link opens the own lost-password page. */
	public static function reset_message( $message, $key, $user_login, $user_data ) {
		$page = NHM_Settings::page_url( 'lost' ) ? NHM_Settings::page_url( 'lost' ) : NHM_Settings::page_url( 'login' );
		if ( ! $page ) {
			return $message;
		}
		$url  = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user_login ),
			),
			$page
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$text  = sprintf( __( 'Someone asked to reset the password of your %s account.', 'neurohr-members' ), $site ) . "\r\n\r\n";
		$text .= __( 'If this was not you, ignore this email and nothing will change.', 'neurohr-members' ) . "\r\n\r\n";
		$text .= __( 'To set a new password, open this link:', 'neurohr-members' ) . "\r\n\r\n";
		$text .= $url . "\r\n";
		return $text;
	}

	public static function handle_reset() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified by the dispatcher.
		$key   = isset( $_POST['rp_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rp_key'] ) ) : '';
		$login = isset( $_POST['rp_login'] ) ? sanitize_text_field( wp_unslash( $_POST['rp_login'] ) ) : '';
		$pass  = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			NHM_Forms::error( 'reset', __( 'This link has expired or was already used. Request a new one below.', 'neurohr-members' ) );
			return;
		}
		$min = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		if ( strlen( $pass ) < $min ) {
			/* translators: %d: number of characters */
			NHM_Forms::error( 'reset', sprintf( __( 'Choose a password of at least %d characters.', 'neurohr-members' ), $min ) );
			return;
		}
		reset_password( $user, $pass );
		NHM_Forms::done( self::login_url(), 'password_reset' );
	}

	/* ---------- Forms ---------- */

	public static function render_login( $atts ) {
		$atts = shortcode_atts( array( 'redirect' => '', 'title' => '' ), $atts, 'nhm_login' );
		NHM_Forms::no_cache();
		$html = $atts['title'] ? '<h2 class="nhm-title">' . esc_html( $atts['title'] ) . '</h2>' : '';
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$html .= NHM_Forms::render_messages( 'login' );
			/* translators: %s: name */
			$html .= '<p class="nhm-lead">' . esc_html( sprintf( __( 'You are logged in as %s.', 'neurohr-members' ), $user->display_name ) ) . '</p>';
			$html .= '<p class="nhm-actions"><a class="nhm-btn nhm-btn--primary" href="' . esc_url( self::after_login_url() ) . '">' . esc_html__( 'Continue', 'neurohr-members' ) . '</a> ';
			$html .= '<a class="nhm-btn" href="' . esc_url( wp_logout_url( self::login_url() ) ) . '">' . esc_html__( 'Log out', 'neurohr-members' ) . '</a></p>';
			return NHM_Forms::wrap( $html, 'nhm-login' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$redirect = NHM_Forms::value( 'login', 'redirect_to', isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : $atts['redirect'] );

		$html .= NHM_Forms::render_messages( 'login' );
		$html .= NHM_Forms::open( 'login' );
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">';
		$html .= NHM_Forms::field( array( 'name' => 'log', 'label' => __( 'Email address', 'neurohr-members' ), 'type' => 'text', 'value' => NHM_Forms::value( 'login', 'log' ), 'required' => true, 'autocomplete' => 'username', 'attrs' => 'inputmode="email"' ) );
		$html .= NHM_Forms::field( array( 'name' => 'pwd', 'label' => __( 'Password', 'neurohr-members' ), 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password' ) );
		$html .= '<p class="nhm-row"><label class="nhm-check"><input type="checkbox" name="rememberme" value="1" checked> ' . esc_html__( 'Keep me logged in', 'neurohr-members' ) . '</label>';
		$html .= '<a class="nhm-link" href="' . esc_url( add_query_arg( 'nhm_modal', 'lost' ) ) . '" data-nhm-dialog="nhm-lost-modal">' . esc_html__( 'Forgot password?', 'neurohr-members' ) . '</a></p>';
		$html .= NHM_Forms::button( __( 'Log in', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' );
		$html .= '</form>';
		if ( self::registration_open() && nhm_register_url() ) {
			$html .= '<p class="nhm-switch">' . esc_html__( 'No account yet?', 'neurohr-members' ) . ' <a href="' . esc_url( nhm_register_url() ) . '">' . esc_html__( 'Create a free account', 'neurohr-members' ) . '</a></p>';
		}
		// Forgot password: a pop-up window on the login page.
		$open  = self::lost_open();
		$modal = '<dialog class="nhm-modal" id="nhm-lost-modal"' . ( $open ? ' open data-nhm-autoopen' : '' ) . ' aria-labelledby="nhm-lost-title"><div class="nhm-modal__box">'
			. '<a class="nhm-modal__close" href="' . esc_url( remove_query_arg( array( 'nhm_modal', 'nhm_msg', 'action', 'key', 'login' ) ) ) . '" data-nhm-close aria-label="' . esc_attr__( 'Close', 'neurohr-members' ) . '">&times;</a>'
			. self::lost_inner() . '</div></dialog>';
		$modal = str_replace( '<h2 class="nhm-modal__title">', '<h2 class="nhm-modal__title" id="nhm-lost-title">', $modal );

		return NHM_Forms::wrap( $html . $modal, 'nhm-login' );
	}

	public static function render_register( $atts ) {
		$atts = shortcode_atts( array( 'redirect' => '', 'title' => '', 'button' => '', 'level' => '' ), $atts, 'nhm_register' );
		NHM_Forms::no_cache();
		// phpcs:ignore WordPress.Security.NonceVerification
		$token  = isset( $_GET['nhm_invite'] ) ? sanitize_text_field( wp_unslash( $_GET['nhm_invite'] ) ) : NHM_Forms::value( 'register', 'nhm_invite' );
		$invite = $token ? NHM_Companies::find_invite( $token ) : null;
		$html   = $atts['title'] ? '<h2 class="nhm-title">' . esc_html( $atts['title'] ) . '</h2>' : '';
		$level  = $invite ? null : self::chosen_level( $atts['level'] );

		if ( is_user_logged_in() ) {
			if ( $token ) {
				return NHM_Forms::wrap( $html . NHM_Account::render_invite_box( $token ), 'nhm-register' );
			}
			if ( $level ) {
				return NHM_Forms::wrap( $html . self::render_apply( $level ), 'nhm-register' );
			}
			$html .= NHM_Forms::render_messages( 'register' );
			$html .= '<p class="nhm-lead">' . esc_html__( 'You already have an account and are logged in.', 'neurohr-members' ) . '</p>';
			$html .= '<p class="nhm-actions"><a class="nhm-btn nhm-btn--primary" href="' . esc_url( self::after_login_url() ) . '">' . esc_html__( 'Continue', 'neurohr-members' ) . '</a></p>';
			return NHM_Forms::wrap( $html, 'nhm-register' );
		}
		if ( $token && ! $invite ) {
			$html .= '<div class="nhm-alert nhm-alert--error"><p>' . esc_html__( 'This invitation link is not valid any more. Ask your company to send a new one.', 'neurohr-members' ) . '</p></div>';
		}
		if ( ! self::registration_open() && ! $invite ) {
			$html .= '<p class="nhm-lead">' . esc_html__( 'Registration is closed at the moment.', 'neurohr-members' ) . '</p>';
			return NHM_Forms::wrap( $html, 'nhm-register' );
		}

		if ( $invite ) {
			$company = NHM_Companies::get( $invite['company_id'] );
			$html   .= '<div class="nhm-invite-note">';
			/* translators: 1: company, 2: plan */
			$html   .= '<p>' . wp_kses( sprintf( __( 'You have been invited to join <strong>%1$s</strong> with the %2$s plan. Create your account to accept.', 'neurohr-members' ), esc_html( $company['name'] ), esc_html( NHM_Levels::name( $company['level_id'] ) ) ), array( 'strong' => array() ) ) . '</p>';
			$html   .= '<p>' . esc_html__( 'Already have an account?', 'neurohr-members' ) . ' <a href="' . esc_url( self::login_url( NHM_Companies::invite_url( $token ) ) ) . '">' . esc_html__( 'Log in to accept', 'neurohr-members' ) . '</a></p>';
			$html   .= '</div>';
		}

		$redirect = NHM_Forms::value( 'register', 'redirect_to', isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : $atts['redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $level ) {
			$paid = (float) $level['amount'] > 0;
			if ( $paid && NHM_Payments::available() ) {
				$html .= '<ol class="nhm-steps-bar"><li class="is-on">' . esc_html__( 'Create account', 'neurohr-members' ) . '</li><li>' . esc_html__( 'Payment', 'neurohr-members' ) . '</li></ol>';
			}
			$html .= self::plan_summary( $level );
			if ( $paid ) {
				$note = NHM_Payments::available()
					/* translators: %s: level */
					? sprintf( __( 'Next you choose how to pay: online, bank transfer or DuitNow QR. Your %s plan starts as soon as the payment is complete.', 'neurohr-members' ), $level['name'] )
					/* translators: %s: level */
					: sprintf( __( 'After you create your account we email you the invoice. Your %s plan starts as soon as the payment is received.', 'neurohr-members' ), $level['name'] );
				$html .= '<p class="nhm-muted nhm-plan-note">' . esc_html( $note ) . '</p>';
			}
		}
		$html .= NHM_Forms::render_messages( 'register' );
		$html .= NHM_Forms::open( 'register' );
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">';
		if ( $level ) {
			$html .= '<input type="hidden" name="nhm_plan" value="' . esc_attr( $level['slug'] ) . '">';
		}
		if ( $invite ) {
			$html .= '<input type="hidden" name="nhm_invite" value="' . esc_attr( $token ) . '">';
		}
		$html .= NHM_Forms::field( array( 'name' => 'nhm_name', 'label' => __( 'Full name', 'neurohr-members' ), 'value' => NHM_Forms::value( 'register', 'nhm_name' ), 'required' => true, 'autocomplete' => 'name' ) );
		$html .= NHM_Forms::field(
			array(
				'name'         => 'nhm_email',
				'label'        => __( 'Email address', 'neurohr-members' ),
				'type'         => 'email',
				'value'        => $invite ? $invite['email'] : NHM_Forms::value( 'register', 'nhm_email' ),
				'required'     => true,
				'autocomplete' => 'email',
				'attrs'        => $invite ? 'readonly' : '',
			)
		);
		$min   = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		$html .= NHM_Forms::field(
			array(
				'name'         => 'nhm_password',
				'label'        => __( 'Password', 'neurohr-members' ),
				'type'         => 'password',
				'required'     => true,
				'autocomplete' => 'new-password',
				/* translators: %d: number of characters */
				'help'         => sprintf( __( 'At least %d characters.', 'neurohr-members' ), $min ),
				'attrs'        => 'minlength="' . (int) $min . '"',
			)
		);
		foreach ( NHM_Fields::for_register() as $key => $f ) {
			$value = NHM_Forms::raw( 'register', 'nhm_' . $key, 'checkboxes' === $f['type'] ? array() : '' );
			if ( 'company' === $key && '' === $value && $invite ) {
				$value = $company['name'];
			}
			$html .= NHM_Fields::render( $f, $value );
		}
		$terms = self::consent_label();
		if ( $terms ) {
			$html .= '<p class="nhm-field nhm-field--terms"><label class="nhm-check"><input type="checkbox" name="nhm_terms" value="1" required' . checked( '1', NHM_Forms::value( 'register', 'nhm_terms' ), false ) . '> <span>' . wp_kses( $terms, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'span' => array( 'class' => array() ) ) ) . ' <span class="nhm-req">*</span></span></label></p>';
		}
		if ( NHM_MailerLite::enabled() ) {
			$checked = NHM_Forms::has_errors( 'register' ) ? '1' === NHM_Forms::value( 'register', 'nhm_newsletter' ) : (bool) NHM_Settings::get( 'ml_checked' );
			$html   .= '<p class="nhm-field nhm-field--newsletter"><label class="nhm-check"><input type="checkbox" name="nhm_newsletter" value="1"' . ( $checked ? ' checked' : '' ) . '> <span>' . esc_html( NHM_Settings::get( 'ml_label' ) ) . '</span></label></p>';
		}
		$html .= NHM_Forms::spam_fields();
		$label = $invite ? __( 'Create account and join', 'neurohr-members' ) : ( $level && (float) $level['amount'] > 0 ? ( NHM_Payments::available() ? __( 'Continue to payment', 'neurohr-members' ) : __( 'Create account and get the invoice', 'neurohr-members' ) ) : __( 'Create account', 'neurohr-members' ) );
		$html .= NHM_Forms::button( $atts['button'] ? $atts['button'] : $label, 'nhm-btn nhm-btn--primary nhm-btn--block' );
		$html .= '</form>';
		if ( ! $invite && nhm_login_url() ) {
			$back  = $level ? NHM_Levels::register_url( $level['id'] ) : $redirect;
			$html .= '<p class="nhm-switch">' . esc_html__( 'Already have an account?', 'neurohr-members' ) . ' <a href="' . esc_url( self::login_url( $back ) ) . '">' . esc_html__( 'Log in', 'neurohr-members' ) . '</a></p>';
		}
		return NHM_Forms::wrap( $html, 'nhm-register' );
	}

	/**
	 * [nhm_invite]: the page behind invitation links. Only the invitation: company name, the
	 * invited email (fixed), name and password. No plans or prices.
	 */
	public static function render_invite( $atts ) {
		$atts = shortcode_atts( array( 'title' => '' ), $atts, 'nhm_invite' );
		NHM_Forms::no_cache();
		// phpcs:ignore WordPress.Security.NonceVerification
		$token  = isset( $_GET['nhm_invite'] ) ? sanitize_text_field( wp_unslash( $_GET['nhm_invite'] ) ) : NHM_Forms::value( 'register', 'nhm_invite' );
		$invite = $token ? NHM_Companies::find_invite( $token ) : null;
		if ( ! $invite ) {
			$h  = '<div class="nhm-invite-hero nhm-invite-hero--empty"><h2 class="nhm-invite-hero__title">' . esc_html__( 'This invitation link has expired or was already used', 'neurohr-members' ) . '</h2>';
			$h .= '<p class="nhm-lead">' . esc_html__( 'Please ask the person who manages your company account to send you a new invitation.', 'neurohr-members' ) . '</p>';
			if ( ! is_user_logged_in() && nhm_login_url() ) {
				$h .= '<p class="nhm-actions"><a class="nhm-btn" href="' . esc_url( nhm_login_url() ) . '">' . esc_html__( 'Log in', 'neurohr-members' ) . '</a></p>';
			}
			return NHM_Forms::wrap( $h . '</div>', 'nhm-register nhm-invite-page' );
		}
		$company = NHM_Companies::get( $invite['company_id'] );
		$by      = ! empty( $invite['invited_by'] ) ? get_userdata( $invite['invited_by'] ) : null;
		$h       = '<div class="nhm-invite-hero"><p class="nhm-invite-hero__kicker">' . esc_html__( 'You are invited', 'neurohr-members' ) . '</p>';
		/* translators: 1: company, 2: site name */
		$h .= '<h2 class="nhm-invite-hero__title">' . esc_html( sprintf( __( 'Join %1$s on %2$s', 'neurohr-members' ), $company ? $company['name'] : '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ) . '</h2>';
		$bits = array();
		if ( $by && ! user_can( $by, NHM_CAP ) ) {
			/* translators: %s: name */
			$bits[] = sprintf( __( 'Invited by %s', 'neurohr-members' ), $by->display_name );
		}
		if ( $company ) {
			/* translators: %s: plan */
			$bits[] = sprintf( __( '%s access, paid by your company', 'neurohr-members' ), NHM_Levels::name( $company['level_id'] ) );
		}
		$h .= $bits ? '<p class="nhm-muted">' . esc_html( implode( ' · ', $bits ) ) . '</p>' : '';
		$h .= '</div>';
		if ( is_user_logged_in() ) {
			return NHM_Forms::wrap( $h . NHM_Account::render_invite_box( $token ), 'nhm-register nhm-invite-page' );
		}
		$h .= NHM_Forms::render_messages( 'register' );
		$h .= NHM_Forms::open( 'register' ) . '<input type="hidden" name="nhm_invite" value="' . esc_attr( $token ) . '"><input type="hidden" name="redirect_to" value="">';
		$h .= NHM_Forms::field( array( 'name' => 'nhm_email', 'label' => __( 'Email address', 'neurohr-members' ), 'type' => 'email', 'value' => $invite['email'], 'required' => true, 'attrs' => 'readonly', 'help' => __( 'Your account uses the address the invitation was sent to.', 'neurohr-members' ) ) );
		$h .= NHM_Forms::field( array( 'name' => 'nhm_name', 'label' => __( 'Full name', 'neurohr-members' ), 'value' => NHM_Forms::value( 'register', 'nhm_name' ), 'required' => true, 'autocomplete' => 'name' ) );
		$min = max( 6, (int) NHM_Settings::get( 'min_password' ) );
		/* translators: %d: number of characters */
		$h .= NHM_Forms::field( array( 'name' => 'nhm_password', 'label' => __( 'Choose a password', 'neurohr-members' ), 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => sprintf( __( 'At least %d characters.', 'neurohr-members' ), $min ), 'attrs' => 'minlength="' . (int) $min . '"' ) );
		foreach ( NHM_Fields::for_register() as $key => $f ) {
			$value = NHM_Forms::raw( 'register', 'nhm_' . $key, 'checkboxes' === $f['type'] ? array() : '' );
			if ( 'company' === $key && '' === $value && $company ) {
				$value = $company['name'];
			}
			$h .= NHM_Fields::render( $f, $value );
		}
		$terms = self::consent_label();
		if ( $terms ) {
			$h .= '<p class="nhm-field nhm-field--terms"><label class="nhm-check"><input type="checkbox" name="nhm_terms" value="1" required' . checked( '1', NHM_Forms::value( 'register', 'nhm_terms' ), false ) . '> <span>' . wp_kses( $terms, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'span' => array( 'class' => array() ) ) ) . ' <span class="nhm-req">*</span></span></label></p>';
		}
		$h .= NHM_Forms::spam_fields();
		$h .= NHM_Forms::button( __( 'Join and log in', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' ) . '</form>';
		$h .= '<p class="nhm-switch">' . esc_html__( 'Already have an account with this email?', 'neurohr-members' ) . ' <a href="' . esc_url( self::login_url( NHM_Companies::invite_url( $token ) ) ) . '">' . esc_html__( 'Log in to accept', 'neurohr-members' ) . '</a></p>';
		return NHM_Forms::wrap( $h, 'nhm-register nhm-invite-page' );
	}

	public static function render_lost( $atts ) {
		$atts = shortcode_atts( array( 'title' => '' ), $atts, 'nhm_lost_password' );
		NHM_Forms::no_cache();
		$html = $atts['title'] ? '<h2 class="nhm-title">' . esc_html( $atts['title'] ) . '</h2>' : '';
		return NHM_Forms::wrap( $html . self::lost_inner( true ), 'nhm-lost' );
	}

	/** True when the forgot-password window should open by itself (reset link, result, errors). */
	public static function lost_open() {
		// phpcs:disable WordPress.Security.NonceVerification
		return ( isset( $_GET['action'] ) && 'rp' === $_GET['action'] ) || ! empty( $_GET['nhm_modal'] ) || NHM_Forms::has_errors( 'lost' ) || NHM_Forms::has_errors( 'reset' );
		// phpcs:enable
	}

	/** Forgot-password and choose-new-password forms (used in the login window and on [nhm_lost_password]). */
	public static function lost_inner( $page_mode = false ) {
		$html = '';
		// phpcs:disable WordPress.Security.NonceVerification
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : NHM_Forms::value( 'reset', 'rp_key' );
		$login  = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : NHM_Forms::value( 'reset', 'rp_login' );
		$sent   = isset( $_GET['nhm_msg'] ) && 'reset_sent' === $_GET['nhm_msg'];
		// phpcs:enable

		if ( ( 'rp' === $action || NHM_Forms::has_errors( 'reset' ) ) && $key && $login ) {
			$check = check_password_reset_key( $key, $login );
			$html .= NHM_Forms::render_messages( 'reset' );
			if ( ! is_wp_error( $check ) ) {
				$min   = max( 6, (int) NHM_Settings::get( 'min_password' ) );
				$html .= '<h2 class="nhm-modal__title">' . esc_html__( 'Choose a new password', 'neurohr-members' ) . '</h2>';
				$html .= NHM_Forms::open( 'reset' );
				$html .= '<input type="hidden" name="rp_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="rp_login" value="' . esc_attr( $login ) . '">';
				/* translators: %d: number of characters */
				$html .= NHM_Forms::field( array( 'name' => 'pass1', 'label' => __( 'New password', 'neurohr-members' ), 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => sprintf( __( 'At least %d characters.', 'neurohr-members' ), $min ), 'attrs' => 'minlength="' . (int) $min . '"' ) );
				$html .= NHM_Forms::button( __( 'Save new password', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' );
				return $html . '</form>';
			}
			if ( ! NHM_Forms::has_errors( 'reset' ) ) {
				$html .= '<div class="nhm-alert nhm-alert--error"><p>' . esc_html__( 'This link has expired or was already used. Request a new one below.', 'neurohr-members' ) . '</p></div>';
			}
		}

		$html .= '<h2 class="nhm-modal__title">' . esc_html__( 'Forgot your password?', 'neurohr-members' ) . '</h2>';
		$html .= NHM_Forms::render_messages( 'lost' );
		if ( $sent ) {
			return $html . '<p class="nhm-lead">' . esc_html__( 'Check your inbox for the link. It works once and expires after a day.', 'neurohr-members' ) . '</p>';
		}
		$html .= '<p class="nhm-lead">' . esc_html__( 'Enter the email address of your account. We will send you a link to choose a new password.', 'neurohr-members' ) . '</p>';
		$html .= NHM_Forms::open( 'lost' );
		$html .= NHM_Forms::field( array( 'name' => 'user_login', 'label' => __( 'Email address', 'neurohr-members' ), 'type' => 'email', 'value' => NHM_Forms::value( 'lost', 'user_login' ), 'required' => true, 'autocomplete' => 'email' ) );
		$html .= NHM_Forms::button( __( 'Send reset link', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' );
		$html .= '</form>';
		if ( $page_mode ) {
			$html .= '<p class="nhm-switch"><a href="' . esc_url( self::login_url() ) . '">' . esc_html__( 'Back to log in', 'neurohr-members' ) . '</a></p>';
		}
		return $html;
	}
}
