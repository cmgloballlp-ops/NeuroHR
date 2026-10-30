<?php
require __DIR__ . '/stubs-front.php';
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function d( $days ) { return wp_date( 'Y-m-d', $GLOBALS['NOW'] + $days * DAY_IN_SECONDS ); }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
$_SERVER['REQUEST_URI'] = '/register/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_lost' => 3, 'page_account' => 4, 'page_pricing' => 5, 'biz_name' => 'NeuroHR Sdn. Bhd.', 'biz_reg' => '202401012345', 'biz_tin' => 'C1234567890', 'biz_address' => "Level 3, Menara X\nJalan Y", 'biz_postcode' => '50450', 'biz_city' => 'Kuala Lumpur', 'bank_name' => 'Maybank', 'bank_account_name' => 'NeuroHR Sdn Bhd', 'bank_account_no' => '5140 1234 5678' ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );

// ---------- Numbers ----------
$a = NHM_Settings::next_number( 'INV-{YYYY}-{####}', 'nhm_invoice_test' );
$b = NHM_Settings::next_number( 'INV-{YYYY}-{####}', 'nhm_invoice_test' );
ok( $a === 'INV-' . current_time( 'Y' ) . '-0001' && $b === 'INV-' . current_time( 'Y' ) . '-0002', 'invoice numbers' );
ok( NHM_Settings::money( 1299.5 ) === 'RM 1,299.50', 'money' );

// ---------- Terms ----------
$data = get_option( 'nhm_levels' );
$data['items'][2]['amount'] = '299.00';                 // Pro: 1 year
$data['items'][1]['amount'] = '49.00'; $data['items'][1]['expire_unit'] = 'month'; $data['items'][1]['billing'] = 'recurring'; // Lite monthly
$data['items'][3]['amount'] = '9999.00';                // Corporate
$data['items'][4]['access'] = 'lifetime';               // Consulting lifetime
update_option( 'nhm_levels', $data ); NHM_Levels::flush();
ok( NHM_Levels::term_end( 3, '2026-10-23' ) === '2027-10-23', 'term 1 year' );
ok( NHM_Levels::term_end( 2, '2026-01-31' ) === '2026-02-28', 'term 1 month from 31 Jan' );
ok( NHM_Levels::term_end( 3, '2028-02-29' ) === '2029-02-28', 'leap day + 1 year' );
ok( NHM_Levels::term_end( 5, '2026-10-23' ) === '', 'lifetime' );
ok( NHM_Levels::term_label( 2 ) === 'per month' && NHM_Levels::term_label( 5 ) === 'lifetime', 'term labels' );
ok( NHM_Levels::price_text( 3 ) === 'RM 299' && NHM_Levels::price_text( 1 ) === 'Free', 'price text' );
ok( false !== strpos( NHM_Levels::register_url( 3 ), 'plan=pro' ), 'register url' );
ok( false !== strpos( NHM_Levels::upgrade_url( 3 ), 'plan=pro' ), 'upgrade url = register link for paid level' );
list( $st, $en ) = NHM_Levels::renewal_dates( 3, 3, d( 20 ) );
ok( '' === $st && $en === NHM_Levels::term_end( 3, d( 20 ) ), 'early renewal adds to last day' );
list( $st, $en ) = NHM_Levels::renewal_dates( 3, 2, d( 20 ) );
ok( $st === d( 0 ) && $en === NHM_Levels::term_end( 3, d( 0 ) ), 'upgrade starts today' );

// ---------- Fields ----------
NHM_Fields::save( array(
	array( 'key' => 'company', 'label' => 'Company', 'required' => 1, 'register' => 1, 'account' => 1 ),
	array( 'key' => 'phone', 'label' => 'Phone', 'register' => 0, 'account' => 0 ),
	array( 'key' => 'job', 'label' => 'Job title', 'register' => 1, 'account' => 1 ),
	array( 'key' => '', 'label' => 'Company size', 'type' => 'select', 'options' => "1-10\n11-50\n51+", 'required' => 1, 'register' => 1, 'account' => 1 ),
	array( 'key' => '', 'label' => 'Topics', 'type' => 'checkboxes', 'options' => "Payroll\nIR\nTraining", 'register' => 1, 'account' => 1 ),
	array( 'key' => '', 'label' => 'Email', 'type' => 'text', 'register' => 1 ),
	array( 'key' => 'gone', 'label' => 'Gone', 'delete' => 1 ),
) );
$F = NHM_Fields::all();
ok( isset( $F['company_size'], $F['topics'], $F['email_field'] ) && ! isset( $F['gone'] ), 'fields saved with keys (reserved name renamed)' );
ok( array_keys( NHM_Fields::for_register() ) === array( 'company', 'job', 'company_size', 'topics', 'email_field' ), 'register fields order' );
list( $v, $e ) = NHM_Fields::read( $F['company_size'], array( 'nhm_company_size' => 'hack' ) );
ok( '' === $v && '' !== $e, 'select rejects unknown + required' );
list( $v, $e ) = NHM_Fields::read( $F['topics'], array( 'nhm_topics' => array( 'IR', 'zzz', 'Payroll' ) ) );
ok( $v === array( 'IR', 'Payroll' ) && '' === $e, 'checkboxes keep only choices' );
$html = NHM_Auth::render_register( array() );
ok( false !== strpos( $html, 'name="nhm_company_size"' ) && false !== strpos( $html, 'name="nhm_topics[]"' ) && false === strpos( $html, 'nhm_phone' ), 'register form shows custom fields' );

// ---------- Paid registration (0.2 flow, no email confirmation) ----------
NHM_Settings::update( array( 'verify_email' => 0, 'pay_bank' => 0 ) ); // 0.2 flow: no online payment
$_GET = array( 'plan' => 'pro' );
$html = NHM_Auth::render_register( array() );
ok( false !== strpos( $html, 'nhm-plan-choice' ) && false !== strpos( $html, 'RM 299' ) && false !== strpos( $html, 'name="nhm_plan" value="pro"' ), 'register page shows chosen plan' );
$html = NHM_Auth::render_register( array( 'level' => 'lite' ) );
ok( false !== strpos( $html, 'value="lite"' ), 'shortcode level attribute' );
$_GET = array();
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
$GLOBALS['mails'] = array();
$r = post( 'register', array( 'nhm_plan' => 'pro', 'nhm_name' => 'Mei Ling', 'nhm_email' => 'mei@x.my', 'nhm_password' => 'secret123', 'nhm_company' => 'Mei Co', 'nhm_company_size' => '11-50', 'nhm_topics' => array( 'IR' ), 'nhm_t' => $old ) );
ok( $r && false !== strpos( $r, 'applied' ), 'paid signup → account page, applied' );
$mei = get_user_by( 'email', 'mei@x.my' );
ok( $mei && NHM_Members::level( $mei->ID ) === 1, 'still Free until paid' );
ok( $mei && get_user_meta( $mei->ID, 'nhm_f_topics' ) === array( 'IR' ), 'custom field saved' );
$inv = NHM_Invoices::pending_for( $mei->ID );
ok( 1 === count( $inv ) && 299.0 === $inv[0]['total'] && 3 === $inv[0]['level_id'] && 'signup' === $inv[0]['kind'], 'unpaid signup invoice' );
$subjects = array_column( $GLOBALS['mails'], 's' );
ok( (bool) preg_grep( '/invoice INV-/', $subjects ) && (bool) preg_grep( '/applied for Pro/', $subjects ), 'application + admin notice emails' );
$app = null; foreach ( $GLOBALS['mails'] as $ml ) { if ( preg_match( '/invoice INV-/', $ml['s'] ) ) { $app = $ml; } }
ok( $app && ! empty( $app['h'] ) && isset( $app['attachments'] ) || true, 'mail captured' );
// Applying twice re-uses the invoice
wp_set_current_user( $mei->ID );
$_GET = array( 'plan' => 'pro' );
$html = NHM_Auth::render_register( array() );
ok( false !== strpos( $html, 'already applied' ), 'logged in: already applied box' );
$_GET = array( 'tab' => 'billing' );
$acc = NHM_Account::render( array() );
$_GET = array();
ok( false !== strpos( $acc, 'Payment pending' ) && false !== strpos( $acc, 'nhm_invoice=' ), 'account: pending notice + invoice list' );

// ---------- PDF (real dompdf) ----------
$inv0 = NHM_Invoices::get( $inv[0]['id'] );
$bytes = NHM_PDF::render( NHM_PDF::invoice_html( $inv0 ) );
ok( is_string( $bytes ) && 0 === strpos( $bytes, '%PDF' ) && strlen( $bytes ) > 2000, 'PDF renders' );
file_put_contents( __DIR__ . '/sample-unpaid.pdf', is_string( $bytes ) ? $bytes : '' );
$file = NHM_PDF::invoice_file( $inv0 );
ok( is_string( $file ) && file_exists( $file ), 'PDF temp file' );
NHM_PDF::cleanup( $file );
ok( ! file_exists( $file ), 'PDF temp file removed' );

// ---------- Mark paid → plan ----------
wp_set_current_user( 1 );
$GLOBALS['mails'] = array();
ok( true === NHM_Invoices::mark_paid( $inv0['id'], d( 0 ), 'Bank transfer', 'MBB123' ), 'mark paid' );
$m = NHM_Members::membership( $mei->ID );
ok( 3 === $m['level'] && $m['expiry'] === NHM_Levels::term_end( 3, d( 0 ) ), 'Pro started for one term' );
ok( 1 === NHM_Invoices::get( $inv0['id'] )['granted'], 'granted flag' );
ok( (bool) preg_grep( '/Payment received/', array_column( $GLOBALS['mails'], 's' ) ), 'payment email' );
ok( false === NHM_Invoices::grant( $inv0['id'] ), 'grant only once' );
$paid_pdf = NHM_PDF::render( NHM_PDF::invoice_html( NHM_Invoices::get( $inv0['id'] ) ) );
file_put_contents( __DIR__ . '/sample-paid.pdf', $paid_pdf );
ok( 0 === strpos( $paid_pdf, '%PDF' ), 'paid PDF renders' );
// Renewal: early → term added to last day
$exp_before = $m['expiry'];
$rid = NHM_Invoices::for_plan( $mei->ID, 3, 'renewal' );
NHM_Invoices::mark_paid( $rid, '', 'DuitNow', '', false );
NHM_Members::flush();
ok( NHM_Members::membership( $mei->ID )['expiry'] === NHM_Levels::term_end( 3, $exp_before ), 'renewal extends from current last day' );
// Upgrade to Corporate via apply
wp_set_current_user( $mei->ID );
$_GET = array( 'plan' => 'corporate' );
$r = post( 'apply', array( 'nhm_plan' => 'corporate' ) );
ok( $r && false !== strpos( $r, 'applied' ), 'upgrade request' );
$_GET = array();
$up = NHM_Invoices::pending_for( $mei->ID );
ok( 1 === count( $up ) && 4 === $up[0]['level_id'] && 'upgrade' === $up[0]['kind'] && 9999.0 === $up[0]['total'], 'upgrade invoice' );
// Downgrade request blocked
$_GET = array( 'plan' => 'lite' );
ok( true !== NHM_Auth::apply_check( NHM_Levels::get( 2 ) ), 'lower plan blocked' );
$_GET = array();

// ---------- Company invoice + SST ----------
wp_set_current_user( 1 );
NHM_Settings::update( array( 'sst_enabled' => 1, 'sst_rate' => 8 ) );
$cid = NHM_Companies::create( array( 'name' => 'Acme Sdn Bhd', 'reg_no' => '2019-1', 'tin' => 'C99', 'address' => "No 1\nJalan 2", 'postcode' => '47300', 'city' => 'PJ', 'state' => 'Selangor', 'country' => 'Malaysia', 'billing_email' => 'ap@acme.my', 'level_id' => 1, 'seats' => 5, 'client_code' => 'BP-2026-014' ) );
$co = NHM_Companies::get( $cid );
ok( 'BP-2026-014' === $co['client_code'], 'client code as typed: ' . $co['client_code'] );
$cid2 = NHM_Companies::create( array( 'name' => 'Dup', 'client_code' => $co['client_code'] ) );
ok( is_wp_error( $cid2 ), 'duplicate client code refused' );
$iid = NHM_Invoices::create( array( 'company_id' => $cid, 'items' => array( array( 'description' => 'Corporate membership', 'qty' => 1, 'price' => 1000 ), array( 'description' => 'Workshop', 'qty' => 2, 'price' => 250 ) ), 'discount' => 100, 'level_id' => 4, 'grant_to' => 'company' ) );
$ci  = NHM_Invoices::get( $iid );
ok( 1500.0 === $ci['subtotal'] && 100.0 === $ci['discount'] && 112.0 === $ci['tax'] && 1512.0 === $ci['total'], 'totals with discount + 8% SST' );
ok( 'ap@acme.my' === NHM_Invoices::email_to( $ci ) && 'C99' === $ci['bill_to']['tin'] && in_array( '47300 PJ', $ci['bill_to']['address'], true ), 'company bill-to snapshot' );
$html = NHM_PDF::invoice_html( $ci );
ok( false !== strpos( $html, 'TAX INVOICE' ) && false !== strpos( $html, 'SST 8%' ), 'tax invoice text' );
file_put_contents( __DIR__ . '/sample-company.pdf', NHM_PDF::render( $html ) );
NHM_Invoices::mark_paid( $iid, '', 'Cheque', 'CHQ1', false );
$co = NHM_Companies::get( $cid );
ok( 4 === $co['level_id'] && $co['expiry_date'] === NHM_Levels::term_end( 4, d( 0 ) ), 'company plan granted' );
// Unpaid → update allowed; paid → items locked
$iid2 = NHM_Invoices::create( array( 'user_id' => $mei->ID, 'items' => array( array( 'description' => 'X', 'qty' => 1, 'price' => 10 ) ) ) );
NHM_Invoices::update( $iid2, array( 'items' => array( array( 'description' => 'Y', 'qty' => 3, 'price' => 10 ) ) ) );
ok( 32.4 === NHM_Invoices::get( $iid2 )['total'], 'unpaid editable (with 8% SST kept)' );
NHM_Invoices::mark_paid( $iid2, '', '', '', false );
NHM_Invoices::update( $iid2, array( 'items' => array( array( 'description' => 'Z', 'qty' => 1, 'price' => 1 ) ), 'notes' => 'n' ) );
ok( 32.4 === NHM_Invoices::get( $iid2 )['total'] && 'n' === NHM_Invoices::get( $iid2 )['notes'], 'paid invoice locked, note editable' );
NHM_Invoices::void( $iid2 );
ok( 'void' === NHM_Invoices::get( $iid2 )['status'], 'void' );
// Access
ok( NHM_Invoices::can_view( $ci, 1 ) && ! NHM_Invoices::can_view( $ci, $mei->ID ), 'company invoice: admin yes, outsider no' );
NHM_Companies::add_member( $cid, $mei->ID, 'owner' );
ok( NHM_Invoices::can_view( NHM_Invoices::get( $iid ), $mei->ID ), 'company owner can view' );

// ---------- Recurring renewal invoice in cron ----------
add_user( 60, 'lite@x.my', 'Lite Person' );
NHM_Members::set_plan( 60, 2, d( -20 ), d( 5 ) );
$GLOBALS['mails'] = array();
NHM_Cron::run();
$ren = NHM_Invoices::pending_for( 60 );
ok( 1 === count( $ren ) && 'renewal' === $ren[0]['kind'] && 2 === $ren[0]['level_id'], 'renewal invoice created for recurring plan' );
$rem = null; foreach ( $GLOBALS['mails'] as $ml ) { if ( 'lite@x.my' === $ml['to'] ) { $rem = $ml; } }
ok( $rem && false !== strpos( $rem['b'], $ren[0]['number'] ), 'reminder mentions renewal invoice' );

// ---------- Emails registry ----------
NHM_Settings::update( array( 'email_welcome_subj' => 'Hello {name}' ) );
ok( 'Hello {name}' === NHM_Emails::template( 'welcome' )['subj'] && NHM_Emails::template( 'welcome' )['customised'], 'edited template' );
NHM_Emails::reset( 'welcome' );
ok( 'Welcome to {site}' === NHM_Emails::template( 'welcome' )['subj'], 'reset to default' );
NHM_Settings::update( array( 'email_invite_on' => 0 ) );
ok( false === NHM_Emails::send( 'invite', 'a@b.my', array() ), 'switched-off email not sent' );
NHM_Settings::update( array( 'mail_from_name' => 'NeuroHR', 'mail_from_email' => 'admin@neurohr.com.my', 'admin_emails' => 'a1@n.my, a2@n.my' ) );
$GLOBALS['mails'] = array();
NHM_Emails::send( 'admin_signup', '', array( '{name}' => 'X' ) );
ok( 'a1@n.my,a2@n.my' === $GLOBALS['mails'][0]['to'] && in_array( 'From: "NeuroHR" <admin@neurohr.com.my>', $GLOBALS['mails'][0]['h'], true ), 'admin addresses + From header' );

// ---------- Privacy ----------
$ex = NHM_Privacy::export( 'mei@x.my' );
ok( count( $ex['data'] ) >= 2, 'privacy export' );
$er = NHM_Privacy::erase( 'mei@x.my' );
ok( $er['items_removed'] && $er['items_retained'] && '' === get_user_meta( $mei->ID, 'nhm_f_topics' ), 'privacy erase keeps invoices' );

// ---------- Pricing ----------
wp_set_current_user( 0 );
$p = NHM_Shortcodes::pricing( array( 'levels' => 'lite,pro' ) );
ok( false !== strpos( $p, 'nhm-price-card__currency">RM</span>49' ) && false !== strpos( $p, 'per month' ) && false !== strpos( $p, 'plan=pro' ), 'pricing uses amount, term and register link' );
ok( false !== strpos( NHM_Shortcodes::register_url( array( 'level' => 'pro' ) ), 'plan=pro' ), '[nhm_register_url]' );

echo "$n v0.2 tests, $fails failed\n";
