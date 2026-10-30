<?php
/**
 * Sending email through your own mailbox (SMTP), for the whole site: this plugin's emails,
 * WordPress's own and other plugins'. Settings → Emails → Sending.
 *
 * With cPanel: Email Accounts → e.g. admin@neurohr.com.my → Connect Devices shows the
 * server (often mail.neurohr.com.my), port 465 (SSL). Turn on SPF and DKIM in cPanel →
 * Email Deliverability so mail does not land in spam.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Mailer {

	const ERROR_OPTION = 'nhm_mail_last_error';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'smtp' ), 20 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from' ), 20 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ) );
	}

	public static function enabled() {
		return NHM_Settings::get( 'smtp_on' ) && '' !== trim( (string) NHM_Settings::get( 'smtp_host' ) );
	}

	/** Every email from the site uses your sender instead of "WordPress <wordpress@…>". */
	public static function from( $email ) {
		$own = sanitize_email( (string) NHM_Settings::get( 'mail_from_email' ) );
		if ( $own ) {
			return $own;
		}
		$user = sanitize_email( (string) NHM_Settings::get( 'smtp_user' ) );
		return self::enabled() && is_email( $user ) ? $user : $email;
	}

	public static function from_name( $name ) {
		$own = trim( (string) NHM_Settings::get( 'mail_from_name' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return 'WordPress' === $name ? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : $name;
	}

	public static function smtp( $phpmailer ) {
		if ( ! self::enabled() ) {
			return;
		}
		$secure = (string) NHM_Settings::get( 'smtp_secure' );
		$phpmailer->isSMTP();
		$phpmailer->Host       = trim( (string) NHM_Settings::get( 'smtp_host' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Port       = (int) NHM_Settings::get( 'smtp_port' ) ? (int) NHM_Settings::get( 'smtp_port' ) : 465; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPSecure = in_array( $secure, array( 'ssl', 'tls' ), true ) ? $secure : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPAutoTLS = 'none' !== $secure; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$user = trim( (string) NHM_Settings::get( 'smtp_user' ) );
		if ( '' !== $user ) {
			$phpmailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Username = $user; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Password = (string) NHM_Settings::get( 'smtp_pass' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		$phpmailer->Timeout = 20; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		// The envelope sender must be the mailbox, or many servers refuse the email.
		if ( is_email( $user ) ) {
			$phpmailer->Sender = $user; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	}

	public static function failed( $error ) {
		if ( is_wp_error( $error ) ) {
			$data = $error->get_error_data();
			$to   = is_array( $data ) && isset( $data['to'] ) ? implode( ', ', (array) $data['to'] ) : '';
			update_option( self::ERROR_OPTION, array( 'time' => current_time( 'mysql' ), 'message' => mb_substr( $error->get_error_message(), 0, 300 ), 'to' => $to ), false );
		}
	}

	/** Settings → Emails → "Send a test email". @return true|WP_Error */
	public static function test( $to ) {
		delete_option( self::ERROR_OPTION );
		$ok = wp_mail(
			$to,
			sprintf( '[%s] Test email', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			"This is a test email from your website.\n\nIf you can read this, sending works." . ( self::enabled() ? "\nIt was sent through " . NHM_Settings::get( 'smtp_host' ) . '.' : "\nSMTP is off, so it was sent by the web server." )
		);
		if ( $ok ) {
			return true;
		}
		$err = get_option( self::ERROR_OPTION );
		return new WP_Error( 'nhm_mail', is_array( $err ) ? $err['message'] : __( 'The email could not be sent.', 'neurohr-members' ) );
	}
}
