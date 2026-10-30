<?php
// v0.4.3 phase 1: consent, refund policy tick box, card auto-renew.
require __DIR__ . '/stubs-front.php';
require __DIR__ . '/stub-extra.php';
$GLOBALS['stripe_responses'] = array();
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $u, $a ) {
		$GLOBALS['remote'][] = array( $u, $a );
		foreach ( $GLOBALS['stripe_responses'] as $needle => $resp ) {
			if ( false !== strpos( $u, $needle ) ) {
				if ( isset( $resp['seq'] ) ) { $r = array_shift( $GLOBALS['stripe_responses'][ $needle ]['seq'] ); return array( 'response' => array( 'code' => $r[0] ), 'body' => json_encode( $r[1] ) ); }
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
function sent_params( $needle ) { foreach ( array_reverse( $GLOBALS['remote'] ) as $c ) { if ( false !== strpos( $c[0], $needle ) && isset( $c[1]['body'] ) ) { parse_str( $c[1]['body'], $p ); return array( $p, $c[1] ); } } return array( array(), array() ); }
function day( $offset ) { return wp_date( 'Y-m-d', strtotime( NHM_Settings::today() . ' 12:00:00 ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) ); }

$_SERVER['REQUEST_URI'] = '/my-account/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.7';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4, 'biz_product' => 'NeuroHR', 'bank_name' => 'Maybank', 'bank_account_no' => '5140', 'verify_email' => 0, 'pay_stripe' => 1, 'stripe_test_sk' => 'sk_test_x', 'stripe_test_whsec' => 'whsec_t' ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts', 'manage_options', NHM_CAP ) );
$d = get_option( 'nhm_levels' );
$lv = array();
foreach ( $d['items'] as $i => $it ) { $lv[ $it['slug'] ] = $it['id']; if ( 'lite' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '199'; } if ( 'pro' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '399'; } if ( 'corporate' === $it['slug'] ) { $d['items'][ $i ]['amount'] = '1999'; } }
update_option( 'nhm_levels', $d ); NHM_Levels::flush();
$lite = $lv['lite']; $pro = $lv['pro'];

// ---------- Consent ----------
ok( '' === NHM_Auth::consent_label(), 'no tick box without pages' );
NHM_Settings::update( array( 'page_terms' => 11, 'page_privacy' => 12 ) );
$label = NHM_Auth::consent_label();
ok( false !== strpos( $label, 'Terms of Use' ) && false !== strpos( $label, 'Privacy Policy' ) && false !== strpos( $label, '?p=11' ) && false !== strpos( $label, '?p=12' ), 'label with both links' );
wp_set_current_user( 0 );
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
$html = NHM_Auth::render_register( array() );
ok( false !== strpos( $html, 'name="nhm_terms" value="1" required' ), 'required tick box on the form' );
post( 'register', array( 'nhm_name' => 'Nina', 'nhm_email' => 'nina@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
ok( false !== strpos( form_errors( 'register' ), 'Terms of Use and Privacy Policy' ), 'must tick' );
post( 'register', array( 'nhm_name' => 'Nina', 'nhm_email' => 'nina@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old, 'nhm_terms' => '1' ) );
$nina = email_exists( 'nina@x.my' );
$c = $nina ? get_user_meta( $nina, 'nhm_consent', true ) : null;
ok( $nina && is_array( $c ) && $c['date'] && false !== strpos( $c['terms'], '?p=11' ), 'consent kept with date' );
NHM_Settings::update( array( 'consent_on' => 0 ) );
ok( '' === NHM_Auth::consent_label(), 'switch off' );
NHM_Settings::update( array( 'consent_on' => 1 ) );

// ---------- Corporate does not auto-renew ----------
ok( NHM_Renew::level_ok( $lite ) && NHM_Renew::level_ok( $pro ) && ! NHM_Renew::level_ok( $lv['corporate'] ) && ! NHM_Renew::level_ok( NHM_Levels::base_id() ), 'auto-renew for Lite/Pro only' );

// ---------- Pay page: refund policy + auto-renew tick boxes ----------
add_user( 60, 'amy@x.my', 'Amy' );
wp_set_current_user( 60 );
$inv = NHM_Invoices::get( NHM_Invoices::for_plan( 60, $lite, 'signup' ) );
$_GET = array( 'nhm_pay' => $inv['token'] );
$html = NHM_Payments::shortcode();
ok( false !== strpos( $html, 'name="nhm_autorenew" value="1" checked' ) && false !== strpos( $html, 'Renew automatically every year at RM 199.00' ), 'auto-renew ticked by default' );
ok( false === strpos( $html, 'nhm_refund_ok' ), 'no refund box without a page' );
NHM_Settings::update( array( 'page_refund' => 13 ) );
$html = NHM_Payments::shortcode();
ok( false !== strpos( $html, 'name="nhm_refund_ok" value="1" required' ) && false !== strpos( $html, '?p=13' ), 'refund policy tick box' );
$_GET = array( 'nhm_pay' => $inv['token'], 'method' => 'bank' );
ok( false !== strpos( NHM_Payments::shortcode(), 'nhm_refund_ok' ), 'also on the transfer form' );
$_GET = array();
$GLOBALS['remote'] = array();
post( 'pay_stripe', array( 'nhm_pay_token' => $inv['token'], 'nhm_autorenew' => '1' ) );
ok( false !== strpos( form_errors( 'pay_stripe' ), 'Refund Policy' ) && ! $GLOBALS['remote'], 'must agree before Stripe' );
$GLOBALS['stripe_responses'] = array( 'checkout/sessions' => array( 200, array( 'id' => 'cs_ar', 'url' => 'https://checkout.stripe.com/x' ) ) );
$r = post( 'pay_stripe', array( 'nhm_pay_token' => $inv['token'], 'nhm_autorenew' => '1', 'nhm_refund_ok' => '1' ) );
list( $p ) = sent_params( 'checkout/sessions' );
ok( 'https://checkout.stripe.com/x' === $r, 'to Stripe' );
ok( 'off_session' === $p['payment_method_options']['card']['setup_future_usage'] && 'always' === $p['customer_creation'] && '1' === $p['metadata']['autorenew'] && 'amy@x.my' === $p['customer_email'], 'card kept for renewals, customer created' );
post( 'pay_stripe', array( 'nhm_pay_token' => $inv['token'], 'nhm_refund_ok' => '1' ) );
list( $p ) = sent_params( 'checkout/sessions' );
ok( ! isset( $p['payment_method_options'] ) && ! isset( $p['metadata']['autorenew'] ), 'unticked: no card kept' );

// ---------- Paid with the box ticked ----------
$pay = NHM_Payments::by_session( 'cs_ar' );
$pm  = array( 'id' => 'pm_1', 'type' => 'card', 'card' => array( 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030 ) );
$session = array( 'id' => 'cs_ar', 'payment_status' => 'paid', 'amount_total' => 19900, 'customer' => 'cus_1', 'metadata' => array( 'autorenew' => '1', 'payment_id' => $pay['id'] ), 'payment_intent' => array( 'id' => 'pi_1', 'payment_method' => $pm ) );
$GLOBALS['mails'] = array();
ok( 'paid' === NHM_Stripe::settle( $session, 'test' ), 'settled' );
NHM_Members::flush();
$a = NHM_Renew::get( 60 );
ok( $a['on'] && 'pm_1' === $a['pm'] && '4242' === $a['last4'] && 199.0 === (float) $a['price'] && (int) $a['level'] === $lite && 'test' === $a['mode'], 'auto-renew on, price locked: ' . json_encode( $a ) );
ok( 'cus_1' === NHM_Renew::customer_id( 60 ), 'customer kept' );
ok( 'Visa •••• 4242 (04/30)' === NHM_Renew::card_label( $a ), 'card label' );
ok( $lite === NHM_Members::level( 60 ), 'Lite active' );

// Next checkout uses the customer.
$inv2 = NHM_Invoices::get( NHM_Invoices::for_plan( 60, $pro, 'upgrade' ) );
post( 'pay_stripe', array( 'nhm_pay_token' => $inv2['token'], 'nhm_autorenew' => '1', 'nhm_refund_ok' => '1' ) );
list( $p ) = sent_params( 'checkout/sessions' );
ok( 'cus_1' === $p['customer'] && ! isset( $p['customer_email'] ) && ! isset( $p['customer_creation'] ), 'existing customer re-used' );
NHM_Invoices::void( $inv2['id'] );

// ---------- My account card ----------
$_GET = array( 'tab' => 'billing' );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'id="nhm-autorenew"' ) && false !== strpos( $html, 'Visa •••• 4242' ) && false !== strpos( $html, 'Update card' ) && false !== strpos( $html, 'Turn off auto-renew' ) && false !== strpos( $html, 'RM 199.00' ), 'auto-renew card' );
$_GET = array();

// ---------- Price lock ----------
$d = get_option( 'nhm_levels' ); foreach ( $d['items'] as $i => $it ) { if ( $it['id'] === $lite ) { $d['items'][ $i ]['amount'] = '249'; } } update_option( 'nhm_levels', $d ); NHM_Levels::flush();
ok( 199.0 === NHM_Renew::price( 60 ), 'price rise: still 199' );
$d['items'][ array_search( $lite, array_column( $d['items'], 'id' ) ) ]['amount'] = '159'; update_option( 'nhm_levels', $d ); NHM_Levels::flush();
ok( 159.0 === NHM_Renew::price( 60 ), 'price drop: follows to 159' );
$d['items'][ array_search( $lite, array_column( $d['items'], 'id' ) ) ]['amount'] = '249'; update_option( 'nhm_levels', $d ); NHM_Levels::flush();

// ---------- Reminders ----------
NHM_Members::set_plan( 60, $lite, day( -300 ), day( 7 ) );
$GLOBALS['mails'] = array();
NHM_Renew::run();
ok( (bool) preg_grep( '/renews on/', subjects() ), '7-day reminder' );
ok( false !== strpos( $GLOBALS['mails'][0]['b'], 'RM 199.00' ) && false !== strpos( $GLOBALS['mails'][0]['b'], '4242' ), 'reminder has amount and card' );
$GLOBALS['mails'] = array();
NHM_Renew::run();
ok( ! $GLOBALS['mails'], 'not twice' );
NHM_Cron::run();
ok( ! preg_grep( '/ending soon|ends on|has ended/i', subjects() ), 'no normal "ending soon" email for auto-renew' );
NHM_Members::set_plan( 60, $lite, day( -300 ), day( 3 ) );
$GLOBALS['mails'] = array();
NHM_Renew::run();
ok( 1 === count( preg_grep( '/renews on/', subjects() ) ), '3-day reminder' );
// Card runs out first.
$a = NHM_Renew::get( 60 ); $a['exp_month'] = 1; $a['exp_year'] = 2020; NHM_Renew::save( 60, $a );
NHM_Members::set_plan( 60, $lite, day( -300 ), day( 20 ) );
$GLOBALS['mails'] = array();
NHM_Renew::run();
ok( (bool) preg_grep( '/update your card/', subjects() ), 'card expiring email' );
$a = NHM_Renew::get( 60 ); $a['exp_month'] = 4; $a['exp_year'] = 2030; NHM_Renew::save( 60, $a );

// ---------- Charge on the last day: success ----------
$last = day( 0 );
NHM_Members::set_plan( 60, $lite, day( -365 ), $last );
$GLOBALS['remote'] = array();
$GLOBALS['stripe_responses'] = array( 'payment_intents' => array( 200, array( 'id' => 'pi_r1', 'status' => 'succeeded', 'amount' => 19900 ) ) );
$GLOBALS['mails'] = array();
NHM_Renew::run();
NHM_Members::flush();
list( $p, $args ) = sent_params( 'payment_intents' );
ok( '19900' === $p['amount'] && 'cus_1' === $p['customer'] && 'pm_1' === $p['payment_method'] && 'true' === $p['off_session'] && '1' === $p['metadata']['nhm_renew'], 'off-session charge at the locked price' );
ok( isset( $args['headers']['Idempotency-Key'] ) && 0 === strpos( $args['headers']['Idempotency-Key'], 'nhm-renew-' ), 'idempotency key' );
$pp = NHM_Members::personal( 60 );
ok( NHM_Levels::term_end( $lite, $last ) === $pp['expiry'], 'new term follows the old last day: ' . $pp['expiry'] );
$rinv = NHM_Invoices::query( array( 'user_id' => 60, 'status' => 'paid', 'limit' => 1 ) )[0];
ok( 'autorenew' === $rinv['kind'] && 'Card (auto-renew)' === $rinv['payment_method'] && 199.0 === (float) $rinv['total'], 'paid renewal invoice' );
ok( (bool) preg_grep( '/is renewed until/', subjects() ) && ! preg_grep( '/Payment received/i', subjects() ), 'renewed email (not the normal one)' );
$a = NHM_Renew::get( 60 );
ok( $a['on'] && 0 === (int) $a['attempts'] && '' === $a['retry'], 'state clean' );

// ---------- Charge fails, grace, second try fails ----------
$last = day( 0 );
NHM_Members::set_plan( 60, $lite, day( -365 ), $last );
$a = NHM_Renew::get( 60 ); unset( $a['tried_for'] ); NHM_Renew::save( 60, $a );
$GLOBALS['stripe_responses'] = array( 'payment_intents' => array( 402, array( 'error' => array( 'message' => 'Your card was declined.', 'code' => 'card_declined' ) ) ) );
$GLOBALS['mails'] = array();
NHM_Renew::run();
$a = NHM_Renew::get( 60 );
ok( day( 3 ) === $a['retry'] && day( 3 ) === $a['grace'] && 1 === (int) $a['attempts'], 'retry in 3 days' );
ok( (bool) preg_grep( "/couldn't renew/", subjects() ), 'failed email' );
NHM_Members::set_plan( 60, $lite, day( -366 ), day( -1 ) ); // The day after: still access (grace).
$a['retry'] = day( 2 ); $a['grace'] = day( 2 ); NHM_Renew::save( 60, $a );
NHM_Members::flush();
ok( $lite === NHM_Members::level( 60 ), 'access continues during the grace days' );
$GLOBALS['mails'] = array();
NHM_Cron::run();
ok( ! preg_grep( '/has ended/i', subjects() ), 'no "ended" email during grace' );
$_GET = array( 'tab' => 'billing' );
ok( false !== strpos( NHM_Account::render( array() ), 'charge your card on' ), 'My account shows the problem' );
$_GET = array();
// Second try day.
$a = NHM_Renew::get( 60 ); $a['retry'] = day( 0 ); $a['grace'] = day( 0 ); NHM_Renew::save( 60, $a );
$GLOBALS['mails'] = array();
NHM_Renew::run();
NHM_Members::flush();
$a = NHM_Renew::get( 60 );
ok( ! $a['on'] && '' === $a['retry'] && 0.0 === (float) $a['price'], 'second failure: auto-renew off, lock cleared' );
ok( (bool) preg_grep( '/Please renew your/', subjects() ) && (bool) preg_grep( '/Auto-renew failed/', subjects() ), 'invoice email + admin email' );
$open = NHM_Invoices::query( array( 'user_id' => 60, 'status' => 'unpaid', 'limit' => 5 ) );
ok( 1 === count( $open ) && 'autorenew' === $open[0]['kind'] && 199.0 === (float) $open[0]['total'] && false !== strpos( implode( ' ', array_column( $GLOBALS['mails'], 'b' ) ), 'nhm_pay=' . $open[0]['token'] ), 'one open invoice at the locked price with pay link' );
NHM_Members::flush();
ok( NHM_Levels::base_id() === NHM_Members::level( 60 ), 'grace over: Free' );
// Paying that invoice later by card with the box ticked: back on, plan continues from the old last day.
$pay2 = NHM_Payments::create( array( 'invoice_id' => $open[0]['id'], 'method' => 'stripe', 'amount' => 199, 'mode' => 'test', 'user_id' => 60 ) );
NHM_Payments::update( $pay2, array( 'stripe_session' => 'cs_late' ) );
NHM_Stripe::settle( array( 'id' => 'cs_late', 'payment_status' => 'paid', 'amount_total' => 19900, 'customer' => 'cus_1', 'metadata' => array( 'autorenew' => '1' ), 'payment_intent' => array( 'id' => 'pi_late', 'payment_method' => $pm ) ), 'test' );
NHM_Members::flush();
$a = NHM_Renew::get( 60 );
ok( $a['on'] && 199.0 === (float) $a['price'], 'paid the renewal invoice: back on at 199 (lock kept)' );
ok( NHM_Levels::term_end( $lite, day( -1 ) ) === NHM_Members::personal( 60 )['expiry'], 'term continues from the old last day' );

// ---------- Turn off / on in My account ----------
$r = post( 'autorenew_off', array( 'nhm_reason' => 'Too expensive', 'nhm_reason_more' => 'budget' ) );
$a = NHM_Renew::get( 60 );
ok( false !== strpos( (string) $r, 'autorenew_off' ) && ! $a['on'] && 'Too expensive: budget' === $a['off_reason'] && '4242' === $a['last4'], 'off with reason, card kept' );
$r = post( 'autorenew_on', array() );
$a = NHM_Renew::get( 60 );
ok( false !== strpos( (string) $r, 'autorenew_on' ) && $a['on'] && 249.0 === (float) $a['price'], 'on again with the saved card: today\'s price 249' );

// ---------- Card setup (new card) ----------
$GLOBALS['stripe_responses'] = array( 'checkout/sessions' => array( 200, array( 'id' => 'cs_setup', 'url' => 'https://checkout.stripe.com/setup' ) ) );
$r = post( 'card_update', array() );
list( $p ) = sent_params( 'checkout/sessions' );
ok( 'https://checkout.stripe.com/setup' === $r && 'setup' === $p['mode'] && 'cus_1' === $p['customer'] && 'update' === $p['metadata']['purpose'], 'update card: Stripe setup page' );
$si = array( 'id' => 'cs_setup', 'mode' => 'setup', 'status' => 'complete', 'metadata' => array( 'user_id' => '60', 'purpose' => 'update' ), 'setup_intent' => array( 'id' => 'seti_1', 'payment_method' => array( 'id' => 'pm_2', 'type' => 'card', 'card' => array( 'brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 9, 'exp_year' => 2031 ) ) ) );
// Through the webhook.
$payload = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => $si ) ) );
$resp = NHM_Stripe::webhook( new FakeRequest( $payload, 't=' . time() . ',v1=' . hash_hmac( 'sha256', time() . '.' . $payload, 'whsec_t' ) ) );
$a = NHM_Renew::get( 60 );
ok( 200 === $resp->status && 'pm_2' === $a['pm'] && '4444' === $a['last4'], 'new card saved from the webhook' );

// ---------- Admin: charge now, processing, webhook ----------
wp_set_current_user( 1 );
$before = NHM_Members::personal( 60 )['expiry'];
$GLOBALS['stripe_responses'] = array( 'payment_intents' => array( 200, array( 'id' => 'pi_p', 'status' => 'processing', 'amount' => 24900 ) ) );
ok( 'processing' === NHM_Renew::charge( 60, 'staff' ), 'processing' );
$a = NHM_Renew::get( 60 );
$rid = $a['invoice'];
$pi = array( 'id' => 'pi_p', 'amount' => 24900, 'metadata' => array( 'nhm_renew' => '1', 'invoice_id' => (string) $rid, 'user_id' => '60', 'payment_id' => '0' ) );
$payload = json_encode( array( 'type' => 'payment_intent.succeeded', 'data' => array( 'object' => $pi ) ) );
$GLOBALS['mails'] = array();
NHM_Stripe::webhook( new FakeRequest( $payload, 't=' . time() . ',v1=' . hash_hmac( 'sha256', time() . '.' . $payload, 'whsec_t' ) ) );
NHM_Members::flush();
ok( 'paid' === NHM_Invoices::get( $rid )['status'] && NHM_Levels::term_end( $lite, $before ) === NHM_Members::personal( 60 )['expiry'], 'webhook finished the processing charge; early renewal adds a term' );
ok( (bool) preg_grep( '/is renewed until/', subjects() ), 'renewed email' );
// Webhook again: nothing double.
NHM_Stripe::webhook( new FakeRequest( $payload, 't=' . time() . ',v1=' . hash_hmac( 'sha256', time() . '.' . $payload, 'whsec_t' ) ) );
NHM_Members::flush();
ok( NHM_Levels::term_end( $lite, $before ) === NHM_Members::personal( 60 )['expiry'], 'no double term' );

// ---------- Pricing line ----------
$html = NHM_Shortcodes::pricing( array( 'levels' => 'lite,corporate' ) );
ok( 1 === substr_count( $html, 'Renews automatically every year. Cancel any time.' ), 'pricing line only on Lite' );

// ---------- Unticked payment turns auto-renew off ----------
$inv3 = NHM_Invoices::get( NHM_Invoices::for_plan( 60, $pro, 'upgrade' ) );
$pay3 = NHM_Payments::create( array( 'invoice_id' => $inv3['id'], 'method' => 'stripe', 'amount' => 399, 'mode' => 'test', 'user_id' => 60 ) );
NHM_Payments::update( $pay3, array( 'stripe_session' => 'cs_no' ) );
NHM_Stripe::settle( array( 'id' => 'cs_no', 'payment_status' => 'paid', 'amount_total' => 39900, 'metadata' => array(), 'payment_intent' => array( 'id' => 'pi_no', 'payment_method' => $pm ) ), 'test' );
ok( ! NHM_Renew::is_on( 60 ), 'paid without the tick: auto-renew off' );

echo "$n v0.4.3 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
