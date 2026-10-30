<?php
/**
 * Card auto-renew for personal plans.
 *
 * - The member ticks "Renew automatically" on the payment page (ticked by default). Stripe keeps
 *   the card; we only keep the Stripe customer and payment-method IDs plus brand / last 4 / expiry.
 * - The price is locked at what they paid while auto-renew stays on. A lower level price is
 *   followed; a higher one is not. Turning auto-renew off clears the lock.
 * - Reminder emails 7 and 3 days before the last day (and 30 days before when the card expires
 *   first). On the last day the card is charged; the plan continues from the old last day.
 * - A failed charge: email, access continues, a second try 3 days later. If that fails too the
 *   member gets a normal invoice with the payment link and auto-renew turns off.
 * - Company plans never auto-renew.
 *
 * User meta nhm_autorenew = array(
 *   on, level, price (locked unit price), pm, brand, last4, exp_month, exp_year, mode, since,
 *   invoice (open renewal invoice), attempts, retry (Y-m-d), grace (Y-m-d), last_error,
 *   sent (markers per last day), off_reason
 * )
 * User meta nhm_stripe_customer_test / nhm_stripe_customer_live = cus_…
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Renew {

	const META    = 'nhm_autorenew';
	const RETRY   = 3; // Days between the first and the second try.
	const REMIND  = array( 7, 3 );
	const CARD_AT = 30; // Days before the last day for "your card expires first".

	public static function init() {
		add_filter( 'nhm_form_handlers', array( __CLASS__, 'handlers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'card_return' ), 2 );
	}

	public static function handlers( $h ) {
		$h['autorenew_off'] = array( __CLASS__, 'handle_off' );
		$h['autorenew_on']  = array( __CLASS__, 'handle_on' );
		$h['card_update']   = array( __CLASS__, 'handle_card' );
		return $h;
	}

	/* ---------- State ---------- */

	public static function get( $user_id ) {
		$a = get_user_meta( (int) $user_id, self::META, true );
		return array_merge(
			array( 'on' => 0, 'level' => 0, 'price' => 0, 'pm' => '', 'brand' => '', 'last4' => '', 'exp_month' => 0, 'exp_year' => 0, 'mode' => '', 'since' => '', 'invoice' => 0, 'attempts' => 0, 'retry' => '', 'grace' => '', 'last_error' => '', 'sent' => array(), 'off_reason' => '' ),
			is_array( $a ) ? $a : array()
		);
	}

	public static function save( $user_id, array $a ) {
		update_user_meta( (int) $user_id, self::META, $a );
		NHM_Members::flush( (int) $user_id );
	}

	public static function is_on( $user_id ) {
		$a = self::get( $user_id );
		return ! empty( $a['on'] );
	}

	public static function customer_id( $user_id, $mode = null ) {
		$mode = $mode ? $mode : NHM_Stripe::mode();
		return (string) get_user_meta( (int) $user_id, 'nhm_stripe_customer_' . $mode, true );
	}

	/** A saved card for the current Stripe mode. */
	public static function has_card( $user_id ) {
		$a = self::get( $user_id );
		return '' !== $a['pm'] && NHM_Stripe::mode() === $a['mode'] && '' !== self::customer_id( $user_id );
	}

	/** Levels that can renew by themselves: paid, with an end date that moves ("expire after …"). */
	public static function level_ok( $level_id ) {
		$l = NHM_Levels::get( $level_id );
		return $l && ! $l['is_base'] && 'expire' === $l['access'] && (float) $l['amount'] > 0 && ! empty( $l['autorenew'] );
	}

	/** Can this invoice offer "Renew automatically"? Personal plan invoices paid by card through Stripe. */
	public static function can_offer( $inv ) {
		return $inv && NHM_Stripe::ready() && ! empty( $inv['user_id'] ) && empty( $inv['company_id'] ) && self::level_ok( $inv['level_id'] ) && 'user' === $inv['grant_to'];
	}

	/** Price for the next renewal: the locked price, or the level price if that is lower now. */
	public static function price( $user_id ) {
		$a     = self::get( $user_id );
		$level = NHM_Levels::get( $a['level'] );
		$now   = $level ? (float) $level['amount'] : 0;
		$lock  = (float) $a['price'];
		return $lock > 0 && ( $now <= 0 || $lock < $now ) ? $lock : $now;
	}

	/** Total with SST etc. as the renewal invoice will show it. */
	public static function total( $user_id ) {
		$t = NHM_Invoices::totals( array( array( 'description' => 'x', 'qty' => 1, 'price' => self::price( $user_id ) ) ), 0, NHM_Invoices::default_tax_rate(), NHM_Invoices::default_inclusive() );
		return (float) $t['total'];
	}

	/** "every year", "every 6 months". */
	public static function every( $level_id ) {
		$l = NHM_Levels::get( $level_id );
		if ( ! $l ) {
			return '';
		}
		$n     = max( 1, (int) $l['expire_n'] );
		$units = array( 'day' => array( 'day', 'days' ), 'week' => array( 'week', 'weeks' ), 'month' => array( 'month', 'months' ), 'year' => array( 'year', 'years' ) );
		$u     = isset( $units[ $l['expire_unit'] ] ) ? $units[ $l['expire_unit'] ] : $units['year'];
		/* translators: %s: "year" or "2 years" */
		return sprintf( __( 'every %s', 'neurohr-members' ), 1 === $n ? $u[0] : $n . ' ' . $u[1] );
	}

	public static function card_label( $a ) {
		if ( '' === $a['last4'] ) {
			return '';
		}
		return trim( ucfirst( (string) $a['brand'] ) . ' •••• ' . $a['last4'] . ( $a['exp_month'] ? ' (' . sprintf( '%02d/%02d', $a['exp_month'], $a['exp_year'] % 100 ) . ')' : '' ) );
	}

	/** Access during the days between a failed charge and the second try. */
	public static function in_grace( $user_id ) {
		$g = get_user_meta( (int) $user_id, self::META, true );
		return is_array( $g ) && ! empty( $g['grace'] ) && $g['grace'] >= NHM_Settings::today();
	}

	private static function card_from_pm( array &$a, $pm ) {
		if ( ! is_array( $pm ) || empty( $pm['id'] ) || 'card' !== ( isset( $pm['type'] ) ? $pm['type'] : '' ) ) {
			return false;
		}
		$a['pm']        = (string) $pm['id'];
		$a['brand']     = isset( $pm['card']['brand'] ) ? (string) $pm['card']['brand'] : '';
		$a['last4']     = isset( $pm['card']['last4'] ) ? (string) $pm['card']['last4'] : '';
		$a['exp_month'] = isset( $pm['card']['exp_month'] ) ? (int) $pm['card']['exp_month'] : 0;
		$a['exp_year']  = isset( $pm['card']['exp_year'] ) ? (int) $pm['card']['exp_year'] : 0;
		return true;
	}

	public static function turn_on( $user_id, $level_id, $price, $by = 'member' ) {
		$a          = self::get( $user_id );
		$was        = ! empty( $a['on'] );
		$a['on']    = 1;
		$a['level'] = (int) $level_id;
		$a['price'] = round( (float) $price, 2 );
		$a['since'] = $was && $a['since'] ? $a['since'] : current_time( 'mysql' );
		$a['off_reason'] = '';
		self::save( $user_id, $a );
		if ( ! $was ) {
			NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'autorenew_on', 'message' => sprintf( '%s %s · %s', NHM_Levels::name( $level_id ), NHM_Settings::money( $price ), 'admin' === $by ? 'by staff' : 'by member' ) ) );
		}
	}

	public static function turn_off( $user_id, $reason = '', $by = 'member' ) {
		$a = self::get( $user_id );
		if ( empty( $a['on'] ) && empty( $a['retry'] ) ) {
			return;
		}
		$a['on']         = 0;
		$a['price']      = 0;
		$a['retry']      = '';
		$a['grace']      = '';
		$a['attempts']   = 0;
		$a['off_reason'] = (string) $reason;
		self::save( $user_id, $a );
		NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'autorenew_off', 'message' => ( 'admin' === $by ? 'By staff' : ( 'system' === $by ? 'Automatically' : 'By member' ) ) . ( '' !== $reason ? ' · ' . $reason : '' ) ) );
	}

	/* ---------- After a Stripe Checkout payment ---------- */

	/**
	 * Paid through Checkout: save the card and lock the price when "Renew automatically" was ticked.
	 * Not ticked: auto-renew turns off. Paid with FPX / GrabPay: an existing card keeps renewing
	 * at the new plan and price.
	 */
	public static function after_checkout( $inv, array $session, $type, $mode ) {
		if ( ! $inv || ! self::can_offer( $inv ) ) {
			return;
		}
		$uid  = (int) $inv['user_id'];
		$a    = self::get( $uid );
		$unit = isset( $inv['items'][0]['price'] ) ? (float) $inv['items'][0]['price'] : (float) $inv['total'];
		if ( ! empty( $session['customer'] ) && is_string( $session['customer'] ) ) {
			update_user_meta( $uid, 'nhm_stripe_customer_' . $mode, $session['customer'] );
		}
		$ticked = ! empty( $session['metadata']['autorenew'] );
		if ( $ticked && 'card' === $type && isset( $session['_payment_method'] ) && self::card_from_pm( $a, $session['_payment_method'] ) ) {
			$a['mode'] = $mode;
			self::clear_attempts( $a );
			self::save( $uid, $a );
			self::turn_on( $uid, (int) $inv['level_id'], $unit );
			return;
		}
		if ( $ticked && ! empty( $a['on'] ) && '' !== $a['pm'] ) {
			// Paid another way, but a card is already on file: keep renewing the new plan.
			self::clear_attempts( $a );
			self::save( $uid, $a );
			self::turn_on( $uid, (int) $inv['level_id'], $unit );
			return;
		}
		if ( ! $ticked ) {
			self::turn_off( $uid, '', 'system' );
		}
	}

	private static function clear_attempts( array &$a ) {
		$a['attempts']   = 0;
		$a['retry']      = '';
		$a['grace']      = '';
		$a['invoice']    = 0;
		$a['last_error'] = '';
	}

	/* ---------- Card setup (add or change the card) ---------- */

	/** @return string|WP_Error customer ID */
	public static function ensure_customer( $user_id ) {
		$cus = self::customer_id( $user_id );
		if ( '' !== $cus ) {
			return $cus;
		}
		$user = get_userdata( $user_id );
		$r    = NHM_Stripe::request( 'POST', 'customers', array( 'email' => $user->user_email, 'name' => $user->display_name, 'metadata' => array( 'user_id' => (int) $user_id, 'site' => home_url( '/' ) ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_user_meta( $user_id, 'nhm_stripe_customer_' . NHM_Stripe::mode(), $r['id'] );
		return $r['id'];
	}

	public static function account_url( $args = array() ) {
		$base = NHM_Settings::page_url( 'account' ) ? NHM_Settings::page_url( 'account' ) : home_url( '/' );
		return add_query_arg( array_merge( array( 'tab' => 'billing' ), $args ), $base );
	}

	/** Stripe Checkout in setup mode. $purpose: update (new card) | enable (new card + turn on). */
	public static function setup_session( $user_id, $purpose ) {
		$cus = self::ensure_customer( $user_id );
		if ( is_wp_error( $cus ) ) {
			return $cus;
		}
		return NHM_Stripe::request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                 => 'setup',
				'customer'             => $cus,
				'payment_method_types' => array( 'card' ),
				'success_url'          => self::account_url( array( 'nhm_card' => 'done' ) ) . '&session_id={CHECKOUT_SESSION_ID}',
				'cancel_url'           => self::account_url( array( 'nhm_card' => 'cancel' ) ) . '#nhm-autorenew',
				'metadata'             => array( 'user_id' => (int) $user_id, 'purpose' => $purpose, 'site' => home_url( '/' ) ),
				'setup_intent_data'    => array( 'metadata' => array( 'user_id' => (int) $user_id, 'site' => home_url( '/' ) ) ),
			)
		);
	}

	/** A finished setup session (return page or webhook). */
	public static function setup_done( array $session, $mode ) {
		$uid = isset( $session['metadata']['user_id'] ) ? (int) $session['metadata']['user_id'] : 0;
		if ( ! $uid || ! get_userdata( $uid ) || ( isset( $session['status'] ) && 'complete' !== $session['status'] ) ) {
			return false;
		}
		$si = isset( $session['setup_intent'] ) ? $session['setup_intent'] : '';
		if ( ! is_array( $si ) || ! is_array( $si['payment_method'] ?? null ) ) {
			$id = is_array( $si ) ? $si['id'] : (string) $si;
			$si = NHM_Stripe::request( 'GET', 'setup_intents/' . rawurlencode( $id ), array( 'expand' => array( 'payment_method' ) ), $mode );
			if ( is_wp_error( $si ) ) {
				return false;
			}
		}
		$a = self::get( $uid );
		if ( isset( $si['payment_method']['id'] ) && $si['payment_method']['id'] === $a['pm'] ) {
			return true; // Already saved (return page and webhook both arrive).
		}
		if ( ! self::card_from_pm( $a, $si['payment_method'] ) ) {
			return false;
		}
		$a['mode'] = $mode;
		$retry     = '' !== $a['retry'];
		self::save( $uid, $a );
		NHM_Log::add( array( 'user_id' => $uid, 'event' => 'card', 'message' => self::card_label( $a ) ) );
		$p = NHM_Members::personal( $uid );
		if ( 'enable' === ( $session['metadata']['purpose'] ?? '' ) && self::level_ok( $p['level'] ) ) {
			// Turned on again: today's price.
			self::turn_on( $uid, $p['level'], (float) NHM_Levels::get( $p['level'] )['amount'] );
		}
		// A charge waiting for a second try: try the new card at once.
		if ( $retry && ! empty( $a['on'] ) ) {
			self::charge( $uid, 'new card' );
		}
		return true;
	}

	public static function card_return() {
		// phpcs:disable WordPress.Security.NonceVerification -- the session is checked with Stripe.
		if ( ! isset( $_GET['nhm_card'] ) || ! is_user_logged_in() ) {
			return;
		}
		$back = self::account_url() . '#nhm-autorenew';
		if ( 'done' !== $_GET['nhm_card'] || empty( $_GET['session_id'] ) ) {
			NHM_Forms::done( $back );
		}
		$sid = sanitize_text_field( wp_unslash( $_GET['session_id'] ) );
		// phpcs:enable
		$s = NHM_Stripe::request( 'GET', 'checkout/sessions/' . rawurlencode( $sid ), array( 'expand' => array( 'setup_intent.payment_method' ) ) );
		$ok = ! is_wp_error( $s ) && isset( $s['metadata']['user_id'] ) && (int) $s['metadata']['user_id'] === get_current_user_id() && self::setup_done( $s, NHM_Stripe::mode() );
		NHM_Forms::done( $back, $ok ? 'card_saved' : '' );
	}

	/* ---------- Member forms (My account) ---------- */

	public static function handle_off() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the dispatcher.
		$reason = isset( $_POST['nhm_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_reason'] ) ) : '';
		$more   = isset( $_POST['nhm_reason_more'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nhm_reason_more'] ) ) : '';
		// phpcs:enable
		self::turn_off( $uid, trim( $reason . ( '' !== $more ? ': ' . $more : '' ) ) );
		NHM_Forms::done( self::account_url() . '#nhm-autorenew', 'autorenew_off' );
	}

	public static function handle_on() {
		$uid = get_current_user_id();
		$p   = $uid ? NHM_Members::personal( $uid ) : null;
		if ( ! $p || ! self::level_ok( $p['level'] ) || ! NHM_Members::date_active( $p['expiry'] ) || ! NHM_Stripe::ready() ) {
			NHM_Forms::error( 'autorenew', __( 'Auto-renew is not available for this plan.', 'neurohr-members' ) );
			return;
		}
		if ( self::has_card( $uid ) ) {
			self::turn_on( $uid, $p['level'], (float) NHM_Levels::get( $p['level'] )['amount'] );
			NHM_Forms::done( self::account_url() . '#nhm-autorenew', 'autorenew_on' );
		}
		self::go_setup( $uid, 'enable' );
	}

	public static function handle_card() {
		$uid = get_current_user_id();
		if ( ! $uid || ! NHM_Stripe::ready() ) {
			return;
		}
		self::go_setup( $uid, 'update' );
	}

	private static function go_setup( $uid, $purpose ) {
		$s = self::setup_session( $uid, $purpose );
		if ( is_wp_error( $s ) || empty( $s['url'] ) ) {
			NHM_Forms::error( 'autorenew', __( 'The card page could not be opened. Please try again in a moment.', 'neurohr-members' ) );
			return;
		}
		wp_redirect( $s['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checkout.stripe.com
		exit;
	}

	/** Card on My account → Plan & billing. */
	public static function card( $user_id ) {
		if ( ! NHM_Stripe::ready() ) {
			return '';
		}
		$p = NHM_Members::personal( $user_id );
		$a = self::get( $user_id );
		if ( ! self::level_ok( $p['level'] ) || ( ! NHM_Members::date_active( $p['expiry'] ) && ! self::in_grace( $user_id ) ) || '' === $p['expiry'] ) {
			return '';
		}
		$h  = '<section class="nhm-card nhm-renew" id="nhm-autorenew">';
		$h .= '<div class="nhm-renew__head"><h3 class="nhm-card__title">' . esc_html__( 'Auto-renew', 'neurohr-members' ) . '</h3>';
		$h .= ! empty( $a['on'] ) ? '<span class="nhm-pill nhm-pill--ok">' . esc_html__( 'On', 'neurohr-members' ) . '</span>' : '<span class="nhm-pill">' . esc_html__( 'Off', 'neurohr-members' ) . '</span>';
		$h .= '</div>' . NHM_Forms::render_messages( 'autorenew' );
		if ( ! empty( $a['on'] ) ) {
			if ( '' !== $a['retry'] ) {
				/* translators: 1: date of the failed charge, 2: date of the next try */
				$h .= '<div class="nhm-alert nhm-alert--warning"><p>' . esc_html( sprintf( __( 'We couldn\'t charge your card on %1$s. Your plan stays active while we try again on %2$s. Updating your card now makes us try it straight away.', 'neurohr-members' ), NHM_Emails::format_date( $p['expiry'] ), NHM_Emails::format_date( $a['retry'] ) ) ) . '</p></div>';
			}
			$h .= '<dl class="nhm-renew__facts">';
			$h .= '<div><dt>' . esc_html__( 'Next payment', 'neurohr-members' ) . '</dt><dd>' . esc_html( NHM_Settings::money( self::total( $user_id ) ) ) . ' <span class="nhm-muted">' . esc_html( sprintf( /* translators: %s: date */ __( 'on %s', 'neurohr-members' ), NHM_Emails::format_date( '' !== $a['retry'] ? $a['retry'] : $p['expiry'] ) ) ) . '</span></dd></div>';
			$h .= '<div><dt>' . esc_html__( 'Card', 'neurohr-members' ) . '</dt><dd>' . esc_html( self::card_label( $a ) ) . '</dd></div>';
			$h .= '</dl>';
			/* translators: 1: price, 2: "every year" */
			$h .= '<p class="nhm-muted nhm-renew__lock">' . esc_html( sprintf( __( 'Your price of %1$s %2$s stays the same as long as auto-renew is on.', 'neurohr-members' ), NHM_Settings::money( self::price( $user_id ) ), self::every( $a['level'] ) ) ) . '</p>';
			$h .= '<div class="nhm-actions">' . NHM_Forms::open( 'card_update', 'nhm-inline' ) . NHM_Forms::button( __( 'Update card', 'neurohr-members' ), 'nhm-btn' ) . '</form>';
			$h .= '<a href="#nhm-renew-off" class="nhm-link-muted" data-nhm-dialog="nhm-renew-off">' . esc_html__( 'Turn off auto-renew', 'neurohr-members' ) . '</a></div>';
			$h .= self::off_dialog( $p );
		} else {
			/* translators: %s: date */
			$h .= '<p>' . esc_html( sprintf( __( 'Your plan ends on %s. Turn on auto-renew and we\'ll renew it for you with your card, so you never lose access.', 'neurohr-members' ), NHM_Emails::format_date( $p['expiry'] ) ) ) . '</p>';
			$amount = (float) NHM_Levels::get( $p['level'] )['amount'];
			/* translators: 1: price, 2: "every year" */
			$h .= '<p class="nhm-muted">' . esc_html( sprintf( __( '%1$s %2$s. Cancel any time.', 'neurohr-members' ), NHM_Settings::money( $amount ), self::every( $p['level'] ) ) ) . '</p>';
			$label = self::has_card( $user_id ) ? sprintf( /* translators: %s: card */ __( 'Turn on with %s', 'neurohr-members' ), self::card_label( $a ) ) : __( 'Add a card and turn on auto-renew', 'neurohr-members' );
			$h    .= NHM_Forms::open( 'autorenew_on' ) . NHM_Forms::button( $label, 'nhm-btn nhm-btn--primary' ) . '</form>';
		}
		return $h . '</section>';
	}

	public static function off_reasons() {
		return array(
			'I prefer to renew myself'      => __( 'I prefer to renew myself', 'neurohr-members' ),
			'I may not need it next year'   => __( 'I may not need it next year', 'neurohr-members' ),
			'Too expensive'                 => __( 'Too expensive', 'neurohr-members' ),
			'My company will pay instead'   => __( 'My company will pay instead', 'neurohr-members' ),
			'Other'                         => __( 'Other', 'neurohr-members' ),
		);
	}

	private static function off_dialog( $p ) {
		$h  = '<dialog class="nhm-modal" id="nhm-renew-off" aria-labelledby="nhm-renew-off-title"><div class="nhm-modal__box"><a href="#" class="nhm-modal__close" data-nhm-close aria-label="' . esc_attr__( 'Close', 'neurohr-members' ) . '">&times;</a>';
		$h .= '<h3 class="nhm-modal__title" id="nhm-renew-off-title">' . esc_html__( 'Turn off auto-renew?', 'neurohr-members' ) . '</h3>';
		/* translators: %s: date */
		$h .= '<p class="nhm-lead">' . esc_html( sprintf( __( 'Your plan stays active until %s. After that you can renew yourself, at the price of that time.', 'neurohr-members' ), NHM_Emails::format_date( $p['expiry'] ) ) ) . '</p>';
		$h .= NHM_Forms::open( 'autorenew_off' );
		$h .= '<p class="nhm-field"><label for="nhm-reason">' . esc_html__( 'May we ask why?', 'neurohr-members' ) . ' <span class="nhm-optional">' . esc_html__( '(optional)', 'neurohr-members' ) . '</span></label><select id="nhm-reason" name="nhm_reason" class="nhm-input"><option value="">' . esc_html__( 'Choose a reason', 'neurohr-members' ) . '</option>';
		foreach ( self::off_reasons() as $k => $label ) {
			$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		$h .= '</select></p><p class="nhm-field"><textarea name="nhm_reason_more" class="nhm-input" rows="2" placeholder="' . esc_attr__( 'Anything else you would like to tell us', 'neurohr-members' ) . '"></textarea></p>';
		$h .= NHM_Forms::button( __( 'Turn off auto-renew', 'neurohr-members' ), 'nhm-btn nhm-btn--block' ) . '</form></div></dialog>';
		return $h;
	}

	/* ---------- Charging ---------- */

	/** The open renewal invoice for this person, made once and re-used for the second try. */
	private static function renewal_invoice( $user_id, array &$a ) {
		$inv = $a['invoice'] ? NHM_Invoices::get( $a['invoice'] ) : null;
		if ( $inv && 'unpaid' === $inv['status'] && (int) $inv['level_id'] === (int) $a['level'] ) {
			return $inv;
		}
		$p  = NHM_Members::personal( $user_id );
		$id = NHM_Invoices::create(
			array(
				'user_id'  => $user_id,
				'kind'     => 'autorenew',
				'level_id' => $a['level'],
				'grant_to' => 'user',
				'items'    => array( array( 'description' => NHM_Invoices::plan_line( $a['level'] ), 'qty' => 1, 'price' => self::price( $user_id ) ) ),
				'due_date' => $p['expiry'] && $p['expiry'] > NHM_Settings::today() ? $p['expiry'] : NHM_Settings::today(),
			)
		);
		if ( is_wp_error( $id ) ) {
			return null;
		}
		$a['invoice'] = $id;
		return NHM_Invoices::get( $id );
	}

	/**
	 * Charge the saved card for the next term.
	 *
	 * @return string 'paid' | 'processing' | 'failed' | 'skipped'
	 */
	public static function charge( $user_id, $why = 'due' ) {
		$a = self::get( $user_id );
		$p = NHM_Members::personal( $user_id );
		if ( empty( $a['on'] ) || '' === $a['pm'] || ! NHM_Stripe::ready() || $a['mode'] !== NHM_Stripe::mode() ) {
			return 'skipped';
		}
		if ( (int) $p['level'] !== (int) $a['level'] && self::level_ok( $p['level'] ) ) {
			$a['level'] = (int) $p['level']; // Plan changed by staff: renew what they have.
		}
		$inv = self::renewal_invoice( $user_id, $a );
		if ( ! $inv ) {
			return 'skipped';
		}
		$a['attempts']  = (int) $a['attempts'] + 1;
		$a['tried_for'] = $p['expiry'];
		self::save( $user_id, $a );
		$pid    = NHM_Payments::create( array( 'invoice_id' => $inv['id'], 'method' => 'stripe', 'amount' => $inv['total'], 'mode' => $a['mode'], 'user_id' => $user_id ) );
		$intent = NHM_Stripe::request(
			'POST',
			'payment_intents',
			array(
				'amount'         => NHM_Stripe::cents( $inv['total'] ),
				'currency'       => 'myr',
				'customer'       => self::customer_id( $user_id, $a['mode'] ),
				'payment_method' => $a['pm'],
				'off_session'    => 'true',
				'confirm'        => 'true',
				'description'    => mb_substr( $inv['number'] . ' · ' . NHM_Invoices::plan_line( $a['level'] ), 0, 250 ),
				'metadata'       => array( 'invoice_id' => (int) $inv['id'], 'invoice_number' => $inv['number'], 'payment_id' => (int) $pid, 'user_id' => (int) $user_id, 'nhm_renew' => 1, 'site' => home_url( '/' ) ),
			),
			$a['mode'],
			'nhm-renew-' . (int) $inv['id'] . '-' . (int) $a['attempts']
		);
		if ( is_wp_error( $intent ) ) {
			$data = $intent->get_error_data();
			$pi   = isset( $data['error']['payment_intent']['id'] ) ? $data['error']['payment_intent']['id'] : '';
			NHM_Payments::update( $pid, array( 'status' => 'failed', 'stripe_intent' => $pi, 'admin_note' => $intent->get_error_message() ) );
			return self::failed( $user_id, $inv, $intent->get_error_message() );
		}
		NHM_Payments::update( $pid, array( 'stripe_intent' => (string) $intent['id'] ) );
		if ( 'succeeded' === $intent['status'] ) {
			return self::succeeded( $user_id, $inv, $intent, $pid );
		}
		if ( 'processing' === $intent['status'] ) {
			NHM_Payments::update( $pid, array( 'status' => 'processing' ) );
			return 'processing';
		}
		NHM_Payments::update( $pid, array( 'status' => 'failed', 'admin_note' => 'Status: ' . $intent['status'] ) );
		return self::failed( $user_id, $inv, 'requires_action' === $intent['status'] ? __( 'Your bank asked for a confirmation (OTP).', 'neurohr-members' ) : 'Status: ' . $intent['status'] );
	}

	private static function succeeded( $user_id, $inv, array $intent, $pid = 0 ) {
		$inv = NHM_Invoices::get( $inv['id'] );
		if ( ! $inv || 'unpaid' !== $inv['status'] ) {
			return 'paid';
		}
		if ( $pid ) {
			NHM_Payments::update( $pid, array( 'status' => 'succeeded', 'stripe_method' => 'card', 'paid_at' => current_time( 'mysql' ), 'amount' => (float) $intent['amount'] / 100 ) );
		}
		NHM_Invoices::mark_paid( $inv['id'], NHM_Settings::today(), 'Card (auto-renew)', (string) $intent['id'], false );
		$a = self::get( $user_id );
		self::clear_attempts( $a );
		self::save( $user_id, $a );
		$inv = NHM_Invoices::get( $inv['id'] );
		$p   = NHM_Members::personal( $user_id );
		NHM_Emails::invoice_email( 'renewed', $inv, array( '{expiry}' => NHM_Emails::format_date( $p['expiry'] ), '{card}' => self::card_label( $a ), '{level}' => NHM_Levels::name( $inv['level_id'] ) ), false );
		return 'paid';
	}

	private static function failed( $user_id, $inv, $error ) {
		$a               = self::get( $user_id );
		$p               = NHM_Members::personal( $user_id );
		$a['last_error'] = (string) $error;
		$user            = get_userdata( $user_id );
		$vars            = array(
			'{name}'        => $user->display_name,
			'{level}'       => NHM_Levels::name( $a['level'] ),
			'{amount}'      => NHM_Settings::money( $inv['total'] ),
			'{card}'        => self::card_label( $a ),
			'{expiry}'      => NHM_Emails::format_date( $p['expiry'] ),
			'{error}'       => wp_strip_all_tags( (string) $error ),
			'{card_url}'    => self::account_url() . '#nhm-autorenew',
			'{account_url}' => self::account_url(),
		);
		NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'renew_failed', 'message' => $inv['number'] . ' · try ' . (int) $a['attempts'] . ' · ' . $error ) );
		if ( (int) $a['attempts'] < 2 ) {
			$base       = max( NHM_Settings::today(), $p['expiry'] ? $p['expiry'] : NHM_Settings::today() );
			$a['retry'] = wp_date( 'Y-m-d', strtotime( $base . ' 12:00:00 +' . self::RETRY . ' days' ) );
			$a['grace'] = $a['retry'];
			self::save( $user_id, $a );
			$vars['{retry_date}'] = NHM_Emails::format_date( $a['retry'] );
			NHM_Emails::send( 'renew_failed', NHM_Members::contact_email( $user ), $vars );
			return 'failed';
		}
		// Second failure: a normal invoice with the payment link; auto-renew off.
		$a['retry']    = '';
		$a['grace']    = '';
		$a['attempts'] = 0;
		$a['invoice']  = 0;
		self::save( $user_id, $a );
		self::turn_off( $user_id, 'Card payment failed twice', 'system' );
		$due = wp_date( 'Y-m-d', strtotime( NHM_Settings::today() . ' 12:00:00 +7 days' ) );
		NHM_Invoices::update( $inv['id'], array( 'due_date' => $due ) );
		NHM_Emails::invoice_email( 'renew_final', NHM_Invoices::get( $inv['id'] ), $vars, false );
		NHM_Emails::send( 'admin_renew_failed', '', array_merge( $vars, array( '{email}' => $user->user_email, '{invoice_number}' => $inv['number'], '{admin_url}' => admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#nhm' ) ) ) );
		return 'failed';
	}

	/** Webhook for charges that finished later (e.g. "processing" first). */
	public static function intent_event( array $pi, $type, $mode ) {
		$inv = NHM_Invoices::get( isset( $pi['metadata']['invoice_id'] ) ? (int) $pi['metadata']['invoice_id'] : 0 );
		$uid = isset( $pi['metadata']['user_id'] ) ? (int) $pi['metadata']['user_id'] : 0;
		if ( ! $inv || ! $uid || 'unpaid' !== $inv['status'] ) {
			return;
		}
		$pid = isset( $pi['metadata']['payment_id'] ) ? (int) $pi['metadata']['payment_id'] : 0;
		$pay = $pid ? NHM_Payments::get( $pid ) : null;
		if ( 'payment_intent.succeeded' === $type ) {
			self::succeeded( $uid, $inv, $pi, $pay ? $pid : 0 );
		} elseif ( $pay && 'processing' === $pay['status'] ) {
			// Only a charge that was still processing: a direct decline was handled already.
			NHM_Payments::update( $pid, array( 'status' => 'failed' ) );
			self::failed( $uid, $inv, isset( $pi['last_payment_error']['message'] ) ? $pi['last_payment_error']['message'] : 'failed' );
		}
	}

	/* ---------- Daily run ---------- */

	public static function run() {
		if ( ! NHM_Stripe::ready() ) {
			return;
		}
		$users = get_users( array( 'fields' => 'ID', 'number' => -1, 'meta_key' => self::META, 'meta_compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $users as $uid ) {
			self::run_one( (int) $uid );
		}
	}

	public static function run_one( $uid ) {
		$a = self::get( $uid );
		if ( empty( $a['on'] ) || NHM_Refunds::on_hold( $uid ) ) {
			return;
		}
		$p = NHM_Members::personal( $uid );
		if ( ! self::level_ok( $p['level'] ) || '' === $p['expiry'] ) {
			self::turn_off( $uid, 'Plan cannot renew by itself', 'system' );
			return;
		}
		$today = NHM_Settings::today();
		// Second try.
		if ( '' !== $a['retry'] ) {
			if ( $today >= $a['retry'] ) {
				self::charge( $uid, 'retry' );
			}
			return;
		}
		$days = NHM_Members::days_left( $p['expiry'] );
		if ( null === $days ) {
			return;
		}
		$sent = isset( $a['sent'][ $p['expiry'] ] ) ? (array) $a['sent'][ $p['expiry'] ] : array();
		// Due (or missed by a few days): charge. Once per last day (a "processing" charge finishes by webhook).
		if ( $days <= 0 && $days >= -7 ) {
			if ( isset( $a['tried_for'] ) && $a['tried_for'] === $p['expiry'] ) {
				return;
			}
			self::charge( $uid, 'due' );
			return;
		}
		if ( $days < -7 ) {
			self::turn_off( $uid, 'Plan ended', 'system' );
			return;
		}
		$user = get_userdata( $uid );
		$vars = array(
			'{name}'        => $user->display_name,
			'{level}'       => NHM_Levels::name( $a['level'] ),
			'{amount}'      => NHM_Settings::money( self::total( $uid ) ),
			'{card}'        => self::card_label( $a ),
			'{expiry}'      => NHM_Emails::format_date( $p['expiry'] ),
			'{renew_date}'  => NHM_Emails::format_date( $p['expiry'] ),
			'{days}'        => (string) $days,
			'{card_url}'    => self::account_url() . '#nhm-autorenew',
			'{account_url}' => self::account_url(),
		);
		$new = array();
		// The card runs out before the charge.
		if ( $days <= self::CARD_AT && ! in_array( 'card', $sent, true ) && $a['exp_year'] ) {
			$card_end = gmdate( 'Y-m-t', gmmktime( 12, 0, 0, max( 1, (int) $a['exp_month'] ), 1, (int) $a['exp_year'] ) );
			if ( $card_end < $p['expiry'] ) {
				NHM_Emails::send( 'card_expiring', NHM_Members::contact_email( $user ), $vars );
				$new[] = 'card';
			}
		}
		// 7 and 3 days before (only the latest one when the run was missed).
		$hit = null;
		foreach ( self::REMIND as $d ) {
			if ( $days <= $d ) {
				$hit = $d;
			}
		}
		if ( null !== $hit && ! in_array( 'r' . $hit, $sent, true ) ) {
			NHM_Emails::send( 'renew_reminder', NHM_Members::contact_email( $user ), $vars );
			foreach ( self::REMIND as $d ) {
				if ( $d >= $hit ) {
					$new[] = 'r' . $d;
				}
			}
			NHM_Log::add( array( 'user_id' => $uid, 'event' => 'reminder', 'message' => 'Auto-renew in ' . $days . ' days' ) );
		}
		if ( $new ) {
			$a['sent'] = array( $p['expiry'] => array_values( array_unique( array_merge( $sent, $new ) ) ) );
			self::save( $uid, $a );
		}
	}

	/* ---------- Staff ---------- */

	/** Upcoming charges within $days (dashboard). @return array{count:int,total:float} */
	public static function upcoming( $days = 30 ) {
		$out   = array( 'count' => 0, 'total' => 0.0 );
		$until = wp_date( 'Y-m-d', strtotime( NHM_Settings::today() . ' 12:00:00 +' . (int) $days . ' days' ) );
		foreach ( get_users( array( 'fields' => 'ID', 'number' => -1, 'meta_key' => self::META, 'meta_compare' => 'EXISTS' ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$a = self::get( $uid );
			$p = NHM_Members::personal( $uid );
			if ( ! empty( $a['on'] ) && $p['expiry'] && $p['expiry'] <= $until && $p['expiry'] >= NHM_Settings::today() ) {
				$out['count']++;
				$out['total'] += self::total( $uid );
			}
		}
		return $out;
	}
}
