<?php
/**
 * Refunds.
 *
 * - A member asks within N hours (Settings, default 72) of paying a personal plan invoice, with a
 *   reason. Access to members-only content is paused at once (user meta nhm_hold).
 * - Staff see what the member opened and downloaded since paying (Knowledge Hub, filter
 *   nhm_member_usage) and approve (amount = paid minus the Stripe fee, editable) or reject.
 * - Card / FPX / GrabPay: Stripe refunds at once. Bank transfer / QR: staff pay back and then
 *   "Mark as refunded" with the date and reference.
 * - Any refund ends the plan and turns off auto-renew. Each refund gets a Refund Note (PDF) with
 *   its own number. Refunds made in the Stripe dashboard come in through the webhook
 *   (charge.refunded); a chargeback (charge.dispute.created) pauses access and emails staff.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Refunds {

	const HOLD = 'nhm_hold'; // array( 'why' => refund|dispute, 'ref' => id, 'since' => mysql )

	public static function init() {
		add_filter( 'nhm_form_handlers', array( __CLASS__, 'handlers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'member_pdf' ), 1 );
	}

	public static function handlers( $h ) {
		$h['refund_request'] = array( __CLASS__, 'handle_request' );
		return $h;
	}

	public static function table() {
		return NHM_DB::table( 'refunds' );
	}

	public static function reasons() {
		return array(
			'Not what I expected'                    => __( 'Not what I expected', 'neurohr-members' ),
			'Charged by mistake'                     => __( 'Charged by mistake', 'neurohr-members' ),
			"Auto-renewed but I didn't want it"      => __( "Auto-renewed but I didn't want it", 'neurohr-members' ),
			'Other'                                  => __( 'Other', 'neurohr-members' ),
		);
	}

	public static function statuses() {
		return array(
			'requested' => __( 'Waiting for review', 'neurohr-members' ),
			'approved'  => __( 'Approved, to pay back', 'neurohr-members' ),
			'refunded'  => __( 'Refunded', 'neurohr-members' ),
			'rejected'  => __( 'Not approved', 'neurohr-members' ),
		);
	}

	/* ---------- Records ---------- */

	private static function normalise( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'invoice_id', 'payment_id', 'user_id', 'company_id', 'decided_by' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		foreach ( array( 'paid_amount', 'amount' ) as $k ) {
			$row[ $k ] = (float) $row[ $k ];
		}
		$row['fee']   = null === $row['fee'] || '' === $row['fee'] ? null : (float) $row['fee'];
		$u            = json_decode( (string) $row['usage_info'], true );
		$row['usage'] = is_array( $u ) ? $u : null;
		foreach ( array( 'details', 'reject_reason', 'admin_note', 'refund_date', 'decided_at', 'number', 'stripe_refund', 'reference' ) as $k ) {
			$row[ $k ] = (string) $row[ $k ];
		}
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return self::normalise( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	public static function by_token( $token ) {
		global $wpdb;
		if ( strlen( (string) $token ) < 20 ) {
			return null;
		}
		$t = self::table();
		return self::normalise( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token = %s", (string) $token ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'status' => '', 'invoice_id' => 0, 'user_id' => 0, 'stripe_refund' => '', 'limit' => 100, 'offset' => 0 ), $args );
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['status'] ) {
			$list    = (array) $args['status'];
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $list );
		}
		foreach ( array( 'invoice_id', 'user_id' ) as $k ) {
			if ( $args[ $k ] ) {
				$where[] = "$k = %d";
				$vals[]  = (int) $args[ $k ];
			}
		}
		if ( '' !== $args['stripe_refund'] ) {
			$where[] = 'stripe_refund = %s';
			$vals[]  = $args['stripe_refund'];
		}
		$t      = self::table();
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'normalise' ), (array) $rows );
	}

	public static function count_open() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('requested','approved')" ); // phpcs:ignore WordPress.DB
	}

	private static function create( array $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array_merge(
				array( 'status' => 'requested', 'source' => 'member', 'token' => wp_generate_password( 32, false, false ), 'created_at' => $now, 'updated_at' => $now ),
				$d
			)
		);
		return (int) $wpdb->insert_id;
	}

	private static function update( $id, array $d ) {
		global $wpdb;
		$d['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $d, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The payment that paid an invoice (latest successful one). */
	public static function paying_payment( $inv ) {
		$rows = NHM_Payments::query( array( 'invoice_id' => $inv['id'], 'status' => array( 'succeeded', 'approved' ), 'limit' => 1 ) );
		return $rows ? $rows[0] : null;
	}

	/* ---------- Access pause ---------- */

	public static function on_hold( $user_id ) {
		$h = get_user_meta( (int) $user_id, self::HOLD, true );
		return is_array( $h ) && ! empty( $h['why'] ) ? $h : null;
	}

	public static function hold( $user_id, $why, $ref = 0 ) {
		update_user_meta( (int) $user_id, self::HOLD, array( 'why' => $why, 'ref' => (int) $ref, 'since' => current_time( 'mysql' ) ) );
		NHM_Members::flush( (int) $user_id );
	}

	public static function release( $user_id ) {
		delete_user_meta( (int) $user_id, self::HOLD );
		NHM_Members::flush( (int) $user_id );
	}

	/* ---------- Member side ---------- */

	/** When the refund window of a paid invoice ends ('' = no button). */
	public static function deadline( $inv ) {
		$hours = (int) NHM_Settings::get( 'refund_hours' );
		if ( $hours <= 0 || ! $inv || 'paid' !== $inv['status'] ) {
			return '';
		}
		$paid = '' !== $inv['paid_at'] ? $inv['paid_at'] : ( $inv['paid_date'] ? $inv['paid_date'] . ' 23:59:59' : '' );
		return $paid ? gmdate( 'Y-m-d H:i:s', strtotime( $paid ) + $hours * HOUR_IN_SECONDS ) : '';
	}

	/** May $user_id ask for a refund of this invoice now? */
	public static function can_request( $inv, $user_id ) {
		if ( ! $inv || 'paid' !== $inv['status'] || (int) $inv['user_id'] !== (int) $user_id || ! empty( $inv['company_id'] ) || (float) $inv['total'] <= 0 || $inv['refunded'] > 0 ) {
			return false;
		}
		$until = self::deadline( $inv );
		if ( '' === $until || current_time( 'mysql' ) > $until ) {
			return false;
		}
		return ! self::query( array( 'invoice_id' => $inv['id'], 'status' => array( 'requested', 'approved', 'refunded' ), 'limit' => 1 ) );
	}

	/** Usage since $since from other plugins (Knowledge Hub). */
	public static function usage( $user_id, $since ) {
		$u = apply_filters( 'nhm_member_usage', null, (int) $user_id, (string) $since );
		return is_array( $u ) ? array_merge( array( 'views' => 0, 'downloads' => 0, 'items' => array() ), $u ) : null;
	}

	public static function handle_request() {
		$uid = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the dispatcher.
		$inv     = NHM_Invoices::get( isset( $_POST['nhm_invoice'] ) ? absint( $_POST['nhm_invoice'] ) : 0 );
		$reason  = isset( $_POST['nhm_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_reason'] ) ) : '';
		$details = isset( $_POST['nhm_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nhm_details'] ) ) : '';
		// phpcs:enable
		if ( ! $uid || ! self::can_request( $inv, $uid ) ) {
			NHM_Forms::error( 'refund', __( 'A refund can no longer be requested for this payment. Please contact us.', 'neurohr-members' ) );
			return;
		}
		if ( ! isset( self::reasons()[ $reason ] ) ) {
			NHM_Forms::error( 'refund', __( 'Please choose a reason.', 'neurohr-members' ) );
			return;
		}
		if ( 'Other' === $reason && '' === trim( $details ) ) {
			NHM_Forms::error( 'refund', __( 'Please tell us a little more.', 'neurohr-members' ) );
			return;
		}
		$pay  = self::paying_payment( $inv );
		$id   = self::create(
			array(
				'invoice_id'  => $inv['id'],
				'payment_id'  => $pay ? $pay['id'] : 0,
				'user_id'     => $uid,
				'reason'      => $reason,
				'details'     => $details,
				'paid_amount' => $inv['total'],
				'amount'      => $inv['total'],
				'method'      => $pay && 'stripe' === $pay['method'] ? 'stripe' : 'manual',
				'usage_info'  => wp_json_encode( self::usage( $uid, '' !== $inv['paid_at'] ? $inv['paid_at'] : $inv['paid_date'] . ' 00:00:00' ) ),
			)
		);
		self::hold( $uid, 'refund', $id );
		NHM_Log::add( array( 'user_id' => $uid, 'event' => 'refund_request', 'message' => $inv['number'] . ' · ' . $reason ) );
		$r = self::get( $id );
		self::email( 'refund_received', $r );
		self::email( 'admin_refund_request', $r );
		NHM_Forms::done( NHM_Renew::account_url(), 'refund_requested' );
	}

	/** Small link + dialog on an invoice row in My account. */
	public static function invoice_actions( $inv, $user_id ) {
		$h    = '';
		$dlg  = '';
		$done = self::query( array( 'invoice_id' => $inv['id'], 'limit' => 5 ) );
		foreach ( $done as $r ) {
			if ( 'refunded' === $r['status'] && '' !== $r['number'] ) {
				/* translators: %s: Refund Note number */
				$h .= '<a class="nhm-btn nhm-btn--small nhm-btn--ghost" href="' . esc_url( self::member_pdf_url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( __( 'Refund Note %s', 'neurohr-members' ), $r['number'] ) ) . '</a>';
			} elseif ( in_array( $r['status'], array( 'requested', 'approved' ), true ) ) {
				$h .= '<span class="nhm-pill nhm-pill--wait">' . esc_html( self::statuses()[ $r['status'] ] ) . '</span>';
			}
		}
		if ( self::can_request( $inv, $user_id ) ) {
			$did = 'nhm-refund-' . (int) $inv['id'];
			$h  .= '<a href="#' . esc_attr( $did ) . '" class="nhm-btn nhm-btn--small nhm-btn--ghost" data-nhm-dialog="' . esc_attr( $did ) . '">' . esc_html__( 'Request a refund', 'neurohr-members' ) . '</a>';
			$dlg .= '<dialog class="nhm-modal" id="' . esc_attr( $did ) . '" aria-labelledby="' . esc_attr( $did ) . '-t"><div class="nhm-modal__box"><a href="#" class="nhm-modal__close" data-nhm-close aria-label="' . esc_attr__( 'Close', 'neurohr-members' ) . '">&times;</a>';
			/* translators: %s: invoice number */
			$dlg .= '<h3 class="nhm-modal__title" id="' . esc_attr( $did ) . '-t">' . esc_html( sprintf( __( 'Request a refund for %s', 'neurohr-members' ), $inv['number'] ) ) . '</h3>';
			/* translators: 1: date and time, 2: number of working days */
			$dlg .= '<p class="nhm-lead">' . esc_html( sprintf( __( 'You can ask until %1$s. While we review your request, access to members-only content is paused. If we approve it, the refund is completed within %2$d working days and your plan ends.', 'neurohr-members' ), mysql2date( 'j M Y, H:i', self::deadline( $inv ) ), (int) NHM_Settings::get( 'refund_days' ) ) ) . '</p>';
			$dlg .= NHM_Forms::open( 'refund_request' ) . '<input type="hidden" name="nhm_invoice" value="' . (int) $inv['id'] . '">';
			$dlg .= '<p class="nhm-field"><label for="' . esc_attr( $did ) . '-r">' . esc_html__( 'Reason', 'neurohr-members' ) . ' <span class="nhm-req">*</span></label><select id="' . esc_attr( $did ) . '-r" name="nhm_reason" class="nhm-input" required><option value="">' . esc_html__( 'Choose a reason', 'neurohr-members' ) . '</option>';
			foreach ( self::reasons() as $k => $label ) {
				$dlg .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
			}
			$dlg .= '</select></p><p class="nhm-field"><label for="' . esc_attr( $did ) . '-d">' . esc_html__( 'Tell us more', 'neurohr-members' ) . '</label><textarea id="' . esc_attr( $did ) . '-d" name="nhm_details" class="nhm-input" rows="3"></textarea></p>';
			$refund = NHM_Settings::legal_url( 'refund' );
			if ( $refund ) {
				$dlg .= '<p class="nhm-muted">' . sprintf( /* translators: %s: link */ esc_html__( 'See our %s.', 'neurohr-members' ), '<a href="' . esc_url( $refund ) . '" target="_blank" rel="noopener">' . esc_html__( 'Refund Policy', 'neurohr-members' ) . '</a>' ) . '</p>';
			}
			$dlg .= NHM_Forms::button( __( 'Send refund request', 'neurohr-members' ), 'nhm-btn nhm-btn--primary nhm-btn--block' ) . '</form></div></dialog>';
		}
		return array( $h, $dlg );
	}

	/* ---------- Staff decisions ---------- */

	/** Stripe's fee for the payment (asked once, then kept). */
	public static function fee( $r ) {
		if ( null !== $r['fee'] ) {
			return $r['fee'];
		}
		$pay = $r['payment_id'] ? NHM_Payments::get( $r['payment_id'] ) : null;
		$fee = 0.0;
		if ( $pay && 'stripe' === $pay['method'] && '' !== $pay['stripe_intent'] ) {
			$pi = NHM_Stripe::request( 'GET', 'payment_intents/' . rawurlencode( $pay['stripe_intent'] ), array( 'expand' => array( 'latest_charge.balance_transaction' ) ), $pay['mode'] ? $pay['mode'] : null );
			if ( is_wp_error( $pi ) ) {
				return null; // Ask again next time.
			}
			$fee = isset( $pi['latest_charge']['balance_transaction']['fee'] ) ? (float) $pi['latest_charge']['balance_transaction']['fee'] / 100 : 0.0;
		}
		self::update( $r['id'], array( 'fee' => $fee ) );
		return $fee;
	}

	/** Suggested amount: what was paid, less Stripe's fee. */
	public static function suggested( $r ) {
		$fee = self::fee( $r );
		return max( 0, round( $r['paid_amount'] - (float) $fee, 2 ) );
	}

	/** Working days after $from (Mon–Fri). */
	public static function complete_by( $r ) {
		$d = new DateTimeImmutable( substr( '' !== $r['decided_at'] ? $r['decided_at'] : $r['created_at'], 0, 10 ), wp_timezone() );
		$n = max( 1, (int) NHM_Settings::get( 'refund_days' ) );
		while ( $n > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) < 6 ) {
				$n--;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	/**
	 * Approve. Stripe payments are refunded at once; others wait for "Mark as refunded".
	 *
	 * @return true|WP_Error
	 */
	public static function approve( $id, $amount, $note = '' ) {
		$r   = self::get( $id );
		$inv = $r ? NHM_Invoices::get( $r['invoice_id'] ) : null;
		if ( ! $r || 'requested' !== $r['status'] || ! $inv ) {
			return new WP_Error( 'nhm_refund', __( 'This request was already handled.', 'neurohr-members' ) );
		}
		$amount = round( (float) $amount, 2 );
		$max    = round( (float) $inv['total'] - (float) $inv['refunded'], 2 );
		if ( $amount <= 0 || $amount > $max ) {
			/* translators: %s: amount */
			return new WP_Error( 'nhm_refund', sprintf( __( 'Enter an amount between RM 0.01 and %s.', 'neurohr-members' ), NHM_Settings::money( $max ) ) );
		}
		self::update( $id, array( 'amount' => $amount, 'admin_note' => $note, 'decided_by' => get_current_user_id(), 'decided_at' => current_time( 'mysql' ) ) );
		if ( 'stripe' === $r['method'] ) {
			$pay = NHM_Payments::get( $r['payment_id'] );
			if ( ! $pay || '' === $pay['stripe_intent'] ) {
				return new WP_Error( 'nhm_refund', __( 'The Stripe payment was not found. Refund it in Stripe; the website follows.', 'neurohr-members' ) );
			}
			$res = NHM_Stripe::request(
				'POST',
				'refunds',
				array( 'payment_intent' => $pay['stripe_intent'], 'amount' => NHM_Stripe::cents( $amount ), 'metadata' => array( 'refund_id' => (int) $id, 'site' => home_url( '/' ) ) ),
				$pay['mode'] ? $pay['mode'] : null,
				'nhm-refund-' . (int) $id . '-' . NHM_Stripe::cents( $amount )
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			self::finish( self::get( $id ), (string) $res['id'], NHM_Settings::today(), (string) $res['id'] );
			return true;
		}
		self::update( $id, array( 'status' => 'approved' ) );
		self::end_plan( self::get( $id ) );
		self::email( 'refund_approved', self::get( $id ) );
		NHM_Log::add( array( 'user_id' => $r['user_id'], 'event' => 'refund', 'message' => $inv['number'] . ' · approved ' . NHM_Settings::money( $amount ) ) );
		return true;
	}

	/** Bank transfer / QR refund paid back by staff. @return true|WP_Error */
	public static function mark_refunded( $id, $date, $reference ) {
		$r = self::get( $id );
		if ( ! $r || 'approved' !== $r['status'] ) {
			return new WP_Error( 'nhm_refund', __( 'This refund is not waiting to be paid back.', 'neurohr-members' ) );
		}
		$date = NHM_Members::clean_date( $date ) ? $date : NHM_Settings::today();
		self::finish( $r, '', $date, $reference );
		return true;
	}

	/** @return true|WP_Error */
	public static function reject( $id, $reason ) {
		$r = self::get( $id );
		if ( ! $r || 'requested' !== $r['status'] ) {
			return new WP_Error( 'nhm_refund', __( 'This request was already handled.', 'neurohr-members' ) );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new WP_Error( 'nhm_refund', __( 'Please give a reason; the member sees it.', 'neurohr-members' ) );
		}
		self::update( $id, array( 'status' => 'rejected', 'reject_reason' => $reason, 'decided_by' => get_current_user_id(), 'decided_at' => current_time( 'mysql' ) ) );
		$h = self::on_hold( $r['user_id'] );
		if ( $h && 'refund' === $h['why'] ) {
			self::release( $r['user_id'] );
		}
		NHM_Log::add( array( 'user_id' => $r['user_id'], 'event' => 'refund', 'message' => 'Refund request not approved: ' . $reason ) );
		self::email( 'refund_rejected', self::get( $id ) );
		return true;
	}

	/** The plan bought with this invoice ends now; auto-renew and the locked price go too. */
	private static function end_plan( $r ) {
		$inv = NHM_Invoices::get( $r['invoice_id'] );
		if ( ! $inv || ! $r['user_id'] ) {
			return;
		}
		NHM_Renew::turn_off( $r['user_id'], 'Refunded', 'system' );
		$p = NHM_Members::personal( $r['user_id'] );
		if ( $inv['level_id'] && (int) $p['level'] === (int) $inv['level_id'] && empty( $inv['company_id'] ) ) {
			NHM_Members::set_plan( $r['user_id'], NHM_Levels::base_id(), '', '', null, 'Refund ' . $inv['number'] );
		}
	}

	/** Paid back: number, invoice, plan, emails. */
	private static function finish( $r, $stripe_refund, $date, $reference ) {
		$inv    = NHM_Invoices::get( $r['invoice_id'] );
		$number = '' !== $r['number'] ? $r['number'] : NHM_Settings::next_number( (string) NHM_Settings::get( 'refund_format' ), 'nhm_refund_seq' );
		self::update(
			$r['id'],
			array(
				'status'        => 'refunded',
				'number'        => $number,
				'stripe_refund' => (string) $stripe_refund,
				'refund_date'   => $date,
				'reference'     => mb_substr( sanitize_text_field( (string) $reference ), 0, 120 ),
			)
		);
		if ( $inv ) {
			global $wpdb;
			$wpdb->update( NHM_Invoices::table(), array( 'refunded' => round( (float) $inv['refunded'] + (float) $r['amount'], 2 ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $inv['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$r = self::get( $r['id'] );
		self::end_plan( $r );
		$h = self::on_hold( $r['user_id'] );
		if ( $h && 'refund' === $h['why'] ) {
			self::release( $r['user_id'] );
		}
		NHM_Log::add( array( 'user_id' => $r['user_id'], 'company_id' => $r['company_id'], 'event' => 'refund', 'message' => $number . ' · ' . NHM_Settings::money( $r['amount'] ) . ( $inv ? ' · ' . $inv['number'] : '' ) ) );
		self::email( 'refund_done', $r, true );
		do_action( 'nhm_refunded', $r );
	}

	/* ---------- Stripe dashboard refunds and chargebacks ---------- */

	/** charge.refunded: a refund made in Stripe (or ours, already recorded). */
	public static function from_stripe( array $charge, $mode ) {
		$pi = isset( $charge['payment_intent'] ) ? (string) $charge['payment_intent'] : '';
		if ( '' === $pi ) {
			return;
		}
		global $wpdb;
		$pt  = NHM_Payments::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $pt WHERE stripe_intent = %s ORDER BY id DESC LIMIT 1", $pi ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$pay = $row ? NHM_Payments::get( $row['id'] ) : null;
		$inv = $pay ? NHM_Invoices::get( $pay['invoice_id'] ) : null;
		if ( ! $inv ) {
			return;
		}
		$total_refunded = isset( $charge['amount_refunded'] ) ? (float) $charge['amount_refunded'] / 100 : 0;
		$new            = round( $total_refunded - (float) $inv['refunded'], 2 );
		if ( $new < 0.01 ) {
			return; // Ours, already recorded.
		}
		$sid  = isset( $charge['refunds']['data'][0]['id'] ) ? (string) $charge['refunds']['data'][0]['id'] : '';
		$open = self::query( array( 'invoice_id' => $inv['id'], 'status' => array( 'requested', 'approved' ), 'limit' => 1 ) );
		if ( $open ) {
			$id = $open[0]['id'];
			self::update( $id, array( 'amount' => $new, 'method' => 'stripe', 'decided_at' => current_time( 'mysql' ), 'admin_note' => 'Refunded in the Stripe dashboard' ) );
		} else {
			$id = self::create(
				array(
					'invoice_id'  => $inv['id'],
					'payment_id'  => $pay['id'],
					'user_id'     => $inv['user_id'],
					'company_id'  => $inv['company_id'],
					'source'      => 'stripe',
					'status'      => 'approved',
					'reason'      => 'Refunded in Stripe',
					'paid_amount' => $inv['total'],
					'amount'      => $new,
					'method'      => 'stripe',
					'decided_at'  => current_time( 'mysql' ),
				)
			);
		}
		self::finish( self::get( $id ), $sid, NHM_Settings::today(), $sid );
	}

	/** charge.dispute.created: pause access and tell staff. */
	public static function dispute( array $dispute, $mode ) {
		$pi = isset( $dispute['payment_intent'] ) ? (string) $dispute['payment_intent'] : '';
		global $wpdb;
		$pt  = NHM_Payments::table();
		$row = '' !== $pi ? $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $pt WHERE stripe_intent = %s ORDER BY id DESC LIMIT 1", $pi ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
		$pay = $row ? NHM_Payments::get( $row['id'] ) : null;
		$inv = $pay ? NHM_Invoices::get( $pay['invoice_id'] ) : null;
		$uid = $inv ? (int) $inv['user_id'] : 0;
		if ( $uid ) {
			self::hold( $uid, 'dispute', $inv['id'] );
			NHM_Renew::turn_off( $uid, 'Chargeback', 'system' );
		}
		$user = $uid ? get_userdata( $uid ) : null;
		NHM_Log::add( array( 'user_id' => $uid, 'event' => 'dispute', 'message' => ( $inv ? $inv['number'] . ' · ' : '' ) . ( isset( $dispute['reason'] ) ? $dispute['reason'] : '' ) ) );
		NHM_Emails::send(
			'admin_dispute',
			'',
			array(
				'{name}'           => $user ? $user->display_name : '—',
				'{email}'          => $user ? $user->user_email : '—',
				'{invoice_number}' => $inv ? $inv['number'] : '—',
				'{amount}'         => NHM_Settings::money( isset( $dispute['amount'] ) ? (float) $dispute['amount'] / 100 : 0 ),
				'{reason}'         => isset( $dispute['reason'] ) ? (string) $dispute['reason'] : '',
				'{stripe_url}'     => 'https://dashboard.stripe.com/' . ( 'test' === $mode ? 'test/' : '' ) . 'disputes/' . rawurlencode( isset( $dispute['id'] ) ? $dispute['id'] : '' ),
				'{admin_url}'      => $uid ? admin_url( 'user-edit.php?user_id=' . $uid . '#nhm' ) : admin_url( 'admin.php?page=nhm-payments' ),
			)
		);
	}

	/* ---------- Emails and PDF ---------- */

	public static function email( $key, $r, $attach = false ) {
		$inv  = NHM_Invoices::get( $r['invoice_id'] );
		$user = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
		$vars = array(
			'{name}'           => $user ? $user->display_name : ( $inv && isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ),
			'{email}'          => $user ? $user->user_email : '',
			'{invoice_number}' => $inv ? $inv['number'] : '',
			'{invoice_total}'  => $inv ? NHM_Settings::money( $inv['total'] ) : '',
			'{amount}'         => NHM_Settings::money( $r['amount'] ),
			'{reason}'         => $r['reason'] . ( '' !== $r['details'] ? ': ' . $r['details'] : '' ),
			'{reject_reason}'  => $r['reject_reason'],
			'{refund_number}'  => $r['number'],
			'{refund_date}'    => NHM_Emails::format_date( $r['refund_date'] ),
			'{refund_days}'    => (string) (int) NHM_Settings::get( 'refund_days' ),
			'{complete_by}'    => NHM_Emails::format_date( self::complete_by( $r ) ),
			'{refunds_url}'    => admin_url( 'admin.php?page=nhm-payments&status=refunds' ),
			'{level}'          => $inv && $inv['level_id'] ? NHM_Levels::name( $inv['level_id'] ) : '',
		);
		$att  = array();
		$file = '';
		if ( $attach && '' !== $r['number'] ) {
			$file = self::pdf_file( $r );
			$att  = is_wp_error( $file ) ? array() : array( $file );
		}
		$to = $user ? NHM_Members::contact_email( $user ) : ( $inv ? NHM_Invoices::email_to( $inv ) : '' );
		NHM_Emails::send( $key, $to, $vars, $att );
		if ( $file && ! is_wp_error( $file ) ) {
			NHM_PDF::cleanup( $file );
		}
	}

	public static function pdf_file( $r ) {
		$bytes = NHM_PDF::render( NHM_PDF::refund_html( $r ) );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$sub  = NHM_PDF::temp_dir() . '/' . wp_generate_password( 16, false, false );
		wp_mkdir_p( $sub );
		$path = $sub . '/' . sanitize_file_name( $r['number'] ) . '.pdf';
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	public static function member_pdf_url( $r ) {
		return add_query_arg( array( 'nhm_refund_pdf' => $r['token'] ), home_url( '/' ) );
	}

	/** ?nhm_refund_pdf=token: the Refund Note PDF (secret link, like invoices). */
	public static function member_pdf() {
		// phpcs:ignore WordPress.Security.NonceVerification -- the token is the key.
		if ( empty( $_GET['nhm_refund_pdf'] ) ) {
			return;
		}
		$r = self::by_token( sanitize_text_field( wp_unslash( $_GET['nhm_refund_pdf'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $r || 'refunded' !== $r['status'] ) {
			wp_die( esc_html__( 'This document was not found.', 'neurohr-members' ), 404 );
		}
		self::send_pdf( $r );
	}

	public static function send_pdf( $r ) {
		$bytes = NHM_PDF::render( NHM_PDF::refund_html( $r ) );
		if ( is_wp_error( $bytes ) ) {
			wp_die( esc_html( $bytes->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $r['number'] ) . '.pdf"' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ---------- Reports ---------- */

	/** Received, refunded and net for a year (by payment / refund date). */
	public static function totals( $year ) {
		global $wpdb;
		$it  = NHM_Invoices::table();
		$rt  = self::table();
		$in  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total) FROM $it WHERE status = 'paid' AND paid_date >= %s AND paid_date <= %s", $year . '-01-01', $year . '-12-31' ) ); // phpcs:ignore WordPress.DB
		$out = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM $rt WHERE status = 'refunded' AND refund_date >= %s AND refund_date <= %s", $year . '-01-01', $year . '-12-31' ) ); // phpcs:ignore WordPress.DB
		return array( 'received' => $in, 'refunded' => $out, 'net' => $in - $out );
	}
}
