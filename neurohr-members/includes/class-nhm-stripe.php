<?php
/**
 * Stripe: hosted Checkout for one invoice (card, FPX, GrabPay — whatever is switched on in the
 * Stripe Dashboard → Settings → Payment methods), the return page and the webhook.
 *
 * No Stripe library is bundled: the few calls are plain HTTPS requests to api.stripe.com.
 * Keys live in Settings → Payments and are never printed back.
 *
 * Webhook: https://your-site/wp-json/nhm/v1/stripe — events
 *   checkout.session.completed, checkout.session.async_payment_succeeded,
 *   checkout.session.async_payment_failed, checkout.session.expired,
 *   payment_intent.succeeded, payment_intent.payment_failed (auto-renew charges),
 *   charge.refunded (refunds made in Stripe), charge.dispute.created (chargebacks)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Stripe {

	const API = 'https://api.stripe.com/v1/';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function mode() {
		return 'live' === NHM_Settings::get( 'stripe_mode' ) ? 'live' : 'test';
	}

	public static function key( $which = 'sk', $mode = null ) {
		$mode = $mode ? $mode : self::mode();
		return trim( (string) NHM_Settings::get( 'stripe_' . $mode . '_' . $which ) );
	}

	/** Switched on and a secret key for the current mode is saved. */
	public static function ready() {
		return (bool) NHM_Settings::get( 'pay_stripe' ) && '' !== self::key( 'sk' );
	}

	public static function webhook_url() {
		return rest_url( 'nhm/v1/stripe' );
	}

	/**
	 * @return array|WP_Error decoded JSON
	 */
	public static function request( $method, $path, array $params = array(), $mode = null, $idempotency = '' ) {
		$sk = self::key( 'sk', $mode );
		if ( '' === $sk ) {
			return new WP_Error( 'nhm_stripe', __( 'The Stripe secret key is missing (Settings → Payments).', 'neurohr-members' ) );
		}
		$url  = self::API . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $sk,
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( '' !== $idempotency ) {
			$args['headers']['Idempotency-Key'] = $idempotency;
		}
		if ( 'GET' === $method ) {
			$url = $params ? $url . '?' . http_build_query( $params ) : $url;
		} else {
			$args['body'] = http_build_query( $params );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code > 299 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'nhm_stripe', 'Stripe: ' . $msg, array( 'status' => $code, 'error' => is_array( $data ) && isset( $data['error'] ) ? $data['error'] : array() ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/** Settings → Payments → "Check the keys". @return true|WP_Error */
	public static function check() {
		$r = self::request( 'GET', 'balance' );
		return is_wp_error( $r ) ? $r : true;
	}

	/** Amount in sen. */
	public static function cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Start a Checkout session for an invoice. @return array|WP_Error Stripe session
	 */
	public static function checkout( $inv, $payment_id, $autorenew = false ) {
		$pay  = NHM_Payments::pay_url( $inv );
		$name = $inv['number'] . ( $inv['level_id'] ? ' · ' . NHM_Invoices::plan_line( $inv['level_id'], $inv['period_start'], $inv['period_end'], $inv['terms'] ) : '' );
		$meta = array(
			'invoice_id'     => (int) $inv['id'],
			'invoice_number' => $inv['number'],
			'payment_id'     => (int) $payment_id,
			'site'           => home_url( '/' ),
		);
		$params = array(
			'mode'                => 'payment',
			'client_reference_id' => (string) (int) $inv['id'],
			'success_url'         => add_query_arg( array( 'nhm_stripe' => 'return' ), $pay ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg( array( 'nhm_stripe' => 'cancel' ), $pay ),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'myr',
						'unit_amount'  => self::cents( $inv['total'] ),
						'product_data' => array( 'name' => mb_substr( $name, 0, 250 ) ),
					),
				),
			),
			'metadata'            => $meta,
			'payment_intent_data' => array(
				'description' => mb_substr( get_bloginfo( 'name' ) . ' ' . $inv['number'], 0, 250 ),
				'metadata'    => $meta,
			),
		);
		$email    = NHM_Invoices::email_to( $inv );
		$customer = $inv['user_id'] ? NHM_Renew::customer_id( $inv['user_id'] ) : '';
		if ( $autorenew ) {
			// Keep the card (only cards can be charged later; FPX and GrabPay still show).
			$params['metadata']['autorenew']                     = 1;
			$params['payment_intent_data']['metadata']['autorenew'] = 1;
			$params['payment_method_options']                    = array( 'card' => array( 'setup_future_usage' => 'off_session' ) );
			if ( '' === $customer ) {
				$params['customer_creation'] = 'always';
			}
		}
		if ( '' !== $customer ) {
			$params['customer'] = $customer;
		} elseif ( is_email( $email ) ) {
			$params['customer_email'] = $email;
		}
		return self::request( 'POST', 'checkout/sessions', apply_filters( 'nhm_stripe_checkout_params', $params, $inv ) );
	}

	public static function session( $id, $mode = null ) {
		return self::request( 'GET', 'checkout/sessions/' . rawurlencode( $id ), array( 'expand' => array( 'payment_intent.payment_method' ) ), $mode );
	}

	/** "card" / "fpx" / "grabpay" → label used on the invoice. */
	public static function method_label( $type ) {
		$labels = array( 'card' => 'Card', 'fpx' => 'FPX', 'grabpay' => 'GrabPay' );
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ( $type ? ucfirst( $type ) : 'Stripe' );
	}

	/**
	 * A paid session: mark the payment and the invoice paid (once).
	 *
	 * @return string 'paid' | 'processing' | 'unknown'
	 */
	public static function settle( array $session, $mode = null ) {
		$payment = NHM_Payments::by_session( isset( $session['id'] ) ? $session['id'] : '' );
		if ( ! $payment && ! empty( $session['metadata']['payment_id'] ) ) {
			$payment = NHM_Payments::get( (int) $session['metadata']['payment_id'] );
		}
		if ( ! $payment ) {
			return 'unknown';
		}
		if ( 'succeeded' === $payment['status'] ) {
			return 'paid';
		}
		if ( 'paid' !== ( isset( $session['payment_status'] ) ? $session['payment_status'] : '' ) ) {
			NHM_Payments::update( $payment['id'], array( 'status' => 'processing' ) );
			return 'processing';
		}
		$inv = NHM_Invoices::get( $payment['invoice_id'] );
		// The payment method type needs the expanded session.
		$type   = '';
		$intent = isset( $session['payment_intent'] ) ? $session['payment_intent'] : '';
		if ( ! is_array( $intent ) && ! empty( $session['id'] ) ) {
			$full = self::session( $session['id'], $mode );
			if ( ! is_wp_error( $full ) ) {
				$intent = isset( $full['payment_intent'] ) ? $full['payment_intent'] : $intent;
			}
		}
		if ( is_array( $intent ) ) {
			$type = isset( $intent['payment_method']['type'] ) ? $intent['payment_method']['type'] : '';
			if ( is_array( $intent['payment_method'] ?? null ) ) {
				$session['_payment_method'] = $intent['payment_method'];
			}
			$intent = isset( $intent['id'] ) ? $intent['id'] : '';
		}
		$amount = isset( $session['amount_total'] ) ? (int) $session['amount_total'] / 100 : (float) $payment['amount'];
		NHM_Payments::update(
			$payment['id'],
			array(
				'status'        => 'succeeded',
				'amount'        => $amount,
				'stripe_intent' => (string) $intent,
				'stripe_method' => (string) $type,
				'paid_at'       => current_time( 'mysql' ),
			)
		);
		if ( ! $inv ) {
			return 'paid';
		}
		if ( 'unpaid' !== $inv['status'] ) {
			// Paid twice (e.g. a transfer was approved too): keep the money visible for a refund.
			NHM_Payments::update( $payment['id'], array( 'admin_note' => __( 'The invoice was already paid or void when this payment arrived. Refund it in Stripe if needed.', 'neurohr-members' ) ) );
			NHM_Log::add( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'event' => 'paid', 'message' => $inv['number'] . ' · second payment (Stripe ' . $intent . ')' ) );
			return 'paid';
		}
		if ( self::cents( $amount ) < self::cents( $inv['total'] ) ) {
			NHM_Payments::update( $payment['id'], array( 'admin_note' => __( 'Paid amount is less than the invoice total — check before starting the plan.', 'neurohr-members' ) ) );
			return 'paid';
		}
		NHM_Invoices::mark_paid( $inv['id'], NHM_Settings::today(), self::method_label( $type ), (string) $intent );
		NHM_Renew::after_checkout( NHM_Invoices::get( $inv['id'] ), $session, $type, $mode );
		return 'paid';
	}

	/* ---------- Webhook ---------- */

	public static function routes() {
		register_rest_route(
			'nhm/v1',
			'/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Checked by the signature below.
			)
		);
	}

	/**
	 * Check the Stripe-Signature header. @return bool
	 */
	public static function verify( $payload, $header, $secret, $tolerance = 300 ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$t    = 0;
		$sigs = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sigs[] = $kv[1];
			}
		}
		if ( ! $t || ! $sigs || abs( time() - $t ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	public static function webhook( $request ) {
		$payload = (string) $request->get_body();
		$header  = (string) $request->get_header( 'stripe_signature' );
		$mode    = '';
		foreach ( array( 'live', 'test' ) as $m ) {
			if ( self::verify( $payload, $header, self::key( 'whsec', $m ) ) ) {
				$mode = $m;
				break;
			}
		}
		if ( '' === $mode ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 400 );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad payload' ), 400 );
		}
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		// Only sessions this site started.
		if ( isset( $object['metadata']['site'] ) && untrailingslashit( $object['metadata']['site'] ) !== untrailingslashit( home_url( '/' ) ) ) {
			return new WP_REST_Response( array( 'ignored' => 'other site' ), 200 );
		}
		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if ( isset( $object['mode'] ) && 'setup' === $object['mode'] ) {
					NHM_Renew::setup_done( $object, $mode );
				} else {
					self::settle( $object, $mode );
				}
				break;
			case 'charge.refunded':
				NHM_Refunds::from_stripe( $object, $mode );
				break;
			case 'charge.dispute.created':
				NHM_Refunds::dispute( $object, $mode );
				break;
			case 'payment_intent.succeeded':
			case 'payment_intent.payment_failed':
				// Only auto-renew charges (made by the daily run) are handled here.
				if ( ! empty( $object['metadata']['nhm_renew'] ) ) {
					NHM_Renew::intent_event( $object, $event['type'], $mode );
				}
				break;
			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				$payment = NHM_Payments::by_session( isset( $object['id'] ) ? $object['id'] : '' );
				if ( $payment && 'succeeded' !== $payment['status'] ) {
					NHM_Payments::update( $payment['id'], array( 'status' => 'checkout.session.expired' === $event['type'] ? 'expired' : 'failed' ) );
				}
				break;
		}
		do_action( 'nhm_stripe_event', $event, $mode );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
