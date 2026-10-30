<?php
// v0.3 tests: invoices per plan + terms, inclusive SST, email confirmation, phone/location,
// visibility rules, menus, hide-WP pieces, avatars, shell, account tabs and handlers.
require __DIR__ . '/stubs-front.php';
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $u, $a ) {
		$GLOBALS['remote'][] = array( $u, $a );
		$m = $a['method'];
		if ( 'GET' === $m ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{"data":{"id":"777"}}' ); }
		return array( 'response' => array( 'code' => 'DELETE' === $m ? 204 : 200 ), 'body' => '' );
	}
}
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $l ) { return false; } }
if ( ! function_exists( 'wp_logout_url' ) ) { function wp_logout_url( $r = '' ) { return apply_filters( 'logout_url', 'https://example.test/wp-login.php?action=logout', $r ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a ) { return 'NONCE'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function post( $action, $fields ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); NHM_Members::flush(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'nhm_action' => $action, '_nhm_nonce' => 'n' ), $fields ); try { NHM_Forms::dispatch(); } catch ( Redirect $r ) { $_SERVER['REQUEST_METHOD'] = 'GET'; return $r->getMessage(); } $_SERVER['REQUEST_METHOD'] = 'GET'; return null; }
function form_errors( $f ) { $rp = new ReflectionProperty( 'NHM_Forms', 'errors' ); $rp->setAccessible( true ); $e = $rp->getValue(); return isset( $e[ $f ] ) ? implode( ' ', $e[ $f ] ) : ''; }
function get_redirect( $cb ) { try { $cb(); } catch ( Redirect $r ) { return $r->getMessage(); } return null; }
$_SERVER['REQUEST_URI'] = '/register/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4, 'page_pricing' => 5, 'biz_name' => 'NeuroHR Sdn. Bhd.', 'ml_api_key' => 'KEY', 'ml_group' => '111', 'ml_group_events' => '333' ) );
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );
$data = get_option( 'nhm_levels' );
$data['items'][2]['amount'] = '299.00';
$data['items'][3]['amount'] = '9999.00';
update_option( 'nhm_levels', $data ); NHM_Levels::flush();

// ---------- Invoices: plan + terms ----------
ok( '2 years' === NHM_Levels::duration_label( 3, 2 ) && '1 year' === NHM_Levels::duration_label( 3, 1 ), 'duration label' );
add_user( 20, 'buyer@x.my', 'Buyer' );
list( $first, $end, $start ) = NHM_Invoices::plan_period( 3, 2, 20 );
ok( $start === current_time( 'Y-m-d' ) && $end === NHM_Levels::term_end( 3, NHM_Levels::term_end( 3, $start ) ), 'two terms = two years: ' . $start . ' → ' . $end );
$t = NHM_Invoices::totals( array( array( 'description' => 'x', 'qty' => 2, 'price' => 299 ) ), 0, 8, true );
ok( 598.0 === (float) $t['total'] && abs( $t['tax'] - 44.30 ) < 0.01, 'SST included: total stays 598, tax 44.30 inside (' . $t['tax'] . ')' );
$t2 = NHM_Invoices::totals( array( array( 'description' => 'x', 'qty' => 1, 'price' => 299 ) ), 0, 8, false );
ok( abs( $t2['total'] - 322.92 ) < 0.001, 'SST added on top' );
$line = NHM_Invoices::plan_line( 3, '', '', 2 );
ok( false !== strpos( $line, 'Pro' ) && false !== strpos( $line, '2 years' ), 'plan line: ' . $line );

// ---------- Phone + location ----------
ok( '+60123456789' === NHM_Fields::join_phone( '+60', '012-345 6789' ), 'phone: leading 0 dropped' );
ok( '+6591234567' === NHM_Fields::join_phone( '+65', '9123 4567' ), 'phone: SG' );
ok( '+447700900123' === NHM_Fields::join_phone( '+60', '+44 7700 900123' ), 'phone typed with own code' );
ok( array( '+60', '123456789' ) === NHM_Fields::split_phone( '+60123456789' ), 'split +60' );
ok( array( '+673', '7123456' ) === NHM_Fields::split_phone( '+6737123456' ), 'split longest code first' );
ok( array( '+60', '123456789' ) === NHM_Fields::split_phone( '012-3456789' ), 'old number counts as Malaysian' );
ok( '+60 12-345 6789' === NHM_Fields::format_phone( '+60123456789' ), 'phone display' );
list( $v, $e ) = NHM_Fields::read( NHM_Fields::get( 'phone' ), array( 'nhm_phone' => '12', 'nhm_phone_cc' => '+60' ) );
ok( '' !== $e, 'too short phone refused' );
$loc = NHM_Fields::get( 'location' );
ok( $loc && in_array( 'Selangor', $loc['options'], true ) && in_array( 'Outside Malaysia', $loc['options'], true ) && 17 === count( $loc['options'] ), 'location: 16 states/territories + outside' );
ok( $loc['register'] && ! $loc['required'], 'location on the form, optional' );
$html = NHM_Fields::render( NHM_Fields::get( 'phone' ), '+6591234567' );
ok( false !== strpos( $html, 'name="nhm_phone_cc"' ) && false !== strpos( $html, 'value="+65" selected' ) && false !== strpos( $html, 'value="91234567"' ), 'phone field markup' );

// ---------- Email confirmation ----------
$old = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), 'salt' );
$GLOBALS['mails'] = array();
NHM_Settings::update( array( 'verify_email' => 1 ) );
wp_set_current_user( 0 );
$r = post( 'register', array( 'nhm_name' => 'Nora', 'nhm_email' => 'nora@x.my', 'nhm_password' => 'secret123', 'nhm_t' => $old, 'nhm_phone' => '0123456789', 'nhm_phone_cc' => '+60', 'nhm_location' => 'Selangor' ) );
$nora = get_user_by( 'email', 'nora@x.my' );
ok( $r && false !== strpos( $r, 'verify_sent' ) && 0 === get_current_user_id(), 'register → check your email, not logged in' );
ok( $nora && '+60123456789' === get_user_meta( $nora->ID, 'nhm_phone', true ) && 'Selangor' === get_user_meta( $nora->ID, 'nhm_location', true ), 'phone + location saved' );
ok( $nora && NHM_Auth::is_unverified( $nora->ID ), 'marked unconfirmed' );
ok( is_wp_error( NHM_Auth::block_unverified( $nora ) ), 'login blocked until confirmed' );
$verify = null; foreach ( $GLOBALS['mails'] as $m ) { if ( preg_match( '/nhm_verify=([A-Za-z0-9]+)/', $m['b'], $mm ) ) { $verify = $mm[1]; } }
ok( $verify && 'nora@x.my' === $GLOBALS['mails'][0]['to'], 'confirmation email sent' );
ok( (bool) preg_grep( '/New/i', array_column( $GLOBALS['mails'], 's' ) ), 'admin told about the sign-up' );
$_GET = array( 'nhm_verify' => 'wrong', 'u' => $nora->ID );
$r = get_redirect( array( 'NHM_Auth', 'verify_requests' ) );
ok( $r && false !== strpos( $r, 'verify_bad' ) && NHM_Auth::is_unverified( $nora->ID ), 'wrong token refused' );
$GLOBALS['mails'] = array();
$_GET = array( 'nhm_verify' => $verify, 'u' => $nora->ID );
$r = get_redirect( array( 'NHM_Auth', 'verify_requests' ) );
$_GET = array();
ok( $r && false !== strpos( $r, 'verified' ) && ! NHM_Auth::is_unverified( $nora->ID ) && get_current_user_id() === $nora->ID, 'confirmed → logged in' );
ok( (bool) preg_grep( '/Welcome/i', array_column( $GLOBALS['mails'], 's' ) ), 'welcome after confirming' );
ok( $nora === NHM_Auth::block_unverified( $nora ), 'login allowed after confirming' );
$_GET = array( 'nhm_resend' => $nora->ID, 't' => 'bad' );
$GLOBALS['mails'] = array();
get_redirect( array( 'NHM_Auth', 'verify_requests' ) );
$_GET = array();
ok( 0 === count( $GLOBALS['mails'] ), 'resend with a bad token sends nothing' );

// ---------- Forgot password pop-up ----------
wp_set_current_user( 0 );
$_SERVER['REQUEST_URI'] = '/login/';
$html = NHM_Auth::render_login( array() );
ok( false !== strpos( $html, '<dialog class="nhm-modal" id="nhm-lost-modal"' ) && false === strpos( $html, 'data-nhm-autoopen' ) && false !== strpos( $html, 'data-nhm-dialog="nhm-lost-modal"' ), 'login has the pop-up (closed)' );
$_GET = array( 'action' => 'rp', 'key' => 'goodkey', 'login' => 'nora@x.my' );
$html = NHM_Auth::render_login( array() );
ok( false !== strpos( $html, 'data-nhm-autoopen' ) && false !== strpos( $html, 'name="pass1"' ), 'reset link opens "choose a new password"' );
$_GET = array( 'nhm_modal' => 'lost', 'nhm_msg' => 'reset_sent' );
$html = NHM_Auth::render_login( array() );
ok( false !== strpos( $html, 'Check your inbox' ), 'sent state in the pop-up' );
$_GET = array();
ok( false !== strpos( nhm_lost_password_url(), 'nhm_modal=lost' ), 'no lost page: link opens the pop-up' );

// ---------- Hide WordPress ----------
ok( 'https://example.test/logout/?_nhm=NONCE' === wp_logout_url(), 'logout url /logout/: ' . wp_logout_url() );
$ep = NHM_HideWP::rest_users( array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\d]+)' => 1, '/wp/v2/posts' => 1 ) );
ok( array( '/wp/v2/posts' ) === array_keys( $ep ), 'REST users hidden for visitors' );
$mail = NHM_HideWP::mail_reset( array( 'to' => 'nora@x.my', 'subject' => 'x', 'message' => 'y', 'headers' => '' ), 'KEY9', 'u' . $nora->ID, $nora );
ok( false !== strpos( $mail['message'], 'action=rp' ) && false !== strpos( $mail['message'], 'key=KEY9' ) && false !== strpos( $mail['message'], 'https://example.test/?p=1' ), 'core reset email → own template + login page' );

// ---------- Visibility ----------
add_user( 30, 'free@x.my', 'Free' );
add_user( 31, 'pro@x.my', 'Pro' );
NHM_Members::set_plan( 31, 3, '', '' );
NHM_Members::flush();
wp_set_current_user( 0 );
ok( NHM_Visibility::allowed( 'guests' ) && ! NHM_Visibility::allowed( 'members' ) && ! NHM_Visibility::allowed( 'min', 2 ), 'guest rules' );
wp_set_current_user( 30 );
ok( ! NHM_Visibility::allowed( 'guests' ) && NHM_Visibility::allowed( 'members' ) && ! NHM_Visibility::allowed( 'min', 3 ) && NHM_Visibility::allowed( 'exact', 0, array( 1 ) ), 'free member rules' );
wp_set_current_user( 31 );
ok( NHM_Visibility::allowed( 'min', 3 ) && NHM_Visibility::allowed( 'min', 2 ) && ! NHM_Visibility::allowed( 'min', 4 ) && ! NHM_Visibility::allowed( 'exact', 0, array( 1 ) ), 'pro member rules' );
wp_set_current_user( 1 );
ok( NHM_Visibility::allowed( 'min', 4 ), 'staff pass "plan or higher"' );

function item( $id, $parent, $url = '/x/', $title = 'X' ) { $o = new stdClass(); $o->ID = $id; $o->menu_item_parent = $parent; $o->url = $url; $o->title = $title; $o->classes = array(); return $o; }
update_post_meta( 501, '_nhm_vis', 'min' ); update_post_meta( 501, '_nhm_vis_level', 3 );
update_post_meta( 503, '_nhm_vis', 'guests' );
$menu = array( item( 502, 501 ), item( 501, 0 ), item( 503, 0 ), item( 504, 0, '#nhm-login', 'Log in / Log out' ), item( 505, 0, '#nhm-register' ), item( 506, 0, '#nhm-upgrade' ), item( 507, 0, '#nhm-user' ), item( 508, 507, '/a/' ) );
wp_set_current_user( 30 );
$out = NHM_Visibility::filter_items( $menu );
$ids = array_map( function ( $i ) { return $i->ID; }, $out );
ok( array( 504, 506, 507, 508 ) === $ids, 'free member menu: pro item + its child gone, guests-only gone, register gone: ' . implode( ',', $ids ) );
$by = array(); foreach ( $out as $i ) { $by[ $i->ID ] = $i; }
ok( 'Log out' === $by[504]->title && false !== strpos( $by[504]->url, '/logout/' ), 'login item becomes Log out' );
ok( false !== strpos( $by[507]->title, 'Free' ) && false !== strpos( $by[507]->title, '<img' ), 'member name + photo item' );
wp_set_current_user( 0 );
$menu = array( item( 502, 501 ), item( 501, 0 ), item( 503, 0 ), item( 504, 0, '#nhm-login' ), item( 505, 0, '#nhm-register' ), item( 506, 0, '#nhm-upgrade' ), item( 507, 0, '#nhm-user' ), item( 508, 507, '/a/' ) );
$ids = array_map( function ( $i ) { return $i->ID; }, NHM_Visibility::filter_items( $menu ) );
ok( array( 503, 504, 505 ) === $ids, 'visitor menu: ' . implode( ',', $ids ) );
$GLOBALS['wpdb']->insert( 'wp_postmeta', array( 'post_id' => 501, 'meta_key' => '_nhm_vis_level', 'meta_value' => '3' ) );
NHM_Visibility::level_deleted( 3, 4 );
ok( 4 === (int) get_post_meta( 501, '_nhm_vis_level' ), 'menu rule moves with a deleted level' );

// ---------- Avatar ----------
$u = get_userdata( 30 ); $u->display_name = 'Amanda Tan';
ok( 'AT' === NHM_Avatar::initials( $u ), 'initials' );
$d = NHM_Avatar::avatar_data( array(), 30 );
$f = $GLOBALS['UPLOADS'] . '/nhm-avatars/' . basename( $d['url'] );
ok( 0 === strpos( $d['url'], 'https://example.test/wp-content/uploads/nhm-avatars/i-' ) && file_exists( $f ) && false !== strpos( file_get_contents( $f ), '>AT<' ), 'no photo → initials file, no Gravatar' );
$big = NHM_Avatar::save_upload( 30, array( 'tmp_name' => __FILE__, 'error' => 0, 'size' => 3 * 1048576 ) );
ok( is_wp_error( $big ) && false !== strpos( $big->get_error_message(), '2 MB' ), 'photo over 2 MB refused' );
$bad = NHM_Avatar::save_upload( 30, array( 'tmp_name' => __FILE__, 'error' => 0, 'size' => 1000 ) );
ok( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'JPG or PNG' ), 'not an image refused' );

// ---------- Shell ----------
$cid = NHM_Companies::create( array( 'name' => 'BrightPath Sdn Bhd', 'level_id' => 4, 'expiry_date' => wp_date( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ), 'seats' => 3 ) );
NHM_Companies::add_member( $cid, 30, 'owner' );
NHM_Members::flush();
ok( 'Corporate · BrightPath Sdn Bhd' === NHM_Shell::plan_line( 30 ), 'top bar: plan · company' );
ok( 'Pro' === NHM_Shell::plan_line( 31 ), 'top bar: only the plan without a company' );
wp_set_current_user( 30 );
$open = NHM_Shell::open();
ok( false !== strpos( $open, 'nhs-side' ) && false !== strpos( $open, 'Amanda Tan' ) && false !== strpos( $open, 'Corporate · BrightPath' ) && false !== strpos( $open, 'Neuro<span>HR</span>' ), 'shell top bar, sidebar, logo' );
ok( false !== strpos( $open, 'Grow people' ) && false !== strpos( $open, 'My company' ) && false !== strpos( $open, '/logout/' ), 'promo, owner company link, logout' );
ok( false !== strpos( $open, '<main class="nhs-main"' ) && '' === NHM_Shell::open(), 'opens once' );
$close = NHM_Shell::close();
ok( 0 === strpos( $close, '</main></div><footer class="nhs-footer"><span>© ' . wp_date( 'Y' ) . ' NeuroHR.' ), 'footer text: ' . substr( $close, 0, 90 ) );
ok( false !== strpos( NHM_Shell::open( 'div' ), '<div class="nhs-main"' ) && 0 === strpos( NHM_Shell::close(), '</div></div>' ), 'div mode for plugin templates' );
ok( 'https://example.test/' === NHM_Shell::search_target()[0] && 's' === NHM_Shell::search_target()[1], 'search: whole site without the hub' );

// ---------- My account tabs ----------
$_SERVER['REQUEST_URI'] = '/account/';
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'nhm-tabs' ) && false !== strpos( $html, '>Company<' ) && false !== strpos( $html, '>Notifications<' ) && false !== strpos( $html, 'Profile information' ) && false !== strpos( $html, 'Contact details' ) && false !== strpos( $html, 'Preferences' ), 'tabs + profile tab' );
ok( false !== strpos( $html, 'enctype="multipart/form-data"' ) && false !== strpos( $html, 'name="nhm_photo"' ), 'photo upload form' );
wp_set_current_user( 31 );
$html = NHM_Account::render( array() );
ok( false === strpos( $html, '>Company<' ), 'no company tab without a company' );
$_GET = array( 'tab' => 'security' );
update_user_meta( 31, 'session_tokens', array( hash( 'sha256', 'tok1' ) => array( 'expiration' => time() + 999, 'login' => time() - 60, 'ua' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120' ), hash( 'sha256', 'other' ) => array( 'expiration' => time() + 999, 'login' => time() - 9999, 'ua' => 'Mozilla/5.0 (iPhone) Safari/604' ) ) );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'Chrome on Windows' ) && false !== strpos( $html, 'Safari on iPhone' ) && false !== strpos( $html, 'This device' ) && false !== strpos( $html, 'all other devices' ), 'security tab: sessions' );
$r = post( 'sessions', array() );
ok( $r && false !== strpos( $r, 'sessions_ended' ) && 1 === count( get_user_meta( 31, 'session_tokens', true ) ), 'log out other devices' );
$_GET = array( 'tab' => 'notifications' );
$html = NHM_Account::render( array() );
ok( false !== strpos( $html, 'Newsletter' ) && false !== strpos( $html, 'Events &amp; webinars' ) && false === strpos( $html, 'New content' ) && false !== strpos( $html, 'Always on' ), 'notifications: groups that are set up + locked plan emails' );
$GLOBALS['remote'] = array();
$r = post( 'notifications', array( 'nhm_n_events' => 1 ) );
ok( $r && 1 === (int) get_user_meta( 31, 'nhm_notify_events', true ) && 'POST' === $GLOBALS['remote'][0][1]['method'] && false !== strpos( $GLOBALS['remote'][0][1]['body'], '"333"' ), 'events on → MailerLite group 333' );
$GLOBALS['remote'] = array();
$r = post( 'notifications', array() );
ok( $r && ! get_user_meta( 31, 'nhm_notify_events', true ) && 'DELETE' === end( $GLOBALS['remote'] )[1]['method'] && false !== strpos( end( $GLOBALS['remote'] )[0], '/777/groups/333' ), 'events off → removed from group' );
$_GET = array();

// Second email
$GLOBALS['mails'] = array();
$r = post( 'contact', array( 'nhm_email2' => 'pro.home@gmail.com', 'nhm_email_pref' => 'second' ) );
ok( $r && false !== strpos( $r, 'email2_sent' ) && 'pro.home@gmail.com' === $GLOBALS['mails'][0]['to'], 'second email: confirmation sent there' );
ok( 'main' === get_user_meta( 31, 'nhm_email_pref', true ) && 'pro@x.my' === NHM_Members::contact_email( get_userdata( 31 ) ), 'not used before it is confirmed' );
preg_match( '/nhm_email2=([A-Za-z0-9]+)/', $GLOBALS['mails'][0]['b'], $mm );
$_GET = array( 'nhm_email2' => $mm[1], 'u' => 31 );
$r = get_redirect( array( 'NHM_Account', 'confirm_email2' ) );
$_GET = array();
ok( $r && false !== strpos( $r, 'email2_ok' ) && 'pro.home@gmail.com' === get_user_meta( 31, 'nhm_email2', true ), 'second email confirmed' );
post( 'contact', array( 'nhm_email2' => 'pro.home@gmail.com', 'nhm_email_pref' => 'second' ) );
ok( 'pro.home@gmail.com' === NHM_Members::contact_email( get_userdata( 31 ) ), 'account emails now go to the second address' );
$r = post( 'contact', array( 'nhm_email2' => 'pro@x.my' ) );
ok( null === $r && false !== strpos( form_errors( 'contact' ), 'different' ), 'second email = login email refused' );
$r = post( 'prefs', array( 'nhm_lang' => 'zh', 'nhm_tz' => 'Asia/Singapore' ) );
ok( 'zh' === get_user_meta( 31, 'nhm_lang', true ) && 'Asia/Singapore' === get_user_meta( 31, 'nhm_tz', true ), 'preferences stored' );

// Company billing by the owner
wp_set_current_user( 30 );
$GLOBALS['mails'] = array();
$r = post( 'company_billing', array( 'nhm_c_name' => 'BrightPath Sdn Bhd', 'nhm_c_tin' => 'C2233', 'nhm_c_billing_email' => 'ap@brightpath.my', 'nhm_c_address' => "Lot 5\nJalan 6" ) );
$co = NHM_Companies::get( $cid );
ok( $r && 'C2233' === $co['tin'] && 'ap@brightpath.my' === $co['billing_email'] && "Lot 5\nJalan 6" === $co['address'], 'owner changed company billing' );
$adm = null; foreach ( $GLOBALS['mails'] as $m ) { if ( false !== strpos( $m['s'], 'billing details' ) ) { $adm = $m; } }
ok( $adm && false !== strpos( $adm['b'], 'TIN: — → C2233' ), 'admin email lists the changes' );
wp_set_current_user( 31 );
$r = post( 'company_billing', array( 'nhm_c_name' => 'Hijack' ) );
ok( null === $r && 'BrightPath Sdn Bhd' === NHM_Companies::get( $cid )['name'], 'non-owner cannot change company billing' );

// Upgrade destination
ok( 2 === NHM_Levels::next_up( 1 ) && 3 === NHM_Levels::next_up( 2 ) && 4 === NHM_Levels::next_up( 3 ) && 0 === NHM_Levels::next_up( 4 ), 'next plan up' );
ok( 'https://example.test/?p=5' === NHM_Levels::upgrade_from( 1 ), 'upgrade → pricing page when set' );
NHM_Settings::update( array( 'page_pricing' => 0 ) );
ok( false !== strpos( NHM_Levels::upgrade_from( 2 ), 'plan=pro' ), 'no pricing page: Lite → Pro register link' );
// Pricing preset + table
ok( 4 === NHM_Levels::apply_pricing_preset(), 'pricing preset fills 4 levels' );
wp_set_current_user( 0 );
$pt = NHM_Shortcodes::pricing( array( 'levels' => 'free,lite,pro,corporate', 'note' => 'Billed yearly' ) );
ok( false !== strpos( $pt, 'Most popular' ) && false !== strpos( $pt, 'Staff logins: <strong>Up to 5</strong>' ) && false !== strpos( $pt, 'class="is-off"' ) && false !== strpos( $pt, 'Billed yearly' ) && false !== strpos( $pt, 'Get Pro' ), 'pricing table: badge, bold, greyed lines, note, buttons' );
echo "$n v0.3 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
