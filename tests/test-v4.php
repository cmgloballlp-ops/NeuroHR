<?php
// v0.4.0 tests: pay page, bank transfer / QR proof + review, Stripe checkout, return and webhook.
require __DIR__ . '/stubs-front.php';
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
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }
class FakeRequest { public $body; public $sig; function __construct( $b, $s ) { $this->body = $b; $this->sig = $s; } function get_body() { return $this->body; } function get_header( $h ) { return 'stripe_signature' === $h ? $this->sig : ''; } }

$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
function form_errors( $f ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $e = $rp->getValue(); return isset( $e[ $f ] ) ? implode( ' ', $e[ $f ] ) : ''; }
function get_redirect( $cb ) { try { $cb(); } catch ( Redirect $r ) { return $r->getMessage(); } return null; }
function sign( $payload, $secret ) { $t = time(); return 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, $secret ); }
function subjects() { return array_column( $GLOBALS['mails'], 's' ); }

$_SERVER['REQUEST_URI'] = '/my-account/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4, 'biz_name' => 'NeuroHR Sdn. Bhd.', 'bank_name' => 'Maybank', 'bank_account_name' => 'NeuroHR Sdn Bhd', 'bank_account_no' => '5140 1234 5678', 'verify_email' => 0 ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );
add_user( 50, 'mei@x.my', 'Mei Ling' );
$d = get_option( 'nhm_levels' );
foreach ( $d['items'] as $i => $it ) { if ( 'pro' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '600'; } }
update_option( 'nhm_levels', $d ); NHM_Levels::flush();
// Slips: copy instead of move_uploaded_file in tests.
add_filter( 'nhm_move_upload', function ( $r, $from, $to ) { return copy( $from, $to ); }, 10, 3 );
$png = sys_get_temp_dir() . '/slip.png';
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$txt = sys_get_temp_dir() . '/slip.txt';
file_put_contents( $txt, 'not an image' );

// ---------- Ways to pay ----------
ok( array( 'bank' ) === array_keys( NHM_Payments::available() ), 'bank on by default (bank details set), Stripe and QR off' );
NHM_Settings::update( array( 'pay_qr' => 1, 'qr_image' => 'https://example.test/qr.png', 'qr_name' => 'NeuroHR Sdn Bhd' ) );
ok( array( 'bank', 'qr' ) === array_keys( NHM_Payments::available() ), 'QR on with a picture' );
NHM_Settings::update( array( 'pay_stripe' => 1 ) );
ok( ! isset( NHM_Payments::available()['stripe'] ), 'Stripe needs a key' );
NHM_Settings::update( array( 'stripe_test_sk' => 'sk_test_123', 'stripe_test_whsec' => 'whsec_test' ) );
ok( array( 'stripe', 'bank', 'qr' ) === array_keys( NHM_Payments::available() ), 'all three' );

// ---------- Apply → pay page ----------
wp_set_current_user( 50 );
$GLOBALS['mails'] = array();
$_GET = array( 'plan' => 'pro' );
$r = post( 'apply', array( 'nhm_plan' => 'pro' ) );
$_GET = array();
$inv = NHM_Invoices::pending_for( 50 )[0];
ok( $r && false !== strpos( $r, 'nhm_pay=' . $inv['token'] ), 'upgrade request goes to the pay page: ' . $r );
ok( (bool) preg_grep( '/invoice/i', subjects() ) && false !== strpos( implode( ' ', array_column( $GLOBALS['mails'], 'b' ) ), 'nhm_pay=' . $inv['token'] ), 'invoice email has the pay link' );
ok( NHM_Invoices::by_token( $inv['token'] )['id'] === $inv['id'] && null === NHM_Invoices::by_token( 'short' ), 'invoice by token' );

// ---------- Pay page ----------
wp_set_current_user( 0 ); // Accounts team without login.
$_GET = array( 'nhm_pay' => $inv['token'] );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'RM 600.00' ) && false !== strpos( $html, 'Pay online' ) && false !== strpos( $html, 'Bank transfer' ) && false !== strpos( $html, 'DuitNow QR' ), 'pay page for a visitor with the link' );
ok( false !== strpos( $html, 'Pay RM 600.00 now' ) && false !== strpos( $html, 'TEST MODE' ), 'Stripe panel first' );
$_GET = array( 'nhm_pay' => $inv['token'], 'method' => 'bank' );
$html = NHM_Payments::shortcode();
ok( false !== strpos( $html, '5140 1234 5678' ) && false !== strpos( $html, 'data-nhm-copy="' . $inv['number'] . '"' ) && false !== strpos( $html, 'name="nhm_slip"' ) && false !== strpos( $html, 'enctype="multipart/form-data"' ), 'bank panel: details, reference, proof form' );
$_GET = array( 'nhm_pay' => $inv['token'], 'method' => 'qr' );
$html = NHM_Payments::shortcode();
ok( false !== strpos( $html, 'qr.png' ) && false !== strpos( $html, 'NeuroHR Sdn Bhd' ), 'QR panel' );
$_GET = array();

// ---------- Proof ----------
$base = array( 'nhm_pay_token' => $inv['token'], 'nhm_method' => 'bank', 'nhm_payer' => 'Mei Co Sdn Bhd', 'nhm_paid_date' => NHM_Settings::today(), 'nhm_paid_time' => '10:15', 'nhm_paid_amount' => '600', 'nhm_reference' => 'MBB123' );
$_FILES = array( 'nhm_slip' => array( 'tmp_name' => $txt, 'error' => 0, 'size' => 12, 'name' => 'x.txt' ) );
$r = post( 'pay_proof', $base );
ok( null === $r && false !== strpos( form_errors( 'pay_proof' ), 'JPG, PNG or PDF' ), 'text file refused' );
$_FILES = array( 'nhm_slip' => array( 'tmp_name' => $png, 'error' => 0, 'size' => 6 * 1048576, 'name' => 'x.png' ) );
$r = post( 'pay_proof', $base );
ok( null === $r && false !== strpos( form_errors( 'pay_proof' ), '5 MB' ), 'over 5 MB refused' );
$_FILES = array();
$r = post( 'pay_proof', $base );
ok( null === $r && false !== strpos( form_errors( 'pay_proof' ), 'slip' ), 'slip required' );
$r = post( 'pay_proof', array_merge( $base, array( 'nhm_paid_date' => wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ) ) );
ok( null === $r && false !== strpos( form_errors( 'pay_proof' ), 'date' ), 'future date refused' );
$GLOBALS['mails'] = array();
$_FILES = array( 'nhm_slip' => array( 'tmp_name' => $png, 'error' => 0, 'size' => 100, 'name' => 'slip.png' ) );
$r = post( 'pay_proof', $base );
$p = NHM_Payments::waiting_for( $inv['id'] );
ok( $r && false !== strpos( $r, 'proof_sent' ) && $p && 'bank' === $p['method'] && 600.0 === $p['amount'] && 'Mei Co Sdn Bhd' === $p['payer_name'] && NHM_Settings::today() . ' 10:15:00' === $p['paid_at'], 'proof saved' );
ok( $p && file_exists( NHM_Payments::slip_path( $p ) ) && file_exists( dirname( NHM_Payments::slip_path( $p ) ) . '/.htaccess' ) && preg_match( '/\.png$/', $p['slip'] ), 'slip stored privately' );
ok( (bool) preg_grep( '/received your payment details/', subjects() ) && (bool) preg_grep( '/Payment to check/', subjects() ), 'member + admin emails' );
ok( 'Checking payment' === NHM_Invoices::status_label( NHM_Invoices::get( $inv['id'] ) ), 'invoice shows Checking payment' );
$_GET = array( 'nhm_pay' => $inv['token'] );
ok( false !== strpos( NHM_Payments::shortcode(), 'We are checking your payment' ), 'pay page: checking' );
$_GET = array();
ok( 1 === NHM_Members::level( 50 ), 'plan not started before approval' );

// Reject → resubmit → approve
wp_set_current_user( 1 );
ok( is_wp_error( NHM_Payments::reject( $p['id'], '' ) ), 'reject needs a reason' );
$GLOBALS['mails'] = array();
ok( true === NHM_Payments::reject( $p['id'], 'Amount not received yet.' ), 'rejected' );
ok( (bool) preg_grep( '/About your payment/', subjects() ) && false !== strpos( $GLOBALS['mails'][0]['b'], 'Amount not received yet.' ), 'rejection email with reason' );
$_GET = array( 'nhm_pay' => $inv['token'] );
ok( false !== strpos( NHM_Payments::shortcode(), 'Amount not received yet.' ), 'pay page shows the reason' );
$_GET = array();
wp_set_current_user( 50 );
$r = post( 'pay_proof', array_merge( $base, array( 'nhm_method' => 'qr' ) ) );
$p2 = NHM_Payments::waiting_for( $inv['id'] );
ok( $p2 && 'qr' === $p2['method'], 'sent again by QR' );
wp_set_current_user( 1 );
$GLOBALS['mails'] = array();
ok( true === NHM_Payments::approve( $p2['id'] ), 'approved' );
NHM_Members::flush();
$paid = NHM_Invoices::get( $inv['id'] );
ok( 'paid' === $paid['status'] && 'DuitNow QR' === $paid['payment_method'] && 'MBB123' === $paid['payment_ref'] && 3 === NHM_Members::level( 50 ), 'invoice paid, Pro started' );
ok( (bool) preg_grep( '/Payment received/', subjects() ), 'payment received email' );
ok( is_wp_error( NHM_Payments::approve( $p2['id'] ) ), 'cannot approve twice' );
$_GET = array( 'nhm_pay' => $inv['token'] );
ok( false !== strpos( NHM_Payments::shortcode(), 'This invoice was paid' ), 'pay page: paid' );
$_GET = array();

// ---------- Stripe ----------
add_user( 51, 'ali@x.my', 'Ali' );
$inv2 = NHM_Invoices::get( NHM_Invoices::for_plan( 51, 3 ) );
$GLOBALS['remote'] = array();
$GLOBALS['stripe_responses'] = array( 'checkout/sessions' => array( 200, array( 'id' => 'cs_test_A', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_A' ) ) );
wp_set_current_user( 0 );
$r = post( 'pay_stripe', array( 'nhm_pay_token' => $inv2['token'] ) );
$call = $GLOBALS['remote'][0];
parse_str( $call[1]['body'], $sent );
$sp = NHM_Payments::by_session( 'cs_test_A' );
ok( 'https://checkout.stripe.com/c/pay/cs_test_A' === $r && $sp && 'pending' === $sp['status'] && 'test' === $sp['mode'], 'checkout session created, redirect to Stripe' );
ok( 'Bearer sk_test_123' === $call[1]['headers']['Authorization'] && '60000' === $sent['line_items'][0]['price_data']['unit_amount'] && 'myr' === $sent['line_items'][0]['price_data']['currency'] && (string) $inv2['id'] === $sent['metadata']['invoice_id'] && 'ali@x.my' === $sent['customer_email'], 'checkout params (sen, MYR, metadata, email)' );
ok( false !== strpos( $sent['success_url'], '{CHECKOUT_SESSION_ID}' ) && false !== strpos( $sent['cancel_url'], 'nhm_stripe=cancel' ), 'return URLs' );

// Webhook: bad signature
$event = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_test_A', 'payment_status' => 'paid', 'amount_total' => 60000, 'metadata' => array( 'payment_id' => $sp['id'], 'site' => 'https://example.test' ) ) ) ) );
$res = NHM_Stripe::webhook( new FakeRequest( $event, sign( $event, 'whsec_wrong' ) ) );
ok( 400 === $res->status && 'unpaid' === NHM_Invoices::get( $inv2['id'] )['status'], 'bad signature refused' );
$old = 't=' . ( time() - 3600 ) . ',v1=' . hash_hmac( 'sha256', ( time() - 3600 ) . '.' . $event, 'whsec_test' );
ok( 400 === NHM_Stripe::webhook( new FakeRequest( $event, $old ) )->status, 'old signature refused' );
// Good signature; the session lookup tells the method (FPX)
$GLOBALS['stripe_responses'] = array( 'checkout/sessions/cs_test_A' => array( 200, array( 'id' => 'cs_test_A', 'payment_status' => 'paid', 'payment_intent' => array( 'id' => 'pi_123', 'payment_method' => array( 'type' => 'fpx' ) ) ) ) );
$GLOBALS['mails'] = array();
$res = NHM_Stripe::webhook( new FakeRequest( $event, sign( $event, 'whsec_test' ) ) );
NHM_Members::flush();
$i2 = NHM_Invoices::get( $inv2['id'] );
ok( 200 === $res->status && 'paid' === $i2['status'] && 'FPX' === $i2['payment_method'] && 'pi_123' === $i2['payment_ref'] && 3 === NHM_Members::level( 51 ), 'webhook: invoice paid by FPX, plan started' );
ok( 'succeeded' === NHM_Payments::by_session( 'cs_test_A' )['status'] && 'fpx' === NHM_Payments::by_session( 'cs_test_A' )['stripe_method'], 'payment row succeeded' );
ok( 1 === count( preg_grep( '/Payment received/', subjects() ) ), 'one payment email' );
// Same event again (Stripe retries): nothing doubled
$GLOBALS['mails'] = array();
NHM_Stripe::webhook( new FakeRequest( $event, sign( $event, 'whsec_test' ) ) );
ok( 0 === count( $GLOBALS['mails'] ), 'repeat event ignored' );
// Return page after a paid session
$_GET = array( 'nhm_stripe' => 'return', 'nhm_pay' => $inv2['token'], 'session_id' => 'cs_test_A' );
$r = get_redirect( array( 'NHM_Payments', 'stripe_return' ) );
ok( $r && false !== strpos( $r, 'pay_done' ), 'return page: done' );
$_GET = array( 'nhm_stripe' => 'cancel', 'nhm_pay' => $inv2['token'] );
ok( false !== strpos( (string) get_redirect( array( 'NHM_Payments', 'stripe_return' ) ), 'pay_cancelled' ), 'cancel message' );
$_GET = array();

// Return before the webhook, smaller amount → not started
add_user( 52, 'lim@x.my', 'Lim' );
$inv3 = NHM_Invoices::get( NHM_Invoices::for_plan( 52, 3 ) );
$GLOBALS['stripe_responses'] = array( 'checkout/sessions/cs_test_B' => array( 200, array( 'id' => 'cs_test_B', 'payment_status' => 'paid', 'amount_total' => 100, 'payment_intent' => array( 'id' => 'pi_9', 'payment_method' => array( 'type' => 'card' ) ) ) ), 'checkout/sessions' => array( 200, array( 'id' => 'cs_test_B', 'url' => 'https://checkout.stripe.com/b' ) ) );
post( 'pay_stripe', array( 'nhm_pay_token' => $inv3['token'] ) );
$_GET = array( 'nhm_stripe' => 'return', 'nhm_pay' => $inv3['token'], 'session_id' => 'cs_test_B' );
get_redirect( array( 'NHM_Payments', 'stripe_return' ) );
$_GET = array();
ok( 'unpaid' === NHM_Invoices::get( $inv3['id'] )['status'] && false !== strpos( NHM_Payments::by_session( 'cs_test_B' )['admin_note'], 'less' ), 'short payment does not start the plan' );
// Processing (async)
add_user( 53, 'tan@x.my', 'Tan' );
$inv4 = NHM_Invoices::get( NHM_Invoices::for_plan( 53, 3 ) );
$GLOBALS['stripe_responses'] = array( 'checkout/sessions' => array( 200, array( 'id' => 'cs_test_C', 'url' => 'https://checkout.stripe.com/c' ) ) );
post( 'pay_stripe', array( 'nhm_pay_token' => $inv4['token'] ) );
$ev = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_test_C', 'payment_status' => 'unpaid', 'metadata' => array( 'site' => 'https://example.test' ) ) ) ) );
NHM_Stripe::webhook( new FakeRequest( $ev, sign( $ev, 'whsec_test' ) ) );
ok( 'processing' === NHM_Payments::by_session( 'cs_test_C' )['status'] && 'unpaid' === NHM_Invoices::get( $inv4['id'] )['status'], 'async payment: processing' );
$ev = json_encode( array( 'type' => 'checkout.session.async_payment_failed', 'data' => array( 'object' => array( 'id' => 'cs_test_C', 'metadata' => array( 'site' => 'https://example.test' ) ) ) ) );
NHM_Stripe::webhook( new FakeRequest( $ev, sign( $ev, 'whsec_test' ) ) );
ok( 'failed' === NHM_Payments::by_session( 'cs_test_C' )['status'], 'async failure recorded' );
// Event of another site on the same Stripe account
$ev = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_x', 'payment_status' => 'paid', 'metadata' => array( 'site' => 'https://other.site' ) ) ) ) );
ok( 'other site' === NHM_Stripe::webhook( new FakeRequest( $ev, sign( $ev, 'whsec_test' ) ) )->data['ignored'], 'other site ignored' );
// Account: Pay now button for open invoices
wp_set_current_user( 53 );
$_GET = array( 'tab' => 'billing' );
$acc = NHM_Account::render( array() );
$_GET = array();
ok( false !== strpos( $acc, 'Pay now' ) && false !== strpos( $acc, 'nhm_pay=' . $inv4['token'] ), 'My account: Pay now' );


// ---------- 0.4.1: paid sign-up goes straight to payment ----------
if ( ! function_exists( 'wp_delete_user' ) ) { function wp_delete_user( $id ) { unset( $GLOBALS['users'][ $id ], $GLOBALS['usermeta'][ $id ] ); $GLOBALS['deleted'][] = $id; return true; } }
wp_set_current_user( 0 );
$_SERVER['REQUEST_URI'] = '/register/?plan=pro';
$_GET = array( 'plan' => 'pro' );
$form = NHM_Auth::render_register( array() );
ok( false !== strpos( $form, 'nhm-steps-bar' ) && false !== strpos( $form, 'Continue to payment' ), 'register: two steps, Continue to payment' );
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
NHM_Settings::update( array( 'verify_email' => 1 ) );
$GLOBALS['mails'] = array();
$r = post( 'register', array( 'nhm_plan' => 'pro', 'nhm_name' => 'Siti', 'nhm_email' => 'siti@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
$_GET = array();
$siti = get_user_by( 'email', 'siti@x.my' );
$sinv = NHM_Invoices::get( NHM_Auth::pending_invoice( $siti->ID ) );
ok( $r && false !== strpos( $r, 'nhm_pay=' . $sinv['token'] ) && false !== strpos( $r, 'signup_pay' ) && 0 === get_current_user_id(), 'paid sign-up: straight to the pay page, not logged in' );
ok( ! preg_grep( '/confirm your email/i', subjects() ) && (bool) preg_grep( '/invoice/i', subjects() ), 'no confirmation email, invoice email instead' );
ok( wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) === $sinv['due_date'], 'due in 3 days' );
ok( is_wp_error( NHM_Auth::block_unverified( $siti ) ) && 'nhm_pay_pending' === NHM_Auth::block_unverified( $siti )->get_error_code(), 'cannot log in before paying' );
$_GET = array( 'nhm_pay' => $sinv['token'] );
$pp = NHM_Payments::shortcode();
$_GET = array();
ok( false !== strpos( $pp, 'nhm-steps-bar' ) && false !== strpos( $pp, 'Download invoice (PDF)' ), 'pay page: steps and PDF link for a visitor' );
// Registering again with the same email
$GLOBALS['mails'] = array();
delete_option( '_t_nhm_paylink_' . $siti->ID ); unset( $GLOBALS['transients'][ 'nhm_paylink_' . $siti->ID ] );
$r = post( 'register', array( 'nhm_plan' => 'pro', 'nhm_name' => 'Siti', 'nhm_email' => 'siti@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
ok( null === $r && false !== strpos( form_errors( 'register' ), 'already started signing up' ) && 1 === count( $GLOBALS['mails'] ), 'same email again: payment link sent again (' . count( $GLOBALS['mails'] ) . ' ' . implode( '|', subjects() ) . ')' );
// Stripe payment → account opens, logged in, goes to the dashboard
$GLOBALS['stripe_responses'] = array( 'checkout/sessions/cs_test_S' => array( 200, array( 'id' => 'cs_test_S', 'payment_status' => 'paid', 'amount_total' => 60000, 'payment_intent' => array( 'id' => 'pi_S', 'payment_method' => array( 'type' => 'card' ) ) ) ), 'checkout/sessions' => array( 200, array( 'id' => 'cs_test_S', 'url' => 'https://checkout.stripe.com/s' ) ) );
post( 'pay_stripe', array( 'nhm_pay_token' => $sinv['token'] ) );
$_GET = array( 'nhm_stripe' => 'return', 'nhm_pay' => $sinv['token'], 'session_id' => 'cs_test_S' );
$r = get_redirect( array( 'NHM_Payments', 'stripe_return' ) );
$_GET = array();
NHM_Members::flush();
ok( $siti->ID === get_current_user_id() && false !== strpos( (string) $r, 'pay_done' ) && 0 === NHM_Auth::pending_invoice( $siti->ID ) && ! NHM_Auth::is_unverified( $siti->ID ) && 3 === NHM_Members::level( $siti->ID ), 'paid: logged in, account open, Pro' );
ok( $siti === NHM_Auth::block_unverified( $siti ), 'can log in afterwards' );
wp_set_current_user( 0 );
// Bank transfer sign-up: opens when approved
$GLOBALS['mails'] = array();
$_GET = array( 'plan' => 'pro' );
post( 'register', array( 'nhm_plan' => 'pro', 'nhm_name' => 'Raj', 'nhm_email' => 'raj@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
$_GET = array();
$raj  = get_user_by( 'email', 'raj@x.my' );
$rinv = NHM_Invoices::get( NHM_Auth::pending_invoice( $raj->ID ) );
$_FILES = array( 'nhm_slip' => array( 'tmp_name' => $png, 'error' => 0, 'size' => 100, 'name' => 'slip.png' ) );
post( 'pay_proof', array_merge( $base, array( 'nhm_pay_token' => $rinv['token'] ) ) );
wp_set_current_user( 1 );
$GLOBALS['mails'] = array();
NHM_Payments::approve( NHM_Payments::waiting_for( $rinv['id'] )['id'] );
ok( 0 === NHM_Auth::pending_invoice( $raj->ID ) && $raj === NHM_Auth::block_unverified( $raj ), 'approval opens the account' );
$pm = null; foreach ( $GLOBALS['mails'] as $m ) { if ( false !== strpos( $m['s'], 'Payment received' ) ) { $pm = $m; } }
ok( $pm && false !== strpos( $pm['b'], 'log in any time' ), 'payment email has the login link' );
// Plan card while waiting for payment
add_user( 60, 'card@x.my', 'Card' );
$cinv = NHM_Invoices::get( NHM_Invoices::for_plan( 60, 3, 'upgrade' ) );
wp_set_current_user( 60 );
$card = NHM_Account::plan_card( array( 'style' => 'light' ) );
ok( false !== strpos( $card, 'Payment pending' ) && false !== strpos( $card, 'Complete payment' ) && false === strpos( $card, '>Upgrade<' ) && false === strpos( $card, 'nhm-alert' ), 'plan card becomes the payment card' );
// Download with the key works without login
wp_set_current_user( 0 );
$_GET = array( 'nhm_invoice' => $cinv['id'], 'key' => 'wrong' );
ok( false !== strpos( (string) get_redirect( array( 'NHM_PDF', 'member_download' ) ), '?p=1' ), 'wrong key: login asked' );
$_GET = array();
// Expiry: reminder the day before, cancel + remove the unpaid sign-up the day after
wp_set_current_user( 0 );
$_GET = array( 'plan' => 'pro' );
post( 'register', array( 'nhm_plan' => 'pro', 'nhm_name' => 'Gone', 'nhm_email' => 'gone@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
$_GET = array();
$gone = get_user_by( 'email', 'gone@x.my' );
$ginv = NHM_Invoices::get( NHM_Auth::pending_invoice( $gone->ID ) );
global $wpdb;
$wpdb->update( NHM_Invoices::table(), array( 'due_date' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ), array( 'id' => $ginv['id'] ) );
$GLOBALS['mails'] = array();
NHM_Cron::online_invoices();
ok( (bool) preg_grep( '/friendly reminder/', subjects() ) && 'unpaid' === NHM_Invoices::get( $ginv['id'] )['status'], 'reminder the day before' );
$GLOBALS['mails'] = array();
NHM_Cron::online_invoices();
ok( 0 === count( $GLOBALS['mails'] ), 'reminder only once' );
$wpdb->update( NHM_Invoices::table(), array( 'due_date' => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ), array( 'id' => $ginv['id'] ) );
$wpdb->update( NHM_Invoices::table(), array( 'due_date' => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ), array( 'id' => $cinv['id'] ) );
NHM_Cron::online_invoices();
ok( 'void' === NHM_Invoices::get( $ginv['id'] )['status'] && in_array( $gone->ID, (array) $GLOBALS['deleted'], true ) && (bool) preg_grep( '/has been cancelled/', subjects() ), 'expired: cancelled, unpaid sign-up removed' );
ok( 'void' === NHM_Invoices::get( $cinv['id'] )['status'] && ! in_array( 60, (array) $GLOBALS['deleted'], true ), 'expired upgrade: cancelled, member kept' );
// A transfer being checked is never cancelled
add_user( 61, 'chk@x.my', 'Chk' );
$kinv = NHM_Invoices::get( NHM_Invoices::for_plan( 61, 3, 'upgrade' ) );
$_FILES = array( 'nhm_slip' => array( 'tmp_name' => $png, 'error' => 0, 'size' => 100, 'name' => 'slip.png' ) );
post( 'pay_proof', array_merge( $base, array( 'nhm_pay_token' => $kinv['token'] ) ) );
$wpdb->update( NHM_Invoices::table(), array( 'due_date' => wp_date( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ) ), array( 'id' => $kinv['id'] ) );
NHM_Cron::online_invoices();
ok( 'unpaid' === NHM_Invoices::get( $kinv['id'] )['status'], 'payment being checked: not cancelled' );
// Renewal invoices keep the normal due date
add_user( 62, 'ren@x.my', 'Ren' );
$ren = NHM_Invoices::get( NHM_Invoices::for_plan( 62, 3, 'renewal' ) );
ok( wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ) === $ren['due_date'], 'renewal: normal due date' );
// SMTP
$pm2 = new stdClass();
$pm2->calls = array();
NHM_Settings::update( array( 'smtp_on' => 1, 'smtp_host' => 'mail.neurohr.com.my', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => 'admin@neurohr.com.my', 'smtp_pass' => 'pw' ) );
class FakeMailer { public $Host, $Port, $SMTPSecure, $SMTPAutoTLS, $SMTPAuth, $Username, $Password, $Timeout, $Sender, $smtp = false; function isSMTP() { $this->smtp = true; } }
$fm = new FakeMailer();
NHM_Mailer::smtp( $fm );
ok( $fm->smtp && 'mail.neurohr.com.my' === $fm->Host && 465 === $fm->Port && 'ssl' === $fm->SMTPSecure && $fm->SMTPAuth && 'admin@neurohr.com.my' === $fm->Username && 'pw' === $fm->Password && 'admin@neurohr.com.my' === $fm->Sender, 'SMTP settings applied' );
ok( 'admin@neurohr.com.my' === NHM_Mailer::from( 'wordpress@neurohr.com.my' ) && 'NeuroHR' === NHM_Mailer::from_name( 'WordPress' ), 'sender is never "WordPress"' );

echo "$n v0.4 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
