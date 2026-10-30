<?php
// Minimal WordPress stand-in for logic tests (not a real WordPress).
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
date_default_timezone_set( 'UTC' );

$GLOBALS['NOW']      = time();
$GLOBALS['hooks']    = array();
$GLOBALS['options']  = array();
$GLOBALS['users']    = array();
$GLOBALS['usermeta'] = array();
$GLOBALS['postmeta'] = array();
$GLOBALS['mails']    = array();
$GLOBALS['current']  = 0;

class WP_Error {
	public $code; public $msg; public $data;
	function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
	function get_error_data() { return $this->data; }
	function get_error_message() { return $this->msg; }
	function get_error_code() { return $this->code; }
}
class WP_User { public $ID, $user_email, $display_name, $user_login, $user_pass = 'x', $user_registered = '2026-01-01 00:00:00', $caps = array(), $first_name = '', $last_name = ''; }
class WP_Post {}

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $a ); return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return add_action( $h, $cb, $p, $a ); }
function remove_filter( $h, $cb, $p = 10 ) { return true; }
function do_action( $h, ...$args ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $c ) { call_user_func_array( $c[0], array_slice( $args, 0, $c[1] ) ); } }
function apply_filters( $h, $v, ...$args ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $c ) { $v = call_user_func_array( $c[0], array_slice( array_merge( array( $v ), $args ), 0, $c[1] ) ); } return $v; }
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function is_admin() { return false; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); }
function get_transient( $k ) { return get_option( '_t_' . $k ); }
function set_transient( $k, $v, $e = 0 ) { update_option( '_t_' . $k, $v ); }
function delete_transient( $k ) { delete_option( '_t_' . $k ); }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 == $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_html__( $t ) { return esc_html( $t ); }
function esc_attr__( $t ) { return esc_html( $t ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u, $p = null ) { return $u; }
function wp_kses( $t ) { return $t; }
function wp_kses_post( $t ) { return $t; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_email( $e ) { return strtolower( trim( (string) $e ) ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', $c ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : null; }
function sanitize_user( $u ) { return preg_replace( '/[^a-z0-9_.\-@]/', '', strtolower( $u ) ); }
function absint( $n ) { return abs( (int) $n ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_permalink( $id = 0 ) { return 'https://example.test/?p=' . (int) $id; }
function get_post_status( $id ) { return 'publish'; }
function add_query_arg( $k, $v = null, $url = null ) { if ( is_array( $k ) ) { $url = $v; $q = http_build_query( $k ); } else { $q = $k . '=' . $v; } if ( null === $url ) { $url = 'https://example.test' . ( $_SERVER['REQUEST_URI'] ?? '/' ); } return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $q; }
function wp_mail( $to, $s, $b, $h = array(), $a = array() ) { $GLOBALS['mails'][] = compact( 'to', 's', 'b', 'h', 'a' ); return true; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_remote_post( $u, $a ) { $GLOBALS['remote'][] = array( $u, $a ); return array( 'response' => array( 'code' => 201 ), 'body' => '{}' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_generate_password( $n = 12 ) { return substr( bin2hex( random_bytes( $n ) ), 0, $n ); }
function wp_salt() { return 'salt'; }
function get_bloginfo( $k ) { return 'NeuroHR'; }
function wpautop( $t ) { return '<p>' . str_replace( "\n\n", '</p><p>', $t ) . '</p>'; }
function make_clickable( $t ) { return $t; }
function wp_specialchars_decode( $t ) { return $t; }
function wp_timezone() { return new DateTimeZone( 'Asia/Kuala_Lumpur' ); }
function current_time( $f ) { $d = ( new DateTimeImmutable( '@' . $GLOBALS['NOW'] ) )->setTimezone( wp_timezone() ); return 'mysql' === $f ? $d->format( 'Y-m-d H:i:s' ) : $d->format( $f ); }
function wp_date( $f, $ts = null ) { $d = ( new DateTimeImmutable( '@' . ( $ts ?? $GLOBALS['NOW'] ) ) )->setTimezone( wp_timezone() ); return $d->format( $f ); }
function mysql2date( $f, $d ) { return date( $f, strtotime( $d ) ); }
function get_current_user_id() { return $GLOBALS['current']; }
function wp_set_current_user( $id ) { $GLOBALS['current'] = $id; }
function is_user_logged_in() { return $GLOBALS['current'] > 0; }
function get_userdata( $id ) { return $GLOBALS['users'][ (int) $id ] ?? false; }
function wp_get_current_user() { return get_userdata( $GLOBALS['current'] ); }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['users'] as $u ) { if ( 'email' === $f && strtolower( $u->user_email ) === strtolower( $v ) ) { return $u; } } return false; }
function email_exists( $e ) { $u = get_user_by( 'email', $e ); return $u ? $u->ID : false; }
function username_exists( $n ) { foreach ( $GLOBALS['users'] as $u ) { if ( $u->user_login === $n ) { return $u->ID; } } return false; }
function user_can( $u, $cap ) { $u = is_object( $u ) ? $u : get_userdata( $u ); return $u && in_array( $cap, $u->caps, true ); }
function current_user_can( $cap ) { return user_can( $GLOBALS['current'], $cap ); }
function get_user_meta( $id, $k, $single = false ) { $v = $GLOBALS['usermeta'][ $id ][ $k ] ?? ''; return $v; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['usermeta'][ $id ][ $k ] = $v; return true; }
function delete_user_meta( $id, $k ) { unset( $GLOBALS['usermeta'][ $id ][ $k ] ); return true; }
function update_meta_cache() {}
function get_users( $a ) {
	$out = array();
	foreach ( $GLOBALS['users'] as $u ) {
		if ( isset( $a['meta_key'] ) && isset( $a['meta_compare'] ) && 'EXISTS' === $a['meta_compare'] ) { if ( '' === get_user_meta( $u->ID, $a['meta_key'], true ) ) { continue; } } elseif ( isset( $a['meta_key'] ) && (string) get_user_meta( $u->ID, $a['meta_key'] ) !== (string) $a['meta_value'] ) { continue; }
		if ( isset( $a['include'] ) && ! in_array( $u->ID, $a['include'] ) ) { continue; }
		if ( isset( $a['meta_query'] ) ) {
			$q = $a['meta_query'][0]; $v = get_user_meta( $u->ID, $q['key'] );
			if ( '' === $v || ! ( $v <= $q['value'] ) ) { continue; }
		}
		$out[] = ( 'ID' === ( $a['fields'] ?? '' ) ) ? $u->ID : $u;
	}
	return $out;
}
function add_user( $id, $email, $name, $caps = array() ) { $u = new WP_User(); $u->ID = $id; $u->user_email = $email; $u->display_name = $name; $u->user_login = 'u' . $id; $u->caps = $caps; $GLOBALS['users'][ $id ] = $u; return $u; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['postmeta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['postmeta'][ $id ][ $k ] = $v; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['postmeta'][ $id ][ $k ] ); }
function wp_next_scheduled() { return 1; }
function nocache_headers() {}


class wpdb {
	public $prefix = 'wp_'; public $postmeta = 'wp_postmeta'; public $usermeta = 'wp_usermeta'; public $insert_id = 0; public $pdo;
	function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION ); }
	function get_charset_collate() { return ''; }
	function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0; $pdo = $this->pdo;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args, $pdo ) { $v = $args[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : $pdo->quote( (string) $v ); }, $sql );
	}
	function get_results( $sql, $o = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	function get_row( $sql, $o = null ) { $r = $this->get_results( $sql ); return $r ? $r[0] : null; }
	function get_var( $sql ) { $r = $this->get_row( $sql ); return $r ? array_values( $r )[0] : null; }
	function get_col( $sql ) { return array_map( function ( $r ) { return array_values( $r )[0]; }, $this->get_results( $sql ) ); }
	function query( $sql ) { return $this->pdo->exec( $sql ); }
	function insert( $t, $d ) { $c = array_keys( $d ); $st = $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_fill( 0, count( $c ), '?' ) ) . ')' ); $st->execute( array_values( $d ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
	function update( $t, $d, $w ) { $set = implode( ',', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $w ) ) ); $st = $this->pdo->prepare( "UPDATE $t SET $set WHERE $wh" ); $st->execute( array_merge( array_values( $d ), array_values( $w ) ) ); return $st->rowCount(); }
	function delete( $t, $w ) { $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $w ) ) ); $st = $this->pdo->prepare( "DELETE FROM $t WHERE $wh" ); $st->execute( array_values( $w ) ); return $st->rowCount(); }
}
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE wp_nhm_companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, client_code TEXT DEFAULT '', reg_no TEXT DEFAULT '', tin TEXT DEFAULT '', sst_no TEXT DEFAULT '', address TEXT, postcode TEXT DEFAULT '', city TEXT DEFAULT '', state TEXT DEFAULT '', country TEXT DEFAULT '', phone TEXT DEFAULT '', billing_email TEXT DEFAULT '', contact_person TEXT DEFAULT '', industry TEXT DEFAULT '', employees INT DEFAULT 0, level_id INT DEFAULT 0, start_date TEXT NULL, expiry_date TEXT NULL, seats INT DEFAULT 1, owner_id INT DEFAULT 0, notes TEXT, reminded TEXT DEFAULT '', created_at TEXT, updated_at TEXT)" );
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE wp_nhm_invites (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INT, email TEXT, role TEXT DEFAULT 'member', token_hash TEXT, invited_by INT, created_at TEXT, expires_at TEXT)" );
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE wp_nhm_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT DEFAULT 0, company_id INT DEFAULT 0, event TEXT, level_from INT DEFAULT 0, level_to INT DEFAULT 0, expiry TEXT NULL, message TEXT, actor_id INT DEFAULT 0, created_at TEXT)" );
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, meta_key TEXT, meta_value TEXT)" );
function wp_login_url( $r = '' ) { return 'https://example.test/wp-login.php' . ( $r ? '?redirect_to=' . rawurlencode( $r ) : '' ); }
function wp_registration_url() { return 'https://example.test/wp-login.php?action=register'; }
function wp_lostpassword_url() { return 'https://example.test/wp-login.php?action=lostpassword'; }
function wp_logout_url( $r = '' ) { return apply_filters( 'logout_url', 'https://example.test/wp-login.php?action=logout', $r ); }
function shortcode_unautop( $t ) { return $t; }
function do_shortcode( $t ) { return $t; }
function shortcode_atts( $d, $a ) { return array_merge( $d, (array) $a ); }
function get_date_from_gmt( $d, $f = 'Y-m-d H:i:s' ) { return date( $f, strtotime( $d ) ); }
function get_option_date() {}
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
$GLOBALS['options']['admin_email'] = 'owner@neurohr.my';
function remove_query_arg( $keys, $url = null ) { if ( null === $url ) { $url = 'https://example.test' . ( $_SERVER['REQUEST_URI'] ?? '/' ); } $p = parse_url( $url ); parse_str( $p['query'] ?? '', $q ); foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); } return $p['scheme'] . '://' . $p['host'] . ( $p['path'] ?? '' ) . ( $q ? '?' . http_build_query( $q ) : '' ); }
function post_type_exists( $t ) { return in_array( $t, array( "page", "post" ), true ); }

$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE wp_nhm_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT DEFAULT '', kind TEXT DEFAULT 'manual', user_id INT DEFAULT 0, company_id INT DEFAULT 0, status TEXT DEFAULT 'unpaid', issue_date TEXT, due_date TEXT, paid_date TEXT, paid_at TEXT, refunded REAL DEFAULT 0, payment_method TEXT DEFAULT '', payment_ref TEXT DEFAULT '', currency TEXT DEFAULT 'RM', subtotal REAL DEFAULT 0, discount REAL DEFAULT 0, tax_rate REAL DEFAULT 0, tax REAL DEFAULT 0, total REAL DEFAULT 0, items TEXT, bill_to TEXT, terms INT DEFAULT 1, tax_inclusive INT DEFAULT 0, period_start TEXT, period_end TEXT, level_id INT DEFAULT 0, grant_to TEXT DEFAULT '', granted INT DEFAULT 0, notes TEXT, token TEXT DEFAULT '', created_by INT DEFAULT 0, created_at TEXT, updated_at TEXT)" );
define( 'MB_IN_BYTES', 1048576 );
$GLOBALS['UPLOADS'] = sys_get_temp_dir() . '/nhm-test-uploads';
@mkdir( $GLOBALS['UPLOADS'], 0777, true );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['UPLOADS'], 'baseurl' => 'https://example.test/wp-content/uploads' ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function sanitize_file_name( $f ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $f ); }
function get_password_reset_key( $u ) { return 'RESETKEY'; }
function network_site_url( $p = '', $s = null ) { return 'https://example.test/' . ltrim( $p, '/' ); }
function wp_get_referer() { return false; }
function wp_nonce_url( $u, $a = -1 ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . '_wpnonce=x'; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function did_action( $h ) { return 0; }

if ( ! function_exists( 'url_to_postid' ) ) { function url_to_postid( $u ) { return 0; } }

if ( ! function_exists( 'disabled' ) ) { function disabled( $a, $b = true, $e = true ) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! class_exists( 'WP_Session_Tokens' ) ) { class WP_Session_Tokens { public $u; public static function get_instance( $u ) { $o = new self(); $o->u = $u; return $o; } public function get_all() { $t = get_user_meta( $this->u, 'session_tokens', true ); return is_array( $t ) ? array_values( $t ) : array(); } public function destroy_all() { $GLOBALS['destroyed_all'][] = $this->u; update_user_meta( $this->u, 'session_tokens', array() ); } public function destroy_others( $tok ) { $GLOBALS['destroyed_others'] = $this->u; $t = get_user_meta( $this->u, 'session_tokens', true ); $keep = array(); foreach ( (array) $t as $k => $v ) { if ( $k === hash( 'sha256', $tok ) ) { $keep[ $k ] = $v; } } update_user_meta( $this->u, 'session_tokens', $keep ); } } }
if ( ! function_exists( 'wp_get_session_token' ) ) { function wp_get_session_token() { return $GLOBALS['SESSION_TOKEN'] ?? 'tok1'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'NONCE'; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $k, $d = false ) { return $GLOBALS['theme_mods'][ $k ] ?? $d; } function set_theme_mod( $k, $v ) { $GLOBALS['theme_mods'][ $k ] = $v; } }
if ( ! function_exists( 'shortcode_exists' ) ) { function shortcode_exists( $t ) { return false; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; } }
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE IF NOT EXISTS wp_nhm_refunds (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT DEFAULT '', invoice_id INT DEFAULT 0, payment_id INT DEFAULT 0, user_id INT DEFAULT 0, company_id INT DEFAULT 0, status TEXT DEFAULT 'requested', source TEXT DEFAULT 'member', reason TEXT DEFAULT '', details TEXT, usage_info TEXT, paid_amount REAL DEFAULT 0, fee REAL NULL, amount REAL DEFAULT 0, method TEXT DEFAULT '', stripe_refund TEXT DEFAULT '', reference TEXT DEFAULT '', refund_date TEXT, reject_reason TEXT, admin_note TEXT, decided_by INT DEFAULT 0, decided_at TEXT, token TEXT DEFAULT '', created_at TEXT, updated_at TEXT)" );
$GLOBALS['wpdb']->pdo->exec( "CREATE TABLE IF NOT EXISTS wp_nhm_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INT DEFAULT 0, user_id INT DEFAULT 0, method TEXT DEFAULT '', status TEXT DEFAULT 'pending', amount REAL DEFAULT 0, currency TEXT DEFAULT 'MYR', mode TEXT DEFAULT '', stripe_session TEXT DEFAULT '', stripe_intent TEXT DEFAULT '', stripe_method TEXT DEFAULT '', payer_name TEXT DEFAULT '', paid_at TEXT, reference TEXT DEFAULT '', note TEXT, slip TEXT DEFAULT '', admin_note TEXT, reviewed_by INT DEFAULT 0, reviewed_at TEXT, created_at TEXT, updated_at TEXT)" );
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); } }
