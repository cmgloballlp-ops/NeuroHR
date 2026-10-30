<?php
// v0.4.2 tests: invoice product line, PDF QR + alignment, QR zoom, block / unblock / delete.
require __DIR__ . '/stubs-front.php';
if ( ! function_exists( 'wp_remote_request' ) ) { function wp_remote_request( $u, $a ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); } }
if ( ! function_exists( 'wp_redirect' ) ) { function wp_redirect( $u ) { throw new Redirect( $u ); } }
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $l ) { return false; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a ) { return 'NONCE'; } }
if ( ! function_exists( 'wp_delete_user' ) ) { function wp_delete_user( $id ) { do_action( 'delete_user', $id ); unset( $GLOBALS['users'][ $id ], $GLOBALS['usermeta'][ $id ] ); $GLOBALS['deleted'][] = $id; return true; } }

$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
function form_errors( $f ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $e = $rp->getValue(); return isset( $e[ $f ] ) ? implode( ' ', $e[ $f ] ) : ''; }

$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.8';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4, 'biz_name' => 'CM HORIZON CONSULTANCY PLT', 'biz_email' => 'hello@neurohr.com.my', 'bank_name' => 'Maybank', 'bank_account_no' => '5140 1234', 'verify_email' => 0, 'pay_qr' => 1, 'qr_image' => 'https://example.test/wp-content/uploads/qr.png', 'qr_name' => 'CM Horizon' ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts', 'manage_options', 'delete_users', NHM_CAP ) );
$d = get_option( 'nhm_levels' );
foreach ( $d['items'] as $i => $it ) { if ( in_array( $it['slug'], array( 'pro', 'lite' ), true ) ) { $d['items'][ $i ]['amount'] = '300'; } }
update_option( 'nhm_levels', $d ); NHM_Levels::flush();
$lite = 0; $pro = 0;
foreach ( NHM_Levels::all() as $l ) { if ( 'lite' === $l['slug'] ) { $lite = $l['id']; } if ( 'pro' === $l['slug'] ) { $pro = $l['id']; } }

// ---------- 1. Product line ----------
$GLOBALS['bloginfo_name'] = 'NeuroHR';
$line = NHM_Invoices::plan_line( $pro );
ok( 0 === strpos( $line, NHM_Invoices::brand() . ' - Pro Membership (' ), 'brand - Plan Membership (term): ' . $line );
ok( false === strpos( $line, 'CM HORIZON' ), 'no company name in the line' );
ok( false === strpos( $line, '—' ), 'no long dash' );
NHM_Settings::update( array( 'biz_product' => 'NeuroHR' ) );
ok( 'NeuroHR - Lite Membership (1 year)' === NHM_Invoices::plan_line( $lite ), 'exact: ' . NHM_Invoices::plan_line( $lite ) );
ok( false !== strpos( NHM_Invoices::plan_line( $lite, '2026-10-30', '2027-10-29' ), '(1 year, 30 October 2026 to 29 October 2027)' ), 'with dates: ' . NHM_Invoices::plan_line( $lite, '2026-10-30', '2027-10-29' ) );

// ---------- 2. PDF ----------
add_user( 60, 'tan@x.my', 'Tan' );
$iid = NHM_Invoices::for_plan( 60, $lite, 'signup' );
$inv = NHM_Invoices::get( $iid );
ok( 'NeuroHR - Lite Membership (1 year)' === $inv['items'][0]['description'], 'new invoice line' );
// A real QR file in uploads so it can be embedded.
$up = wp_upload_dir(); if ( ! is_dir( $up['basedir'] ) ) { mkdir( $up['basedir'], 0777, true ); }
file_put_contents( $up['basedir'] . '/qr.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
NHM_Settings::update( array( 'qr_image' => $up['baseurl'] . '/qr.png' ) );
$html = NHM_PDF::invoice_html( $inv );
ok( false !== strpos( $html, '.items th.num' ), 'header cells of number columns aligned right' );
ok( false !== strpos( $html, 'class="qr"' ) && false !== strpos( $html, 'data:image/png;base64' ), 'QR picture on unpaid invoice' );
ok( false !== strpos( $html, 'DuitNow QR' ) && false !== strpos( $html, 'Scan with any banking app' ), 'QR caption' );
NHM_Settings::update( array( 'pay_qr' => 0 ) );
ok( false === strpos( NHM_PDF::invoice_html( $inv ), 'class="qr"' ), 'no QR when QR payments are off' );
NHM_Settings::update( array( 'pay_qr' => 1 ) );
$paid = $inv; $paid['status'] = 'paid'; $paid['paid_date'] = '2026-10-30';
$ph = NHM_PDF::invoice_html( $paid );
ok( false === strpos( $ph, 'class="qr"' ), 'no QR on a paid invoice' );
ok( false !== strpos( $ph, 'Payment received</strong> on' ), 'paid line without dash' );

// ---------- 3. QR zoom on the pay page ----------
$_GET = array( 'method' => 'qr' );
$page = NHM_Payments::render( $inv );
ok( false !== strpos( $page, 'data-nhm-dialog="nhm-qr-big"' ), 'QR opens a pop-up' );
ok( false !== strpos( $page, '<dialog class="nhm-modal nhm-modal--qr" id="nhm-qr-big"' ), 'pop-up present' );
ok( false !== strpos( $page, 'Tap to enlarge' ), 'hint' );
ok( false !== strpos( $page, ' download>' ), 'save QR link' );
ok( false !== strpos( $page, '<strong>' . $inv['number'] . '</strong>' ), 'pop-up shows the reference' );
$_GET = array();

// ---------- 4. Block ----------
wp_set_current_user( 1 );
add_user( 70, 'spam@bad.my', 'Spammer' );
NHM_Members::set_plan( 70, $pro, '2026-01-01', '2027-01-01' );
update_user_meta( 70, 'session_tokens', array( 'a' => array( 'x' ) ) );
$bad_inv = NHM_Invoices::for_plan( 70, $lite, 'upgrade' );
$r = NHM_Block::block( 70, 'Spam' );
ok( true === $r, 'blocked' );
ok( NHM_Block::is_blocked( 70 ), 'flag set' );
ok( in_array( 70, (array) $GLOBALS['destroyed_all'], true ), 'logged out everywhere' );
NHM_Members::flush();
ok( (int) NHM_Members::personal( 70 )['level'] === (int) NHM_Levels::base_id(), 'own plan back to free' );
ok( 'void' === NHM_Invoices::get( $bad_inv )['status'], 'unpaid invoice voided' );
ok( NHM_Block::email_blocked( 'SPAM@bad.my' ), 'email on the list (any case)' );
ok( false !== strpos( NHM_Settings::get( 'blocklist' ), 'spam@bad.my' ), 'shown in settings' );
$err = NHM_Block::stop_login( get_userdata( 70 ) );
ok( is_wp_error( $err ) && false !== strpos( $err->get_error_message(), "can't be used" ) && false !== strpos( $err->get_error_message(), 'hello@neurohr.com.my' ), 'polite login message with contact' );
ok( is_wp_error( apply_filters( 'wp_authenticate_user', get_userdata( 70 ), 'x' ) ), 'login filter stops them' );
ok( get_userdata( 60 ) === apply_filters( 'wp_authenticate_user', get_userdata( 60 ), 'x' ) || ! is_wp_error( apply_filters( 'wp_authenticate_user', get_userdata( 60 ), 'x' ) ), 'others still log in' );
ok( is_wp_error( NHM_Block::block( 1 ) ), 'cannot block staff / yourself' );
// Registration with a blocked email or domain.
wp_set_current_user( 0 );
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
NHM_Settings::update( array( 'blocklist' => NHM_Settings::get( 'blocklist' ) . "\n@junk.test" ) );
post( 'register', array( 'nhm_name' => 'Again', 'nhm_email' => 'spam@bad.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
ok( false !== strpos( form_errors( 'register' ), "can't be used" ), 'blocked email cannot register: ' . form_errors( 'register' ) );
post( 'register', array( 'nhm_name' => 'Junk', 'nhm_email' => 'any@JUNK.test', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
ok( false !== strpos( form_errors( 'register' ), "can't be used" ), 'blocked domain cannot register' );
ok( ! email_exists( 'any@junk.test' ), 'no account made' );
ok( NHM_Block::email_blocked( 'x@junk.test' ) && ! NHM_Block::email_blocked( 'x@notjunk.test' ), 'domain match is exact' );
class ErrBag { public $e = array(); function add( $c, $m ) { $this->e[] = $c; } }
$e = new ErrBag();
NHM_Block::wp_register_check( $e, 'u', 'a@junk.test' );
ok( array( 'nhm_blocked' ) === $e->e, 'wp-login register also checked' );
// Unblock.
wp_set_current_user( 1 );
NHM_Block::unblock( 70 );
ok( ! NHM_Block::is_blocked( 70 ) && ! NHM_Block::email_blocked( 'spam@bad.my' ), 'unblocked and off the list' );
ok( NHM_Block::email_blocked( 'a@junk.test' ), 'other entries stay' );
ok( ! is_wp_error( NHM_Block::stop_login( get_userdata( 70 ) ) ), 'can log in again' );

// ---------- 5. Admin list ----------
if ( ! function_exists( 'wp_list_pluck' ) ) { function wp_list_pluck( $l, $f ) { return array_map( function ( $o ) use ( $f ) { return is_object( $o ) ? $o->$f : $o[ $f ]; }, $l ); } }
if ( ! function_exists( 'update_meta_cache' ) ) { function update_meta_cache( $t, $i ) {} }
require_once $W . '/neurohr-members/admin/class-nhm-admin.php';
require_once $W . '/neurohr-members/admin/class-nhm-admin-members.php';
NHM_Block::block( 70 );
list( $rows, $counts ) = NHM_Admin_Members::rows( array( 'view' => 'blocked', 'level' => 0, 'company' => 0, 's' => '', 'paged' => 1 ) );
ok( 1 === $counts['blocked'] && 1 === count( $rows ) && 70 === (int) $rows[0]['user']->ID, 'Blocked tab' );
ok( ! in_array( 70, array_map( function ( $r ) { return (int) $r['user']->ID; }, NHM_Admin_Members::rows( array( 'view' => 'free', 'level' => 0, 'company' => 0, 's' => '', 'paged' => 1 ) )[0] ), true ), 'not in Free tab' );

// ---------- 6. Delete ----------
// Paid invoice + payment for Tan, then delete keeping records.
NHM_Invoices::mark_paid( $iid, '2026-10-30', 'Card', 'pi_1', false );
$iid2 = NHM_Invoices::for_plan( 60, $pro, 'upgrade' );
$r = NHM_Block::delete_member( 60 );
ok( true === $r && ! get_userdata( 60 ), 'account deleted' );
ok( 'paid' === NHM_Invoices::get( $iid )['status'], 'paid invoice kept' );
ok( 'Tan' === NHM_Invoices::get( $iid )['bill_to']['name'], 'name kept on invoice' );
ok( 'void' === NHM_Invoices::get( $iid2 )['status'], 'unpaid invoice voided' );
// With records.
add_user( 80, 'test@x.my', 'Test buyer' );
$i3 = NHM_Invoices::for_plan( 80, $lite, 'signup' );
$pid = NHM_Payments::create( array( 'invoice_id' => $i3, 'method' => 'bank', 'amount' => 300, 'status' => 'submitted' ) );
NHM_Log::add( array( 'user_id' => 80, 'event' => 'registered', 'message' => 'x' ) );
$r = NHM_Block::delete_member( 80, true );
ok( true === $r && ! get_userdata( 80 ), 'test account deleted' );
ok( ! NHM_Invoices::get( $i3 ), 'its invoice gone' );
ok( ! NHM_Payments::get( $pid ), 'its payment gone' );
ok( ! NHM_Log::query( array( 'user_id' => 80 ) ), 'its history gone' );
ok( is_wp_error( NHM_Block::delete_member( 1 ) ), 'cannot delete staff / yourself' );
// Deleting a blocked person keeps the email blocked.
NHM_Block::delete_member( 70 );
ok( NHM_Block::email_blocked( 'spam@bad.my' ), 'blocked email stays blocked after delete' );

// ---------- 7. Account email change to a blocked address ----------
add_user( 90, 'ok@x.my', 'Okay' );
wp_set_current_user( 90 );
post( 'profile', array( 'nhm_name' => 'Okay', 'nhm_email' => 'new@junk.test', 'nhm_current_password' => 'secret123' ) );
ok( false !== strpos( form_errors( 'profile' ), "can't be used" ), 'cannot switch email to a blocked one' );

echo "$n v0.4.2 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
