<?php
/**
 * Paying an invoice. The pay page ([nhm_pay], or My account with ?nhm_pay=…) shows the invoice
 * and up to three ways to pay (Settings → Payments):
 *
 *  1. Online — Stripe Checkout (card, FPX, GrabPay). Paid → invoice Paid → plan starts.
 *  2. Bank transfer — your bank details (Business info) + a form for the payment proof.
 *  3. DuitNow QR — your QR picture + the same form.
 *
 * A proof waits under Members → Payments until you Approve (invoice Paid, plan starts) or
 * Reject (the member gets the reason and can send it again). Slips are kept in
 * uploads/nhm-private/slips/ (closed to the web); only staff can open them.
 *
 * The pay link carries the invoice's secret token, so a company's accounts team can pay
 * without logging in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Payments {

	const MAX_SLIP = 5242880; // 5 MB

	public static function init() {
		add_filter( 'nhm_form_handlers', array( __CLASS__, 'handlers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'stripe_return' ), 4 );
	}

	public static function handlers( $h ) {
		$h['pay_stripe'] = array( __CLASS__, 'handle_stripe' );
		$h['pay_proof']  = array( __CLASS__, 'handle_proof' );
		return $h;
	}

	/* ---------- Records ---------- */

	public static function table() {
		return NHM_DB::table( 'payments' );
	}

	public static function methods() {
		return array(
			'stripe' => __( 'Online (card, FPX, GrabPay)', 'neurohr-members' ),
			'bank'   => __( 'Bank transfer', 'neurohr-members' ),
			'qr'     => __( 'DuitNow QR', 'neurohr-members' ),
		);
	}

	public static function statuses() {
		return array(
			'pending'    => __( 'Started', 'neurohr-members' ),
			'processing' => __( 'Processing', 'neurohr-members' ),
			'succeeded'  => __( 'Paid', 'neurohr-members' ),
			'failed'     => __( 'Failed', 'neurohr-members' ),
			'expired'    => __( 'Not completed', 'neurohr-members' ),
			'submitted'  => __( 'Waiting for check', 'neurohr-members' ),
			'approved'   => __( 'Approved', 'neurohr-members' ),
			'rejected'   => __( 'Rejected', 'neurohr-members' ),
		);
	}

	private static function normalise( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'invoice_id', 'user_id', 'reviewed_by' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['amount']     = (float) $row['amount'];
		$row['note']       = (string) $row['note'];
		$row['admin_note'] = (string) $row['admin_note'];
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return self::normalise( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	public static function by_session( $session ) {
		global $wpdb;
		if ( '' === (string) $session ) {
			return null;
		}
		$t = self::table();
		return self::normalise( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE stripe_session = %s", (string) $session ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/** @return int new ID */
	public static function create( array $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array_merge(
			array(
				'invoice_id' => 0,
				'user_id'    => get_current_user_id(),
				'method'     => '',
				'status'     => 'pending',
				'amount'     => 0,
				'currency'   => 'MYR',
				'mode'       => '',
				'payer_name' => '',
				'reference'  => '',
				'note'       => '',
				'slip'       => '',
				'admin_note' => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$d
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $d ) {
		global $wpdb;
		$d['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $d, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array $args invoice_id, status (string or list), method, kind ('online' | 'offline'), limit, offset.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'invoice_id' => 0, 'status' => '', 'method' => '', 'kind' => '', 'limit' => 50, 'offset' => 0 ), $args );
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['invoice_id'] ) {
			$where[] = 'invoice_id = %d';
			$vals[]  = (int) $args['invoice_id'];
		}
		if ( $args['status'] ) {
			$list    = (array) $args['status'];
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $list );
		}
		if ( $args['method'] ) {
			$where[] = 'method = %s';
			$vals[]  = $args['method'];
		}
		if ( 'online' === $args['kind'] ) {
			$where[] = "method = 'stripe'";
		} elseif ( 'offline' === $args['kind'] ) {
			$where[] = "method IN ('bank','qr')";
		}
		$t      = self::table();
		$sql    = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'normalise' ), (array) $rows );
	}

	public static function count_waiting() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'submitted'" ); // phpcs:ignore WordPress.DB
	}

	/** The proof waiting for a check on this invoice, or null. */
	public static function waiting_for( $invoice_id ) {
		$rows = self::query( array( 'invoice_id' => $invoice_id, 'status' => 'submitted', 'limit' => 1 ) );
		return $rows ? $rows[0] : null;
	}

	/* ---------- Which ways to pay ---------- */

	/** method => label, only the ones switched on and set up. */
	public static function available() {
		$out = array();
		if ( NHM_Stripe::ready() ) {
			$out['stripe'] = self::methods()['stripe'];
		}
		if ( NHM_Settings::get( 'pay_bank' ) && '' !== trim( (string) NHM_Settings::get( 'bank_account_no' ) ) ) {
			$out['bank'] = self::methods()['bank'];
		}
		if ( NHM_Settings::get( 'pay_qr' ) && '' !== trim( (string) NHM_Settings::get( 'qr_image' ) ) ) {
			$out['qr'] = self::methods()['qr'];
		}
		return apply_filters( 'nhm_payment_methods', $out );
	}

	public static function pay_url( $inv ) {
		$page = NHM_Settings::page_url( 'pay' );
		$page = $page ? $page : ( nhm_account_url() ? nhm_account_url() : home_url( '/' ) );
		return add_query_arg( 'nhm_pay', $inv['token'], $page );
	}

	/** Can this invoice be paid online now? */
	public static function payable( $inv ) {
		return $inv && 'unpaid' === $inv['status'] && (float) $inv['total'] > 0 && self::available();
	}

	/* ---------- Pay page ---------- */

	/** [nhm_pay] — also shown by [nhm_account] when the address has ?nhm_pay=… */
	public static function shortcode( $atts = array() ) {
		NHM_Forms::no_cache();
		// phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_GET['nhm_pay'] ) ? sanitize_text_field( wp_unslash( $_GET['nhm_pay'] ) ) : NHM_Forms::value( 'pay_proof', 'nhm_pay_token' );
		$inv   = $token ? NHM_Invoices::by_token( $token ) : null;
		if ( ! $inv ) {
			if ( is_user_logged_in() ) {
				$open = NHM_Invoices::pending_for( get_current_user_id() );
				if ( $open ) {
					return self::render( $open[0] );
				}
			}
			return NHM_Forms::wrap( '<p class="nhm-lead">' . esc_html__( 'Open the payment link from your invoice email, or your invoices in My account.', 'neurohr-members' ) . '</p>', 'nhm-pay' );
		}
		return self::render( $inv );
	}

	private static function row( $label, $value, $copy = false ) {
		return '<div class="nhm-pay__row"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong>'
			. ( $copy ? '<button type="button" class="nhm-copy" data-nhm-copy="' . esc_attr( $value ) . '">' . esc_html__( 'Copy', 'neurohr-members' ) . '</button>' : '' ) . '</div>';
	}

	public static function render( $inv ) {
		$h     = NHM_Forms::render_messages( 'pay' );
		$h    .= NHM_Forms::render_messages( 'pay_stripe' );
		$total = NHM_Settings::money( $inv['total'] );
		$bill  = isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '';

		// A new sign-up sees where they are: account done, payment next.
		if ( $inv['user_id'] && NHM_Auth::pending_invoice( $inv['user_id'] ) === (int) $inv['id'] ) {
			$h .= '<ol class="nhm-steps-bar"><li class="is-done">' . esc_html__( 'Create account', 'neurohr-members' ) . '</li><li class="is-on">' . esc_html__( 'Payment', 'neurohr-members' ) . '</li></ol>';
		}
		// Summary.
		$h .= '<section class="nhm-card nhm-pay__summary">';
		$h .= '<p class="nhm-pay__label">' . esc_html__( 'Invoice', 'neurohr-members' ) . ' ' . esc_html( $inv['number'] ) . '</p>';
		$h .= '<p class="nhm-pay__total">' . esc_html( $total ) . '</p>';
		$lines = array();
		foreach ( $inv['items'] as $item ) {
			$lines[] = $item['description'];
		}
		if ( $lines ) {
			$h .= '<p class="nhm-pay__what">' . esc_html( implode( ' · ', $lines ) ) . '</p>';
		}
		$meta = array();
		if ( $bill ) {
			/* translators: %s: name */
			$meta[] = sprintf( __( 'Bill to %s', 'neurohr-members' ), $bill );
		}
		if ( $inv['due_date'] && 'unpaid' === $inv['status'] ) {
			/* translators: %s: date */
			$meta[] = sprintf( __( 'Due %s', 'neurohr-members' ), NHM_Emails::format_date( $inv['due_date'] ) );
		}
		if ( $meta ) {
			$h .= '<p class="nhm-muted">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}
		$h .= '<p class="nhm-pay__pdf"><a href="' . esc_url( NHM_Invoices::download_url( $inv ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download invoice (PDF)', 'neurohr-members' ) . '</a></p>';
		$h .= '</section>';

		if ( 'paid' === $inv['status'] ) {
			/* translators: %s: date */
			$h .= '<div class="nhm-alert nhm-alert--success"><p>' . esc_html( sprintf( __( 'This invoice was paid on %s. Thank you!', 'neurohr-members' ), NHM_Emails::format_date( $inv['paid_date'] ) ) ) . '</p></div>';
			if ( is_user_logged_in() && nhm_account_url() ) {
				$h .= '<p class="nhm-actions"><a class="nhm-btn nhm-btn--primary" href="' . esc_url( NHM_Auth::after_login_url() ) . '">' . esc_html__( 'Continue', 'neurohr-members' ) . '</a></p>';
			}
			return NHM_Forms::wrap( $h, 'nhm-pay' );
		}
		if ( 'void' === $inv['status'] ) {
			$h .= '<div class="nhm-alert nhm-alert--error"><p>' . esc_html__( 'This invoice was cancelled. Please contact us if you want to pay.', 'neurohr-members' ) . '</p></div>';
			return NHM_Forms::wrap( $h, 'nhm-pay' );
		}

		$waiting = self::waiting_for( $inv['id'] );
		if ( $waiting ) {
			/* translators: %s: date */
			$h .= '<div class="nhm-alert nhm-alert--warning"><p><strong>' . esc_html__( 'We are checking your payment.', 'neurohr-members' ) . '</strong> ' . esc_html( sprintf( __( 'We received your payment details on %s and will confirm within 3 working days. You will get an email as soon as your plan starts.', 'neurohr-members' ), mysql2date( 'j M Y, g:i a', $waiting['created_at'] ) ) ) . '</p></div>';
			return NHM_Forms::wrap( $h, 'nhm-pay' );
		}
		$processing = self::query( array( 'invoice_id' => $inv['id'], 'status' => 'processing', 'limit' => 1 ) );
		if ( $processing ) {
			$h .= '<div class="nhm-alert nhm-alert--warning"><p>' . esc_html__( 'Your online payment is being processed by your bank. This page updates when it is done; you will also get an email.', 'neurohr-members' ) . '</p></div>';
		}
		$rejected = self::query( array( 'invoice_id' => $inv['id'], 'status' => 'rejected', 'limit' => 1 ) );
		if ( $rejected && ! $processing ) {
			/* translators: %s: reason */
			$h .= '<div class="nhm-alert nhm-alert--error"><p>' . esc_html( sprintf( __( 'We could not confirm your last payment: %s Please pay again or send the correct details.', 'neurohr-members' ), $rejected[0]['admin_note'] ) ) . '</p></div>';
		}

		$methods = self::available();
		if ( ! $methods ) {
			$h .= '<div class="nhm-alert nhm-alert--warning"><p>' . esc_html__( 'Online payment is not available at the moment. Please contact us to pay this invoice.', 'neurohr-members' ) . '</p></div>';
			return NHM_Forms::wrap( $h, 'nhm-pay' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$chosen = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : NHM_Forms::value( 'pay_proof', 'nhm_method' );
		$chosen = isset( $methods[ $chosen ] ) ? $chosen : key( $methods );

		$h   .= '<h3 class="nhm-pay__heading">' . esc_html__( 'How would you like to pay?', 'neurohr-members' ) . '</h3>';
		$h   .= '<nav class="nhm-pay__methods" aria-label="' . esc_attr__( 'Payment method', 'neurohr-members' ) . '">';
		$base = remove_query_arg( array( 'method', 'nhm_msg', 'nhm_stripe', 'session_id' ), NHM_Forms::current_url() );
		$sub  = array(
			'stripe' => __( 'Card · FPX online banking · GrabPay', 'neurohr-members' ),
			'bank'   => __( 'Transfer to our bank account, then upload the slip', 'neurohr-members' ),
			'qr'     => __( 'Scan with your banking or e-wallet app', 'neurohr-members' ),
		);
		$icons = array( 'stripe' => 'card', 'bank' => 'bank', 'qr' => 'qr' );
		foreach ( $methods as $key => $label ) {
			$titles = array( 'stripe' => __( 'Pay online', 'neurohr-members' ), 'bank' => __( 'Bank transfer', 'neurohr-members' ), 'qr' => __( 'DuitNow QR', 'neurohr-members' ) );
			$h     .= '<a class="nhm-pay__method' . ( $key === $chosen ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( 'method', $key, $base ) ) . '#nhm-pay-panel"' . ( $key === $chosen ? ' aria-current="true"' : '' ) . '>'
				. self::icon( $icons[ $key ] ) . '<span><strong>' . esc_html( $titles[ $key ] ) . '</strong><small>' . esc_html( $sub[ $key ] ) . '</small></span></a>';
		}
		$h .= '</nav>';

		$h .= '<section class="nhm-card nhm-pay__panel" id="nhm-pay-panel">';
		if ( 'stripe' === $chosen ) {
			$h .= '<p>' . esc_html__( 'You will go to Stripe\'s secure payment page and come back here when it is done. Your card details never reach our website.', 'neurohr-members' ) . '</p>';
			$h .= NHM_Forms::open( 'pay_stripe' ) . '<input type="hidden" name="nhm_pay_token" value="' . esc_attr( $inv['token'] ) . '">';
			$h .= self::refund_box( 'pay_stripe' );
			if ( NHM_Renew::can_offer( $inv ) ) {
				$sent = NHM_Forms::has_errors( 'pay_stripe' );
				$on   = $sent ? '1' === NHM_Forms::value( 'pay_stripe', 'nhm_autorenew' ) : true;
				/* translators: 1: price, 2: "every year" */
				$txt = sprintf( __( 'Renew automatically %2$s at %1$s. Cancel any time in My account.', 'neurohr-members' ), NHM_Settings::money( $inv['items'][0]['price'] ?? $inv['total'] ), NHM_Renew::every( $inv['level_id'] ) );
				$h  .= '<p class="nhm-field nhm-pay__renew"><label class="nhm-check"><input type="checkbox" name="nhm_autorenew" value="1"' . ( $on ? ' checked' : '' ) . '> <span>' . esc_html( $txt ) . ' <span class="nhm-muted">' . esc_html__( '(card payments only)', 'neurohr-members' ) . '</span></span></label></p>';
			}
			/* translators: %s: amount */
			$h .= NHM_Forms::button( sprintf( __( 'Pay %s now', 'neurohr-members' ), $total ), 'nhm-btn nhm-btn--primary nhm-btn--block' ) . '</form>';
			$h .= '<p class="nhm-muted nhm-pay__secure">' . esc_html__( 'Secure payment by Stripe', 'neurohr-members' ) . ( 'test' === NHM_Stripe::mode() ? ' · <strong>TEST MODE</strong>' : '' ) . '</p>';
		} else {
			$h .= '<div class="nhm-pay__how">';
			if ( 'bank' === $chosen ) {
				$s  = NHM_Settings::all();
				$h .= '<div class="nhm-pay__details"><p class="nhm-pay__step">' . esc_html__( '1. Transfer to', 'neurohr-members' ) . '</p>';
				if ( $s['bank_name'] ) {
					$h .= self::row( __( 'Bank', 'neurohr-members' ), $s['bank_name'] );
				}
				if ( $s['bank_account_name'] ) {
					$h .= self::row( __( 'Account name', 'neurohr-members' ), $s['bank_account_name'] );
				}
				$h .= self::row( __( 'Account no.', 'neurohr-members' ), $s['bank_account_no'], true );
			} else {
				$h .= '<div class="nhm-pay__details"><p class="nhm-pay__step">' . esc_html__( '1. Scan and pay', 'neurohr-members' ) . '</p>';
				$qr   = (string) NHM_Settings::get( 'qr_image' );
				$qrn  = (string) NHM_Settings::get( 'qr_name' );
				$h   .= '<div class="nhm-pay__qr"><a class="nhm-pay__qrzoom" href="' . esc_url( $qr ) . '" target="_blank" rel="noopener" data-nhm-dialog="nhm-qr-big" aria-label="' . esc_attr__( 'Show the QR code bigger', 'neurohr-members' ) . '"><img src="' . esc_url( $qr ) . '" alt="' . esc_attr__( 'DuitNow QR code', 'neurohr-members' ) . '" width="240" height="240"><span class="nhm-pay__qrhint"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M11 8v6M8 11h6"/></svg>' . esc_html__( 'Tap to enlarge', 'neurohr-members' ) . '</span></a>';
				if ( $qrn ) {
					$h .= '<span>' . esc_html( $qrn ) . '</span>';
				}
				$h .= '</div><p class="nhm-muted">' . esc_html__( 'Type the amount below yourself in your app. Paying on this phone? Save the QR image and open it in your banking app.', 'neurohr-members' ) . ' <a href="' . esc_url( $qr ) . '" download>' . esc_html__( 'Save QR image', 'neurohr-members' ) . '</a></p>';
				$h .= '<dialog class="nhm-modal nhm-modal--qr" id="nhm-qr-big" aria-label="' . esc_attr__( 'DuitNow QR code', 'neurohr-members' ) . '"><div class="nhm-modal__box"><a href="#" class="nhm-modal__close" data-nhm-close aria-label="' . esc_attr__( 'Close', 'neurohr-members' ) . '">&times;</a>'
					. '<img src="' . esc_url( $qr ) . '" alt="' . esc_attr__( 'DuitNow QR code', 'neurohr-members' ) . '">'
					. ( $qrn ? '<p class="nhm-modal--qr__name">' . esc_html( $qrn ) . '</p>' : '' )
					. '<p class="nhm-modal--qr__meta">' . esc_html__( 'Amount', 'neurohr-members' ) . ' <strong>' . esc_html( $total ) . '</strong><br>' . esc_html__( 'Reference', 'neurohr-members' ) . ' <strong>' . esc_html( $inv['number'] ) . '</strong></p></div></dialog>';
			}
			$h .= self::row( __( 'Amount', 'neurohr-members' ), $total, false );
			$h .= self::row( __( 'Reference', 'neurohr-members' ), $inv['number'], true );
			$h .= '<p class="nhm-muted">' . esc_html__( 'Please put the invoice number as the payment reference.', 'neurohr-members' ) . '</p></div>';
			$h .= '<div class="nhm-pay__proof"><p class="nhm-pay__step">' . esc_html__( '2. Send us the payment details', 'neurohr-members' ) . '</p>';
			$h .= self::proof_form( $inv, $chosen );
			$h .= '</div></div>';
		}
		$h .= '</section>';
		return NHM_Forms::wrap( $h, 'nhm-pay' );
	}

	/** Required "I agree to the Refund Policy" tick box (when a Refund Policy page is set). */
	private static function refund_box( $form ) {
		$url = NHM_Settings::legal_url( 'refund' );
		if ( '' === $url ) {
			return '';
		}
		$on = '1' === NHM_Forms::value( $form, 'nhm_refund_ok' );
		/* translators: %s: Refund Policy link */
		$txt = sprintf( __( 'I have read and agree to the %s.', 'neurohr-members' ), '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Refund Policy', 'neurohr-members' ) . '</a>' );
		return '<p class="nhm-field nhm-pay__agree"><label class="nhm-check"><input type="checkbox" name="nhm_refund_ok" value="1" required' . ( $on ? ' checked' : '' ) . '> <span>' . $txt . ' <span class="nhm-req">*</span></span></label></p>';
	}

	private static function refund_ok( $form ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- checked by the dispatcher.
		if ( '' !== NHM_Settings::legal_url( 'refund' ) && empty( $_POST['nhm_refund_ok'] ) ) {
			NHM_Forms::error( $form, __( 'Please tick the box to agree to the Refund Policy.', 'neurohr-members' ) );
			return false;
		}
		return true;
	}

	private static function icon( $name ) {
		$p = array(
			'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
			'bank' => '<path d="M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/>',
			'qr'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h4v-4M14 18v3"/>',
		);
		return '<svg class="nhm-pay__icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[ $name ] . '</svg>';
	}

	private static function proof_form( $inv, $method ) {
		$err  = NHM_Forms::has_errors( 'pay_proof' );
		$v    = function ( $k, $d = '' ) use ( $err ) {
			return $err ? NHM_Forms::value( 'pay_proof', $k, $d ) : $d;
		};
		$user = wp_get_current_user();
		$h    = NHM_Forms::render_messages( 'pay_proof' );
		$h   .= NHM_Forms::open( 'pay_proof', 'nhm-pay__form', true );
		$h   .= '<input type="hidden" name="nhm_pay_token" value="' . esc_attr( $inv['token'] ) . '"><input type="hidden" name="nhm_method" value="' . esc_attr( $method ) . '">';
		$h   .= '<input type="hidden" name="MAX_FILE_SIZE" value="' . (int) self::MAX_SLIP . '">';
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_payer', 'label' => __( 'Paid by (name or company)', 'neurohr-members' ), 'value' => $v( 'nhm_payer', $user && $user->ID ? $user->display_name : ( isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ) ), 'required' => true, 'autocomplete' => 'name' ) );
		$h   .= '<div class="nhm-grid">';
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_paid_date', 'label' => __( 'Date paid', 'neurohr-members' ), 'type' => 'date', 'value' => $v( 'nhm_paid_date', NHM_Settings::today() ), 'required' => true, 'attrs' => 'max="' . esc_attr( NHM_Settings::today() ) . '"' ) );
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_paid_time', 'label' => __( 'Time', 'neurohr-members' ), 'type' => 'time', 'value' => $v( 'nhm_paid_time' ) ) );
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_paid_amount', 'label' => __( 'Amount paid (RM)', 'neurohr-members' ), 'value' => $v( 'nhm_paid_amount', number_format( (float) $inv['total'], 2, '.', '' ) ), 'required' => true, 'attrs' => 'inputmode="decimal"' ) );
		$h   .= NHM_Forms::field( array( 'name' => 'nhm_reference', 'label' => __( 'Bank reference no.', 'neurohr-members' ), 'value' => $v( 'nhm_reference' ) ) );
		$h   .= '</div>';
		$h   .= '<p class="nhm-field nhm-field--file"><label for="nhm-slip">' . esc_html__( 'Payment slip or screenshot', 'neurohr-members' ) . ' <span class="nhm-req" aria-hidden="true">*</span></label>'
			. '<input class="nhm-input" type="file" id="nhm-slip" name="nhm_slip" accept="image/jpeg,image/png,application/pdf" required>'
			. '<span class="nhm-help">' . esc_html__( 'JPG, PNG or PDF, up to 5 MB.', 'neurohr-members' ) . '</span></p>';
		$h .= NHM_Forms::field( array( 'name' => 'nhm_pay_note', 'label' => __( 'Note for us', 'neurohr-members' ), 'type' => 'textarea', 'value' => $v( 'nhm_pay_note' ) ) );
		$h .= self::refund_box( 'pay_proof' );
		$h .= NHM_Forms::button( __( 'Send payment details', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' ) . '</form>';
		return $h;
	}

	/* ---------- Handlers ---------- */

	private static function posted_invoice( $form ) {
		$posted = NHM_Forms::posted();
		$inv    = NHM_Invoices::by_token( isset( $posted['nhm_pay_token'] ) ? (string) $posted['nhm_pay_token'] : '' );
		if ( ! $inv ) {
			NHM_Forms::error( $form, __( 'This payment link is not valid.', 'neurohr-members' ) );
			return null;
		}
		if ( 'unpaid' !== $inv['status'] ) {
			NHM_Forms::done( self::pay_url( $inv ) );
		}
		return $inv;
	}

	public static function handle_stripe() {
		$inv = self::posted_invoice( 'pay_stripe' );
		if ( ! $inv ) {
			return;
		}
		if ( ! NHM_Stripe::ready() ) {
			NHM_Forms::error( 'pay_stripe', __( 'Online payment is not available at the moment.', 'neurohr-members' ) );
			return;
		}
		if ( ! self::refund_ok( 'pay_stripe' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- checked by the dispatcher.
		$renew = ! empty( $_POST['nhm_autorenew'] ) && NHM_Renew::can_offer( $inv );
		if ( NHM_Forms::limited( 'stripe', 20, 60 ) ) {
			NHM_Forms::error( 'pay_stripe', __( 'Too many tries. Please wait a few minutes.', 'neurohr-members' ) );
			return;
		}
		NHM_Forms::hit( 'stripe', 60 );
		$pid     = self::create( array( 'invoice_id' => $inv['id'], 'method' => 'stripe', 'amount' => $inv['total'], 'mode' => NHM_Stripe::mode(), 'user_id' => get_current_user_id() ? get_current_user_id() : $inv['user_id'] ) );
		$session = NHM_Stripe::checkout( $inv, $pid, $renew );
		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			self::update( $pid, array( 'status' => 'failed', 'admin_note' => is_wp_error( $session ) ? $session->get_error_message() : 'No checkout URL' ) );
			NHM_Forms::error( 'pay_stripe', __( 'The payment page could not be opened. Please try again, or choose another way to pay.', 'neurohr-members' ) );
			return;
		}
		self::update( $pid, array( 'stripe_session' => $session['id'] ) );
		wp_redirect( $session['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checkout.stripe.com
		exit;
	}

	/** Back from Stripe: ask Stripe directly (the webhook may come a little later). */
	public static function stripe_return() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['nhm_stripe'], $_GET['nhm_pay'] ) ) {
			return;
		}
		$inv = NHM_Invoices::by_token( sanitize_text_field( wp_unslash( $_GET['nhm_pay'] ) ) );
		if ( ! $inv ) {
			return;
		}
		$back = self::pay_url( $inv );
		if ( 'cancel' === $_GET['nhm_stripe'] ) {
			NHM_Forms::done( $back, 'pay_cancelled' );
		}
		$sid = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		// phpcs:enable
		$payment = NHM_Payments::by_session( $sid );
		if ( ! $payment || (int) $payment['invoice_id'] !== (int) $inv['id'] ) {
			NHM_Forms::done( $back );
		}
		$session = NHM_Stripe::session( $sid, $payment['mode'] ? $payment['mode'] : null );
		$state   = is_wp_error( $session ) ? 'processing' : NHM_Stripe::settle( $session, $payment['mode'] );
		// Someone who just signed up and paid goes straight in (once, within a day of signing up).
		if ( 'paid' === $state && ! is_user_logged_in() && $inv['user_id'] ) {
			$started = (int) get_user_meta( $inv['user_id'], 'nhm_signup_login', true );
			if ( $started && time() - $started < DAY_IN_SECONDS && ! NHM_Auth::pending_invoice( $inv['user_id'] ) ) {
				delete_user_meta( $inv['user_id'], 'nhm_signup_login' );
				wp_set_current_user( $inv['user_id'] );
				wp_set_auth_cookie( $inv['user_id'], true, is_ssl() );
				NHM_Forms::done( NHM_Settings::url( 'after_register', NHM_Auth::after_login_url() ), 'pay_done' );
			}
		}
		NHM_Forms::done( $back, 'paid' === $state ? 'pay_done' : 'pay_processing' );
	}

	public static function handle_proof() {
		$inv = self::posted_invoice( 'pay_proof' );
		if ( ! $inv ) {
			return;
		}
		$p      = NHM_Forms::posted();
		$method = isset( $p['nhm_method'] ) && in_array( $p['nhm_method'], array( 'bank', 'qr' ), true ) ? $p['nhm_method'] : '';
		if ( ! $method || ! isset( self::available()[ $method ] ) ) {
			NHM_Forms::error( 'pay_proof', __( 'Please choose how you paid.', 'neurohr-members' ) );
			return;
		}
		if ( NHM_Forms::limited( 'proof', 10, 60 ) ) {
			NHM_Forms::error( 'pay_proof', __( 'Too many tries. Please wait an hour or contact us.', 'neurohr-members' ) );
			return;
		}
		$payer  = isset( $p['nhm_payer'] ) ? sanitize_text_field( (string) $p['nhm_payer'] ) : '';
		$date   = NHM_Members::clean_date( isset( $p['nhm_paid_date'] ) ? sanitize_text_field( (string) $p['nhm_paid_date'] ) : '' );
		$time   = isset( $p['nhm_paid_time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $p['nhm_paid_time'] ) ? $p['nhm_paid_time'] : '';
		$amount = NHM_Levels::clean_amount( isset( $p['nhm_paid_amount'] ) ? (string) $p['nhm_paid_amount'] : '' );
		$ref    = isset( $p['nhm_reference'] ) ? mb_substr( sanitize_text_field( (string) $p['nhm_reference'] ), 0, 120 ) : '';
		$note   = isset( $p['nhm_pay_note'] ) ? mb_substr( sanitize_textarea_field( (string) $p['nhm_pay_note'] ), 0, 1000 ) : '';
		if ( '' === $payer ) {
			NHM_Forms::error( 'pay_proof', __( 'Enter who paid.', 'neurohr-members' ) );
		}
		if ( ! $date || $date > NHM_Settings::today() ) {
			NHM_Forms::error( 'pay_proof', __( 'Enter the date you paid.', 'neurohr-members' ) );
		}
		if ( (float) $amount <= 0 ) {
			NHM_Forms::error( 'pay_proof', __( 'Enter the amount you paid.', 'neurohr-members' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- checked in save_slip().
		$file = isset( $_FILES['nhm_slip'] ) && is_array( $_FILES['nhm_slip'] ) ? $_FILES['nhm_slip'] : null;
		self::refund_ok( 'pay_proof' );
		if ( NHM_Forms::has_errors( 'pay_proof' ) ) {
			return;
		}
		$slip = self::save_slip( $file );
		if ( is_wp_error( $slip ) ) {
			NHM_Forms::error( 'pay_proof', $slip->get_error_message() );
			return;
		}
		NHM_Forms::hit( 'proof', 60 );
		$pid = self::create(
			array(
				'invoice_id' => $inv['id'],
				'user_id'    => get_current_user_id() ? get_current_user_id() : $inv['user_id'],
				'method'     => $method,
				'status'     => 'submitted',
				'amount'     => $amount,
				'payer_name' => $payer,
				'paid_at'    => $date . ' ' . ( $time ? $time : '00:00' ) . ':00',
				'reference'  => $ref,
				'note'       => $note,
				'slip'       => $slip,
			)
		);
		NHM_Log::add( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'event' => 'proof', 'message' => $inv['number'] . ' · ' . self::methods()[ $method ] . ' · ' . NHM_Settings::money( $amount ) ) );
		self::email_proof( $inv, self::get( $pid ) );
		do_action( 'nhm_payment_submitted', $pid, $inv['id'] );
		NHM_Forms::done( self::pay_url( $inv ), 'proof_sent' );
	}

	/* ---------- Slips ---------- */

	public static function slip_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'nhm-private/slips/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$base = trailingslashit( $up['basedir'] ) . 'nhm-private/';
		foreach ( array( $base, $dir ) as $d ) {
			if ( is_dir( $d ) && ! file_exists( $d . '.htaccess' ) ) {
				file_put_contents( $d . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				file_put_contents( $d . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $dir;
	}

	/** Check and keep an uploaded slip. @return string file name | WP_Error */
	public static function save_slip( $f ) {
		if ( ! $f || empty( $f['tmp_name'] ) || ! empty( $f['error'] ) ) {
			$big = $f && in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new WP_Error( 'nhm_slip', $big ? __( 'The file is larger than 5 MB.', 'neurohr-members' ) : __( 'Please attach the payment slip or a screenshot.', 'neurohr-members' ) );
		}
		if ( (int) $f['size'] > self::MAX_SLIP ) {
			return new WP_Error( 'nhm_slip', __( 'The file is larger than 5 MB.', 'neurohr-members' ) );
		}
		$head = (string) file_get_contents( $f['tmp_name'], false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ext  = '';
		if ( 0 === strpos( $head, '%PDF' ) ) {
			$ext = 'pdf';
		} else {
			$info = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $info && IMAGETYPE_JPEG === $info[2] ) {
				$ext = 'jpg';
			} elseif ( $info && IMAGETYPE_PNG === $info[2] ) {
				$ext = 'png';
			}
		}
		if ( '' === $ext ) {
			return new WP_Error( 'nhm_slip', __( 'Please use a JPG, PNG or PDF file.', 'neurohr-members' ) );
		}
		$name = wp_date( 'Ym' ) . '-' . wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest = self::slip_dir() . $name;
		$ok   = apply_filters( 'nhm_move_upload', null, $f['tmp_name'], $dest );
		if ( null === $ok ) {
			$ok = @move_uploaded_file( $f['tmp_name'], $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! $ok ) {
			return new WP_Error( 'nhm_slip', __( 'The file could not be saved. Please try again or email it to us.', 'neurohr-members' ) );
		}
		return $name;
	}

	public static function slip_path( $payment ) {
		$name = basename( (string) $payment['slip'] );
		return '' === $name ? '' : self::slip_dir() . $name;
	}

	/* ---------- Review ---------- */

	/** @return true|WP_Error */
	public static function approve( $payment_id, $note = '' ) {
		$p   = self::get( $payment_id );
		$inv = $p ? NHM_Invoices::get( $p['invoice_id'] ) : null;
		if ( ! $p || ! $inv || 'submitted' !== $p['status'] ) {
			return new WP_Error( 'nhm_payment', __( 'This payment cannot be approved.', 'neurohr-members' ) );
		}
		self::update( $payment_id, array( 'status' => 'approved', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ), 'admin_note' => sanitize_textarea_field( $note ) ) );
		if ( 'unpaid' === $inv['status'] ) {
			$label = 'qr' === $p['method'] ? 'DuitNow QR' : 'Bank transfer';
			$r     = NHM_Invoices::mark_paid( $inv['id'], substr( (string) $p['paid_at'], 0, 10 ), $label, $p['reference'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function reject( $payment_id, $reason ) {
		$p   = self::get( $payment_id );
		$inv = $p ? NHM_Invoices::get( $p['invoice_id'] ) : null;
		if ( ! $p || ! $inv || 'submitted' !== $p['status'] ) {
			return new WP_Error( 'nhm_payment', __( 'This payment cannot be rejected.', 'neurohr-members' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			return new WP_Error( 'nhm_payment', __( 'Write the reason; the member sees it.', 'neurohr-members' ) );
		}
		self::update( $payment_id, array( 'status' => 'rejected', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ), 'admin_note' => $reason ) );
		NHM_Log::add( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'event' => 'proof_rejected', 'message' => $inv['number'] . ' · ' . $reason ) );
		NHM_Emails::send( 'proof_rejected', NHM_Invoices::email_to( $inv ), array_merge( NHM_Emails::invoice_placeholders( $inv ), array( '{reason}' => $reason ) ) );
		return true;
	}

	private static function email_proof( $inv, $p ) {
		$vars = array_merge(
			NHM_Emails::invoice_placeholders( $inv ),
			array(
				'{method}'        => self::methods()[ $p['method'] ],
				'{paid_amount}'   => NHM_Settings::money( $p['amount'] ),
				'{payer}'         => $p['payer_name'],
				'{paid_at}'       => mysql2date( 'j M Y, g:i a', $p['paid_at'] ),
				'{reference}'     => '' !== $p['reference'] ? $p['reference'] : '—',
				'{payments_url}'  => admin_url( 'admin.php?page=nhm-payments&status=submitted' ),
				'{payment_note}'  => '' !== $p['note'] ? $p['note'] : '—',
			)
		);
		NHM_Emails::send( 'proof_received', NHM_Invoices::email_to( $inv ), $vars );
		$path = self::slip_path( $p );
		NHM_Emails::send( 'admin_proof', '', $vars, $path && file_exists( $path ) ? array( $path ) : array() );
	}
}
