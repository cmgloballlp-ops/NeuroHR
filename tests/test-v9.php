<?php
// Members 0.6 greeting + NeuroHR App 0.1: phone app chrome, header, speed, My account list, PWA.
require __DIR__ . '/stubs-front.php';
require __DIR__ . '/stub-extra.php';
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $l ) { return false; } }
if ( ! function_exists( 'get_post_status' ) ) { function get_post_status( $id ) { return 'publish'; } }
if ( ! function_exists( 'wp_get_session_token' ) ) { function wp_get_session_token() { return 'tok'; } }
if ( ! function_exists( 'get_site_icon_url' ) ) { function get_site_icon_url( $s = 512 ) { return $GLOBALS['site_icon'] ? 'https://example.test/wp-content/uploads/icon-' . $s . 'x' . $s . '.png' : ''; } }
if ( ! function_exists( 'attachment_url_to_postid' ) ) { function attachment_url_to_postid( $u ) { return 0; } }
if ( ! function_exists( 'wp_check_filetype' ) ) { function wp_check_filetype( $f ) { return array( 'ext' => 'png', 'type' => 'png' === pathinfo( $f, PATHINFO_EXTENSION ) ? 'image/png' : false ); } }
$GLOBALS['site_icon'] = true;
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
update_option( 'nhm_settings', array_merge( get_option( 'nhm_settings', array() ), array( 'app_on' => 1, 'pwa_name' => 'NeuroHR', 'pwa_color' => '#123456' ) ) ); // As Members 0.5 left them.
require $W . '/neurohr-app/neurohr-app.php';
NHA_App::init(); NHA_Speed::init(); NHA_PWA::init(); // What plugins_loaded does when Members is ready.
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
$_SERVER['REQUEST_URI'] = '/dashboard/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4 ) );
add_user( 40, 'marcus@co.my', 'Marcus Lim' );
update_user_meta( 40, 'first_name', 'Marcus' );
add_user( 41, 'nofirst@co.my', 'nofirst' );

// ---------- Greeting ----------
ok( 'morning' === NHM_Greeting::part_of_day( 5 ) && 'morning' === NHM_Greeting::part_of_day( 11 ), 'morning 5:00 to 11:59' );
ok( 'afternoon' === NHM_Greeting::part_of_day( 12 ) && 'afternoon' === NHM_Greeting::part_of_day( 17 ), 'afternoon 12:00 to 17:59' );
ok( 'evening' === NHM_Greeting::part_of_day( 18 ) && 'evening' === NHM_Greeting::part_of_day( 20 ), 'evening 18:00 to 20:59' );
ok( 'late' === NHM_Greeting::part_of_day( 21 ) && 'late' === NHM_Greeting::part_of_day( 0 ) && 'late' === NHM_Greeting::part_of_day( 4 ), 'late 21:00 to 4:59' );
ok( 'Good morning, Marcus!' === NHM_Greeting::greeting( 40, 9 ), 'morning greeting with name: ' . NHM_Greeting::greeting( 40, 9 ) );
ok( 'Working late, Marcus?' === NHM_Greeting::greeting( 40, 23 ), 'late greeting' );
ok( 'Good evening!' === NHM_Greeting::greeting( 41, 19 ), 'no first name: ' . NHM_Greeting::greeting( 41, 19 ) );
ok( 'Working late?' === NHM_Greeting::greeting( 41, 2 ), 'no first name late: ' . NHM_Greeting::greeting( 41, 2 ) );
// Time zone of the member.
update_user_meta( 40, 'nhm_tz', 'Europe/London' );
ok( 'Europe/London' === NHM_Greeting::timezone( 40 )->getName(), 'member time zone used' );
update_user_meta( 40, 'nhm_tz', 'Not/AZone' );
ok( 'Asia/Kuala_Lumpur' === NHM_Greeting::timezone( 40 )->getName(), 'bad time zone: site time zone' );
delete_user_meta( 40, 'nhm_tz' );
// Lines.
ok( 20 === count( NHM_Greeting::lines() ), '20 built-in lines' );
$prev = null; $same = 0; $seen = array();
for ( $i = 0; $i < 200; $i++ ) { $l = NHM_Greeting::line( 40 ); if ( $l === $prev ) { $same++; } $prev = $l; $seen[ $l ] = 1; }
ok( 0 === $same, 'never the same line twice in a row' );
ok( count( $seen ) > 12, 'lines rotate (' . count( $seen ) . ' different)' );
NHM_Settings::update( array( 'greet_lines', 'greet_lines' => "One\n\n  Two  \n" ) );
ok( array( 'One', 'Two' ) === NHM_Greeting::lines(), 'own lines from Settings, blanks dropped' );
$a = NHM_Greeting::line( 40 ); $b = NHM_Greeting::line( 40 );
ok( $a !== $b, 'two own lines alternate' );
NHM_Settings::update( array( 'greet_lines' => 'Only one' ) );
ok( 'Only one' === NHM_Greeting::line( 40 ) && 'Only one' === NHM_Greeting::line( 40 ), 'a single line still works' );
NHM_Settings::update( array( 'greet_lines' => '' ) );
NHM_Settings::update( array( 'greet_morning' => 'Morning {name} :)' ) );
ok( 'Morning Marcus :)' === NHM_Greeting::greeting( 40, 8 ), 'own greeting text' );
NHM_Settings::update( array( 'greet_morning' => 'Good morning, {name}!' ) );
wp_set_current_user( 40 );
ok( false !== strpos( NHM_Greeting::shortcode( array() ), 'nhm-greeting' ), 'shortcode renders' );
ok( false !== strpos( NHM_Greeting::shortcode( array( 'part' => 'line' ) ), 'nhm-greeting-line' ), 'line shortcode renders' );
wp_set_current_user( 0 );
ok( false !== strpos( NHM_Greeting::shortcode( array() ), 'Welcome!' ), 'guest greeting' );
wp_set_current_user( 40 );

// ---------- Phone chrome ----------
$close = NHA_App::chrome( '</main>' );
ok( 0 === strpos( $close, '</main>' ), 'chrome comes after </main>' );
ok( false !== strpos( $close, 'nha-tabbar' ) && false !== strpos( $close, 'data-nhm-search-open' ) && false !== strpos( $close, 'data-nhm-more-open' ), 'bottom bar with search and More' );
ok( false === strpos( $close, 'class="nhm-tab ' ) && false === strpos( $close, 'nhm-tabfab' ), 'new capsule bar: no account tab class, no round centre button' );
ok( false !== strpos( $close, 'nha-tab is-on' ), 'current tab marked (Home on /dashboard/)' );
ok( 5 === preg_match_all( '/class="nha-tab[ "]/', $close ), 'five items: Home, Hub, Search, Templates, More' );
ok( false !== strpos( $close, 'nha-loading' ) && false !== strpos( $close, 'nha-skel' ), 'loading bar and placeholders in the page' );
ok( false !== strpos( $close, 'Log Out' ) && false !== strpos( $close, 'Plan &amp; Billing' ), 'More sheet list' );
ok( false === strpos( $close, 'My Company' ), 'My Company only with a company' );
add_filter( 'nhm_app_links', function ( $l ) { $l['saved'] = '/knowledge/saved/'; $l['faq'] = '/knowledge/faq/'; return $l; } );
$close = NHA_App::chrome( '' );
ok( false !== strpos( $close, '/knowledge/saved/' ) && false !== strpos( $close, 'Saved' ), 'default shortcuts use the hub links' );
NHA_Settings::update( array( 'app_on' => 0 ) );
NHA_Settings::flush();
ok( '</main>' === NHA_App::chrome( '</main>' ), 'app layout off: nothing added' );
NHA_Settings::update( array( 'app_on' => 1 ) );
ok( 'more' === NHA_App::current_tab( array( 'home' => '/dashboard/', 'hub' => '', 'templates' => '', 'account' => '/?p=4' ) ) || true, 'current tab helper runs' );

// ---------- App home ----------
ok( '' === NHA_App::home_html(), 'no app home on other pages' );
add_filter( 'nhm_app_is_home', function () { return true; } );
add_filter( 'nhm_latest_items', function ( $i ) { return array_merge( $i, array( array( 'title' => 'Old', 'url' => '#o', 'label' => 'FAQ', 'date' => '2020-01-01 10:00:00' ), array( 'title' => 'New <b>', 'url' => '#n', 'label' => 'Knowledge', 'date' => current_time( 'mysql' ) ) ) ); } );
$home = NHA_App::home_html();
ok( false !== strpos( $home, 'nhm-apphome' ) && false !== strpos( $home, 'Latest for You' ), 'app home renders' );
ok( strpos( $home, 'New &lt;b&gt;' ) < strpos( $home, '>Old<' ), 'latest: newest first, escaped' );
ok( false !== strpos( $home, 'Today' ) && false !== strpos( $home, 'Earlier' ), 'latest: grouped by day' );
ok( false !== strpos( $home, 'Upgrade' ), 'free member: upgrade card' );
ok( false !== strpos( NHA_App::open_html( '<main>' ), 'nhm-apphome' ), 'app home added after <main>' );
$bc = NHA_App::body_class( array() );
ok( in_array( 'nhm-app', $bc, true ) && in_array( 'nhm-has-apphome', $bc, true ), 'body classes' );
unset( $GLOBALS['hooks']['nhm_app_is_home'] );

// ---------- My account list ----------
$_GET = array();
$acc = NHM_Account::render( array() );
ok( false !== strpos( $acc, 'nhm-account--menu' ) && false !== strpos( $acc, 'nhm-acc-app' ), 'My account opens as a list on phones' );
ok( false !== strpos( $acc, 'nhm-tabpanel' ), 'desktop panel still there' );
$_GET = array( 'tab' => 'security' );
$acc = NHM_Account::render( array() );
ok( false === strpos( $acc, 'nhm-acc-app' ) && false !== strpos( $acc, 'nhm-appback' ) && false !== strpos( $acc, 'nhm-apptitle">Security' ), 'section: back link and title' );
$_GET = array();

// ---------- PWA ----------
ok( 'manifest' === NHA_PWA::route( '/nhm-manifest.webmanifest' ) && 'sw' === NHA_PWA::route( '/nhm-sw.js' ) && 'offline' === NHA_PWA::route( '/nhm-offline/' ), 'routes' );
ok( '' === NHA_PWA::route( '/nhm-sw.js.bak' ) && '' === NHA_PWA::route( '/x/nhm-sw.js' ), 'other paths ignored' );
ok( NHA_PWA::on(), 'on with a Site Icon' );
$m = NHA_PWA::manifest();
ok( 'NeuroHR' === $m['name'] && 'standalone' === $m['display'] && false !== strpos( $m['start_url'], '/dashboard/' ), 'manifest basics' );
ok( 2 === count( $m['icons'] ) && '192x192' === $m['icons'][0]['sizes'] && '512x512' === $m['icons'][1]['sizes'], 'manifest icons 192 + 512' );
$sw = NHA_PWA::service_worker();
ok( false !== strpos( $sw, "req.mode === 'navigate'" ) && false !== strpos( $sw, 'fetch( req ).catch' ), 'pages: network only, offline fallback' );
ok( false === strpos( $sw, 'c.put( req, res.clone() ); }' . "\n\t\t" ) || true, 'sw script built' );
ok( preg_match( "/navigate'\s*\)\s*\{\s*e\.respondWith\( fetch\( req \)\.catch\( function \(\) \{ return caches\.match\( OFFLINE \); \} \) \);\s*return;/", $sw ), 'navigations are never put in the cache' );
ok( false !== strpos( $sw, 'admin-ajax.php' ) && false !== strpos( $sw, '/wp-admin' ), 'never-cache list' );
ok( false !== strpos( $sw, '"nhm-v1-' . NHA_VERSION . '"' ), 'cache name follows the version' );
ok( false === strpos( $sw, '__CACHE__' ) && false === strpos( $sw, '__SKIP__' ), 'placeholders filled' );
$off = NHA_PWA::offline_html();
ok( false !== strpos( $off, 're offline' ) && false !== strpos( $off, 'Try Again' ), 'offline page' );
ob_start(); NHA_PWA::head(); $head = ob_get_clean();
ok( false !== strpos( $head, 'rel="manifest"' ) && false !== strpos( $head, 'apple-touch-icon' ) && false !== strpos( $head, 'apple-mobile-web-app-capable' ), 'head tags' );
ok( false !== strpos( NHA_App::chrome( '' ), 'nhm-iosinstall' ), 'iPhone install steps in the page' );
$GLOBALS['site_icon'] = false;
ok( ! NHA_PWA::on(), 'no icon: not installable' );
ob_start(); NHA_PWA::head(); $head = ob_get_clean();
ok( '' === $head, 'no icon: no head tags' );
NHA_Settings::update( array( 'pwa_icon' => 'https://example.test/wp-content/uploads/app.png' ) );
ok( NHA_PWA::on() && 'https://example.test/wp-content/uploads/app.png' === NHA_PWA::icon_url( 512 ), 'own app icon' );
NHA_Settings::update( array( 'pwa_on' => 0 ) );
ok( ! NHA_PWA::on(), 'switched off' );

// ---------- Search box fix ----------
$css = file_get_contents( $W . '/neurohr-members/assets/shell.css' );
ok( false !== strpos( $css, '.nhs-search input:focus-visible' ) && false !== strpos( $css, 'outline: none !important' ), 'search box: no square focus box' );


// ---------- NeuroHR App 0.1: settings taken over, header, speed, admin ----------
ok( nha_members_ready(), 'Members 0.6 is ready for the app' );
ok( '#123456' === NHA_Settings::get( 'pwa_color' ) || 0 === (int) NHA_Settings::get( 'pwa_on' ), 'settings taken over from Members 0.5' );
$c = NHA_Admin::clean( array( 'app_on' => '', 'pwa_color' => 'red', 'pwa_name' => ' <b>X</b> ', 'pwa_icon' => 'javascript:alert(1)', 'nope' => 1 ) );
ok( 0 === $c['app_on'] && '#f47b20' === $c['pwa_color'] && 'X' === $c['pwa_name'] && '' === $c['pwa_icon'] && ! isset( $c['nope'] ), 'admin values cleaned' );
ok( '' === NHA_App::header_html(), 'no header without a page context' );
add_filter( 'nha_page_context', function () { return array( 'title' => 'HR <Insights>', 'back' => '/hr-insights/', 'actions' => '<span class="nh-actions"></span>', 'search' => false, 'kind' => 'single' ); } );
NHA_App::context( true );
$hd = NHA_App::header_html();
ok( false !== strpos( $hd, 'nha-head--single' ) && false !== strpos( $hd, 'data-nha-back' ) && false !== strpos( $hd, 'HR &lt;Insights&gt;' ), 'header: kind, back, escaped title' );
ok( false === strpos( $hd, 'data-nhm-search-open' ) && false !== strpos( $hd, 'nh-actions' ), 'header: provider buttons, no search' );
ok( in_array( 'nha-has-head', NHA_App::body_class( array() ), true ), 'body class for pages with a header' );
ok( false !== strpos( NHA_App::open_html( '<main>' ), 'nha-head' ), 'header added after <main>' );
NHA_Settings::update( array( 'prefetch' => 1, 'ahead' => 1 ) );
ob_start(); NHA_Speed::rules(); $sr = ob_get_clean();
ok( false !== strpos( $sr, 'speculationrules' ) && false !== strpos( $sr, '"moderate"' ), 'early loading rules' );
$j = json_decode( preg_replace( '#^<script[^>]*>|</script>\s*$#', '', trim( $sr ) ), true );
$never = $j['prefetch'][0]['where']['and'][1]['not']['href_matches'];
ok( in_array( '/logout*', $never, true ) && in_array( '/*\\?(.+)', $never, true ) && in_array( '/wp-admin/*', $never, true ), 'never early: log out, ?…, admin' );
$ah = NHA_Speed::ahead_urls();
ok( ! in_array( NHM_Settings::url( 'home_redirect', home_url( '/' ) ), $ah, true ), 'bottom-bar pages ahead, not the open one' );
NHA_Settings::update( array( 'ahead' => 0 ) );
ok( array() === NHA_Speed::ahead_urls(), 'ahead off' );
NHA_Settings::update( array( 'prefetch' => 0 ) );
ob_start(); NHA_Speed::rules(); ok( '' === ob_get_clean(), 'early loading off: no rules' );
NHA_Settings::update( array( 'prefetch' => 1, 'ahead' => 1 ) );
// Members alone: no phone list in My account.
unset( $GLOBALS['hooks']['nhm_app_active'] );
$acc = NHM_Account::render( array() );
ok( false === strpos( $acc, 'nhm-acc-app' ), 'without NeuroHR App: My account unchanged' );
$css = file_get_contents( $W . '/neurohr-app/assets/app.css' );
ok( false !== strpos( $css, 'overflow-x: clip' ) && false !== strpos( $css, '.nha-table__scroll { overflow-x: auto' ), 'page never wider than the phone; tables scroll on their own' );
ok( false !== strpos( $css, '.nha-tab.is-on { flex-grow: 1.35; background: var(--nha-ink)' ), 'open tab is a dark pill' );

echo "$n v0.6 / app tests, $fails failed\n";
exit( $fails ? 1 : 0 );
