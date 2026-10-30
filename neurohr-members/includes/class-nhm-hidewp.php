<?php
/**
 * Members never see WordPress itself:
 * - logout at /logout/ (not wp-login.php?action=logout)
 * - wp-login.php and /wp-admin give a 404 to visitors who are not logged in; everyone logs
 *   in on your own login page (admins too). Links from WordPress's own password-reset
 *   emails are sent to your login page.
 * - WordPress's own account emails (password changed, email changed, new account,
 *   password reset) use the templates in Settings → Emails.
 * - no author pages / ?author=1 / user list in the REST API for visitors
 * - XML-RPC off
 *
 * If the login page ever breaks: rename the folder wp-content/plugins/neurohr-members in
 * cPanel → File Manager. The plugin stops, wp-login.php works again; log in, fix, rename back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_HideWP {

	public static function init() {
		add_filter( 'logout_url', array( __CLASS__, 'logout_url' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'logout_endpoint' ), 1 );
		add_action( 'init', array( __CLASS__, 'guard_admin' ), 2 );
		add_action( 'login_init', array( __CLASS__, 'guard_login' ), 0 );

		if ( NHM_Settings::get( 'hide_authors' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'no_authors' ), 0 );
			add_filter( 'rest_endpoints', array( __CLASS__, 'rest_users' ) );
			add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_author' ) );
		}
		if ( NHM_Settings::get( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( __CLASS__, 'no_pingback_header' ) );
			add_filter( 'pings_open', '__return_false', 20 );
		}
		if ( NHM_Settings::get( 'replace_core_mail' ) ) {
			add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'mail_reset' ), 20, 4 );
			add_filter( 'password_change_email', array( __CLASS__, 'mail_password_changed' ), 20, 2 );
			add_filter( 'send_password_change_email', array( __CLASS__, 'send_password_changed' ), 20 );
			add_filter( 'email_change_email', array( __CLASS__, 'mail_email_changed' ), 20, 3 );
			add_filter( 'send_email_change_email', array( __CLASS__, 'send_email_changed' ), 20 );
			add_filter( 'wp_new_user_notification_email', array( __CLASS__, 'mail_new_user' ), 20, 3 );
			add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
		}
	}

	/* ---------- Logout ---------- */

	public static function logout_url( $url, $redirect ) {
		$args = array( '_nhm' => wp_create_nonce( 'nhm_logout' ) );
		if ( $redirect ) {
			$args['redirect_to'] = rawurlencode( $redirect );
		}
		return add_query_arg( $args, home_url( '/logout/' ) );
	}

	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		return '/' . trim( $path, '/' ) . '/';
	}

	public static function logout_endpoint() {
		if ( '/logout/' !== self::request_path() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- the nonce is what is checked here.
		$nonce    = isset( $_GET['_nhm'] ) ? sanitize_text_field( wp_unslash( $_GET['_nhm'] ) ) : '';
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		// phpcs:enable
		$target = NHM_Settings::url( 'after_logout', home_url( '/' ) );
		if ( is_user_logged_in() ) {
			if ( ! wp_verify_nonce( $nonce, 'nhm_logout' ) ) {
				// Old or copied link: ask first, so nobody is logged out by a link on another site.
				$account = nhm_account_url() ? nhm_account_url() : home_url( '/' );
				wp_safe_redirect( add_query_arg( 'nhm_msg', 'logout_confirm', $account ) );
				exit;
			}
			wp_logout();
		}
		wp_safe_redirect( $redirect ? wp_validate_redirect( $redirect, $target ) : $target );
		exit;
	}

	/* ---------- 404 for wp-login.php and /wp-admin ---------- */

	public static function not_found() {
		global $wp_query;
		status_header( 404 );
		nocache_headers();
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		$template = function_exists( 'get_404_template' ) ? get_404_template() : '';
		if ( $template && did_action( 'after_setup_theme' ) ) {
			include $template;
		} else {
			echo '<!doctype html><title>404</title><p>Page not found.</p>';
		}
		exit;
	}

	/** Visitors who are not logged in get a 404 for /wp-admin (AJAX and form endpoints keep working). */
	public static function guard_admin() {
		if ( ! NHM_Settings::get( 'hide_wp_login' ) || is_user_logged_in() || ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return;
		}
		self::not_found();
	}

	/**
	 * wp-login.php: logged in → home of the member area; reset links → your login page;
	 * everything else for visitors → 404. Password-protected posts and privacy-request
	 * confirmations keep working.
	 */
	public static function guard_login() {
		if ( ! NHM_Settings::get( 'hide_wp_login' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( in_array( $action, array( 'postpass', 'confirmaction' ), true ) ) {
			return;
		}
		if ( 'logout' === $action ) {
			wp_safe_redirect( wp_logout_url() );
			exit;
		}
		if ( in_array( $action, array( 'rp', 'resetpass' ), true ) && ! empty( $_GET['key'] ) && ! empty( $_GET['login'] ) ) {
			$page = NHM_Settings::page_url( 'lost' ) ? NHM_Settings::page_url( 'lost' ) : NHM_Settings::page_url( 'login' );
			if ( $page ) {
				wp_safe_redirect( add_query_arg( array( 'action' => 'rp', 'key' => sanitize_text_field( wp_unslash( $_GET['key'] ) ), 'login' => rawurlencode( sanitize_text_field( wp_unslash( $_GET['login'] ) ) ) ), $page ) );
				exit;
			}
		}
		// phpcs:enable
		if ( is_user_logged_in() ) {
			wp_safe_redirect( NHM_Auth::after_login_url() );
			exit;
		}
		if ( ! NHM_Settings::page_url( 'login' ) ) {
			return; // No own login page yet: keep WordPress's, or nobody could log in.
		}
		self::not_found();
	}

	/* ---------- Authors ---------- */

	public static function no_authors() {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( is_author() || ( isset( $_GET['author'] ) && ! current_user_can( 'list_users' ) ) ) {
			self::not_found();
		}
	}

	public static function rest_users( $endpoints ) {
		// A logged-in browser (editor, Elementor) keeps the list, even before the REST nonce is checked.
		if ( is_user_logged_in() || ( function_exists( 'wp_validate_auth_cookie' ) && wp_validate_auth_cookie( '', 'logged_in' ) ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public static function oembed_author( $data ) {
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}

	public static function no_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/* ---------- WordPress's own emails ---------- */

	private static function vars_for( $user ) {
		return array(
			'{name}'  => $user->display_name ? $user->display_name : $user->user_login,
			'{email}' => $user->user_email,
		);
	}

	/** Fill a core email array with one of our templates. */
	private static function fill( array $email, $key, array $vars ) {
		$built = NHM_Emails::build( $key, $vars );
		if ( ! $built ) {
			return $email;
		}
		$email['subject'] = $built['subject'];
		$email['message'] = $built['html'];
		$email['headers'] = $built['headers'];
		return $email;
	}

	public static function mail_reset( $defaults, $key, $user_login, $user ) {
		$page = NHM_Settings::page_url( 'lost' ) ? NHM_Settings::page_url( 'lost' ) : NHM_Settings::page_url( 'login' );
		if ( ! $page || ! $user ) {
			return $defaults;
		}
		$url = add_query_arg( array( 'action' => 'rp', 'key' => $key, 'login' => rawurlencode( $user_login ) ), $page );
		$out = self::fill( $defaults, 'reset', array_merge( self::vars_for( $user ), array( '{reset_url}' => $url ) ) );
		$out['to'] = NHM_Members::contact_email( $user );
		return $out;
	}

	public static function send_password_changed( $send ) {
		$tpl = NHM_Emails::template( 'password_changed' );
		return $send && $tpl && $tpl['on'];
	}

	public static function mail_password_changed( $email, $user ) {
		$u = get_userdata( is_array( $user ) ? $user['ID'] : $user->ID );
		return $u ? self::fill( $email, 'password_changed', self::vars_for( $u ) ) : $email;
	}

	public static function send_email_changed( $send ) {
		$tpl = NHM_Emails::template( 'email_changed' );
		return $send && $tpl && $tpl['on'];
	}

	public static function mail_email_changed( $email, $user, $userdata ) {
		$u = get_userdata( is_array( $user ) ? $user['ID'] : $user->ID );
		if ( ! $u ) {
			return $email;
		}
		$new = is_array( $userdata ) && isset( $userdata['user_email'] ) ? $userdata['user_email'] : '';
		return self::fill( $email, 'email_changed', array_merge( self::vars_for( $u ), array( '{email}' => $email['to'], '{new_email}' => $new ) ) );
	}

	/** "Send the new user an email" when you add a person in Users → Add New. */
	public static function mail_new_user( $email, $user, $blogname ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $email;
		}
		return self::fill( $email, 'account', array_merge( self::vars_for( $user ), array( '{password_url}' => NHM_Emails::password_url( $user, $key ), '{level}' => NHM_Members::membership( $user->ID )['name'] ) ) );
	}
}
