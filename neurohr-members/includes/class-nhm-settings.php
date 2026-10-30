<?php
/**
 * All plugin settings live in one option. Each settings tab saves only its own keys
 * (merged into the stored array), so saving one tab never wipes another.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Settings {

	const OPTION = 'nhm_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			// Pages (IDs of normal WordPress pages that hold the shortcodes).
			'page_login'         => 0,
			'page_register'      => 0,
			'page_lost'          => 0,
			'page_account'       => 0,
			'page_pricing'       => 0,
			'after_login'        => '/dashboard/',
			'after_register'     => '',
			'after_logout'       => '/',
			'renew_url'          => '',
			'replace_wp_login'   => 1,
			'hide_admin_bar'     => 1,
			'block_admin'        => 1,
			'home_redirect'      => '/dashboard/', // Logged-in members who open the home page go here ('' = stay).

			// Hide WordPress.
			'hide_wp_login'      => 1, // wp-login.php and /wp-admin give a 404 to visitors who are not logged in.
			'hide_authors'       => 1, // No author pages, no ?author=1, no user list in the REST API for visitors.
			'disable_xmlrpc'     => 1,
			'replace_core_mail'  => 1, // WordPress's own account emails use the templates in Settings → Emails.

			// Member area layout (logged-in users, whole site).
			// Sending email (SMTP).
			'smtp_on'            => 0,
			'smtp_host'          => '',
			'smtp_port'          => 465,
			'smtp_secure'        => 'ssl', // ssl | tls | none
			'smtp_user'          => '',
			'smtp_pass'          => '',
			// Payments (0.4).
			'pay_stripe'         => 0,       // Card / FPX / GrabPay through Stripe Checkout.
			'pay_bank'           => 1,       // Bank transfer + proof upload.
			'pay_qr'             => 0,       // DuitNow QR + proof upload.
			'stripe_mode'        => 'test',  // test | live
			'stripe_test_pk'     => '',
			'stripe_test_sk'     => '',
			'stripe_test_whsec'  => '',
			'stripe_live_pk'     => '',
			'stripe_live_sk'     => '',
			'stripe_live_whsec'  => '',
			'qr_image'           => '',      // URL of the DuitNow QR picture.
			'qr_name'            => '',      // Name shown under the QR (e.g. the company name).
			'page_pay'           => 0,       // Page with [nhm_pay]; empty = My account page.
			'shell_enabled'      => 1,
			'shell_logo'         => '',       // Image URL; empty = the text logo below.
			'shell_logo_text'    => 'NeuroHR',
			'shell_logo_accent'  => 'HR',     // The end of the text logo shown in the brand colour.
			'shell_font'         => 'jakarta', // jakarta | theme
			'shell_theme_css'    => 0,
			'shell_search'       => 'Search knowledge, legal cases, templates…',
			'shell_promo_on'     => 1,
			'shell_promo_title'  => 'Grow people, build better businesses',
			'shell_promo_text'   => 'Practical HR knowledge and services for Malaysian SMEs.',
			'shell_promo_url'    => '',
			'shell_footer_text'  => '© {year} {site}. All rights reserved.',

			// Registration (form fields are in the nhm_fields option, see NHM_Fields).
			'registration_open'  => 1,
			'verify_email'       => 1, // New accounts confirm their email address before they can log in.
			'min_password'       => 8,
			'terms_text'         => '',      // Own text for the consent tick box (empty = built from the pages below).
			'consent_on'         => 1,       // Registration: must agree to the Terms of Use and Privacy Policy.
			'page_invite'        => 0,       // Page with [nhm_invite]: company invitations only (no plans).
			'page_terms'         => 0,
			'page_privacy'       => 0,       // 0 = WordPress Settings → Privacy page.
			'page_refund'        => 0,       // Refund policy page: a required tick box on the payment page.
			'turnstile_site'     => '',
			'turnstile_secret'   => '',
			'ml_enabled'         => 0,
			'ml_api_key'         => '',
			'ml_group'           => '',
			'ml_label'           => 'Send me HR updates and new content by email',
			'ml_checked'         => 0,
			'ml_group_content'   => '', // MailerLite group for "New content" (My account → Notifications).
			'ml_group_events'    => '', // MailerLite group for "Events".

			// Emails (texts: NHM_Emails::registry(), edited ones saved as email_{key}_subj/_body).
			'reminder_days'      => '30, 7',
			'admin_emails'       => '',
			'mail_from_name'     => '',
			'mail_from_email'    => '',

			// Business info (invoices).
			'biz_name'           => '',
			'biz_product'        => '',
			'blocklist'          => '',      // Emails and @domains that cannot register or log in.
			'biz_reg'            => '',
			'biz_tin'            => '',
			'biz_sst_no'         => '',
			'biz_address'        => '',
			'biz_postcode'       => '',
			'biz_city'           => '',
			'biz_state'          => '',
			'biz_country'        => 'Malaysia',
			'biz_phone'          => '',
			'biz_email'          => '',
			'biz_website'        => '',
			'biz_logo'           => '',
			'bank_name'          => '',
			'bank_account_name'  => '',
			'bank_account_no'    => '',
			'currency'           => 'RM',
			'sst_enabled'        => 0,
			'sst_rate'           => 8,
			'sst_inclusive'      => 0, // Level prices already include SST.
			'invoice_format'     => 'INV-{YYYY}-{####}',
			'refund_format'      => 'RN-{YYYY}-{####}',
			'refund_hours'       => 72,      // Members can ask for a refund this long after paying.
			'refund_days'        => 14,      // Working days to complete an approved refund.
			'invoice_due_days'   => 14,
			'online_due_days'    => 3,  // Sign-up and upgrade invoices: due after N days, cancelled the day after.
			'invoice_terms'      => 'Payment by bank transfer within 14 days. Please use the invoice number as the payment reference.',
			'invoice_footer'     => 'This is a computer-generated invoice. No signature is required.',
			'renewal_invoice'    => 1,

			// Protection and look.
			'protect_types'      => array( 'page' ),
			'protect_mode'       => 'message', // message | redirect
			'brand'              => '#f47b20',
			'load_css'           => 1,
			'invite_days'        => 14,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Remove keys from the stored settings (back to the default). */
	public static function forget( array $keys ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( $keys as $key ) {
			unset( $saved[ $key ] );
		}
		update_option( self::OPTION, $saved );
		self::$cache = null;
	}

	/** Stored value only (null when never saved). */
	public static function stored( $key ) {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) && array_key_exists( $key, $saved ) ? $saved[ $key ] : null;
	}

	/** Money with the currency, e.g. "RM 1,299.00". */
	public static function money( $amount ) {
		return trim( self::get( 'currency' ) . ' ' . number_format( (float) $amount, 2, '.', ',' ) );
	}

	/**
	 * Next number from a format like INV-{YYYY}-{####} or NHR-C{####}. The counter restarts
	 * every year when the format has {YYYY} or {YY}.
	 */
	public static function next_number( $format, $counter_option ) {
		$format = $format ? $format : '{####}';
		$year   = current_time( 'Y' );
		$key    = ( false !== strpos( $format, '{YY' ) ) ? $year : 'all';
		$seq    = get_option( $counter_option, array() );
		$seq    = is_array( $seq ) ? $seq : array();
		$n      = ( isset( $seq[ $key ] ) ? (int) $seq[ $key ] : 0 ) + 1;
		$seq[ $key ] = $n;
		update_option( $counter_option, $seq, false );
		$out = strtr( $format, array( '{YYYY}' => $year, '{YY}' => substr( $year, 2 ), '{MM}' => current_time( 'm' ) ) );
		return preg_replace_callback(
			'/\{(#+)\}/',
			function ( $m ) use ( $n ) {
				return str_pad( (string) $n, strlen( $m[1] ), '0', STR_PAD_LEFT );
			},
			$out
		);
	}

	/** Merge $values into the stored settings (other keys are kept). */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		update_option( self::OPTION, array_merge( $saved, $values ) );
		self::$cache = null;
	}

	/** Link of a legal page: terms | privacy | refund ('' when not set or not published). */
	public static function legal_url( $which ) {
		$id = (int) self::get( 'page_' . $which );
		if ( ! $id && 'privacy' === $which ) {
			$id = (int) get_option( 'wp_page_for_privacy_policy' );
		}
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/** Permalink of a configured page, or '' when not set / not published. */
	public static function page_url( $key ) {
		$id = (int) self::get( 'page_' . $key );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return get_permalink( $id );
		}
		return '';
	}

	/** A setting that holds a URL or a site path like /dashboard/. */
	public static function url( $key, $fallback = '' ) {
		$value = trim( (string) self::get( $key ) );
		if ( '' === $value ) {
			return $fallback;
		}
		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			return home_url( $value );
		}
		return $value;
	}

	/** Reminder days as a sorted list of positive integers, largest first. */
	public static function reminder_days() {
		$days = array_filter( array_map( 'absint', preg_split( '/[^0-9]+/', (string) self::get( 'reminder_days' ) ) ) );
		$days = array_values( array_unique( $days ) );
		rsort( $days );
		return $days;
	}

	public static function brand() {
		$color = sanitize_hex_color( (string) self::get( 'brand' ) );
		return $color ? $color : '#f47b20';
	}

	/** Local date today (site time zone), Y-m-d. */
	public static function today() {
		return current_time( 'Y-m-d' );
	}
}
