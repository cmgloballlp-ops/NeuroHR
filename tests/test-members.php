<?php
// Logic tests for NeuroHR Members + Knowledge Hub access + Payroll level, on the stub.
require __DIR__ . '/wp-stub.php';
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
NHM_Levels::ensure();

$fails = 0; $n = 0;
function ok( $cond, $label ) { global $fails, $n; $n++; if ( ! $cond ) { $fails++; echo "FAIL: $label\n"; } }
function d( $days ) { return wp_date( 'Y-m-d', $GLOBALS['NOW'] + $days * DAY_IN_SECONDS ); }

// ---------- Levels ----------
$L = NHM_Levels::all();
ok( array_keys( $L ) === array( 1, 2, 3, 4, 5 ), 'seed IDs 1-5 in order' );
ok( $L[1]['rank'] === 1 && $L[2]['rank'] === 2 && $L[3]['rank'] === 3 && $L[4]['rank'] === 4 && $L[5]['rank'] === 4, 'ranks: consulting = corporate' );
ok( NHM_Levels::max_rank() === 4, 'max rank 4' );
ok( NHM_Levels::top()['id'] === 4, 'top = corporate' );
ok( NHM_Levels::resolve( 'pro' )['id'] === 3 && NHM_Levels::resolve( 'Corporate' )['id'] === 4 && NHM_Levels::resolve( '2' )['id'] === 2, 'resolve slug/name/id' );
ok( NHM_Levels::successor( 3 ) === 4 && NHM_Levels::successor( 5 ) === 4, 'successor' );

// ---------- People ----------
add_user( 10, 'free@a.my', 'Free Person' );
add_user( 11, 'pro@a.my', 'Pro Person' );
add_user( 12, 'old@a.my', 'Expired Person' );
add_user( 13, 'boss@client.my', 'Client Boss' );
add_user( 14, 'staff@client.my', 'Client Staff' );
add_user( 15, 'editor@n.my', 'Editor', array( 'edit_others_posts', 'edit_posts' ) );
add_user( 16, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );
wp_set_current_user( 16 );

ok( NHM_Members::level( 0 ) === 0 && NHM_Members::rank( 0 ) === 0, 'guest level 0' );
ok( NHM_Members::level( 10 ) === 1 && NHM_Members::membership( 10 )['source'] === 'base', 'logged in = Free' );

NHM_Members::set_plan( 11, 3, d( -10 ), d( 100 ) );
$m = NHM_Members::membership( 11 );
ok( $m['level'] === 3 && $m['source'] === 'personal' && $m['days_left'] === 100, 'personal Pro, 100 days' );
ok( NHM_Members::can( 'lite', 11 ) && NHM_Members::can( 3, 11 ) && ! NHM_Members::can( 'corporate', 11 ), 'Pro can Lite/Pro not Corporate' );

NHM_Members::set_plan( 12, 2, d( -400 ), d( -1 ) );
$m = NHM_Members::membership( 12 );
ok( $m['level'] === 1 && $m['expired_level'] === 2, 'expired yesterday → Free, expired_level Lite' );
NHM_Members::set_plan( 12, 2, d( -400 ), d( 0 ) );
ok( NHM_Members::level( 12 ) === 2 && NHM_Members::membership( 12 )['days_left'] === 0, 'last day today still active' );

ok( NHM_Members::can( 'corporate', 15 ), 'editor sees everything' );
ok( ! NHM_Members::can( 999, 11 ), 'unknown level closed' );
ok( NHM_Members::plus_year( '' ) === d( 0 ) ? false : true, 'plus_year runs' );
ok( NHM_Members::plus_year( '2027-02-28' ) === '2028-02-28', 'plus_year from future date' );
ok( NHM_Members::clean_date( '2026-02-30' ) === '' && NHM_Members::clean_date( '2026-12-31' ) === '2026-12-31', 'clean_date' );

// ---------- Companies ----------
$cid = NHM_Companies::create( array( 'name' => 'Client Sdn Bhd', 'level_id' => 5, 'start_date' => d( 0 ), 'expiry_date' => d( 365 ), 'seats' => 2 ) );
ok( is_int( $cid ) && $cid > 0, 'company created' );
ok( true === NHM_Companies::add_member( $cid, 13, 'owner' ), 'owner added' );
ok( NHM_Companies::get( $cid )['owner_id'] === 13, 'owner set' );
$m = NHM_Members::membership( 13 );
ok( $m['level'] === 5 && $m['source'] === 'company' && $m['rank'] === 4 && $m['role'] === 'owner', 'owner gets Consulting via company' );

// invite flow
$r = NHM_Companies::invite( $cid, 'staff@client.my', 'member', 13 );
ok( true === $r, 'invite sent' );
$inv_mail = null; foreach ( $GLOBALS['mails'] as $ml ) { if ( false !== strpos( $ml['s'], 'invited you' ) ) { $inv_mail = $ml; } }
ok( $inv_mail && strpos( $inv_mail['b'], 'nhm_invite=' ) !== false, 'invite email has link' );
ok( NHM_Companies::seats_used( $cid ) === 2 && NHM_Companies::seats_free( $cid ) === 0, 'seat reserved by invite' );
$r2 = NHM_Companies::invite( $cid, 'third@client.my', 'member', 13 );
ok( is_wp_error( $r2 ) && 'nhm_seats' === $r2->get_error_code(), 'no seats left' );
preg_match( '/nhm_invite=([A-Za-z0-9%]+)/', $inv_mail['b'], $mm );
$token = rawurldecode( $mm[1] );
ok( null !== NHM_Companies::find_invite( $token ), 'token found' );
$bad = NHM_Companies::accept( $token, 11 );
ok( is_wp_error( $bad ), 'wrong email cannot accept' );
ok( true === NHM_Companies::accept( $token, 14 ), 'staff accepts' );
ok( null === NHM_Companies::find_invite( $token ), 'token used up' );
ok( NHM_Members::membership( 14 )['level'] === 5 && NHM_Members::membership( 14 )['role'] === 'member', 'staff gets company plan' );
ok( NHM_Companies::seats_used( $cid ) === 2, 'seats: 2 people' );

// someone in another company
$c2 = NHM_Companies::create( array( 'name' => 'Other', 'level_id' => 4, 'expiry_date' => d( 10 ), 'seats' => 5 ) );
$r3 = NHM_Companies::add_member( $c2, 14 );
ok( is_wp_error( $r3 ), 'cannot be in two companies' );

// personal Pro + company Consulting → higher wins
NHM_Companies::update( $cid, array( 'seats' => 3 ) );
NHM_Companies::add_member( $cid, 11 );
ok( NHM_Members::membership( 11 )['level'] === 5, 'higher of personal Pro and company Consulting' );
NHM_Companies::remove_member( $cid, 11 );
ok( NHM_Members::membership( 11 )['level'] === 3, 'back to personal Pro after leaving' );

// company expires
NHM_Companies::update( $cid, array( 'expiry_date' => d( -1 ) ) );
$m = NHM_Members::membership( 14 );
ok( $m['level'] === 1 && $m['expired_level'] === 5, 'company ended → staff Free' );
NHM_Companies::update( $cid, array( 'expiry_date' => d( 20 ) ) );
ok( NHM_Members::level( 14 ) === 5, 'renewed' );

// ---------- Cron due() ----------
list( $e, $mk ) = NHM_Cron::due( 40, array(), array( 30, 7 ) ); ok( null === $e, 'due: 40 days nothing' );
list( $e, $mk ) = NHM_Cron::due( 30, array(), array( 30, 7 ) ); ok( 'reminder' === $e && $mk === array( 'r30' ), 'due: 30 → r30' );
list( $e, $mk ) = NHM_Cron::due( 25, array( 'r30' ), array( 30, 7 ) ); ok( null === $e, 'due: 25 already sent' );
list( $e, $mk ) = NHM_Cron::due( 5, array(), array( 30, 7 ) ); ok( 'reminder' === $e && $mk === array( 'r30', 'r7' ), 'due: late run sends only the 7-day one' );
list( $e, $mk ) = NHM_Cron::due( -1, array( 'r30', 'r7' ), array( 30, 7 ) ); ok( 'expired' === $e, 'due: ended' );
list( $e, $mk ) = NHM_Cron::due( -5, array( 'ended' ), array( 30, 7 ) ); ok( null === $e, 'due: ended once' );

// ---------- Cron run ----------
$GLOBALS['mails'] = array();
NHM_Members::set_plan( 10, 2, d( -300 ), d( 7 ) );   // reminder
NHM_Members::set_plan( 12, 2, d( -400 ), d( -2 ) );  // ended
NHM_Cron::run();
$to = array_column( $GLOBALS['mails'], 'to' );
ok( in_array( 'free@a.my', $to, true ), 'cron: 7-day reminder to person' );
ok( in_array( 'old@a.my', $to, true ), 'cron: ended email' );
ok( in_array( 'boss@client.my', $to, true ), 'cron: company 20-day reminder to owner (30 window)' );
ok( ! in_array( 'staff@client.my', $to, true ), 'cron: no email to staff' );
$adm = 0; foreach ( $GLOBALS['mails'] as $mail ) { if ( 'owner@neurohr.my' === $mail['to'] ) { $adm++; } }
ok( $adm >= 3, 'cron: admin copies' );
$count = count( $GLOBALS['mails'] );
NHM_Cron::run();
ok( count( $GLOBALS['mails'] ) === $count, 'cron: second run sends nothing' );
NHM_Members::set_plan( 10, 2, d( -300 ), d( 372 ) );
ok( '' === get_user_meta( 10, NHM_Members::META_REMINDED ), 'new expiry resets reminders' );

// ---------- Levels: add, reorder, tie, delete ----------
$new = NHM_Levels::add( 'Enterprise' );
ok( 6 === $new && NHM_Levels::rank( 6 ) === 5, 'added at top with new ID 6' );
// move Enterprise between Pro and Corporate; rename Lite
NHM_Levels::save( array( 1 => array( 'name' => 'Free' ), 2 => array( 'name' => 'Starter' ), 3 => array( 'name' => 'Pro' ), 6 => array( 'name' => 'Enterprise' ), 4 => array( 'name' => 'Corporate' ), 5 => array( 'name' => 'Consulting', 'same_as_prev' => 1 ) ) );
$L = NHM_Levels::all();
ok( array_keys( $L ) === array( 1, 2, 3, 6, 4, 5 ), 'reordered' );
ok( $L[2]['name'] === 'Starter' && $L[6]['rank'] === 4 && $L[4]['rank'] === 5 && $L[5]['rank'] === 5, 'ranks follow order, tie kept' );
ok( NHM_Members::can( 6, 13 ) === true, 'consulting reads enterprise' );
// base cannot be moved
NHM_Levels::save( array( 3 => array( 'name' => 'Pro' ), 1 => array( 'name' => 'Free' ), 2 => array( 'name' => 'Starter' ), 6 => array( 'name' => 'Enterprise' ), 4 => array( 'name' => 'Corporate' ), 5 => array( 'name' => 'Consulting', 'same_as_prev' => 1 ) ) );
ok( array_keys( NHM_Levels::all() )[0] === 1, 'base stays first' );
NHM_Levels::save( array( 1 => array( 'name' => 'Free' ), 2 => array( 'name' => 'Starter' ), 3 => array( 'name' => 'Pro' ), 6 => array( 'name' => 'Enterprise' ), 4 => array( 'name' => 'Corporate' ), 5 => array( 'name' => 'Consulting', 'same_as_prev' => 1 ) ) );
ok( array_keys( NHM_Levels::all() ) === array( 1, 2, 3, 6, 4, 5 ), 'order restored' );

// delete Corporate (4): Consulting (tied to it) must keep its rank, companies on 4 move
$GLOBALS['wpdb']->insert( 'wp_postmeta', array( 'post_id' => 77, 'meta_key' => '_nhm_min_level', 'meta_value' => '4' ) );
update_post_meta( 77, '_nhm_min_level', 4 );
$rank_before = NHM_Levels::rank( 5 );
$res = NHM_Levels::delete( 4, 3 );
ok( true === $res, 'delete corporate' );
ok( NHM_Levels::rank( 5 ) === $rank_before, 'consulting keeps its rank after deleting the level it was tied to' );
ok( NHM_Companies::get( $c2 )['level_id'] === 3, 'company moved to chosen level' );
ok( (int) get_post_meta( 77, '_nhm_min_level' ) === 5, 'protected page moved to next level up' );
ok( is_wp_error( NHM_Levels::delete( 1, 2 ) ), 'base cannot be deleted' );

// ---------- Emails ----------
$GLOBALS['mails'] = array();
NHM_Emails::welcome( 10 );
NHM_Emails::admin_signup( 10 );
ok( 2 === count( $GLOBALS['mails'] ) && strpos( $GLOBALS['mails'][0]['s'], 'NeuroHR' ) !== false && 'owner@neurohr.my' === $GLOBALS['mails'][1]['to'], 'welcome email + admin signup notice' );

// ---------- Shortcode if ----------
wp_set_current_user( 11 );
NHM_Members::flush();
ok( 'yes' === NHM_Shortcodes::if_level( array( 'level' => 'pro' ), 'yes[nhm_else]no' ), '[nhm_if pro] for Pro' );
ok( 'no' === NHM_Shortcodes::if_level( array( 'level' => 'consulting' ), 'yes[nhm_else]no' ), '[nhm_else] branch' );
ok( 'no' === NHM_Shortcodes::if_level( array( 'guest' => 'yes' ), 'yes[nhm_else]no' ), 'guest=yes when logged in' );
ok( 'yes' === NHM_Shortcodes::if_level( array( 'levels' => 'pro,lite' ), 'yes' ), 'levels exact list' );

// ---------- Knowledge Hub access with Members ----------
class NH_Login { static function required() { return true; } static function login_url( $r ) { return nhm_login_url( $r ); } }
class NH_Post_Types { const CPT = 'nh_resource'; const TAX_TYPE = 'nh_type'; }
function get_post( $p = null ) { return is_object( $p ) ? $p : ( $GLOBALS['posts'][ $p ] ?? null ); }
function get_the_terms( $p, $t ) { $p = get_post( $p ); return $p->terms ?? false; }
function nh_hub_excerpt() { return ''; }
require $W . '/neurohr-hub/includes/class-nh-access.php';
function mkpost( $id, $type, $level = '' ) { $p = new stdClass(); $p->ID = $id; $p->post_type = 'nh_resource'; $t = new stdClass(); $t->slug = $type; $p->terms = array( $t ); $GLOBALS['posts'][ $id ] = $p; if ( '' !== $level ) { update_post_meta( $id, '_nh_min_level', $level ); } return $p; }
NH_Access::init();
$a = mkpost( 201, 'article' );        // type default Lite (2)
$v = mkpost( 202, 'video' );          // Pro (3)
$f = mkpost( 203, 'article', '0' );   // Public → base
$x = mkpost( 204, 'article', '99' );  // unknown → top
ok( NH_Access::members_active(), 'hub sees Members' );
ok( NH_Access::get_post_level( $a ) === 2 && NH_Access::get_post_level( $v ) === 3, 'hub type defaults' );
ok( NH_Access::get_post_level( $f ) === 1, 'hub public → base when login required' );
ok( NH_Access::rank( NH_Access::get_post_level( $x ) ) === NH_Access::max_rank(), 'hub unknown level fails closed' );
ok( NH_Access::get_user_level( 11 ) === 3 && NH_Access::can_view( $v, 11 ) && NH_Access::can_view( $a, 11 ), 'hub: Pro reads video + article' );
NHM_Members::set_plan( 10, 2, d( 0 ), d( 30 ) ); NH_Access::flush();
ok( NH_Access::can_view( $a, 10 ) && ! NH_Access::can_view( $v, 10 ), 'hub: Lite(Starter) reads article not video' );
ok( ! NH_Access::can_view( $a, 0 ), 'hub: guest locked' );
ok( NH_Access::can_view( $x, 15 ), 'hub: editor sees all' );
ok( NH_Access::can_view( $x, 13 ), 'hub: consulting (top) reads top-level content' );
// level deleted → hub type default remap
update_option( 'nh_hub_type_levels', array( 'video' => 3 ) );
$GLOBALS['wpdb']->insert( 'wp_postmeta', array( 'post_id' => 202, 'meta_key' => '_nh_min_level', 'meta_value' => '3' ) );
NHM_Levels::delete( 3, 2 );
NH_Access::flush();
$tl = get_option( 'nh_hub_type_levels' );
ok( (int) $tl['video'] === NHM_Levels::successor( 2 ) || (int) $tl['video'] === 6, 'hub type default moved up after delete' );
ok( (int) get_post_meta( 202, '_nh_min_level' ) === 6, 'hub resource moved to next level up (Enterprise)' );
ok( NHM_Members::level( 11 ) === 2, 'Pro people moved to chosen level' );

// ---------- Payroll level ----------
foreach ( array( 'Money', 'Rates', 'Epf', 'Socso', 'Pcb', 'Calculator', 'SelfTest' ) as $c ) { require_once $W . '/neurohr-payroll/src/Engine/' . $c . '.php'; }
require $W . '/neurohr-payroll/src/Plugin.php';
NHM_Members::set_plan( 11, 6, d( 0 ), d( 30 ) );
update_option( 'nhp_settings', array( 'pro_level' => 6 ) );
ok( \NeuroHR\Payroll\Plugin::level( 11 ) === 'enterprise', 'payroll level slug' );
ok( \NeuroHR\Payroll\Plugin::is_pro( 11 ) && ! \NeuroHR\Payroll\Plugin::is_pro( 10 ) && \NeuroHR\Payroll\Plugin::is_pro( 13 ), 'payroll pro by rank' );
\NeuroHR\Payroll\Plugin::level_deleted( 6, 5 );
ok( (int) get_option( 'nhp_settings' )['pro_level'] === 5, 'payroll setting moved on delete' );

echo "$n tests, $fails failed\n";
exit( $fails ? 1 : 0 );
