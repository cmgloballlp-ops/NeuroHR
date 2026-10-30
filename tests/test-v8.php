<?php
// 0.4.4: invitation page, account tables.
require __DIR__ . '/stubs-front.php';
require __DIR__ . '/stub-extra.php';
if ( ! function_exists( 'wp_redirect' ) ) { function wp_redirect( $u ) { throw new Redirect( $u ); } }
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $l ) { return false; } }
if ( ! function_exists( 'get_post_status' ) ) { function get_post_status( $id ) { return 'publish'; } }
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.5';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4, 'verify_email' => 1 ) );
add_user( 30, 'owner@co.my', 'Owner' );
$cid = NHM_Companies::create( array( 'name' => 'Q-Q FOOD', 'level_id' => 4, 'expiry_date' => '2027-10-30', 'seats' => 5 ) );
NHM_Companies::add_member( $cid, 30, 'owner' ); NHM_Companies::set_owner( $cid, 30 );
ok( false !== strpos( NHM_Companies::invite_url( 'abc' ), '?p=2' ), 'without an invitation page: registration page' );
NHM_Settings::update( array( 'page_invite' => 5 ) );
ok( false !== strpos( NHM_Companies::invite_url( 'abc' ), '?p=5' ), 'invitation page used' );
// A token we know.
NHM_Companies::invite( $cid, 'siti@co.my', 'member', 30 );
$inv = NHM_Companies::invites( $cid )[0];
$tok = str_repeat( 'k', 32 );
global $wpdb; $wpdb->update( NHM_DB::table( 'invites' ), array( 'token_hash' => hash( 'sha256', $tok ) ), array( 'id' => $inv['id'] ) );
wp_set_current_user( 0 );
$_GET = array( 'nhm_invite' => 'nope' );
ok( false !== strpos( NHM_Auth::render_invite( array() ), 'expired or was already used' ), 'bad link explained' );
$_GET = array( 'nhm_invite' => $tok );
$h = NHM_Auth::render_invite( array() );
ok( false !== strpos( $h, 'Join Q-Q FOOD' ) && false !== strpos( $h, 'value="siti@co.my"' ) && false !== strpos( $h, 'readonly' ) && false !== strpos( $h, 'Join and log in' ), 'invitation form' );
ok( false === strpos( $h, 'nhm_plan' ) && false === stripos( $h, 'pricing' ), 'no plans or prices' );
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
$r = post( 'register', array( 'nhm_invite' => $tok, 'nhm_email' => 'other@x.my', 'nhm_name' => 'Siti', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
$uid = email_exists( 'siti@co.my' );
NHM_Members::flush();
ok( $uid && ! email_exists( 'other@x.my' ), 'account made with the invited email' );
ok( $uid && (int) get_user_meta( $uid, NHM_Members::META_COMPANY, true ) === $cid && 4 === NHM_Members::level( $uid ), 'joined the company, Corporate access' );
ok( $uid && ! NHM_Auth::is_unverified( $uid ), 'no email confirmation needed' );
// Owner removes her.
wp_set_current_user( 30 );
$_GET = array( 'tab' => 'company' );
$h = NHM_Account::render( array() );
ok( false !== strpos( $h, 'nhm-btn--danger' ) && false !== strpos( $h, 'data-nhm-confirm-ok="Remove"' ) && false !== strpos( $h, '>You<' ), 'Remove button with confirm; owner row says You' );
$_GET = array();
post( 'remove_member', array( 'nhm_user' => $uid ) );
NHM_Members::flush();
ok( ! get_user_meta( $uid, NHM_Members::META_COMPANY, true ) && 1 === NHM_Members::level( $uid ), 'removed: back to Free' );
// Invoices table: View invoice, dialog outside the table.
$d = get_option( "nhm_levels" ); foreach ( $d["items"] as $k => $it ) { if ( 3 === (int) $it["id"] ) { $d["items"][ $k ]["amount"] = "399"; } } update_option( "nhm_levels", $d ); NHM_Levels::flush();
$i = NHM_Invoices::for_plan( 30, 3, "signup" ); NHM_Invoices::mark_paid( $i, '', 'Card', '', false );
NHM_Settings::update( array( 'refund_hours' => 72 ) );
$_GET = array( 'tab' => 'billing' );
$h = NHM_Account::render( array() );
$t = substr( $h, strpos( $h, '<table' ), strpos( $h, '</table>' ) - strpos( $h, '<table' ) );
ok( false !== strpos( $t, 'View invoice' ) && false === strpos( $t, '<dialog' ) && false !== strpos( $h, '<dialog class="nhm-modal" id="nhm-refund-' ), 'View invoice; refund dialog outside the table' );
echo "$n v0.4.4 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
