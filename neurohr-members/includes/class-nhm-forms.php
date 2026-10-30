<?php
/**
 * Shared plumbing for the front-end forms: one POST dispatcher (forms post back to the
 * page they are on), error and success messages, anti-spam, field markup, assets, and
 * keeping form pages out of the page cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Forms {

	private static $errors = array();
	private static $values = array();
	private static $notice = '';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_pages' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/** Action name => handler. */
	private static function handlers() {
		return apply_filters(
			'nhm_form_handlers',
			array(
				'login'         => array( 'NHM_Auth', 'handle_login' ),
				'register'      => array( 'NHM_Auth', 'handle_register' ),
				'lost'          => array( 'NHM_Auth', 'handle_lost' ),
				'reset'         => array( 'NHM_Auth', 'handle_reset' ),
				'profile'       => array( 'NHM_Account', 'handle_profile' ),
				'password'      => array( 'NHM_Account', 'handle_password' ),
				'invite'        => array( 'NHM_Account', 'handle_invite' ),
				'resend'        => array( 'NHM_Account', 'handle_resend' ),
				'cancel_invite' => array( 'NHM_Account', 'handle_cancel_invite' ),
				'remove_member' => array( 'NHM_Account', 'handle_remove_member' ),
				'leave'         => array( 'NHM_Account', 'handle_leave' ),
				'accept_invite' => array( 'NHM_Account', 'handle_accept_invite' ),
				'apply'         => array( 'NHM_Auth', 'handle_apply' ),
				'billing'       => array( 'NHM_Account', 'handle_billing' ),
			)
		);
	}

	public static function dispatch() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['nhm_action'] ) ) { // phpcs:ignore WordPress.Security
			return;
		}
		$action   = sanitize_key( wp_unslash( $_POST['nhm_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified per handler below.
		$handlers = self::handlers();
		if ( ! isset( $handlers[ $action ] ) || ! is_callable( $handlers[ $action ] ) ) {
			return;
		}
		self::$values[ $action ] = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_POST['_nhm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nhm_nonce'] ) ), 'nhm_' . $action ) ) {
			self::error( $action, __( 'The form expired. Please try again.', 'neurohr-members' ) );
			return;
		}
		call_user_func( $handlers[ $action ] );
	}

	/* ---------- Messages ---------- */

	public static function error( $form, $message ) {
		self::$errors[ $form ][] = $message;
	}

	public static function has_errors( $form ) {
		return ! empty( self::$errors[ $form ] );
	}

	public static function value( $form, $key, $default = '' ) {
		return isset( self::$values[ $form ][ $key ] ) && is_scalar( self::$values[ $form ][ $key ] ) ? (string) self::$values[ $form ][ $key ] : $default;
	}

	/** Posted value of any shape (arrays for several-choice fields). */
	public static function raw( $form, $key, $default = '' ) {
		return isset( self::$values[ $form ][ $key ] ) ? self::$values[ $form ][ $key ] : $default;
	}

	public static function set_notice( $text ) {
		self::$notice = $text;
	}

	/** Redirect after a successful POST (so a reload does not send the form again). */
	public static function done( $url, $message_code = '' ) {
		if ( $message_code ) {
			$url = add_query_arg( 'nhm_msg', $message_code, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function messages() {
		return array(
			'registered'       => __( 'Welcome! Your account is ready.', 'neurohr-members' ),
			'reset_sent'       => __( 'If an account exists for that email address, we have sent a link to set a new password. Check your inbox (and spam folder).', 'neurohr-members' ),
			'password_reset'   => __( 'Your new password is saved. You can log in now.', 'neurohr-members' ),
			'profile_saved'    => __( 'Your details are saved.', 'neurohr-members' ),
			'password_changed' => __( 'Your password is changed.', 'neurohr-members' ),
			'invited'          => __( 'Invitation sent.', 'neurohr-members' ),
			'resent'           => __( 'Invitation sent again.', 'neurohr-members' ),
			'invite_cancelled' => __( 'Invitation cancelled.', 'neurohr-members' ),
			'member_removed'   => __( 'Removed from the company.', 'neurohr-members' ),
			'left'             => __( 'You have left the company.', 'neurohr-members' ),
			'joined'           => __( 'You have joined the company.', 'neurohr-members' ),
			'applied'          => __( 'Thank you! We have emailed you the invoice. Your plan starts as soon as the payment is received.', 'neurohr-members' ),
			'plan_started'     => __( 'Your new plan is active.', 'neurohr-members' ),
			'billing_saved'    => __( 'Your billing details are saved.', 'neurohr-members' ),
			'verify_sent'      => __( 'Almost done! We have sent you an email. Click the link in it to confirm your address and activate your account.', 'neurohr-members' ),
			'verified'         => __( 'Your email address is confirmed. Welcome!', 'neurohr-members' ),
			'verify_bad'       => __( 'This confirmation link is not valid any more. Log in to get a new one.', 'neurohr-members' ),
			'logout_confirm'   => __( 'Do you want to log out?', 'neurohr-members' ),
			'photo_saved'      => __( 'Your photo is updated.', 'neurohr-members' ),
			'photo_removed'    => __( 'Your photo is removed.', 'neurohr-members' ),
			'email2_sent'      => __( 'We have sent a confirmation link to your second email address.', 'neurohr-members' ),
			'email2_ok'        => __( 'Your second email address is confirmed.', 'neurohr-members' ),
			'notifications_saved' => __( 'Your notification settings are saved.', 'neurohr-members' ),
			'sessions_ended'   => __( 'You are logged out on all other devices.', 'neurohr-members' ),
			'company_saved'    => __( 'The company details are saved.', 'neurohr-members' ),
			'contact_saved'    => __( 'Your contact details are saved.', 'neurohr-members' ),
			'proof_sent'       => __( 'Thank you! We received your payment details and will confirm within 3 working days. You will get an email as soon as your plan starts.', 'neurohr-members' ),
			'pay_done'         => __( 'Thank you, your payment is complete! Your plan is now active and the paid invoice is on its way to your inbox.', 'neurohr-members' ),
			'signup_pay'       => __( 'Your account is ready. One last step: choose how you would like to pay. Your plan starts as soon as the payment is complete.', 'neurohr-members' ),
			'pay_processing'   => __( 'Thank you! Your payment is being processed. You will get an email as soon as it is confirmed.', 'neurohr-members' ),
			'refund_requested' => __( 'Thank you, we received your refund request. We will reply by email, and access to members-only content is paused in the meantime.', 'neurohr-members' ),
			'card_saved'       => __( 'Your card is saved. Thank you!', 'neurohr-members' ),
			'autorenew_on'     => __( 'Auto-renew is on. We will remind you before each renewal.', 'neurohr-members' ),
			'autorenew_off'    => __( 'Auto-renew is off. Your plan stays active until its last day.', 'neurohr-members' ),
			'pay_cancelled'    => __( 'The payment was not completed. You can try again or choose another way to pay.', 'neurohr-members' ),
		);
	}

	/** Errors for $form, and on the page's first form also the success message from the URL. */
	public static function render_messages( $form ) {
		$html = '';
		if ( ! empty( self::$errors[ $form ] ) ) {
			$html .= '<div class="nhm-alert nhm-alert--error" role="alert">';
			foreach ( self::$errors[ $form ] as $message ) {
				$html .= '<p>' . wp_kses( $message, array( 'a' => array( 'href' => array() ), 'strong' => array() ) ) . '</p>';
			}
			$html .= '</div>';
		}
		static $shown = false;
		if ( ! $shown ) {
			$code     = isset( $_GET['nhm_msg'] ) ? sanitize_key( wp_unslash( $_GET['nhm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$messages = self::messages();
			$text     = self::$notice ? self::$notice : ( isset( $messages[ $code ] ) ? $messages[ $code ] : '' );
			if ( $text && 'logout_confirm' === $code ) {
				$html  .= '<div class="nhm-alert nhm-alert--warning" role="status"><p>' . esc_html( $text ) . ' <a class="nhm-btn nhm-btn--small nhm-btn--primary" href="' . esc_url( wp_logout_url() ) . '">' . esc_html__( 'Log out', 'neurohr-members' ) . '</a></p></div>';
				$shown  = true;
			} elseif ( $text && in_array( $code, array( 'pay_cancelled', 'pay_processing' ), true ) ) {
				$html  .= '<div class="nhm-alert nhm-alert--warning" role="status"><p>' . esc_html( $text ) . '</p></div>';
				$shown  = true;
			} elseif ( $text && in_array( $code, array( 'verify_bad' ), true ) ) {
				$html  .= '<div class="nhm-alert nhm-alert--error" role="alert"><p>' . esc_html( $text ) . '</p></div>';
				$shown  = true;
			} elseif ( $text ) {
				$html  .= '<div class="nhm-alert nhm-alert--success" role="status"><p>' . esc_html( $text ) . '</p></div>';
				$shown  = true;
			}
		}
		return $html;
	}

	/* ---------- Form pieces ---------- */

	public static function open( $action, $class = '', $files = false ) {
		return '<form class="nhm-form ' . esc_attr( $class ) . '" method="post" action="' . esc_url( self::current_url( array( 'nhm_msg' ) ) ) . '"' . ( $files ? ' enctype="multipart/form-data"' : '' ) . ' novalidate>'
			. '<input type="hidden" name="nhm_action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( 'nhm_' . $action, '_nhm_nonce', false, false );
	}

	/**
	 * @param array $a name, label, type, value, required, autocomplete, help, attrs, options, placeholder.
	 *                 type: text, email, tel, url, number, date, password, textarea, select,
	 *                 radio, checkbox (single tick), checkboxes (several).
	 */
	public static function field( array $a ) {
		$a    = array_merge( array( 'name' => '', 'label' => '', 'type' => 'text', 'value' => '', 'required' => false, 'autocomplete' => '', 'help' => '', 'attrs' => '', 'options' => array(), 'placeholder' => '' ), $a );
		$id   = 'nhm-' . sanitize_html_class( $a['name'] ) . '-' . wp_rand( 100, 999 );
		$type = $a['type'];
		$req  = $a['required'] ? ' required aria-required="true"' : '';
		$mark = $a['required'] ? ' <span class="nhm-req" aria-hidden="true">*</span>' : ' <span class="nhm-optional">' . esc_html__( '(optional)', 'neurohr-members' ) . '</span>';
		$h    = '<p class="nhm-field nhm-field--' . esc_attr( sanitize_html_class( $a['name'] ) ) . ' nhm-field--type-' . esc_attr( $type ) . '">';

		if ( 'checkbox' === $type ) {
			$h .= '<label class="nhm-check"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '" value="1"' . ( $a['value'] ? ' checked' : '' ) . $req . '> <span>' . esc_html( $a['label'] ) . ( $a['required'] ? ' <span class="nhm-req" aria-hidden="true">*</span>' : '' ) . '</span></label>';
		} elseif ( 'radio' === $type || 'checkboxes' === $type ) {
			$values = (array) $a['value'];
			$h     .= '<span class="nhm-field__label" id="' . esc_attr( $id ) . '">' . esc_html( $a['label'] ) . $mark . '</span><span class="nhm-choices" role="group" aria-labelledby="' . esc_attr( $id ) . '">';
			foreach ( $a['options'] as $opt ) {
				$h .= '<label class="nhm-check"><input type="' . ( 'radio' === $type ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $a['name'] . ( 'checkboxes' === $type ? '[]' : '' ) ) . '" value="' . esc_attr( $opt ) . '"' . ( in_array( (string) $opt, array_map( 'strval', $values ), true ) ? ' checked' : '' ) . '> <span>' . esc_html( $opt ) . '</span></label>';
			}
			$h .= '</span>';
		} else {
			$h .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $a['label'] ) . $mark . '</label>';
			$ph = $a['placeholder'] ? ' placeholder="' . esc_attr( $a['placeholder'] ) . '"' : '';
			$ac = $a['autocomplete'] ? ' autocomplete="' . esc_attr( $a['autocomplete'] ) . '"' : '';
			if ( 'textarea' === $type ) {
				$h .= '<textarea class="nhm-input nhm-textarea" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '" rows="4"' . $req . $ph . ' ' . $a['attrs'] . '>' . esc_textarea( (string) $a['value'] ) . '</textarea>';
			} elseif ( 'tel' === $type ) {
				list( $cc, $num ) = NHM_Fields::split_phone( is_array( $a['value'] ) ? '' : $a['value'] );
				$h .= '<span class="nhm-phone"><select class="nhm-input nhm-phone__cc" name="' . esc_attr( $a['name'] ) . '_cc" aria-label="' . esc_attr__( 'Country code', 'neurohr-members' ) . '">';
				foreach ( NHM_Fields::country_codes() as $code => $label ) {
					$h .= '<option value="' . esc_attr( $code ) . '"' . selected( $code, $cc ? $cc : '+60', false ) . '>' . esc_html( $label ) . '</option>';
				}
				$h .= '</select><input class="nhm-input nhm-phone__number" type="tel" inputmode="tel" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '" value="' . esc_attr( $num ) . '"' . $req . $ac . ( $ph ? $ph : ' placeholder="12-345 6789"' ) . ' ' . $a['attrs'] . '></span>';
			} elseif ( 'select' === $type ) {
				$h .= '<select class="nhm-input nhm-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '"' . $req . ' ' . $a['attrs'] . '>';
				$h .= '<option value="">' . esc_html( $a['placeholder'] ? $a['placeholder'] : __( 'Please choose', 'neurohr-members' ) ) . '</option>';
				foreach ( $a['options'] as $opt ) {
					$h .= '<option value="' . esc_attr( $opt ) . '"' . ( (string) $opt === (string) $a['value'] ? ' selected' : '' ) . '>' . esc_html( $opt ) . '</option>';
				}
				$h .= '</select>';
			} else {
				if ( 'password' === $type ) {
					$h .= '<span class="nhm-password">';
				}
				$h .= '<input class="nhm-input" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '"';
				if ( 'password' !== $type ) {
					$h .= ' value="' . esc_attr( is_array( $a['value'] ) ? implode( ', ', $a['value'] ) : (string) $a['value'] ) . '"';
				}
				$h .= $req . $ac . $ph . ' ' . $a['attrs'] . '>';
				if ( 'password' === $type ) {
					$h .= '<button type="button" class="nhm-password__toggle" aria-label="' . esc_attr__( 'Show password', 'neurohr-members' ) . '" data-nhm-show="' . esc_attr__( 'Show', 'neurohr-members' ) . '" data-nhm-hide="' . esc_attr__( 'Hide', 'neurohr-members' ) . '">' . esc_html__( 'Show', 'neurohr-members' ) . '</button></span>';
				}
			}
		}
		if ( $a['help'] ) {
			$h .= '<span class="nhm-help">' . esc_html( $a['help'] ) . '</span>';
		}
		return $h . '</p>';
	}

	/** Raw posted values (unslashed) for NHM_Fields::read(). */
	public static function posted() {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- the dispatcher verified the nonce.
	}

	public static function button( $label, $class = 'nhm-btn nhm-btn--primary' ) {
		return '<p class="nhm-actions"><button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></p>';
	}

	/** Outer wrapper with the brand colour. Every shortcode output sits inside .nhm. */
	public static function wrap( $html, $class = '' ) {
		self::enqueue();
		return '<div class="nhm ' . esc_attr( $class ) . '">' . $html . '</div>';
	}

	public static function current_url( array $remove = array() ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url = home_url( $uri );
		return $remove ? remove_query_arg( $remove, $url ) : $url;
	}

	/* ---------- Anti-spam ---------- */

	public static function spam_fields() {
		$time = time();
		$sig  = hash_hmac( 'sha256', (string) $time, wp_salt( 'nonce' ) );
		$html = '<div class="nhm-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label>' . esc_html__( 'Leave this empty', 'neurohr-members' ) . '<input type="text" name="nhm_website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<input type="hidden" name="nhm_t" value="' . esc_attr( $time . '.' . $sig ) . '">';
		$site = trim( (string) NHM_Settings::get( 'turnstile_site' ) );
		if ( $site && trim( (string) NHM_Settings::get( 'turnstile_secret' ) ) ) {
			wp_enqueue_script( 'nhm-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$html .= '<div class="cf-turnstile nhm-turnstile" data-sitekey="' . esc_attr( $site ) . '"></div>';
		}
		return $html;
	}

	/** @return true|WP_Error */
	public static function spam_check() {
		// phpcs:disable WordPress.Security.NonceVerification -- the dispatcher verified the nonce.
		if ( ! empty( $_POST['nhm_website'] ) ) {
			return new WP_Error( 'nhm_spam', __( 'Something went wrong. Please try again.', 'neurohr-members' ) );
		}
		$token = isset( $_POST['nhm_t'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_t'] ) ) : '';
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return new WP_Error( 'nhm_spam', __( 'The form expired. Please try again.', 'neurohr-members' ) );
		}
		$age = time() - (int) $parts[0];
		if ( $age < 3 ) {
			return new WP_Error( 'nhm_spam', __( 'That was very fast. Please check the form and send it again.', 'neurohr-members' ) );
		}
		if ( $age > DAY_IN_SECONDS ) {
			return new WP_Error( 'nhm_spam', __( 'The form expired. Please try again.', 'neurohr-members' ) );
		}
		$secret = trim( (string) NHM_Settings::get( 'turnstile_secret' ) );
		if ( $secret && trim( (string) NHM_Settings::get( 'turnstile_site' ) ) ) {
			$response = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
			$check    = wp_remote_post(
				'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				array(
					'timeout' => 8,
					'body'    => array( 'secret' => $secret, 'response' => $response, 'remoteip' => self::ip() ),
				)
			);
			$data = is_wp_error( $check ) ? null : json_decode( wp_remote_retrieve_body( $check ), true );
			// If Cloudflare cannot be reached, let the person through rather than block every sign-up.
			if ( is_array( $data ) && empty( $data['success'] ) ) {
				return new WP_Error( 'nhm_spam', __( 'Please confirm you are human and try again.', 'neurohr-members' ) );
			}
		}
		// phpcs:enable
		return true;
	}

	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** Simple limit per IP: $max attempts per $minutes. */
	public static function limited( $what, $max, $minutes ) {
		$key = 'nhm_rl_' . $what . '_' . md5( self::ip() );
		return (int) get_transient( $key ) >= $max;
	}

	public static function hit( $what, $minutes ) {
		$key = 'nhm_rl_' . $what . '_' . md5( self::ip() );
		set_transient( $key, (int) get_transient( $key ) + 1, $minutes * MINUTE_IN_SECONDS );
	}

	public static function clear_hits( $what ) {
		delete_transient( 'nhm_rl_' . $what . '_' . md5( self::ip() ) );
	}

	/* ---------- Cache and assets ---------- */

	/** Login, register, lost-password and account pages must never be cached. */
	public static function no_cache_pages() {
		if ( ! is_singular() ) {
			return;
		}
		$id = get_queried_object_id();
		foreach ( array( 'login', 'register', 'lost', 'account' ) as $key ) {
			if ( $id && (int) NHM_Settings::get( 'page_' . $key ) === $id ) {
				self::no_cache();
				return;
			}
		}
	}

	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'neurohr-members form' );
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	public static function register_assets() {
		wp_register_style( 'nhm-front', NHM_URL . 'assets/front.css', array(), NHM_VERSION );
		wp_register_script( 'nhm-front', NHM_URL . 'assets/front.js', array(), NHM_VERSION, true );
		wp_register_script( 'nhm-picker', NHM_URL . 'assets/picker.js', array(), NHM_VERSION, true );
	}

	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! wp_style_is( 'nhm-front', 'registered' ) ) {
			self::register_assets();
		}
		if ( NHM_Settings::get( 'load_css' ) ) {
			wp_enqueue_style( 'nhm-front' );
			wp_add_inline_style( 'nhm-front', '.nhm{--nhm-primary:' . NHM_Settings::brand() . ';}' );
		}
		wp_enqueue_script( 'nhm-front' );
		wp_enqueue_script( 'nhm-picker' );
	}
}
