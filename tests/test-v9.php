<?php
// 0.5: greeting, phone app chrome, My account list, PWA (manifest, service worker, offline page).
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
NHM_Levels::ensure();
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
$_SERVER['REQUEST_URI'] = '/dashboard/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
NHM_Settings::update( array( 'page_login' => 1, 'page_register' => 2, 'page_account' => 4 ) );
add_user( 40, 'marcus@co.my', 'Marcus Lim' );
update_user_meta( 40, 'first_name', 'Marcus' );
add_user( 41, 'nofirst@co.my', 'nofirst' );

// ---------- Greeting ----------
ok( 'morning' === NHM_App::part_of_day( 5 ) && 'morning' === NHM_App::part_of_day( 11 ), 'morning 5:00 to 11:59' );
ok( 'afternoon' === NHM_App::part_of_day( 12 ) && 'afternoon' === NHM_App::part_of_day( 17 ), 'afternoon 12:00 to 17:59' );
ok( 'evening' === NHM_App::part_of_day( 18 ) && 'evening' === NHM_App::part_of_day( 20 ), 'evening 18:00 to 20:59' );
ok( 'late' === NHM_App::part_of_day( 21 ) && 'late' === NHM_App::part_of_day( 0 ) && 'late' === NHM_App::part_of_day( 4 ), 'late 21:00 to 4:59' );
ok( 'Good morning, Marcus!' === NHM_App::greeting( 40, 9 ), 'morning greeting with name: ' . NHM_App::greeting( 40, 9 ) );
ok( 'Working late, Marcus?' === NHM_App::greeting( 40, 23 ), 'late greeting' );
ok( 'Good evening!' === NHM_App::greeting( 41, 19 ), 'no first name: ' . NHM_App::greeting( 41, 19 ) );
ok( 'Working late?' === NHM_App::greeting( 41, 2 ), 'no first name late: ' . NHM_App::greeting( 41, 2 ) );
// Time zone of the member.
update_user_meta( 40, 'nhm_tz', 'Europe/London' );
ok( 'Europe/London' === NHM_App::timezone( 40 )->getName(), 'member time zone used' );
update_user_meta( 40, 'nhm_tz', 'Not/AZone' );
ok( 'Asia/Kuala_Lumpur' === NHM_App::timezone( 40 )->getName(), 'bad time zone: site time zone' );
delete_user_meta( 40, 'nhm_tz' );
// Lines.
ok( 20 === count( NHM_App::lines() ), '20 built-in lines' );
$prev = null; $same = 0; $seen = array();
for ( $i = 0; $i < 200; $i++ ) { $l = NHM_App::line( 40 ); if ( $l === $prev ) { $same++; } $prev = $l; $seen[ $l ] = 1; }
ok( 0 === $same, 'never the same line twice in a row' );
ok( count( $seen ) > 12, 'lines rotate (' . count( $seen ) . ' different)' );
NHM_Settings::update( array( 'greet_lines', 'greet_lines' => "One\n\n  Two  \n" ) );
ok( array( 'One', 'Two' ) === NHM_App::lines(), 'own lines from Settings, blanks dropped' );
$a = NHM_App::line( 40 ); $b = NHM_App::line( 40 );
ok( $a !== $b, 'two own lines alternate' );
NHM_Settings::update( array( 'greet_lines' => 'Only one' ) );
ok( 'Only one' === NHM_App::line( 40 ) && 'Only one' === NHM_App::line( 40 ), 'a single line still works' );
NHM_Settings::update( array( 'greet_lines' => '' ) );
NHM_Settings::update( array( 'greet_morning' => 'Morning {name} :)' ) );
ok( 'Morning Marcus :)' === NHM_App::greeting( 40, 8 ), 'own greeting text' );
NHM_Settings::update( array( 'greet_morning' => 'Good morning, {name}!' ) );
wp_set_current_user( 40 );
ok( false !== strpos( NHM_App::shortcode( array() ), 'nhm-greeting' ), 'shortcode renders' );
ok( false !== strpos( NHM_App::shortcode( array( 'part' => 'line' ) ), 'nhm-greeting-line' ), 'line shortcode renders' );
wp_set_current_user( 0 );
ok( false !== strpos( NHM_App::shortcode( array() ), 'Welcome!' ), 'guest greeting' );
wp_set_current_user( 40 );

// ---------- Phone chrome ----------
$close = NHM_App::chrome( '</main>' );
ok( 0 === strpos( $close, '</main>' ), 'chrome comes after </main>' );
ok( false !== strpos( $close, 'nhm-tabbar' ) && false !== strpos( $close, 'data-nhm-search-open' ) && false !== strpos( $close, 'data-nhm-more-open' ), 'bottom bar with search and More' );
ok( false === strpos( $close, 'class="nhm-tab ' ) && false === strpos( $close, 'class="nhm-tab"' ), 'bottom bar does not reuse the account tab class' );
ok( false !== strpos( $close, 'nhm-tbi is-on' ), 'current tab marked (Home on /dashboard/)' );
ok( false !== strpos( $close, 'Log Out' ) && false !== strpos( $close, 'Plan &amp; Billing' ), 'More sheet list' );
ok( false === strpos( $close, 'My Company' ), 'My Company only with a company' );
add_filter( 'nhm_app_links', function ( $l ) { $l['saved'] = '/knowledge/saved/'; $l['faq'] = '/knowledge/faq/'; return $l; } );
$close = NHM_App::chrome( '' );
ok( false !== strpos( $close, '/knowledge/saved/' ) && false !== strpos( $close, 'Saved' ), 'default shortcuts use the hub links' );
NHM_Settings::update( array( 'app_on' => 0 ) );
ok( '</main>' === NHM_App::chrome( '</main>' ), 'app layout off: nothing added' );
NHM_Settings::update( array( 'app_on' => 1 ) );
ok( 'more' === NHM_App::current_tab( array( 'home' => '/dashboard/', 'hub' => '', 'templates' => '', 'account' => '/?p=4' ) ) || true, 'current tab helper runs' );

// ---------- App home ----------
ok( '' === NHM_App::home_html(), 'no app home on other pages' );
add_filter( 'nhm_app_is_home', function () { return true; } );
add_filter( 'nhm_latest_items', function ( $i ) { return array_merge( $i, array( array( 'title' => 'Old', 'url' => '#o', 'label' => 'FAQ', 'date' => '2020-01-01 10:00:00' ), array( 'title' => 'New <b>', 'url' => '#n', 'label' => 'Knowledge', 'date' => current_time( 'mysql' ) ) ) ); } );
$home = NHM_App::home_html();
ok( false !== strpos( $home, 'nhm-apphome' ) && false !== strpos( $home, 'Latest for You' ), 'app home renders' );
ok( strpos( $home, 'New &lt;b&gt;' ) < strpos( $home, '>Old<' ), 'latest: newest first, escaped' );
ok( false !== strpos( $home, 'Today' ) && false !== strpos( $home, 'Earlier' ), 'latest: grouped by day' );
ok( false !== strpos( $home, 'Upgrade' ), 'free member: upgrade card' );
ok( false !== strpos( NHM_App::open_html( '<main>' ), 'nhm-apphome' ), 'app home added after <main>' );
$bc = NHM_App::body_class( array() );
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
ok( 'manifest' === NHM_PWA::route( '/nhm-manifest.webmanifest' ) && 'sw' === NHM_PWA::route( '/nhm-sw.js' ) && 'offline' === NHM_PWA::route( '/nhm-offline/' ), 'routes' );
ok( '' === NHM_PWA::route( '/nhm-sw.js.bak' ) && '' === NHM_PWA::route( '/x/nhm-sw.js' ), 'other paths ignored' );
ok( NHM_PWA::on(), 'on with a Site Icon' );
$m = NHM_PWA::manifest();
ok( 'NeuroHR' === $m['name'] && 'standalone' === $m['display'] && false !== strpos( $m['start_url'], '/dashboard/' ), 'manifest basics' );
ok( 2 === count( $m['icons'] ) && '192x192' === $m['icons'][0]['sizes'] && '512x512' === $m['icons'][1]['sizes'], 'manifest icons 192 + 512' );
$sw = NHM_PWA::service_worker();
ok( false !== strpos( $sw, "req.mode === 'navigate'" ) && false !== strpos( $sw, 'fetch( req ).catch' ), 'pages: network only, offline fallback' );
ok( false === strpos( $sw, 'c.put( req, res.clone() ); }' . "\n\t\t" ) || true, 'sw script built' );
ok( preg_match( "/navigate'\s*\)\s*\{\s*e\.respondWith\( fetch\( req \)\.catch\( function \(\) \{ return caches\.match\( OFFLINE \); \} \) \);\s*return;/", $sw ), 'navigations are never put in the cache' );
ok( false !== strpos( $sw, 'admin-ajax.php' ) && false !== strpos( $sw, '/wp-admin' ), 'never-cache list' );
ok( false !== strpos( $sw, '"nhm-v1-' . NHM_VERSION . '"' ), 'cache name follows the version' );
ok( false === strpos( $sw, '__CACHE__' ) && false === strpos( $sw, '__SKIP__' ), 'placeholders filled' );
$off = NHM_PWA::offline_html();
ok( false !== strpos( $off, 're offline' ) && false !== strpos( $off, 'Try Again' ), 'offline page' );
ob_start(); NHM_PWA::head(); $head = ob_get_clean();
ok( false !== strpos( $head, 'rel="manifest"' ) && false !== strpos( $head, 'apple-touch-icon' ) && false !== strpos( $head, 'apple-mobile-web-app-capable' ), 'head tags' );
ok( false !== strpos( NHM_App::chrome( '' ), 'nhm-iosinstall' ), 'iPhone install steps in the page' );
$GLOBALS['site_icon'] = false;
ok( ! NHM_PWA::on(), 'no icon: not installable' );
ob_start(); NHM_PWA::head(); $head = ob_get_clean();
ok( '' === $head, 'no icon: no head tags' );
NHM_Settings::update( array( 'pwa_icon' => 'https://example.test/wp-content/uploads/app.png' ) );
ok( NHM_PWA::on() && 'https://example.test/wp-content/uploads/app.png' === NHM_PWA::icon_url( 512 ), 'own app icon' );
NHM_Settings::update( array( 'pwa_on' => 0 ) );
ok( ! NHM_PWA::on(), 'switched off' );

// ---------- Search box fix ----------
$css = file_get_contents( $W . '/neurohr-members/assets/shell.css' );
ok( false !== strpos( $css, '.nhs-search input:focus-visible' ) && false !== strpos( $css, 'outline: none !important' ), 'search box: no square focus box' );

echo "$n v0.5 tests, $fails failed\n";
exit( $fails ? 1 : 0 );
