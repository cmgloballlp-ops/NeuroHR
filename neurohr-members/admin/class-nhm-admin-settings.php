<?php
/**
 * Settings with tabs. Each tab saves only its own fields (merged into the stored option).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Settings {

	public static function init() {
		add_action( 'admin_post_nhm_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nhm_create_page', array( __CLASS__, 'create_page' ) );
		add_action( 'admin_post_nhm_create_menus', array( __CLASS__, 'create_menus' ) );
		add_action( 'admin_post_nhm_stripe_check', array( __CLASS__, 'stripe_check' ) );
		add_action( 'admin_post_nhm_smtp_test', array( __CLASS__, 'smtp_test' ) );
		add_action( 'admin_post_nhm_test_email', array( __CLASS__, 'test_email' ) );
		add_action( 'admin_post_nhm_run_cron', array( __CLASS__, 'run_cron' ) );
		add_action( 'admin_post_nhm_reset_email', array( __CLASS__, 'reset_email' ) );
	}

	public static function tabs() {
		return array(
			'setup'        => array( __( 'Setup', 'neurohr-members' ), 'yes-alt' ),
			'pages'        => array( __( 'Pages & login', 'neurohr-members' ), 'admin-page' ),
			'registration' => array( __( 'Registration', 'neurohr-members' ), 'id-alt' ),
			'emails'       => array( __( 'Emails', 'neurohr-members' ), 'email-alt' ),
			'business'     => array( __( 'Business info & invoices', 'neurohr-members' ), 'building' ),
			'payments'     => array( __( 'Payments', 'neurohr-members' ), 'money-alt' ),
			'protection'   => array( __( 'Protection & look', 'neurohr-members' ), 'lock' ),
			'area'         => array( __( 'Member area', 'neurohr-members' ), 'layout' ),
			'changelog'    => array( __( 'Changelog', 'neurohr-members' ), 'list-view' ),
		);
	}

	/** Fields per tab: key => type (text, url, int, bool, select, textarea, html, types). */
	private static function fields( $tab ) {
		$map = array(
			'pages'        => array(
				'page_login'       => 'int',
				'page_register'    => 'int',
				'page_lost'        => 'int',
				'page_account'     => 'int',
				'page_pricing'     => 'int',
				'page_terms'       => 'int',
				'page_privacy'     => 'int',
				'page_refund'      => 'int',
				'after_login'      => 'url',
				'after_register'   => 'url',
				'after_logout'     => 'url',
				'renew_url'        => 'url',
				'replace_wp_login' => 'bool',
				'hide_wp_login'    => 'bool',
				'home_redirect'    => 'url',
				'hide_admin_bar'   => 'bool',
				'block_admin'      => 'bool',
			),
			'registration' => array(
				'registration_open' => 'bool',
				'verify_email'      => 'bool',
				'ml_group_content'  => 'text',
				'ml_group_events'   => 'text',
				'min_password'      => 'int',
				'consent_on'        => 'bool',
				'blocklist'         => 'textarea',
				'terms_text'        => 'html',
				'turnstile_site'    => 'text',
				'turnstile_secret'  => 'text',
				'ml_enabled'        => 'bool',
				'ml_api_key'        => 'secret',
				'ml_group'          => 'text',
				'ml_label'          => 'text',
				'ml_checked'        => 'bool',
			),
			'emails'       => array(
				'reminder_days'   => 'text',
				'admin_emails'    => 'text',
				'mail_from_name'  => 'text',
				'smtp_on'         => 'bool',
				'smtp_host'       => 'text',
				'smtp_port'       => 'int',
				'smtp_secure'     => 'text',
				'smtp_user'       => 'text',
				'smtp_pass'       => 'secret',
				'mail_from_email' => 'email',
			),
			'business'     => array(
				'biz_name'           => 'text',
				'biz_product'        => 'text',
				'biz_reg'            => 'text',
				'biz_tin'            => 'text',
				'biz_sst_no'         => 'text',
				'biz_address'        => 'textarea',
				'biz_postcode'       => 'text',
				'biz_city'           => 'text',
				'biz_state'          => 'text',
				'biz_country'        => 'text',
				'biz_phone'          => 'text',
				'biz_email'          => 'email',
				'biz_website'        => 'text',
				'biz_logo'           => 'url',
				'bank_name'          => 'text',
				'bank_account_name'  => 'text',
				'bank_account_no'    => 'text',
				'currency'           => 'text',
				'sst_enabled'        => 'bool',
				'sst_rate'           => 'decimal',
				'sst_inclusive'      => 'bool',
				'invoice_format'     => 'text',
				'refund_format'      => 'text',
				'refund_hours'       => 'int',
				'refund_days'        => 'int',
				'invoice_due_days'   => 'int',
				'invoice_terms'      => 'textarea',
				'invoice_footer'     => 'text',
				'renewal_invoice'    => 'bool',
			),
			'protection'   => array(
				'protect_types' => 'types',
				'protect_mode'  => 'text',
				'brand'         => 'color',
				'load_css'      => 'bool',
				'hide_authors'  => 'bool',
				'disable_xmlrpc' => 'bool',
				'replace_core_mail' => 'bool',
				'invite_days'   => 'int',
			),
		);
		$map['payments'] = array(
			'pay_stripe'        => 'bool',
			'pay_bank'          => 'bool',
			'pay_qr'            => 'bool',
			'stripe_mode'       => 'text',
			'stripe_test_pk'    => 'text',
			'stripe_test_sk'    => 'secret',
			'stripe_test_whsec' => 'secret',
			'stripe_live_pk'    => 'text',
			'stripe_live_sk'    => 'secret',
			'stripe_live_whsec' => 'secret',
			'qr_image'          => 'url',
			'qr_name'           => 'text',
			'page_pay'          => 'int',
		);
		$map['area'] = array(
			'shell_enabled'     => 'bool',
			'shell_theme_css'   => 'bool',
			'shell_logo'        => 'url',
			'shell_logo_text'   => 'text',
			'shell_logo_accent' => 'text',
			'shell_search'      => 'text',
			'shell_promo_on'    => 'bool',
			'shell_promo_title' => 'text',
			'shell_promo_text'  => 'text',
			'shell_promo_url'   => 'url',
			'shell_footer_text' => 'text',
		);
		if ( 'emails' === $tab ) {
			foreach ( array_keys( NHM_Emails::registry() ) as $key ) {
				$map['emails'][ 'email_' . $key . '_on' ]   = 'bool';
				$map['emails'][ 'email_' . $key . '_subj' ] = 'email_text';
				$map['emails'][ 'email_' . $key . '_body' ] = 'email_text';
			}
		}
		return isset( $map[ $tab ] ) ? $map[ $tab ] : array();
	}

	public static function save() {
		NHM_Admin::check( 'nhm_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$in     = isset( $_POST['nhm'] ) && is_array( $_POST['nhm'] ) ? wp_unslash( $_POST['nhm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per type below.
		// phpcs:enable
		$values = array();
		$forget = array();
		foreach ( self::fields( $tab ) as $key => $type ) {
			$raw = isset( $in[ $key ] ) ? $in[ $key ] : null;
			switch ( $type ) {
				case 'bool':
					$values[ $key ] = empty( $raw ) ? 0 : 1;
					break;
				case 'int':
					$values[ $key ] = absint( $raw );
					break;
				case 'url':
					$values[ $key ] = NHM_Levels::clean_url( (string) $raw );
					break;
				case 'email':
					$values[ $key ] = sanitize_email( (string) $raw );
					break;
				case 'mode':
					$values[ $key ] = in_array( $raw, array( 'required', 'optional', 'hidden' ), true ) ? $raw : 'optional';
					break;
				case 'textarea':
					$values[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'html':
					$values[ $key ] = wp_kses( (string) $raw, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) );
					break;
				case 'decimal':
					$values[ $key ] = max( 0, min( 100, (float) $raw ) );
					break;
				case 'email_text':
					// Same as the default text = not stored, so later default improvements apply.
					if ( null === $raw ) {
						break;
					}
					$parts = explode( '_', substr( $key, 6 ) );
					$which = array_pop( $parts );
					$ekey  = implode( '_', $parts );
					$reg   = NHM_Emails::registry();
					$clean = 'body' === $which ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
					if ( isset( $reg[ $ekey ] ) && str_replace( "\r\n", "\n", $clean ) === $reg[ $ekey ][ $which ] ) {
						$forget[] = $key;
					} else {
						$values[ $key ] = $clean;
					}
					break;
				case 'color':
					$color          = sanitize_hex_color( (string) $raw );
					$values[ $key ] = $color ? $color : '#f47b20';
					break;
				case 'types':
					$values[ $key ] = array_values( array_filter( array_map( 'sanitize_key', (array) $raw ), 'post_type_exists' ) );
					break;
				case 'secret':
					// Empty field = keep the saved key (it is never printed back).
					if ( '' !== trim( (string) $raw ) ) {
						$values[ $key ] = sanitize_text_field( (string) $raw );
					}
					if ( ! empty( $in[ $key . '_clear' ] ) ) {
						$values[ $key ] = '';
					}
					break;
				default:
					$values[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		if ( isset( $values['protect_mode'] ) && ! in_array( $values['protect_mode'], array( 'message', 'redirect' ), true ) ) {
			$values['protect_mode'] = 'message';
		}
		if ( isset( $values['min_password'] ) ) {
			$values['min_password'] = max( 6, min( 64, $values['min_password'] ) );
		}
		if ( isset( $values['invite_days'] ) ) {
			$values['invite_days'] = max( 1, min( 90, $values['invite_days'] ) );
		}
		if ( 'registration' === $tab ) {
			$rows = isset( $_POST['nhm_fields'] ) && is_array( $_POST['nhm_fields'] ) ? wp_unslash( $_POST['nhm_fields'] ) : array(); // phpcs:ignore WordPress.Security -- sanitised in NHM_Fields::save().
			NHM_Fields::save( array_values( $rows ) );
		}
		if ( isset( $values['invoice_format'] ) && false === strpos( $values['invoice_format'], '{#' ) ) {
			$values['invoice_format'] .= '-{####}';
		}
		if ( isset( $values['refund_format'] ) ) {
			$values['refund_format'] = '' === trim( $values['refund_format'] ) ? 'RN-{YYYY}-{####}' : $values['refund_format'];
			if ( false === strpos( $values['refund_format'], '{#' ) ) {
				$values['refund_format'] .= '-{####}';
			}
		}
		foreach ( array( 'invoice' => 'invoice_format', 'refund' => 'refund_format' ) as $kind => $fmt_key ) {
			// "Next number": set the running number once.
			$next = isset( $_POST['nhm_next'][ $kind ] ) ? absint( $_POST['nhm_next'][ $kind ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked in save().
			if ( $next > 0 ) {
				$fmt = isset( $values[ $fmt_key ] ) ? $values[ $fmt_key ] : (string) NHM_Settings::get( $fmt_key );
				$opt = 'invoice' === $kind ? 'nhm_invoice_seq' : 'nhm_refund_seq';
				$seq = get_option( $opt, array() );
				$seq = is_array( $seq ) ? $seq : array();
				$seq[ false !== strpos( $fmt, '{YY' ) ? current_time( 'Y' ) : 'all' ] = $next - 1;
				update_option( $opt, $seq, false );
			}
		}
		if ( isset( $values['refund_hours'] ) ) {
			$values['refund_hours'] = max( 0, min( 720, $values['refund_hours'] ) );
		}
		if ( isset( $values['refund_days'] ) ) {
			$values['refund_days'] = max( 1, min( 60, $values['refund_days'] ) );
		}
		if ( $forget ) {
			NHM_Settings::forget( $forget );
		}
		NHM_Settings::update( $values );
		NHM_Admin::flash( __( 'Settings saved.', 'neurohr-members' ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-settings&tab=' . $tab ) );
	}

	/* ---------- Page ---------- */

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'setup'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'setup';
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Members settings', 'neurohr-members' ) );
		echo '<nav class="nhm-admin-tabs">';
		foreach ( $tabs as $key => $t ) {
			echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=nhm-settings&tab=' . $key ) ) . '"><span class="dashicons dashicons-' . esc_attr( $t[1] ) . '"></span>' . esc_html( $t[0] ) . '</a>';
		}
		echo '</nav>';

		if ( 'setup' === $tab ) {
			self::tab_setup();
		} elseif ( 'changelog' === $tab ) {
			self::tab_changelog();
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="nhm_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
			wp_nonce_field( 'nhm_save_settings' );
			call_user_func( array( __CLASS__, 'tab_' . $tab ) );
			submit_button();
			echo '</form>';
		}
		echo '</div>';
	}

	private static function name( $key ) {
		return 'nhm[' . $key . ']';
	}

	private static function toggle( $key, $title, $text = '' ) {
		echo '<label class="nhm-toggle"><input type="hidden" name="' . esc_attr( self::name( $key ) ) . '" value="0"><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1" ' . checked( (bool) NHM_Settings::get( $key ), true, false ) . '> <span><strong>' . esc_html( $title ) . '</strong>' . ( $text ? '<br>' . wp_kses_post( $text ) : '' ) . '</span></label>';
	}

	/** "Next number" box: shows the next number and lets you set it once. */
	private static function next_field( $kind, $fmt_key, $opt ) {
		$fmt  = (string) NHM_Settings::get( $fmt_key );
		$seq  = get_option( $opt, array() );
		$key  = false !== strpos( $fmt, '{YY' ) ? current_time( 'Y' ) : 'all';
		$next = ( is_array( $seq ) && isset( $seq[ $key ] ) ? (int) $seq[ $key ] : 0 ) + 1;
		echo '<p class="nhm-field"><label for="nhm-next-' . esc_attr( $kind ) . '">' . esc_html__( 'Next running number', 'neurohr-members' ) . '</label>';
		echo '<input type="number" min="1" id="nhm-next-' . esc_attr( $kind ) . '" name="nhm_next[' . esc_attr( $kind ) . ']" value="" placeholder="' . esc_attr( (string) $next ) . '" style="max-width:140px">';
		/* translators: %d: number */
		echo '<span class="description">' . esc_html( sprintf( __( 'Now %d. Type a number only to change it, e.g. to follow your accounting system.', 'neurohr-members' ), $next ) ) . '</span></p>';
	}

	private static function text( $key, $label, $help = '', $type = 'text', $placeholder = '' ) {
		echo '<p class="nhm-field"><label for="nhm-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="' . esc_attr( $type ) . '" id="nhm-s-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) NHM_Settings::get( $key ) ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
		if ( $help ) {
			echo '<span class="description">' . wp_kses_post( $help ) . '</span>';
		}
		echo '</p>';
	}

	private static function page_keys() {
		return array(
			'login'    => array( __( 'Login page', 'neurohr-members' ), '[nhm_login]', __( 'Log in', 'neurohr-members' ) ),
			'register' => array( __( 'Registration page', 'neurohr-members' ), '[nhm_register]', __( 'Create your account', 'neurohr-members' ) ),
			'lost'     => array( __( 'Lost password page (optional: the login page has a pop-up)', 'neurohr-members' ), '[nhm_lost_password]', __( 'Reset your password', 'neurohr-members' ) ),
			'account'  => array( __( 'My account page', 'neurohr-members' ), '[nhm_account]', __( 'My account', 'neurohr-members' ) ),
			'pricing'  => array( __( 'Pricing page', 'neurohr-members' ), '[nhm_pricing]', __( 'Plans and pricing', 'neurohr-members' ) ),
		);
	}

	private static function tab_pages() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-admin-page"></span>' . esc_html__( 'Your pages', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Pick the page that holds each shortcode. Design the page itself in Elementor or Blocksy as you like. You can reuse the old MemberPress pages: replace their MemberPress shortcode with the one shown here.', 'neurohr-members' ) . '</p>';
		echo '<table class="nhm-admin-table nhm-pages-table"><tbody>';
		foreach ( self::page_keys() as $key => $info ) {
			$id     = (int) NHM_Settings::get( 'page_' . $key );
			$has_sc = $id && false !== strpos( (string) get_post_field( 'post_content', $id ), rtrim( $info[1], ']' ) );
			echo '<tr><th>' . esc_html( $info[0] ) . '</th><td>';
			wp_dropdown_pages(
				array(
					'name'              => esc_attr( self::name( 'page_' . $key ) ),
					'selected'          => $id, // phpcs:ignore WordPress.Security.EscapeOutput
					'show_option_none'  => esc_html__( '— choose a page —', 'neurohr-members' ),
					'option_none_value' => '0',
					'post_status'       => array( 'publish', 'draft', 'private' ),
				)
			);
			echo ' <code>' . esc_html( $info[1] ) . '</code>';
			if ( $id ) {
				echo ' <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'neurohr-members' ) . '</a>';
				if ( 'publish' !== get_post_status( $id ) ) {
					echo ' <span class="nhm-exp--ended">' . esc_html__( 'not published', 'neurohr-members' ) . '</span>';
				} elseif ( ! $has_sc && ! get_post_meta( $id, '_elementor_data', true ) ) {
					echo ' <span class="nhm-exp--soon">' . esc_html__( 'shortcode not found on the page', 'neurohr-members' ) . '</span>';
				}
			} else {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=nhm_create_page&key=' . $key ), 'nhm_create_page' );
				echo ' <a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Create page', 'neurohr-members' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-media-text"></span>' . esc_html__( 'Policy pages', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Your own pages. Terms of Use and Privacy Policy: a required tick box on the registration form. Refund Policy: a required tick box on the payment page.', 'neurohr-members' ) . '</p>';
		echo '<table class="nhm-admin-table nhm-pages-table"><tbody>';
		$legal = array(
			'terms'   => __( 'Terms of Use', 'neurohr-members' ),
			'privacy' => __( 'Privacy Policy', 'neurohr-members' ),
			'refund'  => __( 'Refund Policy', 'neurohr-members' ),
		);
		foreach ( $legal as $key => $label ) {
			$id = (int) NHM_Settings::get( 'page_' . $key );
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			wp_dropdown_pages(
				array(
					'name'              => esc_attr( self::name( 'page_' . $key ) ),
					'selected'          => $id, // phpcs:ignore WordPress.Security.EscapeOutput
					'show_option_none'  => 'privacy' === $key ? esc_html__( '— the WordPress privacy page —', 'neurohr-members' ) : esc_html__( '— choose a page —', 'neurohr-members' ),
					'option_none_value' => '0',
					'post_status'       => array( 'publish', 'draft', 'private' ),
				)
			);
			$url = NHM_Settings::legal_url( $key );
			echo $url ? ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'neurohr-members' ) . '</a>' : ( $id ? ' <span class="nhm-exp--ended">' . esc_html__( 'not published', 'neurohr-members' ) . '</span>' : '' );
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-randomize"></span>' . esc_html__( 'Where people go', 'neurohr-members' ) . '</h2><div class="nhm-fields">';
		self::text( 'after_login', __( 'After login', 'neurohr-members' ), __( 'When the person did not come from a members-only page. e.g. /dashboard/', 'neurohr-members' ), 'text', '/dashboard/' );
		self::text( 'after_register', __( 'After registration', 'neurohr-members' ), __( 'Empty = same as after login.', 'neurohr-members' ), 'text', '/dashboard/' );
		self::text( 'after_logout', __( 'After logout', 'neurohr-members' ), '', 'text', '/' );
		self::text( 'home_redirect', __( 'Members who open the home page go to', 'neurohr-members' ), __( 'e.g. /dashboard/. Empty = they see the normal home page. Editors and admins always see the home page.', 'neurohr-members' ), 'text', '/dashboard/' );
		self::text( 'renew_url', __( 'Renew link', 'neurohr-members' ), __( 'Used in reminder emails and the Renew button. e.g. /contact/, a WhatsApp link or mailto:. Empty = pricing page.', 'neurohr-members' ), 'text', 'https://wa.me/60…' );
		echo '</div></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-shield"></span>' . esc_html__( 'WordPress login screens', 'neurohr-members' ) . '</h2>';
		self::toggle( 'hide_wp_login', __( 'Hide WordPress: wp-login.php and /wp-admin give "page not found" to visitors', 'neurohr-members' ), __( 'Everyone (you too) logs in on your own login page; logout is at /logout/. Only works once the login page above is set. If the login page ever breaks: in cPanel → File Manager, rename the folder <code>wp-content/plugins/neurohr-members</code> (e.g. to neurohr-members-off). WordPress\'s normal login works again; log in, rename it back, fix the page.', 'neurohr-members' ) );
		self::toggle( 'replace_wp_login', __( 'Links to wp-login.php open my pages', 'neurohr-members' ), __( 'Used when the option above is off.', 'neurohr-members' ) );
		self::toggle( 'hide_admin_bar', __( 'Hide the black WordPress toolbar for members', 'neurohr-members' ), __( 'Editors and admins still see it.', 'neurohr-members' ) );
		self::toggle( 'block_admin', __( 'Keep members out of wp-admin', 'neurohr-members' ), __( 'Members who open /wp-admin/ go to My account.', 'neurohr-members' ) );
		echo '</section>';
	}

	private static function tab_registration() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-id-alt"></span>' . esc_html__( 'Registration', 'neurohr-members' ) . '</h2>';
		self::toggle( 'registration_open', __( 'Anyone can create a free account', 'neurohr-members' ), __( 'When off, only people with a company invitation can register.', 'neurohr-members' ) );
		self::toggle( 'consent_on', __( 'People must agree to the Terms of Use and Privacy Policy', 'neurohr-members' ), __( 'A required tick box with links to the pages chosen in Pages & login → Policy pages. The date and time of agreeing is kept on the member\'s profile.', 'neurohr-members' ) );
		self::toggle( 'verify_email', __( 'Confirm the email address before the account works', 'neurohr-members' ), __( 'New people get an email with a link; they can log in after clicking it. People who join through a company invitation, and accounts you add yourself, are confirmed already.', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		self::text( 'min_password', __( 'Minimum password length', 'neurohr-members' ), '', 'number' );
		echo '<p class="nhm-field"><label for="nhm-s-terms">' . esc_html__( 'Own text for the tick box (optional)', 'neurohr-members' ) . '</label><input type="text" id="nhm-s-terms" name="' . esc_attr( self::name( 'terms_text' ) ) . '" value="' . esc_attr( NHM_Settings::get( 'terms_text' ) ) . '" placeholder="' . esc_attr( 'I agree to the <a href="/terms/">Terms</a> and <a href="/privacy-policy/">Privacy Policy</a>' ) . '"><span class="description">' . esc_html__( 'Empty = "I have read and agree to the Terms of Use and Privacy Policy." with the links. When filled, this text is used instead (links allowed) and people must tick it.', 'neurohr-members' ) . '</span></p>';
		echo '</div><p class="description">' . esc_html__( 'The email to you about new sign-ups is in the Emails tab (Admin notices → New Signup).', 'neurohr-members' ) . '</p></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-dismiss"></span>' . esc_html__( 'Blocked emails', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'These cannot register or log in. One per line: a full email address, or a whole domain such as @spam-domain.com. People you block on the Members page are added here automatically; unblocking removes them.', 'neurohr-members' ) . '</p>';
		echo '<p class="nhm-field"><label for="nhm-s-blocklist">' . esc_html__( 'Block list', 'neurohr-members' ) . '</label><textarea id="nhm-s-blocklist" rows="5" name="' . esc_attr( self::name( 'blocklist' ) ) . '" placeholder="spammer@example.com&#10;@spam-domain.com">' . esc_textarea( NHM_Settings::get( 'blocklist' ) ) . '</textarea></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: message */ __( 'They see: "%s"', 'neurohr-members' ), NHM_Block::message() ) ) . '</p></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-forms"></span>' . esc_html__( 'Form fields', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Name, email and password are always asked. Add your own fields, drag to reorder, and choose where each one shows. Company, Phone and Job title can be hidden but not deleted. Answers appear on the member\'s profile, in the CSV export and in the new-signup email.', 'neurohr-members' ) . '</p>';
		echo '<div class="nhm-fieldlist" data-nhm-fieldlist>';
		$i = 0;
		foreach ( NHM_Fields::all() as $f ) {
			self::field_row( $i++, $f );
		}
		echo '</div>';
		echo '<template id="nhm-field-template">';
		self::field_row( '__i__', array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => array(), 'required' => 0, 'placeholder' => '', 'help' => '', 'register' => 1, 'account' => 1, 'builtin' => 0 ) );
		echo '</template>';
		echo '<p><button type="button" class="button" data-nhm-add-field>+ ' . esc_html__( 'Add a field', 'neurohr-members' ) . '</button></p>';
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-shield-alt"></span>' . esc_html__( 'Spam protection', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Always on: a hidden trap field, a minimum time to fill the form, and at most 5 sign-ups per hour from one network. For more, add Cloudflare Turnstile (free, no puzzles for real people): dash.cloudflare.com → Turnstile → Add site.', 'neurohr-members' ) . '</p><div class="nhm-fields">';
		self::text( 'turnstile_site', __( 'Turnstile site key', 'neurohr-members' ) );
		self::text( 'turnstile_secret', __( 'Turnstile secret key', 'neurohr-members' ) );
		echo '</div></section>';

		$error = get_option( NHM_MailerLite::ERROR_OPTION );
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-email"></span>MailerLite</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'A tick box on the registration form. People who tick it are added to a MailerLite group.', 'neurohr-members' ) . '</p>';
		self::toggle( 'ml_enabled', __( 'Show the newsletter tick box', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		$has_key = '' !== trim( (string) NHM_Settings::get( 'ml_api_key' ) );
		echo '<p class="nhm-field"><label for="nhm-s-ml">' . esc_html__( 'API token', 'neurohr-members' ) . '</label><input type="password" id="nhm-s-ml" name="' . esc_attr( self::name( 'ml_api_key' ) ) . '" value="" autocomplete="off" placeholder="' . esc_attr( $has_key ? __( 'Saved — paste a new one to replace', 'neurohr-members' ) : __( 'MailerLite → Integrations → API', 'neurohr-members' ) ) . '">';
		if ( $has_key ) {
			echo '<label class="nhm-inline-check"><input type="checkbox" name="' . esc_attr( self::name( 'ml_api_key_clear' ) ) . '" value="1"> ' . esc_html__( 'Remove saved token', 'neurohr-members' ) . '</label>';
		}
		echo '</p>';
		self::text( 'ml_group', __( 'Group ID', 'neurohr-members' ), __( 'MailerLite → Subscribers → Groups → open the group: the number in the address.', 'neurohr-members' ) );
		self::text( 'ml_group_content', __( 'Group ID: "New content" notifications', 'neurohr-members' ), __( 'Members switch this on in My account → Notifications. Empty = the switch is not shown.', 'neurohr-members' ) );
		self::text( 'ml_group_events', __( 'Group ID: "Events & webinars" notifications', 'neurohr-members' ), __( 'Empty = the switch is not shown.', 'neurohr-members' ) );
		self::text( 'ml_label', __( 'Tick box text', 'neurohr-members' ) );
		echo '</div>';
		self::toggle( 'ml_checked', __( 'Ticked by default', 'neurohr-members' ), __( 'Under Malaysia\'s PDPA, consent is clearer when people tick it themselves. Recommended: off.', 'neurohr-members' ) );
		if ( is_array( $error ) ) {
			/* translators: 1: time, 2: message */
			echo '<p class="nhm-exp--ended">' . esc_html( sprintf( __( 'Last MailerLite error (%1$s): %2$s', 'neurohr-members' ), $error['time'], $error['message'] ) ) . '</p>';
		}
		echo '</section>';
	}

	/** One row of the field builder. */
	private static function field_row( $i, array $f ) {
		$n       = 'nhm_fields[' . $i . ']';
		$builtin = ! empty( $f['builtin'] );
		$choice  = in_array( $f['type'], array( 'select', 'radio', 'checkboxes' ), true );
		echo '<details class="nhm-frow' . ( $builtin ? ' is-builtin' : '' ) . '"' . ( '' === $f['key'] ? ' open' : '' ) . '>';
		echo '<summary><span class="nhm-frow__handle dashicons dashicons-menu" aria-hidden="true"></span><strong class="nhm-frow__label">' . esc_html( $f['label'] ? $f['label'] : __( 'New field', 'neurohr-members' ) ) . '</strong>';
		echo '<span class="nhm-pill">' . esc_html( isset( NHM_Fields::types()[ $f['type'] ] ) ? NHM_Fields::types()[ $f['type'] ] : $f['type'] ) . '</span>';
		if ( $f['required'] ) {
			echo '<span class="nhm-pill nhm-pill--req">' . esc_html__( 'required', 'neurohr-members' ) . '</span>';
		}
		if ( ! $f['register'] && ! $f['account'] ) {
			echo '<span class="nhm-pill nhm-pill--muted">' . esc_html__( 'hidden', 'neurohr-members' ) . '</span>';
		}
		echo '<span class="nhm-level__edit">' . esc_html__( 'Edit', 'neurohr-members' ) . '</span></summary><div class="nhm-frow__body">';
		echo '<input type="hidden" name="' . esc_attr( $n ) . '[key]" value="' . esc_attr( $f['key'] ) . '">';
		echo '<div class="nhm-fields">';
		echo '<p class="nhm-field"><label>' . esc_html__( 'Label', 'neurohr-members' ) . '</label><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( $f['label'] ) . '" data-nhm-field-label></p>';
		echo '<p class="nhm-field"><label>' . esc_html__( 'Type', 'neurohr-members' ) . '</label>';
		if ( $builtin ) {
			echo '<input type="text" value="' . esc_attr( NHM_Fields::types()[ $f['type'] ] ) . '" disabled><input type="hidden" name="' . esc_attr( $n ) . '[type]" value="' . esc_attr( $f['type'] ) . '">';
		} else {
			echo '<select name="' . esc_attr( $n ) . '[type]" data-nhm-field-type>';
			foreach ( NHM_Fields::types() as $t => $tl ) {
				echo '<option value="' . esc_attr( $t ) . '"' . selected( $f['type'], $t, false ) . '>' . esc_html( $tl ) . '</option>';
			}
			echo '</select>';
		}
		echo '</p>';
		echo '<p class="nhm-field"><label>' . esc_html__( 'Placeholder', 'neurohr-members' ) . '</label><input type="text" name="' . esc_attr( $n ) . '[placeholder]" value="' . esc_attr( $f['placeholder'] ) . '"></p>';
		echo '<p class="nhm-field"><label>' . esc_html__( 'Help text under the field', 'neurohr-members' ) . '</label><input type="text" name="' . esc_attr( $n ) . '[help]" value="' . esc_attr( $f['help'] ) . '"></p>';
		echo '</div>';
		echo '<p class="nhm-field" data-nhm-options' . ( $choice && ! $builtin ? '' : ' hidden' ) . '><label>' . esc_html__( 'Choices (one per line)', 'neurohr-members' ) . '</label><textarea rows="4" name="' . esc_attr( $n ) . '[options]" placeholder="' . esc_attr( "1–10\n11–50\n51–200\n200+" ) . '">' . esc_textarea( implode( "\n", (array) $f['options'] ) ) . '</textarea></p>';
		echo '<p class="nhm-toggles">';
		echo '<label class="nhm-inline-check"><input type="checkbox" name="' . esc_attr( $n ) . '[required]" value="1"' . checked( ! empty( $f['required'] ), true, false ) . '> ' . esc_html__( 'Required', 'neurohr-members' ) . '</label> ';
		echo '<label class="nhm-inline-check"><input type="checkbox" name="' . esc_attr( $n ) . '[register]" value="1"' . checked( ! empty( $f['register'] ), true, false ) . '> ' . esc_html__( 'Show on the registration form', 'neurohr-members' ) . '</label> ';
		echo '<label class="nhm-inline-check"><input type="checkbox" name="' . esc_attr( $n ) . '[account]" value="1"' . checked( ! empty( $f['account'] ), true, false ) . '> ' . esc_html__( 'Members can change it in My account', 'neurohr-members' ) . '</label>';
		echo '</p>';
		if ( 'location' === $f['key'] ) {
			echo '<p class="description">' . esc_html__( 'Choices: the Malaysian states and federal territories, plus "Outside Malaysia".', 'neurohr-members' ) . '</p>';
		} elseif ( 'phone' === $f['key'] ) {
			echo '<p class="description">' . esc_html__( 'With a country code picker (Malaysia +60 first). Saved as +60123456789.', 'neurohr-members' ) . '</p>';
		}
		if ( ! $builtin ) {
			echo '<p class="nhm-frow__delete"><label class="nhm-link-danger"><input type="checkbox" name="' . esc_attr( $n ) . '[delete]" value="1"> ' . esc_html__( 'Delete this field when saving (answers already given stay in the database)', 'neurohr-members' ) . '</label></p>';
		}
		if ( $f['key'] ) {
			echo '<p class="description">' . esc_html__( 'Stored as', 'neurohr-members' ) . ' <code>' . esc_html( NHM_Fields::get( $f['key'] ) ? NHM_Fields::get( $f['key'] )['meta'] : '' ) . '</code></p>';
		}
		echo '</div></details>';
	}

	private static function tab_emails() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-admin-settings"></span>' . esc_html__( 'Sending', 'neurohr-members' ) . '</h2><div class="nhm-fields">';
		self::text( 'mail_from_name', __( 'From name', 'neurohr-members' ), __( 'Used for every email of the website. Empty = the site title (Settings → General).', 'neurohr-members' ), 'text', 'NeuroHR' );
		self::text( 'mail_from_email', __( 'From email', 'neurohr-members' ), __( 'Use an address on your own domain (e.g. admin@neurohr.com.my), so emails are not marked as spam.', 'neurohr-members' ), 'email', 'admin@neurohr.com.my' );
		self::text( 'admin_emails', __( 'Admin email addresses', 'neurohr-members' ), __( 'Admin notices go here. Several: separate with commas. Empty = the site admin email.', 'neurohr-members' ), 'text', get_option( 'admin_email' ) );
		self::text( 'reminder_days', __( 'Reminder days before the last day', 'neurohr-members' ), __( 'Comma separated, e.g. 30, 7', 'neurohr-members' ) );
		echo '</div></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-email"></span>' . esc_html__( 'Send through your mailbox (SMTP)', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'All emails of the website (this plugin, WordPress, other plugins) are sent through this mailbox, so they arrive from your address and are less likely to land in spam. cPanel: Email Accounts → your mailbox → Connect Devices shows the server and port. Also turn on SPF and DKIM in cPanel → Email Deliverability.', 'neurohr-members' ) . '</p>';
		self::toggle( 'smtp_on', __( 'Send emails through SMTP', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		self::text( 'smtp_host', __( 'SMTP server', 'neurohr-members' ), '', 'text', 'mail.neurohr.com.my' );
		self::text( 'smtp_port', __( 'Port', 'neurohr-members' ), __( '465 with SSL (usual for cPanel), or 587 with TLS.', 'neurohr-members' ), 'number', '465' );
		$sec = (string) NHM_Settings::get( 'smtp_secure' );
		echo '<p class="nhm-field"><label for="nhm-s-smtp_secure">' . esc_html__( 'Encryption', 'neurohr-members' ) . '</label><select id="nhm-s-smtp_secure" name="' . esc_attr( self::name( 'smtp_secure' ) ) . '">';
		foreach ( array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => __( 'None', 'neurohr-members' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $sec, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
		self::text( 'smtp_user', __( 'Username (the full email address)', 'neurohr-members' ), '', 'text', 'admin@neurohr.com.my' );
		self::secret_field( 'smtp_pass', __( 'Password of that mailbox', 'neurohr-members' ), __( 'Type it here yourself; never send it to anyone.', 'neurohr-members' ) );
		echo '</div>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nhm_smtp_test' ), 'nhm_smtp_test' ) ) . '">' . esc_html__( 'Send a test email to me', 'neurohr-members' ) . '</a> <span class="description">' . esc_html__( 'Save first.', 'neurohr-members' ) . '</span></p>';
		$err = get_option( NHM_Mailer::ERROR_OPTION );
		if ( is_array( $err ) ) {
			/* translators: 1: time, 2: message */
			echo '<p class="nhm-exp--ended">' . esc_html( sprintf( __( 'Last email that could not be sent (%1$s): %2$s', 'neurohr-members' ), $err['time'], $err['message'] ) ) . '</p>';
		}
		echo '</section>';

		$groups = array(
			'member' => array( __( 'Member Notices', 'neurohr-members' ), 'admin-users' ),
			'admin'  => array( __( 'Admin Notices', 'neurohr-members' ), 'shield' ),
		);
		foreach ( $groups as $group => $g ) {
			echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-' . esc_attr( $g[1] ) . '"></span>' . esc_html( $g[0] ) . '</h2><div class="nhm-emaillist">';
			foreach ( NHM_Emails::registry() as $key => $e ) {
				if ( $e['group'] !== $group ) {
					continue;
				}
				$tpl   = NHM_Emails::template( $key );
				$test  = wp_nonce_url( admin_url( 'admin-post.php?action=nhm_test_email&key=' . $key ), 'nhm_test_email' );
				$reset = wp_nonce_url( admin_url( 'admin-post.php?action=nhm_reset_email&key=' . $key ), 'nhm_reset_email' );
				echo '<div class="nhm-email" id="nhm-email-' . esc_attr( $key ) . '"><div class="nhm-email__row">';
				echo '<label class="nhm-email__on"><input type="hidden" name="' . esc_attr( self::name( 'email_' . $key . '_on' ) ) . '" value="0"><input type="checkbox" name="' . esc_attr( self::name( 'email_' . $key . '_on' ) ) . '" value="1"' . checked( $tpl['on'], true, false ) . '> ' . esc_html__( 'Send', 'neurohr-members' ) . ' <strong>' . esc_html( $e['title'] ) . '</strong></label>';
				echo '<span class="dashicons dashicons-info-outline nhm-email__info" title="' . esc_attr( $e['info'] ) . '"></span>';
				if ( $tpl['customised'] ) {
					echo '<span class="nhm-pill">' . esc_html__( 'edited', 'neurohr-members' ) . '</span>';
				}
				echo '<span class="nhm-email__buttons"><button type="button" class="button" data-nhm-toggle="nhm-email-edit-' . esc_attr( $key ) . '">' . esc_html__( 'Edit', 'neurohr-members' ) . '</button> ';
				echo '<a class="button" href="' . esc_url( $test ) . '">' . esc_html__( 'Send Test', 'neurohr-members' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $reset ) . '" data-nhm-confirm="' . esc_attr__( 'Put the default subject and text back?', 'neurohr-members' ) . '">' . esc_html__( 'Reset to Default', 'neurohr-members' ) . '</a></span></div>';
				echo '<div class="nhm-email__edit" id="nhm-email-edit-' . esc_attr( $key ) . '" hidden>';
				echo '<p class="description">' . esc_html( $e['info'] ) . '</p>';
				echo '<p class="nhm-field"><label>' . esc_html__( 'Subject', 'neurohr-members' ) . '</label><input type="text" name="' . esc_attr( self::name( 'email_' . $key . '_subj' ) ) . '" value="' . esc_attr( $tpl['subj'] ) . '"></p>';
				echo '<p class="nhm-field"><label>' . esc_html__( 'Text', 'neurohr-members' ) . '</label><textarea rows="10" name="' . esc_attr( self::name( 'email_' . $key . '_body' ) ) . '">' . esc_textarea( $tpl['body'] ) . '</textarea></p>';
				echo '</div></div>';
			}
			echo '</div></section>';
		}
		echo '<section class="nhm-admin-card"><h2>' . esc_html__( 'Placeholders', 'neurohr-members' ) . '</h2><table class="nhm-admin-table"><tbody>';
		foreach ( NHM_Emails::placeholders() as $ph => $desc ) {
			echo '<tr><td><code>' . esc_html( $ph ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Save before sending a test. Tests use example values. Invoice emails attach a sample invoice PDF.', 'neurohr-members' ) . '</p></section>';
	}

	private static function tab_business() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-building"></span>' . esc_html__( 'Your company', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Printed at the top of every invoice.', 'neurohr-members' ) . '</p><div class="nhm-fields">';
		self::text( 'biz_name', __( 'Company name', 'neurohr-members' ), '', 'text', 'NeuroHR Sdn. Bhd.' );
		self::text( 'biz_product', __( 'Product name on invoices', 'neurohr-members' ), __( 'Invoice lines read "NeuroHR - Pro Membership (1 year)". Leave empty to use the site name.', 'neurohr-members' ), 'text', 'NeuroHR' );
		self::text( 'biz_reg', __( 'Registration no. (SSM)', 'neurohr-members' ), '', 'text', '202401012345 (1234567-X)' );
		self::text( 'biz_tin', __( 'TIN', 'neurohr-members' ), '', 'text', 'C1234567890' );
		self::text( 'biz_sst_no', __( 'SST registration no.', 'neurohr-members' ), __( 'Shown only on invoices with SST.', 'neurohr-members' ) );
		self::text( 'biz_phone', __( 'Phone', 'neurohr-members' ) );
		self::text( 'biz_email', __( 'Email', 'neurohr-members' ), '', 'email' );
		self::text( 'biz_website', __( 'Website', 'neurohr-members' ), '', 'text', 'www.neurohr.com.my' );
		echo '</div>';
		echo '<p class="nhm-field"><label for="nhm-s-biz_address">' . esc_html__( 'Address', 'neurohr-members' ) . '</label><textarea id="nhm-s-biz_address" rows="3" name="' . esc_attr( self::name( 'biz_address' ) ) . '">' . esc_textarea( NHM_Settings::get( 'biz_address' ) ) . '</textarea></p><div class="nhm-fields">';
		self::text( 'biz_postcode', __( 'Postcode', 'neurohr-members' ) );
		self::text( 'biz_city', __( 'City', 'neurohr-members' ) );
		self::text( 'biz_state', __( 'State', 'neurohr-members' ) );
		self::text( 'biz_country', __( 'Country', 'neurohr-members' ) );
		echo '</div>';
		$logo = (string) NHM_Settings::get( 'biz_logo' );
		echo '<p class="nhm-field"><label for="nhm-s-biz_logo">' . esc_html__( 'Logo (PNG or JPG from the Media Library)', 'neurohr-members' ) . '</label><span class="nhm-logo-row"><input type="text" id="nhm-s-biz_logo" name="' . esc_attr( self::name( 'biz_logo' ) ) . '" value="' . esc_attr( $logo ) . '"> <button type="button" class="button" data-nhm-media="nhm-s-biz_logo">' . esc_html__( 'Choose…', 'neurohr-members' ) . '</button></span>';
		if ( $logo ) {
			echo '<img src="' . esc_url( $logo ) . '" alt="" class="nhm-logo-preview">';
		}
		echo '</p></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-bank"></span>' . esc_html__( 'Payment details', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Printed on unpaid invoices and in the invoice emails.', 'neurohr-members' ) . '</p><div class="nhm-fields">';
		self::text( 'bank_name', __( 'Bank', 'neurohr-members' ), '', 'text', 'Maybank' );
		self::text( 'bank_account_name', __( 'Account name', 'neurohr-members' ) );
		self::text( 'bank_account_no', __( 'Account no.', 'neurohr-members' ) );
		echo '</div><p class="nhm-field"><label for="nhm-s-invoice_terms">' . esc_html__( 'Payment terms', 'neurohr-members' ) . '</label><textarea id="nhm-s-invoice_terms" rows="2" name="' . esc_attr( self::name( 'invoice_terms' ) ) . '">' . esc_textarea( NHM_Settings::get( 'invoice_terms' ) ) . '</textarea></p></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-media-spreadsheet"></span>' . esc_html__( 'Invoices', 'neurohr-members' ) . '</h2><div class="nhm-fields">';
		self::text( 'invoice_format', __( 'Invoice number format', 'neurohr-members' ), __( '{YYYY} year, {YY} short year, {MM} month, {####} running number (restarts every year when the year is in the format).', 'neurohr-members' ) );
		self::text( 'invoice_due_days', __( 'Payment due after (days)', 'neurohr-members' ), '', 'number' );
		self::text( 'currency', __( 'Currency', 'neurohr-members' ) );
		self::text( 'invoice_footer', __( 'Footer line', 'neurohr-members' ) );
		echo '</div>';
		self::next_field( 'invoice', 'invoice_format', 'nhm_invoice_seq' );
		self::toggle( 'renewal_invoice', __( 'Recurring plans: make a renewal invoice automatically', 'neurohr-members' ), __( 'With the first "Plan Ending Soon" email, an unpaid invoice for the next term is created and attached. Marking it paid extends the plan.', 'neurohr-members' ) );
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-undo"></span>' . esc_html__( 'Refunds', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Every refund gets a Refund Note (PDF) with its own running number. The Refund Policy page is chosen in Pages & login.', 'neurohr-members' ) . '</p><div class="nhm-fields">';
		self::text( 'refund_format', __( 'Refund Note number format', 'neurohr-members' ), __( 'Same codes as invoices, e.g. RN-{YYYY}-{####} or CN-{YY}{MM}-{###}. With the year in it, the number restarts every year.', 'neurohr-members' ) );
		self::text( 'refund_hours', __( 'Members can ask for a refund within (hours after paying)', 'neurohr-members' ), __( '0 = no refund button; members contact you.', 'neurohr-members' ), 'number' );
		self::text( 'refund_days', __( 'Complete approved refunds within (working days)', 'neurohr-members' ), '', 'number' );
		echo '</div>';
		self::next_field( 'refund', 'refund_format', 'nhm_refund_seq' );
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-calculator"></span>SST</h2>';
		self::toggle( 'sst_enabled', __( 'Charge SST on new invoices', 'neurohr-members' ), __( 'Only if your company is registered for SST. The invoice is then titled "Tax Invoice" and shows your SST no. Invoices already made keep their own rate.', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		self::text( 'sst_rate', __( 'SST rate (%)', 'neurohr-members' ), '', 'number' );
		echo '</div>';
		self::toggle( 'sst_inclusive', __( 'Level prices already include SST', 'neurohr-members' ), __( 'On: a RM 299 plan costs RM 299 and the invoice shows the SST part inside it. Off: SST is added on top (RM 299 + 8%).', 'neurohr-members' ) );
		$prev = wp_nonce_url( admin_url( 'admin-post.php?action=nhm_invoice_preview' ), 'nhm_invoice_preview' );
		echo '<p><a class="button" target="_blank" href="' . esc_url( $prev ) . '">' . esc_html__( 'Preview a sample invoice (PDF)', 'neurohr-members' ) . '</a> <a class="button" target="_blank" href="' . esc_url( add_query_arg( 'paid', 1, $prev ) ) . '">' . esc_html__( 'Preview as paid', 'neurohr-members' ) . '</a> <span class="description">' . esc_html__( 'Save first.', 'neurohr-members' ) . '</span></p>';
		echo '<p class="description">' . esc_html__( 'e-Invoice (MyInvois): from 1 September 2026 businesses with yearly turnover below RM3 million are generally exempt. These PDF invoices are normal invoices, not MyInvois e-invoices. Check your situation with your accountant.', 'neurohr-members' ) . '</p></section>';
	}

	private static function tab_protection() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-lock"></span>' . esc_html__( 'Members-only pages', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'These types get a "Who can see this page" box in the editor (e.g. set /dashboard/ to logged-in members). Knowledge Hub resources have their own levels and are not listed here.', 'neurohr-members' ) . '</p>';
		$chosen = (array) NHM_Settings::get( 'protect_types' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'nh_resource', 'elementor_library', 'e-landing-page' ), true ) ) {
				continue;
			}
			echo '<label class="nhm-toggle"><input type="checkbox" name="' . esc_attr( self::name( 'protect_types' ) ) . '[]" value="' . esc_attr( $type->name ) . '" ' . checked( in_array( $type->name, $chosen, true ), true, false ) . '> <span>' . esc_html( $type->labels->name ) . '</span></label>';
		}
		echo '<h3>' . esc_html__( 'Members below the level', 'neurohr-members' ) . '</h3>';
		$mode = NHM_Settings::get( 'protect_mode' );
		echo '<label class="nhm-toggle"><input type="radio" name="' . esc_attr( self::name( 'protect_mode' ) ) . '" value="message" ' . checked( $mode, 'message', false ) . '> <span><strong>' . esc_html__( 'Show an upgrade box instead of the content', 'neurohr-members' ) . '</strong></span></label>';
		echo '<label class="nhm-toggle"><input type="radio" name="' . esc_attr( self::name( 'protect_mode' ) ) . '" value="redirect" ' . checked( $mode, 'redirect', false ) . '> <span><strong>' . esc_html__( 'Send them to the level\'s upgrade link / pricing page', 'neurohr-members' ) . '</strong></span></label>';
		echo '<p class="description">' . esc_html__( 'Guests always go to the login page and come back afterwards.', 'neurohr-members' ) . '</p></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-art"></span>' . esc_html__( 'Look', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-field"><label for="nhm-s-brand">' . esc_html__( 'Brand colour', 'neurohr-members' ) . '</label><span class="nhm-color-row"><input type="color" id="nhm-s-brand" name="' . esc_attr( self::name( 'brand' ) ) . '" value="' . esc_attr( NHM_Settings::brand() ) . '"> <code>' . esc_html( NHM_Settings::brand() ) . '</code></span><span class="description">' . esc_html__( 'Buttons and links in the forms. Overrides Blocksy\'s colours inside the forms.', 'neurohr-members' ) . '</span></p>';
		self::toggle( 'hide_authors', __( 'Hide author pages', 'neurohr-members' ), __( '/author/… and ?author=1 give "page not found", and the user list in the REST API is closed to visitors. Stops people finding login names.', 'neurohr-members' ) );
		self::toggle( 'disable_xmlrpc', __( 'Turn off XML-RPC', 'neurohr-members' ), __( 'An old remote-publishing door that bots use to guess passwords. Only needed for the old WordPress mobile app or Jetpack.', 'neurohr-members' ) );
		self::toggle( 'replace_core_mail', __( 'Send WordPress\'s own account emails with my templates', 'neurohr-members' ), __( 'Password reset, password changed, email changed and new account (Users → Add New) use the texts in the Emails tab and your From address.', 'neurohr-members' ) );
		self::toggle( 'load_css', __( 'Use the built-in form style', 'neurohr-members' ), __( 'Turn off to style everything yourself. Every part has its own class (.nhm, .nhm-form, .nhm-field, .nhm-btn, .nhm-card, .nhm-price-card …).', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		self::text( 'invite_days', __( 'Invitation links are valid for (days)', 'neurohr-members' ), '', 'number' );
		echo '</div></section>';
	}

	private static function tab_area() {
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-layout"></span>' . esc_html__( 'Member area layout', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Logged-in people see every page inside the member layout: a sidebar on the left, a top bar with search and their name, your page in the middle. Visitors who are not logged in keep seeing your Blocksy site. Pages built with "Elementor Canvas", and pages where you tick "Don\'t use the member layout" in the editor, keep their own look.', 'neurohr-members' ) . '</p>';
		self::toggle( 'shell_enabled', __( 'Use the member layout for logged-in people', 'neurohr-members' ) );
		self::toggle( 'shell_theme_css', __( 'Keep the Blocksy styles inside the member layout', 'neurohr-members' ), __( 'Off (recommended): only the member-area style and Elementor\'s own styles load, so Blocksy\'s header and colours don\'t mix in. Turn on if text on normal pages looks too plain.', 'neurohr-members' ) );
		$locs = get_theme_mod( 'nav_menu_locations' );
		$set  = is_array( $locs ) && ( ! empty( $locs['nhm_sidebar'] ) || ! empty( $locs['nhm_sidebar_account'] ) );
		echo '<h3>' . esc_html__( 'Sidebar menu', 'neurohr-members' ) . '</h3>';
		if ( ! $set ) {
			$names = array();
			foreach ( NHM_Shell::suggested_links()['nhm_sidebar'] as $l ) {
				$names[] = $l[0];
			}
			echo '<p>' . esc_html__( 'No menu is set yet, so the sidebar shows these links:', 'neurohr-members' ) . ' <strong>' . esc_html( implode( ' · ', $names ) ) . '</strong>.</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nhm_create_menus' ), 'nhm_create_menus' ) ) . '">' . esc_html__( 'Create the member menus', 'neurohr-members' ) . '</a> ';
			echo '<span class="description">' . esc_html__( 'Makes two normal WordPress menus with these links and icons. Then add, remove, rename or reorder items (e.g. more tools) in Appearance → Menus.', 'neurohr-members' ) . '</span></p>';
		}
		echo '<p class="description">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Menus: <a href="%s">Appearance → Menus</a>, locations "Member area: main menu", "Member area: account menu" and "Member area: footer links". Each menu item has an icon and a "Who sees this" setting.', 'neurohr-members' ), esc_url( admin_url( 'nav-menus.php' ) ) ) ) . '</p>';
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-format-image"></span>' . esc_html__( 'Logo and search', 'neurohr-members' ) . '</h2><div class="nhm-fields">';
		echo '<p class="nhm-field"><label for="nhm-s-shell_logo">' . esc_html__( 'Logo image (optional)', 'neurohr-members' ) . '</label><span class="nhm-color-row"><input type="text" id="nhm-s-shell_logo" name="' . esc_attr( self::name( 'shell_logo' ) ) . '" value="' . esc_attr( NHM_Settings::get( 'shell_logo' ) ) . '" placeholder="https://…/logo.svg"> <button type="button" class="button" data-nhm-media="nhm-s-shell_logo">' . esc_html__( 'Choose', 'neurohr-members' ) . '</button></span><span class="description">' . esc_html__( 'Empty = the text logo below. About 160 × 40 px.', 'neurohr-members' ) . '</span></p>';
		self::text( 'shell_logo_text', __( 'Text logo', 'neurohr-members' ), '', 'text', 'NeuroHR' );
		self::text( 'shell_logo_accent', __( 'Part of the text logo in orange', 'neurohr-members' ), __( 'e.g. HR (must be the end of the text logo).', 'neurohr-members' ), 'text', 'HR' );
		self::text( 'shell_search', __( 'Search box text', 'neurohr-members' ), __( 'With the Knowledge Hub active, the box searches the hub; otherwise the whole site.', 'neurohr-members' ), 'text', __( 'Search knowledge, legal cases, templates…', 'neurohr-members' ) );
		echo '</div></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-megaphone"></span>' . esc_html__( 'Card at the bottom of the sidebar', 'neurohr-members' ) . '</h2>';
		self::toggle( 'shell_promo_on', __( 'Show the card', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		self::text( 'shell_promo_title', __( 'Title', 'neurohr-members' ) );
		self::text( 'shell_promo_text', __( 'Text', 'neurohr-members' ) );
		self::text( 'shell_promo_url', __( 'Arrow link', 'neurohr-members' ), __( 'e.g. /services/. Empty = no arrow.', 'neurohr-members' ) );
		echo '</div></section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-editor-insertmore"></span>' . esc_html__( 'Footer', 'neurohr-members' ) . '</h2><div class="nhm-fields">';
		self::text( 'shell_footer_text', __( 'Footer text', 'neurohr-members' ), __( '{year} and {site} are filled in.', 'neurohr-members' ) );
		echo '</div></section>';
	}

	private static function tab_setup() {
		$checks = array();
		foreach ( self::page_keys() as $key => $info ) {
			$checks[] = array( (bool) NHM_Settings::page_url( $key ), $info[0], $info[1] );
		}
		$last     = get_option( 'nhm_cron_last_run' );
		$next     = wp_next_scheduled( NHM_Cron::HOOK );
		$hub      = defined( 'NH_HUB_VERSION' ) ? NH_HUB_VERSION : '';
		$payroll  = defined( 'NHP_VERSION' ) ? NHP_VERSION : '';
		$checks[] = array( (bool) $next, __( 'Daily check scheduled', 'neurohr-members' ), $last ? sprintf( /* translators: %s: date */ __( 'last run %s', 'neurohr-members' ), mysql2date( 'j M Y H:i', $last ) ) : __( 'not run yet', 'neurohr-members' ) );
		$checks[] = array( ! $hub || version_compare( $hub, '0.8.1', '>=' ), __( 'Knowledge Hub uses the levels and the member layout', 'neurohr-members' ), $hub ? 'v' . $hub . ( version_compare( $hub, '0.8.1', '<' ) ? ' — ' . __( 'update to 0.8.1', 'neurohr-members' ) : '' ) : __( 'not active', 'neurohr-members' ) );
		$checks[] = array( ! NHM_Settings::get( 'hide_wp_login' ) || (bool) NHM_Settings::page_url( 'login' ), __( 'WordPress login hidden', 'neurohr-members' ), NHM_Settings::page_url( 'login' ) ? ( NHM_Settings::get( 'hide_wp_login' ) ? __( 'wp-login.php and /wp-admin give a 404 to visitors', 'neurohr-members' ) : __( 'off', 'neurohr-members' ) ) : __( 'set the login page first — until then WordPress\'s login keeps working', 'neurohr-members' ) );
		$checks[] = array( '' === trim( (string) NHM_Settings::get( 'home_redirect' ) ) || (bool) url_to_postid( NHM_Settings::url( 'home_redirect' ) ), __( 'Member home page exists', 'neurohr-members' ), (string) NHM_Settings::get( 'home_redirect' ) );
		$checks[] = array( ! $payroll || version_compare( $payroll, '0.5.0', '>=' ), __( 'Simple Payroll reads levels from here', 'neurohr-members' ), $payroll ? 'v' . $payroll . ( version_compare( $payroll, '0.5.0', '<' ) ? ' — ' . __( 'update to 0.5.0', 'neurohr-members' ) : '' ) : __( 'not active', 'neurohr-members' ) );
		$checks[] = array( NHM_Settings::get( 'biz_name' ) && NHM_Settings::get( 'biz_address' ), __( 'Business info for invoices', 'neurohr-members' ), NHM_Settings::get( 'bank_account_no' ) ? '' : __( 'add your bank details so customers know how to pay', 'neurohr-members' ) );
		$checks[] = array( count( array_filter( NHM_Levels::all(), function ( $l ) { return (float) $l['amount'] > 0; } ) ) > 0, __( 'Prices set on paid levels', 'neurohr-members' ), __( 'Members → Levels → Price and term', 'neurohr-members' ) );
		$checks[] = array( ! class_exists( 'MeprUser' ), __( 'MemberPress deactivated', 'neurohr-members' ), class_exists( 'MeprUser' ) ? __( 'still active — deactivate when the pages above work', 'neurohr-members' ) : '' );

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'Checklist', 'neurohr-members' ) . '</h2><ul class="nhm-checklist">';
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'is-ok' : 'is-todo' ) . '"><span class="dashicons dashicons-' . ( $c[0] ? 'yes' : 'marker' ) . '"></span><strong>' . esc_html( $c[1] ) . '</strong> <span class="nhm-muted">' . esc_html( $c[2] ) . '</span></li>';
		}
		echo '</ul>';
		$run = wp_nonce_url( admin_url( 'admin-post.php?action=nhm_run_cron' ), 'nhm_run_cron' );
		echo '<p><a class="button" href="' . esc_url( $run ) . '">' . esc_html__( 'Run the daily check now', 'neurohr-members' ) . '</a> <span class="description">' . esc_html__( 'Sends any reminders that are due. Safe to run more than once.', 'neurohr-members' ) . '</span></p>';
		echo '<p class="description">' . wp_kses_post( __( 'WordPress runs the daily check when someone visits the site. For an exact time, add a cron job in cPanel (Yeahhost): <code>wget -q -O - https://www.neurohr.com.my/wp-cron.php?doing_wp_cron >/dev/null 2>&1</code> every 15 minutes.', 'neurohr-members' ) ) . '</p>';
		echo '</section>';

		$codes = array(
			'[nhm_login]'                                   => __( 'Login form. Option: redirect="/dashboard/"', 'neurohr-members' ),
			'[nhm_register]'                                => __( 'Registration form (also accepts company invitations).', 'neurohr-members' ),
			'[nhm_register level="pro"]'                    => __( 'Registration for one plan. Paid plans: account first, invoice emailed, plan starts when paid. Logged-in members see an upgrade / renew button instead.', 'neurohr-members' ),
			'[nhm_register_url level="pro"]'                => __( 'Only the link (/register/?plan=pro) — for the link field of an Elementor button.', 'neurohr-members' ),
			'[nhm_lost_password]'                           => __( 'Lost password and "choose a new password" form.', 'neurohr-members' ),
			'[nhm_account]'                                 => __( 'My account. Option: sections="plan,company,invoices,profile,billing,password"', 'neurohr-members' ),
			'[nhm_pricing]'                                 => __( 'Pricing cards from the Levels screen. Options: levels="lite,pro,corporate" columns="3"', 'neurohr-members' ),
			'[nhm_if level="pro"]…[nhm_else]…[/nhm_if]'     => __( 'Content for Pro and above, something else for everyone else. Also: levels="lite,pro" (exactly), guest="yes", logged_in="yes", not="yes".', 'neurohr-members' ),
			'[nhm_user field="first_name"]'                 => __( 'name, first_name, email, level, expiry, days_left, status, company', 'neurohr-members' ),
			'[nhm_logout text="Log out"]'                   => __( 'Logout link. Option: redirect="/"', 'neurohr-members' ),
		);
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-shortcode"></span>' . esc_html__( 'Shortcodes', 'neurohr-members' ) . '</h2><table class="nhm-admin-table nhm-admin-table--wide"><tbody>';
		foreach ( $codes as $code => $desc ) {
			echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function tab_changelog() {
		$log = include NHM_DIR . 'includes/changelog.php';
		echo '<section class="nhm-admin-card nhm-changelog">';
		foreach ( (array) $log as $version => $entry ) {
			echo '<h3>' . esc_html( $version ) . ' <span>' . esc_html( $entry['date'] ) . '</span></h3><ul>';
			foreach ( $entry['items'] as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/* ---------- Actions ---------- */

	public static function smtp_test() {
		NHM_Admin::check( 'nhm_smtp_test' );
		$to = wp_get_current_user()->user_email;
		$r  = NHM_Mailer::test( $to );
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error */
			NHM_Admin::flash( sprintf( __( 'The test email could not be sent: %s', 'neurohr-members' ), $r->get_error_message() ), 'error' );
		} else {
			/* translators: %s: email */
			NHM_Admin::flash( sprintf( __( 'Test email sent to %s. Check the inbox (and the spam folder).', 'neurohr-members' ), $to ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=nhm-settings&tab=emails' ) );
		exit;
	}

	public static function stripe_check() {
		NHM_Admin::check( 'nhm_stripe_check' );
		$r = NHM_Stripe::check();
		if ( is_wp_error( $r ) ) {
			NHM_Admin::flash( $r->get_error_message(), 'error' );
		} else {
			/* translators: %s: test or live */
			NHM_Admin::flash( sprintf( __( 'Stripe answered: the %s secret key works.', 'neurohr-members' ), NHM_Stripe::mode() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=nhm-settings&tab=payments' ) );
		exit;
	}

	private static function secret_field( $key, $label, $placeholder ) {
		$has = '' !== trim( (string) NHM_Settings::get( $key ) );
		echo '<p class="nhm-field"><label for="nhm-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="password" id="nhm-s-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="" autocomplete="off" placeholder="' . esc_attr( $has ? __( 'Saved — paste a new one to replace', 'neurohr-members' ) : $placeholder ) . '">';
		if ( $has ) {
			echo '<label class="nhm-inline-check"><input type="checkbox" name="' . esc_attr( self::name( $key . '_clear' ) ) . '" value="1"> ' . esc_html__( 'Remove', 'neurohr-members' ) . '</label>';
		}
		echo '</p>';
	}

	private static function tab_payments() {
		$bank_ok = '' !== trim( (string) NHM_Settings::get( 'bank_account_no' ) );
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-money-alt"></span>' . esc_html__( 'Ways to pay', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Members see these on the payment page of an invoice (link in every invoice email, "Pay now" in My account, and right after choosing a paid plan). Bank transfer and DuitNow QR: the member sends the slip, you approve it under Members → Payments, then the plan starts.', 'neurohr-members' ) . '</p>';
		self::toggle( 'pay_stripe', __( 'Pay online with Stripe (card, FPX, GrabPay)', 'neurohr-members' ), __( 'Needs the Stripe keys below. Which methods show is set in your Stripe Dashboard → Settings → Payment methods.', 'neurohr-members' ) );
		self::toggle( 'pay_bank', __( 'Bank transfer', 'neurohr-members' ), $bank_ok ? __( 'Uses the bank details in Business info & invoices.', 'neurohr-members' ) : '<span class="nhm-exp--soon">' . __( 'Add your bank details in Business info & invoices first.', 'neurohr-members' ) . '</span>' );
		self::toggle( 'pay_qr', __( 'DuitNow QR', 'neurohr-members' ), __( 'Upload your DuitNow QR picture below. Members type the amount themselves.', 'neurohr-members' ) );
		echo '<div class="nhm-fields">';
		echo '<p class="nhm-field"><label for="nhm-s-qr_image">' . esc_html__( 'DuitNow QR picture', 'neurohr-members' ) . '</label><span class="nhm-color-row"><input type="text" id="nhm-s-qr_image" name="' . esc_attr( self::name( 'qr_image' ) ) . '" value="' . esc_attr( NHM_Settings::get( 'qr_image' ) ) . '" placeholder="https://…/duitnow-qr.png"> <button type="button" class="button" data-nhm-media="nhm-s-qr_image">' . esc_html__( 'Choose', 'neurohr-members' ) . '</button></span><span class="description">' . esc_html__( 'The QR from your bank (PNG or JPG, square).', 'neurohr-members' ) . '</span></p>';
		self::text( 'qr_name', __( 'Name under the QR', 'neurohr-members' ), __( 'e.g. your company name, so members know they scanned the right code.', 'neurohr-members' ), 'text', 'NeuroHR Sdn. Bhd.' );
		echo '</div>';
		echo '<p class="nhm-field"><label for="nhm-s-page_pay">' . esc_html__( 'Payment page (optional)', 'neurohr-members' ) . '</label>';
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::name( 'page_pay' ) ),
				'id'                => 'nhm-s-page_pay',
				'selected'          => (int) NHM_Settings::get( 'page_pay' ), // phpcs:ignore WordPress.Security.EscapeOutput
				'show_option_none'  => esc_html__( '— My account page —', 'neurohr-members' ),
				'option_none_value' => '0',
			)
		);
		echo ' <code>[nhm_pay]</code><span class="description">' . esc_html__( 'Empty = the payment page opens on your My account page. Make your own page with [nhm_pay] if you want a different design around it.', 'neurohr-members' ) . '</span></p>';
		echo '</section>';

		$mode = NHM_Stripe::mode();
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-admin-network"></span>' . esc_html__( 'Stripe', 'neurohr-members' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Stripe Dashboard → Developers → API keys. Start in Test mode: pay with the test card 4242 4242 4242 4242 (any future date, any CVC) and check that the invoice becomes Paid. Then switch to Live.', 'neurohr-members' ) . '</p>';
		echo '<p class="nhm-field"><label>' . esc_html__( 'Mode', 'neurohr-members' ) . '</label>';
		foreach ( array( 'test' => __( 'Test (no real money)', 'neurohr-members' ), 'live' => __( 'Live', 'neurohr-members' ) ) as $k => $l ) {
			echo '<label class="nhm-inline-check" style="margin-right:16px"><input type="radio" name="' . esc_attr( self::name( 'stripe_mode' ) ) . '" value="' . esc_attr( $k ) . '"' . checked( $mode, $k, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '</p><div class="nhm-fields">';
		self::text( 'stripe_test_pk', __( 'Test publishable key', 'neurohr-members' ), '', 'text', 'pk_test_…' );
		self::secret_field( 'stripe_test_sk', __( 'Test secret key', 'neurohr-members' ), 'sk_test_…' );
		self::secret_field( 'stripe_test_whsec', __( 'Test webhook signing secret', 'neurohr-members' ), 'whsec_…' );
		self::text( 'stripe_live_pk', __( 'Live publishable key', 'neurohr-members' ), '', 'text', 'pk_live_…' );
		self::secret_field( 'stripe_live_sk', __( 'Live secret key', 'neurohr-members' ), 'sk_live_…' );
		self::secret_field( 'stripe_live_whsec', __( 'Live webhook signing secret', 'neurohr-members' ), 'whsec_…' );
		echo '</div>';
		echo '<h3>' . esc_html__( 'Webhook (so Stripe tells the site about payments)', 'neurohr-members' ) . '</h3><ol class="nhm-steps">';
		echo '<li>' . esc_html__( 'Stripe Dashboard → Developers → Webhooks → Add endpoint (do it once in Test mode and once in Live mode).', 'neurohr-members' ) . '</li>';
		echo '<li>' . esc_html__( 'Endpoint URL:', 'neurohr-members' ) . ' <input type="text" readonly class="regular-text" value="' . esc_attr( NHM_Stripe::webhook_url() ) . '" onclick="this.select()"></li>';
		echo '<li>' . esc_html__( 'Events:', 'neurohr-members' ) . ' <code>checkout.session.completed</code> <code>checkout.session.async_payment_succeeded</code> <code>checkout.session.async_payment_failed</code> <code>checkout.session.expired</code> <code>payment_intent.succeeded</code> <code>payment_intent.payment_failed</code> <code>charge.refunded</code> <code>charge.dispute.created</code></li>';
		echo '<li>' . esc_html__( 'Copy its "Signing secret" (whsec_…) into the box above for that mode and save.', 'neurohr-members' ) . '</li></ol>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nhm_stripe_check' ), 'nhm_stripe_check' ) ) . '">' . esc_html__( 'Check the secret key', 'neurohr-members' ) . '</a> <span class="description">' . esc_html__( 'Save first. Never send your secret keys to anyone.', 'neurohr-members' ) . '</span></p>';
		echo '</section>';
	}

	public static function create_menus() {
		NHM_Admin::check( 'nhm_create_menus' );
		$made = NHM_Shell::create_menus();
		if ( $made ) {
			NHM_Admin::flash( sprintf( /* translators: %s: link */ __( 'Member menus created. Add your other tools or links in <a href="%s">Appearance → Menus</a>.', 'neurohr-members' ), esc_url( admin_url( 'nav-menus.php' ) ) ) );
		} else {
			NHM_Admin::flash( __( 'The member-area menu locations already have menus.', 'neurohr-members' ), 'warning' );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'nhm-settings', 'tab' => 'area' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function create_page() {
		NHM_Admin::check( 'nhm_create_page' );
		$key  = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		$keys = self::page_keys();
		if ( isset( $keys[ $key ] ) ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $keys[ $key ][2],
					'post_content' => '<!-- wp:shortcode -->' . $keys[ $key ][1] . '<!-- /wp:shortcode -->',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				NHM_Settings::update( array( 'page_' . $key => (int) $id ) );
				/* translators: %s: page link */
				NHM_Admin::flash( sprintf( __( 'Page created: %s. Edit its look in Elementor or Blocksy whenever you like.', 'neurohr-members' ), '<a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank">' . esc_html( get_permalink( $id ) ) . '</a>' ) );
			}
		}
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-settings&tab=pages' ) );
	}

	public static function test_email() {
		NHM_Admin::check( 'nhm_test_email' );
		$key  = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		$back = admin_url( 'admin.php?page=nhm-settings&tab=emails#nhm-email-' . $key );
		$reg  = NHM_Emails::registry();
		if ( ! isset( $reg[ $key ] ) ) {
			NHM_Admin::back( $back );
		}
		$user = wp_get_current_user();
		$top  = NHM_Levels::top();
		$vars = array(
			'{name}'              => $user->display_name,
			'{email}'             => $user->user_email,
			'{level}'             => $top['name'],
			'{expiry}'            => NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+7 days' ) ) ),
			'{days}'              => '7',
			'{company}'           => 'Example Sdn Bhd',
			'{for_company}'       => ' for Example Sdn Bhd',
			'{inviter}'           => $user->display_name,
			'{invite_url}'        => NHM_Companies::invite_url( 'example-token-for-testing-only' ),
			'{password_url}'      => NHM_Settings::page_url( 'lost' ) ? NHM_Settings::page_url( 'lost' ) : home_url( '/' ),
			'{invoice_number}'    => 'INV-TEST-0001',
			'{invoice_total}'     => NHM_Settings::money( 299 ),
			'{invoice_due}'       => NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+14 days' ) ) ),
			'{invoice_admin_url}' => admin_url( 'admin.php?page=nhm-invoices' ),
			'{amount}'            => NHM_Settings::money( 199 ),
			'{card}'              => 'Visa •••• 4242 (04/28)',
			'{renew_date}'        => NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+7 days' ) ) ),
			'{retry_date}'        => NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+3 days' ) ) ),
			'{card_url}'          => NHM_Renew::account_url() . '#nhm-autorenew',
			'{error}'             => 'Your card was declined.',
			'{reason}'            => 'Not what I expected: example',
			'{reject_reason}'     => 'The templates were downloaded.',
			'{refund_number}'     => 'RN-TEST-0001',
			'{refund_date}'       => NHM_Emails::format_date( NHM_Settings::today() ),
			'{complete_by}'       => NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+14 days' ) ) ),
			'{refunds_url}'       => admin_url( 'admin.php?page=nhm-payments&status=refunds' ),
			'{stripe_url}'        => 'https://dashboard.stripe.com/',
			'{plan_line}'         => sprintf( 'Your %s plan is active until %s.', $top['name'], NHM_Emails::format_date( wp_date( 'Y-m-d', strtotime( '+1 year' ) ) ) ),
			'{renewal_text}'      => 'To renew, reply to this email or visit ' . NHM_Settings::url( 'renew_url', NHM_Levels::upgrade_url() ) . '.',
			'{fields}'            => 'Company: Example Sdn Bhd',
			'{seats}'             => '3 / 5',
			'{admin_url}'         => admin_url( 'admin.php?page=nhm-members' ),
			'{company_admin_url}' => admin_url( 'admin.php?page=nhm-companies' ),
			'{until}'             => '',
		);
		$att = array();
		if ( in_array( $key, array( 'application', 'invoice', 'payment' ), true ) ) {
			$sample = self::sample_invoice( 'payment' === $key );
			$file   = NHM_PDF::invoice_file( $sample );
			$att    = is_wp_error( $file ) ? array() : array( $file );
		}
		$tpl = NHM_Emails::template( $key );
		if ( ! $tpl['on'] ) {
			NHM_Admin::flash( __( 'This email is switched off, so no test was sent.', 'neurohr-members' ), 'warning' );
		} elseif ( NHM_Emails::send( $key, $user->user_email, $vars, $att ) ) {
			$to = 'admin' === $reg[ $key ]['group'] ? implode( ', ', NHM_Emails::admin_addresses() ) : $user->user_email;
			/* translators: %s: email */
			NHM_Admin::flash( sprintf( __( 'Test sent to %s.', 'neurohr-members' ), esc_html( $to ) ) );
		} else {
			NHM_Admin::flash( __( 'WordPress could not send the email. Check the site\'s mail setup (SMTP plugin or host).', 'neurohr-members' ), 'error' );
		}
		NHM_PDF::cleanup( $att ? $att[0] : '' );
		NHM_Admin::back( $back );
	}

	/** An invoice that is never saved, for previews and test emails. */
	public static function sample_invoice( $paid = false ) {
		$top = NHM_Levels::top();
		$amt = (float) $top['amount'] > 0 ? (float) $top['amount'] : 299;
		$t   = NHM_Invoices::totals( array( array( 'description' => NHM_Invoices::plan_line( $top['id'], NHM_Settings::today(), NHM_Levels::term_end( $top['id'], NHM_Settings::today() ) ), 'qty' => 1, 'price' => $amt ) ), 0, NHM_Invoices::default_tax_rate() );
		return array_merge(
			$t,
			array(
				'id'             => 0,
				'number'         => 'INV-TEST-0001',
				'status'         => $paid ? 'paid' : 'unpaid',
				'issue_date'     => NHM_Settings::today(),
				'due_date'       => NHM_Invoices::due_date(),
				'paid_date'      => $paid ? NHM_Settings::today() : '',
				'payment_method' => $paid ? 'Bank transfer' : '',
				'payment_ref'    => $paid ? 'MBB123456' : '',
				'currency'       => NHM_Settings::get( 'currency' ),
				'bill_to'        => array( 'name' => 'Example Sdn Bhd', 'attention' => 'Siti Aminah', 'reg_no' => '201901000001 (1300000-A)', 'tin' => 'C2584563200', 'address' => array( 'Level 8, Menara Example', 'Jalan Ampang', '50450 Kuala Lumpur' ), 'email' => 'hr@example.com.my', 'code' => 'NHR-C0001' ),
				'notes'          => '',
				'user_id'        => 0,
				'company_id'     => 0,
				'level_id'       => 0,
				'granted'        => 0,
				'grant_to'       => '',
				'token'          => '',
			)
		);
	}

	public static function reset_email() {
		NHM_Admin::check( 'nhm_reset_email' );
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		NHM_Emails::reset( $key );
		NHM_Admin::flash( __( 'Default text restored.', 'neurohr-members' ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-settings&tab=emails#nhm-email-' . $key ) );
	}

	public static function run_cron() {
		NHM_Admin::check( 'nhm_run_cron' );
		NHM_Cron::run();
		NHM_Admin::flash( __( 'Daily check done. See History for anything that was sent.', 'neurohr-members' ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-settings&tab=setup' ) );
	}
}
