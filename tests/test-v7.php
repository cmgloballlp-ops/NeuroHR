<?php
// v0.4.3 phase 2: refunds, Refund Notes, Stripe sync, chargebacks, reports.
require __DIR__ . '/stubs-front.php';
require __DIR__ . '/stub-extra.php';
$GLOBALS['stripe_responses'] = array();
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $u, $a ) {
		$GLOBALS['remote'][] = array( $u, $a );
		foreach ( $GLOBALS['stripe_responses'] as $needle => $resp ) {
			if ( false !== strpos( $u, $needle ) ) {
				return array( 'response' => array( 'code' => $resp[0] ), 'body' => json_encode( $resp[1] ) );
			}
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}
if ( ! function_exists( 'wp_redirect' ) ) { function wp_redirect( $u ) { throw new Redirect( $u ); } }
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $l ) { return false; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a ) { return 'NONCE'; } }
if ( ! function_exists( 'get_post_status' ) ) { function get_post_status( $id ) { return 'publish'; } }
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }
class FakeRequest { public $body; public $sig; function __construct( $b, $s ) { $this->body = $b; $this->sig = $s; } function get_body() { return $this->body; } function get_header( $h ) { return 'stripe_signature' === $h ? $this->sig : ''; } }

$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
function form_errors( $f ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $e = $rp->getValue(); return isset( $e[ $f ] ) ? implode( ' ', $e[ $f ] ) : ''; }
function subjects() { return array_column( $GLOBALS['mails'], 's' ); }
function hook( $payload ) { $t = time(); return NHM_Stripe::webhook( new FakeRequest( $payload, 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_t' ) ) ); }
function paid_by_stripe( $uid, $level, $pi ) {
	$inv = NHM_Invoices::get( NHM_Invoices::for_plan( $uid, $level, 'signup' ) );
	$pid = NHM_Payments::create( array( 'invoice_id' => $inv['id'], 'method' => 'stripe', 'amount' => $inv['total'], 'mode' => 'test', 'user_id' => $uid ) );
	NHM_Payments::update( $pid, array( 'stripe_session' => 'cs_' . $pi ) );
	NHM_Stripe::settle( array( 'id' => 'cs_' . $pi, 'payment_status' => 'paid', 'amount_total' => NHM_Stripe::cents( $inv['total'] ), 'metadata' => array(), 'payment_intent' => array( 'id' => $pi, 'payment_method' => array( 'id' => 'pm_x', 'type' => 'card', 'card' => array( 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 1, 'exp_year' => 2030 ) ) ) ), 'test' );
	return NHM_Invoices::get( $inv['id'] );
}

$_SERVER['REQUEST_URI'] = '/my-account/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.6';
NHM_Settings::update( array( 'page_login' => 1, 'page_account' => 4, 'biz_name' => 'CM HORIZON CONSULTANCY PLT', 'biz_product' => 'NeuroHR', 'bank_name' => 'Maybank', 'bank_account_no' => '5140', 'pay_stripe' => 1, 'stripe_test_sk' => 'sk_test_x', 'stripe_test_whsec' => 'whsec_t', 'page_refund' => 13 ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts', 'manage_options', NHM_CAP ) );
$d = get_option( 'nhm_levels' ); $lv = array();
foreach ( $d['items'] as $i => $it ) { $lv[ $it['slug'] ] = $it['id']; if ( 'lite' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '199'; } if ( 'pro' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '399'; } }
update_option( 'nhm_levels', $d ); NHM_Levels::flush();
$lite = $lv['lite']; $pro = $lv['pro'];
// Usage from the hub.
add_filter( 'nhm_member_usage', function ( $u, $uid, $since ) { return array( 'views' => 12, 'downloads' => 3, 'items' => array( array( 'time' => '2026-10-30 10:00:00', 'action' => 'download', 'title' => 'Offer letter template', 'url' => 'https://example.test/t' ) ) ); }, 10, 3 );

// ---------- Member asks ----------
add_user( 70, 'kim@x.my', 'Kim' );
wp_set_current_user( 70 );
$inv = paid_by_stripe( 70, $lite, 'pi_kim' );
NHM_Members::flush();
ok( 'paid' === $inv['status'] && '' !== $inv['paid_at'] && $lite === NHM_Members::level( 70 ), 'paid, Lite' );
ok( NHM_Refunds::can_request( $inv, 70 ), 'can ask within 72 hours' );
ok( ! NHM_Refunds::can_request( $inv, 71 ), 'only the payer' );
$_GET = array( 'tab' => 'billing' );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'Request a refund' ) && false !== strpos( $html, 'name="nhm_reason"' ) && false !== strpos( $html, '?p=13' ), 'button + dialog with policy link' );
$_GET = array();
post( 'refund_request', array( 'nhm_invoice' => $inv['id'], 'nhm_reason' => '' ) );
ok( false !== strpos( form_errors( 'refund' ), 'reason' ), 'reason needed' );
post( 'refund_request', array( 'nhm_invoice' => $inv['id'], 'nhm_reason' => 'Other' ) );
ok( false !== strpos( form_errors( 'refund' ), 'more' ), '"Other" needs details' );
$GLOBALS['mails'] = array();
$r = post( 'refund_request', array( 'nhm_invoice' => $inv['id'], 'nhm_reason' => 'Not what I expected', 'nhm_details' => 'Too basic for me' ) );
NHM_Members::flush();
$req = NHM_Refunds::query( array( 'invoice_id' => $inv['id'] ) )[0];
ok( false !== strpos( (string) $r, 'refund_requested' ) && 'requested' === $req['status'] && 'stripe' === $req['method'] && 12 === $req['usage']['views'], 'request saved with usage' );
ok( NHM_Levels::base_id() === NHM_Members::level( 70 ), 'access paused at once' );
ok( (bool) preg_grep( '/received your refund request/', subjects() ) && (bool) preg_grep( '/Refund request: Kim/', subjects() ), 'member + admin emails' );
ok( ! NHM_Refunds::can_request( NHM_Invoices::get( $inv['id'] ), 70 ), 'no second request while open' );
$_GET = array( 'tab' => 'billing' );
ok( false !== strpos( NHM_Account::render( array() ), 'reviewing your refund request' ), 'My account explains the pause' );
$_GET = array();

// ---------- Reject → access back ----------
wp_set_current_user( 1 );
ok( is_wp_error( NHM_Refunds::reject( $req['id'], '' ) ), 'reject needs a reason' );
$GLOBALS['mails'] = array();
ok( true === NHM_Refunds::reject( $req['id'], 'Templates were downloaded.' ), 'rejected' );
NHM_Members::flush();
ok( $lite === NHM_Members::level( 70 ) && ! NHM_Refunds::on_hold( 70 ), 'access back' );
ok( (bool) preg_grep( '/About your refund request/', subjects() ) && false !== strpos( $GLOBALS['mails'][0]['b'], 'Templates were downloaded.' ), 'rejection email with reason' );
ok( NHM_Refunds::can_request( NHM_Invoices::get( $inv['id'] ), 70 ), 'may ask again later' );

// ---------- Ask again → approve through Stripe (fee deducted) ----------
wp_set_current_user( 70 );
post( 'refund_request', array( 'nhm_invoice' => $inv['id'], 'nhm_reason' => 'Charged by mistake' ) );
$req = NHM_Refunds::query( array( 'invoice_id' => $inv['id'], 'status' => 'requested' ) )[0];
wp_set_current_user( 1 );
$GLOBALS['stripe_responses'] = array( 'payment_intents/pi_kim' => array( 200, array( 'id' => 'pi_kim', 'latest_charge' => array( 'balance_transaction' => array( 'fee' => 697 ) ) ) ), 'refunds' => array( 200, array( 'id' => 're_1', 'status' => 'succeeded' ) ) );
ok( 6.97 === NHM_Refunds::fee( $req ) && 192.03 === NHM_Refunds::suggested( NHM_Refunds::get( $req['id'] ) ), 'suggested = paid − Stripe fee' );
ok( is_wp_error( NHM_Refunds::approve( $req['id'], 250 ) ), 'not more than paid' );
$GLOBALS['remote'] = array(); $GLOBALS['mails'] = array();
ok( true === NHM_Refunds::approve( $req['id'], 192.03, 'ok' ), 'approved' );
$call = end( $GLOBALS['remote'] ); parse_str( $call[1]['body'], $sent );
ok( false !== strpos( $call[0], '/refunds' ) && 'pi_kim' === $sent['payment_intent'] && '19203' === $sent['amount'] && isset( $call[1]['headers']['Idempotency-Key'] ), 'Stripe refund call' );
$req = NHM_Refunds::get( $req['id'] );
NHM_Members::flush();
ok( 'refunded' === $req['status'] && preg_match( '/^RN-\d{4}-0001$/', $req['number'] ) && 're_1' === $req['stripe_refund'], 'refunded with Refund Note number ' . $req['number'] );
ok( NHM_Levels::base_id() === NHM_Members::level( 70 ) && NHM_Levels::base_id() === NHM_Members::personal( 70 )['level'], 'plan ended' );
ok( ! NHM_Refunds::on_hold( 70 ), 'hold cleared (Free now)' );
$inv = NHM_Invoices::get( $inv['id'] );
ok( 192.03 === $inv['refunded'] && 'Partly refunded' === NHM_Invoices::status_label( $inv ), 'invoice: Partly refunded' );
ok( (bool) preg_grep( '/Your refund of RM 192.03/', subjects() ), 'refund done email' );
// Refund Note PDF.
$html = NHM_PDF::refund_html( $req );
ok( false !== strpos( $html, 'REFUND NOTE' ) && false !== strpos( $html, $req['number'] ) && false !== strpos( $html, $inv['number'] ) && false !== strpos( $html, 'RM 192.03' ) && false !== strpos( $html, 'processing fee' ), 'Refund Note content' );
$pdf = NHM_PDF::render( $html );
ok( is_string( $pdf ) && 0 === strpos( $pdf, '%PDF' ), 'Refund Note renders as PDF' );
file_put_contents( sys_get_temp_dir() . '/refund-note.pdf', $pdf );
// Stripe's charge.refunded for our own refund: nothing double.
$payload = json_encode( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_1', 'payment_intent' => 'pi_kim', 'amount_refunded' => 19203, 'metadata' => array( 'site' => home_url( '/' ) ) ) ) ) );
hook( $payload );
ok( 1 === count( NHM_Refunds::query( array( 'invoice_id' => $inv['id'], 'status' => 'refunded' ) ) ) && 192.03 === NHM_Invoices::get( $inv['id'] )['refunded'], 'own refund not counted twice' );
wp_set_current_user( 70 );
$_GET = array( 'tab' => 'billing' );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'Refund Note ' . $req['number'] ) && false !== strpos( $html, 'nhm_refund_pdf=' . $req['token'] ) && false === strpos( $html, 'Request a refund' ), 'member sees the Refund Note link' );
$_GET = array();

// ---------- After 72 hours: no button ----------
add_user( 71, 'old@x.my', 'Old' );
$old = paid_by_stripe( 71, $lite, 'pi_old' );
global $wpdb; $wpdb->update( NHM_Invoices::table(), array( 'paid_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 73 * 3600 ) ), array( 'id' => $old['id'] ) );
ok( ! NHM_Refunds::can_request( NHM_Invoices::get( $old['id'] ), 71 ), 'too late after 72 hours' );
NHM_Settings::update( array( 'refund_hours' => 0 ) );
$wpdb->update( NHM_Invoices::table(), array( 'paid_at' => current_time( 'mysql' ) ), array( 'id' => $old['id'] ) );
ok( ! NHM_Refunds::can_request( NHM_Invoices::get( $old['id'] ), 71 ), 'switched off with 0 hours' );
NHM_Settings::update( array( 'refund_hours' => 72 ) );

// ---------- Bank transfer: approve, then mark refunded ----------
add_user( 72, 'tan@x.my', 'Tan' );
$binv = NHM_Invoices::get( NHM_Invoices::for_plan( 72, $pro, 'signup' ) );
$bp   = NHM_Payments::create( array( 'invoice_id' => $binv['id'], 'method' => 'bank', 'amount' => 399, 'status' => 'submitted', 'payer_name' => 'Tan', 'paid_at' => current_time( 'mysql' ), 'user_id' => 72 ) );
NHM_Payments::approve( $bp );
NHM_Members::flush();
wp_set_current_user( 72 );
post( 'refund_request', array( 'nhm_invoice' => $binv['id'], 'nhm_reason' => 'Not what I expected' ) );
$br = NHM_Refunds::query( array( 'invoice_id' => $binv['id'] ) )[0];
wp_set_current_user( 1 );
ok( 'manual' === $br['method'] && 399.0 === NHM_Refunds::suggested( $br ), 'transfer: full amount suggested (no fee)' );
$GLOBALS['mails'] = array(); $GLOBALS['remote'] = array();
NHM_Refunds::approve( $br['id'], 399 );
NHM_Members::flush();
$br = NHM_Refunds::get( $br['id'] );
ok( 'approved' === $br['status'] && '' === $br['number'] && ! $GLOBALS['remote'], 'approved without Stripe' );
ok( NHM_Levels::base_id() === NHM_Members::personal( 72 )['level'], 'plan ended at approval' );
ok( (bool) preg_grep( '/is approved/', subjects() ), 'approved email' );
ok( is_wp_error( NHM_Refunds::mark_refunded( $req['id'], '', '' ) ), 'cannot mark a finished one' );
$GLOBALS['mails'] = array();
NHM_Refunds::mark_refunded( $br['id'], NHM_Settings::today(), 'MBB-REF-9' );
$br = NHM_Refunds::get( $br['id'] );
ok( 'refunded' === $br['status'] && preg_match( '/-0002$/', $br['number'] ) && 'MBB-REF-9' === $br['reference'], 'marked refunded, number 2' );
ok( 'Refunded' === NHM_Invoices::status_label( NHM_Invoices::get( $binv['id'] ) ), 'invoice Refunded' );
ok( (bool) preg_grep( '/Your refund of RM 399.00/', subjects() ) && ! empty( $GLOBALS['mails'][0]['a'] ), 'email with the PDF attached' );
ok( false !== strpos( NHM_PDF::refund_html( $br ), 'Bank transfer' ), 'Refund Note: bank transfer' );

// ---------- Refund made in the Stripe dashboard ----------
add_user( 73, 'lee@x.my', 'Lee' );
$sinv = paid_by_stripe( 73, $lite, 'pi_lee' );
NHM_Renew::turn_on( 73, $lite, 199 );
$GLOBALS['mails'] = array();
$payload = json_encode( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_2', 'payment_intent' => 'pi_lee', 'amount_refunded' => 19900, 'refunds' => array( 'data' => array( array( 'id' => 're_dash' ) ) ), 'metadata' => array( 'site' => home_url( '/' ) ) ) ) ) );
hook( $payload );
NHM_Members::flush();
$sr = NHM_Refunds::query( array( 'invoice_id' => $sinv['id'] ) )[0];
ok( 'refunded' === $sr['status'] && 'stripe' === $sr['source'] && 're_dash' === $sr['stripe_refund'] && 199.0 === $sr['amount'], 'dashboard refund recorded' );
ok( NHM_Levels::base_id() === NHM_Members::level( 73 ) && ! NHM_Renew::is_on( 73 ), 'plan ended, auto-renew off' );
ok( (bool) preg_grep( '/Your refund of RM 199.00/', subjects() ), 'member got the Refund Note' );

// ---------- Chargeback ----------
add_user( 74, 'cb@x.my', 'Chargeback' );
$cinv = paid_by_stripe( 74, $lite, 'pi_cb' );
$GLOBALS['mails'] = array();
hook( json_encode( array( 'type' => 'charge.dispute.created', 'data' => array( 'object' => array( 'id' => 'dp_1', 'payment_intent' => 'pi_cb', 'amount' => 19900, 'reason' => 'fraudulent' ) ) ) ) );
NHM_Members::flush();
ok( 'dispute' === NHM_Refunds::on_hold( 74 )['why'] && NHM_Levels::base_id() === NHM_Members::level( 74 ), 'access paused' );
ok( (bool) preg_grep( '/Chargeback: Chargeback/', subjects() ), 'admin email' );
NHM_Refunds::release( 74 );
NHM_Members::flush();
ok( $lite === NHM_Members::level( 74 ), 'restored by staff' );

// ---------- Reports ----------
$t = NHM_Refunds::totals( current_time( 'Y' ) );
ok( abs( $t['refunded'] - ( 192.03 + 399 + 199 ) ) < 0.01 && abs( $t['net'] - ( $t['received'] - $t['refunded'] ) ) < 0.01, 'received / refunded / net: ' . json_encode( $t ) );

// ---------- Settings: next number ----------
NHM_Settings::update( array( 'refund_format' => 'CN-{YY}{MM}-{###}' ) );
update_option( 'nhm_refund_seq', array( current_time( 'Y' ) => 41 ) );
ok( 'CN-' . substr( current_time( 'Y' ), 2 ) . current_time( 'm' ) . '-042' === NHM_Settings::next_number( NHM_Settings::get( 'refund_format' ), 'nhm_refund_seq' ), 'own format and running number' );

echo "$n v0.4.3 refund tests, $fails failed\n";
exit( $fails ? 1 : 0 );
