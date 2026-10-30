<?php
require __DIR__ . '/wp-stub.php';
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( "$str @ $file:$line" ); } );
class Redirect extends Exception {}
function wp_nonce_field( $a, $n, $r = true, $e = true ) { $h = '<input type="hidden" name="' . $n . '" value="nonce">'; if ( $e ) { echo $h; } return $h; }
function wp_verify_nonce() { return 1; }
function checked( $a, $b = true, $e = true ) { $r = $a == $b ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = $a == $b ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function wp_rand( $a, $b ) { return mt_rand( $a, $b ); }
function wp_register_style() {} function wp_register_script() {} function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_add_inline_style() {}
function wp_style_is() { return true; }
function is_ssl() { return true; }
function wp_validate_redirect( $u, $f ) { return 0 === strpos( $u, 'https://example.test' ) ? $u : $f; }
function wp_safe_redirect( $u ) { throw new Redirect( $u ); }
function wp_insert_user( $d ) { $id = 100 + count( $GLOBALS['users'] ); $u = add_user( $id, $d['user_email'], $d['display_name'] ); $u->user_login = $d['user_login']; return $id; }
function wp_update_user( $d ) { $u = get_userdata( $d['ID'] ); if ( isset( $d['user_email'] ) ) { $u->user_email = $d['user_email']; } if ( isset( $d['display_name'] ) ) { $u->display_name = $d['display_name']; } return $d['ID']; }
function wp_set_auth_cookie() {}
function wp_new_user_notification() { $GLOBALS['admin_notified'] = true; }
function wp_signon( $c ) { $u = get_user_by( 'email', $c['user_login'] ); return ( $u && 'secret123' === $c['user_password'] ) ? $u : new WP_Error( 'x', 'bad' ); }
function wp_check_password( $p ) { return 'secret123' === $p; }
function retrieve_password( $l ) { return true; }
function check_password_reset_key( $k, $l ) { return 'goodkey' === $k ? get_user_by( 'email', $l ) : new WP_Error( 'x', 'bad' ); }
function reset_password( $u, $p ) { $GLOBALS['reset'] = $p; }
function is_singular() { return false; }
function get_queried_object_id() { return 0; }
function untrailingslashit( $u ) { return rtrim( $u, '/' ); }

$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_lost' => 3, 'page_account' => 4, 'page_pricing' => 5, 'ml_enabled' => 1, 'ml_api_key' => 'k', 'ml_group' => '123', 'terms_text' => 'I agree' ) );
$_SERVER['REQUEST_URI'] = '/account/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
function form_errors( $f ) { return NHM_Forms::render_messages( $f ); }

// Guest renders
foreach ( array( array( 'NHM_Auth', 'render_login' ), array( 'NHM_Auth', 'render_register' ), array( 'NHM_Auth', 'render_lost' ), array( 'NHM_Account', 'render' ), array( 'NHM_Shortcodes', 'pricing' ) ) as $cb ) {
	$html = call_user_func( $cb, array() );
	ok( is_string( $html ) && strlen( $html ) > 50 && false !== strpos( $html, 'class="nhm' ), 'render ' . $cb[1] );
}
$reg = NHM_Auth::render_register( array() );
ok( false !== strpos( $reg, 'nhm_newsletter' ) && false !== strpos( $reg, 'nhm_company' ) && false !== strpos( $reg, 'nhm_t' ) && false !== strpos( $reg, 'nhm_terms' ), 'register form fields' );
NHM_Fields::save( array( array( 'key' => 'company', 'label' => 'Company', 'required' => 1, 'register' => 1, 'account' => 1 ), array( 'key' => 'phone', 'label' => 'Phone', 'register' => 0, 'account' => 0 ), array( 'key' => 'job', 'label' => 'Job title', 'register' => 1, 'account' => 1 ) ) );
$reg = NHM_Auth::render_register( array() );
ok( false === strpos( $reg, 'nhm_phone' ) && false !== strpos( $reg, 'nhm_company' ), 'hidden field not shown' );

// Register: too fast
$t = time(); $tok = $t . '.' . hash_hmac( 'sha256', (string) $t, 'salt' );
$r = post( 'register', array( 'nhm_name' => 'Aisha', 'nhm_email' => 'aisha@x.my', 'nhm_password' => 'secret123', 'nhm_company' => 'X Sdn Bhd', 'nhm_t' => $tok, 'nhm_terms' => 1 ) );
ok( null === $r && false !== strpos( form_errors( 'register' ), 'fast' ), 'register too fast blocked' );
// Register: honeypot
$old = ( $t - 10 ) . '.' . hash_hmac( 'sha256', (string) ( $t - 10 ), 'salt' );
$r = post( 'register', array( 'nhm_name' => 'Bot', 'nhm_email' => 'bot@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old, 'nhm_website' => 'spam' ) );
ok( null === $r, 'honeypot blocked' );
// Register: missing required company + no terms
$r = post( 'register', array( 'nhm_name' => 'Aisha', 'nhm_email' => 'aisha@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old ) );
ok( null === $r, 'missing company/terms blocked' );
// Register OK (0.2 flow: no email confirmation)
NHM_Settings::update( array( 'verify_email' => 0 ) );
$GLOBALS['remote'] = array();
$r = post( 'register', array( 'nhm_name' => 'Aisha', 'nhm_email' => 'aisha@x.my', 'nhm_password' => 'secret123', 'nhm_company' => 'X Sdn Bhd', 'nhm_t' => $old, 'nhm_terms' => 1, 'nhm_newsletter' => 1 ) );
ok( $r && false !== strpos( $r, 'nhm_msg=registered' ), 'register ok → redirect' );
$aisha = get_user_by( 'email', 'aisha@x.my' );
ok( $aisha && 'X Sdn Bhd' === get_user_meta( $aisha->ID, 'nhm_company_name' ), 'profile saved' );
ok( ! empty( $GLOBALS['remote'] ) && false !== strpos( $GLOBALS['remote'][0][0], 'mailerlite' ) && false !== strpos( $GLOBALS['remote'][0][1]['body'], '"groups":["123"]' ), 'mailerlite subscribe' );
ok( (bool) preg_grep( '/New account/', array_column( $GLOBALS['mails'], 's' ) ), 'admin notified (New Signup email)' );
// Duplicate email
$r = post( 'register', array( 'nhm_name' => 'A2', 'nhm_email' => 'aisha@x.my', 'nhm_password' => 'secret123', 'nhm_company' => 'X', 'nhm_t' => $old, 'nhm_terms' => 1 ) );
ok( null === $r, 'duplicate email blocked' );

// Login
wp_set_current_user( 0 );
$r = post( 'login', array( 'log' => 'aisha@x.my', 'pwd' => 'wrong' ) );
ok( null === $r && false !== strpos( form_errors( 'login' ), 'not correct' ), 'wrong password' );
$r = post( 'login', array( 'log' => 'aisha@x.my', 'pwd' => 'secret123', 'redirect_to' => 'https://example.test/knowledge/x/' ) );
ok( 'https://example.test/knowledge/x/' === $r, 'login back to page' );
$r = post( 'login', array( 'log' => 'aisha@x.my', 'pwd' => 'secret123', 'redirect_to' => 'https://evil.test/' ) );
ok( 'https://example.test/dashboard/' === $r, 'login external redirect refused' );

// Lost / reset
$r = post( 'lost', array( 'user_login' => 'nobody@x.my' ) );
ok( $r && false !== strpos( $r, 'reset_sent' ), 'lost: same answer' );
$msg = NHM_Auth::reset_message( '', 'KEY1', 'aisha', $aisha );
ok( false !== strpos( $msg, '?p=3' ) && false !== strpos( $msg, 'key=KEY1' ), 'reset email links to own page' );
$r = post( 'reset', array( 'rp_key' => 'goodkey', 'rp_login' => 'aisha@x.my', 'pass1' => 'short' ) );
ok( null === $r, 'reset: short password blocked' );
$r = post( 'reset', array( 'rp_key' => 'goodkey', 'rp_login' => 'aisha@x.my', 'pass1' => 'longpassword' ) );
ok( $r && 'longpassword' === $GLOBALS['reset'], 'reset ok' );
$_GET = array( 'action' => 'rp', 'key' => 'goodkey', 'login' => 'aisha@x.my' );
ok( false !== strpos( NHM_Auth::render_lost( array() ), 'pass1' ), 'reset form shows' );
$_GET = array();

// Logged in: account with company owner
wp_set_current_user( $aisha->ID );
$cid = NHM_Companies::create( array( 'name' => 'X Sdn Bhd', 'level_id' => 4, 'expiry_date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ), 'seats' => 3 ) );
NHM_Companies::add_member( $cid, $aisha->ID, 'owner' );
$_GET = array( 'tab' => 'company' );
$html = NHM_Account::render( array() );
$_GET = array( 'tab' => 'billing' );
$html .= NHM_Account::render( array() );
$_GET = array();
ok( false !== strpos( $html, 'Corporate' ) && false !== strpos( $html, 'seats used' ) && false !== strpos( $html, 'Renew' ), 'account: owner view' );
$r = post( 'invite', array( 'nhm_invite_email' => 'colleague@x.my' ) );
ok( $r && false !== strpos( $r, 'invited' ), 'owner invites' );
$inv = NHM_Companies::invites( $cid );
ok( 1 === count( $inv ), 'invite stored' );
$r = post( 'resend', array( 'nhm_invite_id' => $inv[0]['id'] ) );
ok( $r && false !== strpos( $r, 'resent' ), 'resend' );
// accept as guest via registration
preg_match( '/nhm_invite=([A-Za-z0-9]+)/', end( $GLOBALS['mails'] )['b'], $mm );
wp_set_current_user( 0 );
$_GET = array( 'nhm_invite' => $mm[1] );
$reg = NHM_Auth::render_register( array() );
ok( false !== strpos( $reg, 'readonly' ) && false !== strpos( $reg, 'X Sdn Bhd' ), 'invite registration form' );
$_GET = array();
$r = post( 'register', array( 'nhm_invite' => $mm[1], 'nhm_name' => 'Colleague', 'nhm_email' => 'hacker@x.my', 'nhm_password' => 'secret123', 'nhm_company' => 'X', 'nhm_t' => $old, 'nhm_terms' => 1 ) );
$col = get_user_by( 'email', 'colleague@x.my' );
ok( $r && false !== strpos( $r, 'joined' ) && $col && ! get_user_by( 'email', 'hacker@x.my' ), 'invite register uses invited email + joins' );
ok( $col && NHM_Members::membership( $col->ID )['level'] === 4, 'colleague has Corporate' );
// staff view + leave
wp_set_current_user( $col->ID );
$_GET = array( 'tab' => 'company' );
$html = NHM_Account::render( array() );
$_GET = array();
ok( false !== strpos( $html, 'managed by' ), 'staff view' );
// owner removes
wp_set_current_user( $aisha->ID );
$r = post( 'remove_member', array( 'nhm_user' => $col->ID ) );
ok( $r && NHM_Members::membership( $col->ID )['level'] === 1, 'owner removes staff' );
$r = post( 'remove_member', array( 'nhm_user' => $aisha->ID ) );
ok( null === $r, 'owner cannot remove self' );
// non-owner cannot invite
wp_set_current_user( $col->ID );
$r = post( 'invite', array( 'nhm_invite_email' => 'z@x.my' ) );
ok( null === $r, 'non-owner cannot invite' );
// profile + password
wp_set_current_user( $aisha->ID );
$r = post( 'profile', array( 'nhm_name' => 'Aisha B', 'nhm_email' => 'new@x.my', 'nhm_company' => 'X', 'nhm_current' => 'wrong' ) );
ok( null === $r, 'email change needs password' );
$r = post( 'profile', array( 'nhm_name' => 'Aisha B', 'nhm_email' => 'new@x.my', 'nhm_company' => 'X', 'nhm_current' => 'secret123' ) );
ok( $r && 'new@x.my' === get_userdata( $aisha->ID )->user_email, 'profile + email saved' );
$r = post( 'password', array( 'nhm_current' => 'secret123', 'nhm_new' => 'abc' ) );
ok( null === $r, 'short new password' );
// pricing + shortcodes logged in
$p = NHM_Shortcodes::pricing( array( 'levels' => 'lite,pro,corporate' ) );
ok( false !== strpos( $p, 'Your plan' ) && false !== strpos( $p, 'Included' ), 'pricing marks current/included' );
ok( 'Aisha' === NHM_Shortcodes::user( array( 'field' => 'first_name' ) ) && 'Corporate' === NHM_Shortcodes::user( array( 'field' => 'level' ) ), '[nhm_user]' );
ok( false !== strpos( NHM_Shortcodes::logout( array() ), 'logout' ), '[nhm_logout]' );
ok( false !== strpos( NHM_Auth::render_login( array() ), 'logged in as' ), 'login form when logged in' );
// Protect
update_post_meta( 50, '_nhm_min_level', 3 );
$post = new stdClass(); $post->ID = 50; $post->post_type = 'page'; $post->post_excerpt = '';
function get_post( $p = null ) { global $post; return $post; }
function has_excerpt() { return false; }
ok( NHM_Protect::can_view( 50, $aisha->ID ) && ! NHM_Protect::can_view( 50, $col->ID ) && ! NHM_Protect::can_view( 50, 0 ), 'protect can_view' );
wp_set_current_user( $col->ID );
ok( false !== strpos( NHM_Protect::filter_content( 'SECRET' ), 'Upgrade to Pro' ), 'protect replaces content' );
wp_set_current_user( $aisha->ID );
ok( 'SECRET' === NHM_Protect::filter_content( 'SECRET' ), 'protect shows to allowed' );

echo "$n render/handler tests, $fails failed\n";
