<?php
/**
 * Invoices. One document per sale: Unpaid → Paid (shows PAID with date and reference) or Void.
 *
 * An invoice can carry a plan ("level_id" + "grant_to" user|company): when it is marked paid,
 * the plan is switched on with the level's billing term. Paid signups and upgrade requests
 * create such an invoice automatically; you can also write any invoice by hand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Invoices {

	public static function table() {
		return NHM_DB::table( 'invoices' );
	}

	public static function statuses() {
		return array(
			'unpaid' => __( 'Unpaid', 'neurohr-members' ),
			'paid'   => __( 'Paid', 'neurohr-members' ),
			'void'   => __( 'Void', 'neurohr-members' ),
		);
	}

	public static function methods() {
		return array( 'Bank transfer', 'DuitNow', 'FPX', 'Cheque', 'Cash', 'Card', 'Other' );
	}

	private static function normalise( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'user_id', 'company_id', 'level_id', 'granted', 'created_by' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['terms']         = isset( $row['terms'] ) ? max( 1, (int) $row['terms'] ) : 1;
		$row['tax_inclusive'] = ! empty( $row['tax_inclusive'] ) ? 1 : 0;
		foreach ( array( 'period_start', 'period_end' ) as $k ) {
			$row[ $k ] = ( ! empty( $row[ $k ] ) && '0000-00-00' !== $row[ $k ] ) ? $row[ $k ] : '';
		}
		$row['refunded'] = isset( $row['refunded'] ) ? (float) $row['refunded'] : 0.0;
		$row['paid_at']  = isset( $row['paid_at'] ) ? (string) $row['paid_at'] : '';
		foreach ( array( 'subtotal', 'discount', 'tax_rate', 'tax', 'total' ) as $k ) {
			$row[ $k ] = (float) $row[ $k ];
		}
		foreach ( array( 'issue_date', 'due_date', 'paid_date' ) as $k ) {
			$row[ $k ] = ( $row[ $k ] && '0000-00-00' !== $row[ $k ] ) ? $row[ $k ] : '';
		}
		$items          = json_decode( (string) $row['items'], true );
		$bill           = json_decode( (string) $row['bill_to'], true );
		$row['items']   = is_array( $items ) ? $items : array();
		$row['bill_to'] = is_array( $bill ) ? $bill : array();
		$row['notes']   = (string) $row['notes'];
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return self::normalise( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/** Invoice by its secret token (pay links). */
	public static function by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$table = self::table();
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB
		return $id ? self::get( $id ) : null;
	}

	/**
	 * @param array $args status, user_id, company_id, search, level_id, pending (bool), limit, offset, for_user.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'status' => '', 'user_id' => 0, 'company_id' => 0, 'search' => '', 'level_id' => 0, 'pending' => false, 'limit' => 200, 'offset' => 0, 'for_user' => 0 ), $args );
		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['status'] ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( $args['user_id'] ) {
			$where[] = 'user_id = %d';
			$vals[]  = (int) $args['user_id'];
		}
		if ( $args['company_id'] ) {
			$where[] = 'company_id = %d';
			$vals[]  = (int) $args['company_id'];
		}
		if ( $args['for_user'] ) {
			// The person's own invoices + invoices of the company they own.
			$owned = array();
			foreach ( NHM_Companies::query( array( 'limit' => 1000 ) ) as $c ) {
				if ( (int) $c['owner_id'] === (int) $args['for_user'] ) {
					$owned[] = (int) $c['id'];
				}
			}
			$where[] = $owned ? '(user_id = %d OR company_id IN (' . implode( ',', $owned ) . '))' : 'user_id = %d';
			$vals[]  = (int) $args['for_user'];
		}
		if ( $args['level_id'] ) {
			$where[] = 'level_id = %d';
			$vals[]  = (int) $args['level_id'];
		}
		if ( $args['pending'] ) {
			$where[] = "status = 'unpaid' AND level_id > 0 AND granted = 0";
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(number LIKE %s OR bill_to LIKE %s)';
			array_push( $vals, $like, $like );
		}
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'normalise' ), (array) $rows );
	}

	/** Count and total per status (for tabs and the dashboard). */
	public static function summary( $year = '' ) {
		global $wpdb;
		$table = self::table();
		$where = $year ? $wpdb->prepare( 'WHERE issue_date >= %s AND issue_date <= %s', $year . '-01-01', $year . '-12-31' ) : '';
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n, SUM(total) AS amount FROM $table $where GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( array_keys( self::statuses() ) as $s ) {
			$out[ $s ] = array( 'n' => 0, 'amount' => 0.0 );
		}
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = array( 'n' => (int) $r['n'], 'amount' => (float) $r['amount'] );
		}
		return $out;
	}

	/* ---------- Bill to ---------- */

	/** Billing details of a person (My account → Billing details, else their profile). */
	public static function user_bill_to( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$m       = function ( $k ) use ( $user_id ) {
			return trim( (string) get_user_meta( $user_id, $k, true ) );
		};
		$address = array_filter( array_map( 'trim', explode( "\n", $m( 'nhm_bill_address' ) ) ) );
		return array(
			'name'      => $m( 'nhm_bill_company' ) ? $m( 'nhm_bill_company' ) : ( $m( 'nhm_bill_name' ) ? $m( 'nhm_bill_name' ) : $user->display_name ),
			'attention' => $m( 'nhm_bill_company' ) ? ( $m( 'nhm_bill_name' ) ? $m( 'nhm_bill_name' ) : $user->display_name ) : '',
			'reg_no'    => $m( 'nhm_bill_reg' ),
			'tin'       => $m( 'nhm_bill_tin' ),
			'sst_no'    => '',
			'address'   => array_values( $address ),
			'email'     => $m( 'nhm_bill_email' ) ? $m( 'nhm_bill_email' ) : NHM_Members::contact_email( $user ),
			'phone'     => NHM_Fields::format_phone( $m( 'nhm_phone' ) ),
			'code'      => '',
		);
	}

	public static function bill_to_for( $user_id, $company_id ) {
		if ( $company_id ) {
			$c = NHM_Companies::get( $company_id );
			if ( $c ) {
				return NHM_Companies::bill_to( $c );
			}
		}
		return self::user_bill_to( $user_id );
	}

	/** Where to email this invoice. */
	public static function email_to( $inv ) {
		if ( ! empty( $inv['bill_to']['email'] ) && is_email( $inv['bill_to']['email'] ) ) {
			return $inv['bill_to']['email'];
		}
		$user = $inv['user_id'] ? get_userdata( $inv['user_id'] ) : null;
		return $user ? NHM_Members::contact_email( $user ) : '';
	}

	/* ---------- Writing ---------- */

	/**
	 * Items → totals. Items: description, qty, price.
	 * $inclusive: prices already include SST (the tax is the part of the total that is SST).
	 */
	public static function totals( array $items, $discount, $tax_rate, $inclusive = false ) {
		$clean    = array();
		$subtotal = 0.0;
		foreach ( $items as $it ) {
			$desc = isset( $it['description'] ) ? sanitize_text_field( $it['description'] ) : '';
			$qty  = isset( $it['qty'] ) ? (float) $it['qty'] : 1;
			$unit = isset( $it['price'] ) ? (float) preg_replace( '/[^0-9.\-]/', '', (string) $it['price'] ) : 0;
			if ( '' === $desc && ! $unit ) {
				continue;
			}
			$qty      = $qty > 0 ? $qty : 1;
			$line     = round( $qty * $unit, 2 );
			$subtotal += $line;
			$clean[]  = array( 'description' => $desc, 'qty' => $qty, 'price' => round( $unit, 2 ), 'amount' => $line );
		}
		$discount = max( 0, min( $subtotal, round( (float) $discount, 2 ) ) );
		$taxable  = $subtotal - $discount;
		if ( $inclusive && $tax_rate > 0 ) {
			$tax   = round( $taxable * $tax_rate / ( 100 + $tax_rate ), 2 );
			$total = round( $taxable, 2 );
		} else {
			$tax   = $tax_rate > 0 ? round( $taxable * $tax_rate / 100, 2 ) : 0.0;
			$total = round( $taxable + $tax, 2 );
		}
		return array(
			'items'         => $clean,
			'subtotal'      => round( $subtotal, 2 ),
			'discount'      => $discount,
			'tax_rate'      => (float) $tax_rate,
			'tax'           => $tax,
			'total'         => $total,
			'tax_inclusive' => $inclusive && $tax_rate > 0 ? 1 : 0,
		);
	}

	public static function default_inclusive() {
		return NHM_Settings::get( 'sst_enabled' ) && NHM_Settings::get( 'sst_inclusive' );
	}

	public static function default_tax_rate() {
		return NHM_Settings::get( 'sst_enabled' ) ? (float) NHM_Settings::get( 'sst_rate' ) : 0.0;
	}

	public static function due_date( $from = '' ) {
		$days = max( 0, (int) NHM_Settings::get( 'invoice_due_days' ) );
		$from = $from ? $from : NHM_Settings::today();
		return DateTimeImmutable::createFromFormat( '!Y-m-d', $from )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * @param array $d user_id, company_id, items, discount, tax_rate (null = settings), issue_date,
	 *                 due_date, notes, level_id, grant_to, kind.
	 * @return int|WP_Error
	 */
	public static function create( array $d ) {
		global $wpdb;
		$d = array_merge(
			array( 'user_id' => 0, 'company_id' => 0, 'items' => array(), 'discount' => 0, 'tax_rate' => null, 'tax_inclusive' => null, 'issue_date' => '', 'due_date' => '', 'notes' => '', 'level_id' => 0, 'grant_to' => '', 'kind' => 'manual', 'terms' => 1 ),
			$d
		);
		if ( ! $d['user_id'] && ! $d['company_id'] ) {
			return new WP_Error( 'nhm_invoice', __( 'Choose who the invoice is for.', 'neurohr-members' ) );
		}
		$t = self::totals( (array) $d['items'], $d['discount'], null === $d['tax_rate'] ? self::default_tax_rate() : (float) $d['tax_rate'], null === $d['tax_inclusive'] ? self::default_inclusive() : (bool) $d['tax_inclusive'] );
		if ( ! $t['items'] ) {
			return new WP_Error( 'nhm_invoice', __( 'Add at least one line.', 'neurohr-members' ) );
		}
		$issue = NHM_Members::clean_date( $d['issue_date'] ) ? $d['issue_date'] : NHM_Settings::today();
		$level = NHM_Levels::get( $d['level_id'] ) ? (int) $d['level_id'] : 0;
		$row   = array(
			'number'     => NHM_Settings::next_number( (string) NHM_Settings::get( 'invoice_format' ), 'nhm_invoice_seq' ),
			'kind'       => sanitize_key( $d['kind'] ),
			'user_id'    => (int) $d['user_id'],
			'company_id' => (int) $d['company_id'],
			'status'     => 'unpaid',
			'issue_date' => $issue,
			'due_date'   => NHM_Members::clean_date( $d['due_date'] ) ? $d['due_date'] : self::due_date( $issue ),
			'currency'   => (string) NHM_Settings::get( 'currency' ),
			'subtotal'   => $t['subtotal'],
			'discount'   => $t['discount'],
			'tax_rate'   => $t['tax_rate'],
			'tax'        => $t['tax'],
			'total'      => $t['total'],
			'tax_inclusive' => $t['tax_inclusive'],
			'terms'      => max( 1, min( 10, (int) $d['terms'] ) ),
			'items'      => wp_json_encode( $t['items'] ),
			'bill_to'    => wp_json_encode( self::bill_to_for( $d['user_id'], $d['company_id'] ) ),
			'level_id'   => $level,
			'grant_to'   => $level ? ( 'company' === $d['grant_to'] && $d['company_id'] ? 'company' : 'user' ) : '',
			'notes'      => sanitize_textarea_field( $d['notes'] ),
			'token'      => wp_generate_password( 32, false, false ),
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'nhm_invoice', __( 'The invoice could not be saved.', 'neurohr-members' ) );
		}
		NHM_Log::add( array( 'user_id' => $row['user_id'], 'company_id' => $row['company_id'], 'event' => 'invoice', 'level_to' => $level, 'message' => $row['number'] . ' · ' . NHM_Settings::money( $row['total'] ) ) );
		do_action( 'nhm_invoice_created', $id );
		return $id;
	}

	/** Edit an unpaid invoice (lines, dates, notes, plan). Paid / void: only notes. */
	public static function update( $id, array $d ) {
		global $wpdb;
		$inv = self::get( $id );
		if ( ! $inv ) {
			return new WP_Error( 'nhm_invoice', __( 'Invoice not found.', 'neurohr-members' ) );
		}
		$row = array( 'updated_at' => current_time( 'mysql' ) );
		if ( array_key_exists( 'notes', $d ) ) {
			$row['notes'] = sanitize_textarea_field( $d['notes'] );
		}
		if ( 'unpaid' === $inv['status'] ) {
			if ( array_key_exists( 'items', $d ) ) {
				$t = self::totals( (array) $d['items'], isset( $d['discount'] ) ? $d['discount'] : $inv['discount'], isset( $d['tax_rate'] ) ? (float) $d['tax_rate'] : $inv['tax_rate'], isset( $d['tax_inclusive'] ) ? (bool) $d['tax_inclusive'] : (bool) $inv['tax_inclusive'] );
				if ( ! $t['items'] ) {
					return new WP_Error( 'nhm_invoice', __( 'Add at least one line.', 'neurohr-members' ) );
				}
				$row = array_merge( $row, array( 'items' => wp_json_encode( $t['items'] ), 'subtotal' => $t['subtotal'], 'discount' => $t['discount'], 'tax_rate' => $t['tax_rate'], 'tax' => $t['tax'], 'total' => $t['total'], 'tax_inclusive' => $t['tax_inclusive'] ) );
			}
			if ( isset( $d['terms'] ) ) {
				$row['terms'] = max( 1, min( 10, (int) $d['terms'] ) );
			}
			foreach ( array( 'issue_date', 'due_date' ) as $k ) {
				if ( isset( $d[ $k ] ) && NHM_Members::clean_date( $d[ $k ] ) ) {
					$row[ $k ] = $d[ $k ];
				}
			}
			if ( array_key_exists( 'user_id', $d ) || array_key_exists( 'company_id', $d ) ) {
				$user    = array_key_exists( 'user_id', $d ) ? (int) $d['user_id'] : $inv['user_id'];
				$company = array_key_exists( 'company_id', $d ) ? (int) $d['company_id'] : $inv['company_id'];
				if ( $user || $company ) {
					$row['user_id']    = $user;
					$row['company_id'] = $company;
					$row['bill_to']    = wp_json_encode( self::bill_to_for( $user, $company ) );
				}
			}
			if ( ! empty( $d['refresh_bill_to'] ) && ! isset( $row['bill_to'] ) ) {
				$row['bill_to'] = wp_json_encode( self::bill_to_for( $inv['user_id'], $inv['company_id'] ) );
			}
			if ( array_key_exists( 'level_id', $d ) ) {
				$level           = NHM_Levels::get( $d['level_id'] ) ? (int) $d['level_id'] : 0;
				$company         = isset( $row['company_id'] ) ? $row['company_id'] : $inv['company_id'];
				$row['level_id'] = $level;
				$row['grant_to'] = $level ? ( isset( $d['grant_to'] ) && 'company' === $d['grant_to'] && $company ? 'company' : 'user' ) : '';
			}
		}
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Mark paid; switches on the plan the invoice carries; emails the paid invoice.
	 *
	 * @return true|WP_Error
	 */
	public static function mark_paid( $id, $date = '', $method = '', $ref = '', $send_email = true ) {
		global $wpdb;
		$inv = self::get( $id );
		if ( ! $inv || 'void' === $inv['status'] ) {
			return new WP_Error( 'nhm_invoice', __( 'This invoice cannot be marked paid.', 'neurohr-members' ) );
		}
		$date = NHM_Members::clean_date( $date ) ? $date : NHM_Settings::today();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'         => 'paid',
				'paid_date'      => $date,
				'paid_at'        => current_time( 'mysql' ),
				'payment_method' => mb_substr( sanitize_text_field( $method ), 0, 60 ),
				'payment_ref'    => mb_substr( sanitize_text_field( $ref ), 0, 120 ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		NHM_Log::add( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'event' => 'paid', 'message' => $inv['number'] . ' · ' . NHM_Settings::money( $inv['total'] ) ) );
		$granted = self::grant( $id );
		if ( $send_email ) {
			NHM_Emails::invoice_email( 'payment', self::get( $id ) );
		}
		do_action( 'nhm_invoice_paid', (int) $id, $granted );
		return true;
	}

	/**
	 * [start, last day] that $terms terms of $level_id would give, bought today, for a person
	 * or a company with its current plan (early renewal continues from the current last day).
	 */
	public static function plan_period( $level_id, $terms, $user_id = 0, $company_id = 0 ) {
		if ( $company_id ) {
			$c       = NHM_Companies::get( $company_id );
			$current = $c && NHM_Companies::is_active( $c ) ? $c['level_id'] : 0;
			$expiry  = $c ? $c['expiry_date'] : '';
		} else {
			$p       = $user_id ? NHM_Members::personal( $user_id ) : array( 'level' => 0, 'expiry' => '' );
			$current = NHM_Members::date_active( $p['expiry'] ) ? $p['level'] : 0;
			$expiry  = $p['expiry'];
		}
		list( $start, $end ) = NHM_Levels::renewal_dates( $level_id, $current, $expiry );
		$first = $start ? $start : DateTimeImmutable::createFromFormat( '!Y-m-d', $expiry )->modify( '+1 day' )->format( 'Y-m-d' );
		for ( $i = 1; $i < max( 1, (int) $terms ) && $end; $i++ ) {
			$end = NHM_Levels::term_end( $level_id, $end );
		}
		return array( $first, $end, $start );
	}

	/** Switch on the plan of a paid invoice (once). Returns the new last day or false. */
	public static function grant( $id ) {
		global $wpdb;
		$inv = self::get( $id );
		if ( ! $inv || 'paid' !== $inv['status'] || ! $inv['level_id'] || $inv['granted'] || ! NHM_Levels::get( $inv['level_id'] ) ) {
			return false;
		}
		$level = (int) $inv['level_id'];
		if ( 'company' === $inv['grant_to'] && $inv['company_id'] ) {
			$c = NHM_Companies::get( $inv['company_id'] );
			if ( ! $c ) {
				return false;
			}
			list( $first, $end, $start ) = self::plan_period( $level, $inv['terms'], 0, $c['id'] );
			$data = array( 'level_id' => $level, 'expiry_date' => $end );
			if ( $start ) {
				$data['start_date'] = $start;
			}
			NHM_Companies::update( $c['id'], $data );
		} elseif ( $inv['user_id'] ) {
			$p = NHM_Members::personal( $inv['user_id'] );
			list( $first, $end, $start ) = self::plan_period( $level, $inv['terms'], $inv['user_id'], 0 );
			// Auto-renew: the new term follows the old last day (also after a late second try).
			if ( 'autorenew' === $inv['kind'] && (int) $p['level'] === $level && $p['expiry'] && $p['expiry'] >= wp_date( 'Y-m-d', strtotime( NHM_Settings::today() . ' 12:00:00 -10 days' ) ) ) {
				$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $p['expiry'] )->modify( '+1 day' )->format( 'Y-m-d' );
				$end   = NHM_Levels::term_end( $level, $p['expiry'] );
				$start = '';
			}
			NHM_Members::set_plan( $inv['user_id'], $level, $start ? $start : $p['start'], $end, null, sprintf( 'Invoice %s paid', $inv['number'] ) );
		} else {
			return false;
		}
		$wpdb->update( self::table(), array( 'granted' => 1, 'period_start' => $first, 'period_end' => $end ? $end : null ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $end;
	}

	public static function void( $id ) {
		global $wpdb;
		$inv = self::get( $id );
		if ( ! $inv ) {
			return;
		}
		$wpdb->update( self::table(), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		NHM_Log::add( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'event' => 'void', 'message' => $inv['number'] ) );
	}

	/* ---------- Plans ---------- */

	/** Brand used in invoice lines: Settings → Business info → Product name, else the site name. */
	public static function brand() {
		$brand = trim( (string) NHM_Settings::get( 'biz_product' ) );
		return wp_specialchars_decode( '' !== $brand ? $brand : (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** "NeuroHR - Lite Membership (1 year)", with the dates when known. */
	public static function plan_line( $level_id, $start = '', $end = '', $terms = 1 ) {
		$name = trim( (string) NHM_Levels::name( $level_id ) );
		/* translators: 1: brand, 2: plan name */
		$text = sprintf( __( '%1$s - %2$s Membership', 'neurohr-members' ), self::brand(), $name );
		$bits = array();
		$term = NHM_Levels::duration_label( $level_id, $terms );
		if ( $term ) {
			$bits[] = $term;
		}
		if ( $start && $end ) {
			$bits[] = NHM_Emails::format_date( $start ) . ' to ' . NHM_Emails::format_date( $end );
		}
		return $bits ? $text . ' (' . implode( ', ', $bits ) . ')' : $text;
	}

	/**
	 * Invoice for a plan, for a person (or their company when $company_id is set). Re-uses an
	 * open invoice for the same plan instead of making a second one.
	 *
	 * @return int|WP_Error
	 */
	public static function for_plan( $user_id, $level_id, $kind = 'signup', $company_id = 0 ) {
		$level = NHM_Levels::get( $level_id );
		if ( ! $level || (float) $level['amount'] <= 0 ) {
			return new WP_Error( 'nhm_invoice', __( 'This plan has no price.', 'neurohr-members' ) );
		}
		$open = self::query( array( 'pending' => true, 'level_id' => $level_id, $company_id ? 'company_id' : 'user_id' => $company_id ? $company_id : $user_id, 'limit' => 1 ) );
		if ( $open ) {
			return (int) $open[0]['id'];
		}
		$days = max( 1, (int) NHM_Settings::get( 'online_due_days' ) );
		return self::create(
			array(
				// Sign-ups and upgrades: a short due date (they are cancelled the day after it).
				'due_date'   => in_array( $kind, array( 'signup', 'upgrade' ), true ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', NHM_Settings::today() )->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) : '',
				'user_id'    => $company_id ? 0 : $user_id,
				'company_id' => (int) $company_id,
				'items'      => array( array( 'description' => self::plan_line( $level_id ), 'qty' => 1, 'price' => $level['amount'] ) ),
				'level_id'   => $level_id,
				'grant_to'   => $company_id ? 'company' : 'user',
				'kind'       => $kind,
			)
		);
	}

	/** Open plan invoices of a person (and of the company they own). */
	public static function pending_for( $user_id ) {
		return array_values( array_filter( self::query( array( 'for_user' => $user_id, 'status' => 'unpaid', 'limit' => 50 ) ), function ( $i ) { return $i['level_id'] && ! $i['granted']; } ) );
	}

	/* ---------- Access and links ---------- */

	public static function can_view( $inv, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $inv || ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, NHM_CAP ) ) {
			return true;
		}
		if ( $inv['user_id'] && (int) $inv['user_id'] === $user_id ) {
			return true;
		}
		if ( $inv['company_id'] ) {
			$c = NHM_Companies::get( $inv['company_id'] );
			return $c && (int) $c['owner_id'] === $user_id;
		}
		return false;
	}

	/** Download link for members (needs login as the person / company owner). */
	public static function download_url( $inv ) {
		return add_query_arg( array( 'nhm_invoice' => (int) $inv['id'], 'key' => $inv['token'] ), home_url( '/' ) );
	}

	public static function admin_pdf_url( $inv ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=nhm_invoice_pdf&id=' . (int) $inv['id'] ), 'nhm_invoice_pdf' );
	}

	public static function edit_url( $id ) {
		return admin_url( 'admin.php?page=nhm-invoices&action=edit&id=' . (int) $id );
	}

	public static function status_label( $inv ) {
		if ( 'unpaid' === $inv['status'] && class_exists( 'NHM_Payments' ) && NHM_Payments::waiting_for( $inv['id'] ) ) {
			return __( 'Checking payment', 'neurohr-members' );
		}
		if ( 'unpaid' === $inv['status'] && $inv['due_date'] && $inv['due_date'] < NHM_Settings::today() ) {
			return __( 'Overdue', 'neurohr-members' );
		}
		if ( 'paid' === $inv['status'] && ! empty( $inv['refunded'] ) && $inv['refunded'] > 0 ) {
			return $inv['refunded'] + 0.005 >= (float) $inv['total'] ? __( 'Refunded', 'neurohr-members' ) : __( 'Partly refunded', 'neurohr-members' );
		}
		$s = self::statuses();
		return isset( $s[ $inv['status'] ] ) ? $s[ $inv['status'] ] : $inv['status'];
	}
}
