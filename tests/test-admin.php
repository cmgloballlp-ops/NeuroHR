<?php
require __DIR__ . '/wp-stub.php';
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( "$str @ $file:$line" ); } );
function wp_nonce_field( $a, $n = '_wpnonce', $r = true, $e = true ) { $h = '<input type="hidden" name="' . $n . '">'; if ( $e ) { echo $h; } return $h; }
function checked( $a, $b = true, $e = true ) { $r = $a == $b ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = $a == $b ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function submit_button( $t = 'Save' ) { echo '<button>' . $t . '</button>'; }
function wp_dropdown_pages( $a ) { echo '<select name="' . $a['name'] . '"></select>'; }
function get_edit_user_link( $id ) { return 'user-edit.php?user_id=' . $id; }
function get_post_types() { $o = new stdClass(); $o->name = 'page'; $o->labels = (object) array( 'name' => 'Pages' ); return array( 'page' => $o ); }
function get_current_screen() { return (object) array( 'id' => 'toplevel_page_nhm-members' ); }
function plugin_basename( $f ) { return basename( $f ); }
function wp_list_pluck( $l, $f ) { return array_map( function ( $o ) use ( $f ) { return is_array( $o ) ? $o[ $f ] : $o->$f; }, $l ); }
function esc_attr_e( $t ) { echo esc_html( $t ); }
function esc_html_e( $t ) { echo esc_html( $t ); }
function get_post_field() { return '[nhm_login]'; }
function wp_next_scheduled_x() {}
function wp_is_post_revision() { return false; }
function wp_enqueue_media() {}
function wp_rand( $a, $b ) { return mt_rand( $a, $b ); }
function add_menu_page() {} function add_submenu_page() {}
function disabled( $a, $b = true, $e = true ) { $r = $a == $b ? ' disabled' : ''; if ( $e ) { echo $r; } return $r; }
$GLOBALS['wpdb']->pdo->exec( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INT, meta_key TEXT, meta_value TEXT)' );
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
foreach ( array( 'class-nhm-admin', 'class-nhm-admin-levels', 'class-nhm-admin-members', 'class-nhm-admin-companies', 'class-nhm-admin-settings', 'class-nhm-admin-invoices', 'class-nhm-admin-dashboard', 'class-nhm-admin-tools', 'class-nhm-admin-payments' ) as $f ) { require_once $W . '/neurohr-members/admin/' . $f . '.php'; }
NHM_Levels::ensure();
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );
add_user( 2, 'm@n.my', 'Member' );
wp_set_current_user( 1 );
$cid = NHM_Companies::create( array( 'name' => 'Co', 'level_id' => 4, 'expiry_date' => '2027-01-01', 'seats' => 3 ) );
NHM_Companies::add_member( $cid, 2, 'owner' );
NHM_Companies::invite( $cid, 'x@co.my', 'member', 1 );
NHM_Members::set_plan( 2, 3, '2026-01-01', '2026-11-01' );
$n = 0;
function run( $label, $cb ) { global $n; ob_start(); call_user_func( $cb ); $h = ob_get_clean(); $n++; if ( strlen( $h ) < 100 ) { echo "FAIL (short): $label\n"; } }
run( 'members list', array( 'NHM_Admin_Members', 'page' ) );
foreach ( array( 'expiring', 'paid', 'expired', 'free' ) as $v ) { $_GET = array( 'view' => $v ); run( "members $v", array( 'NHM_Admin_Members', 'page' ) ); }
$_GET = array();
run( 'profile', function () { NHM_Admin_Members::profile_section( get_userdata( 2 ) ); } );
run( 'companies', array( 'NHM_Admin_Companies', 'page' ) );
$_GET = array( 'action' => 'edit', 'id' => $cid ); run( 'company edit', array( 'NHM_Admin_Companies', 'page' ) );
$_GET = array( 'action' => 'new' ); run( 'company new', array( 'NHM_Admin_Companies', 'page' ) );
$_GET = array(); run( 'levels', array( 'NHM_Admin_Levels', 'page' ) );
run( 'history', array( 'NHM_Admin', 'history_page' ) );
foreach ( array_keys( NHM_Admin_Settings::tabs() ) as $t ) { $_GET = array( 'tab' => $t ); run( "settings $t", array( 'NHM_Admin_Settings', 'page' ) ); }
$lv = get_option( 'nhm_levels' ); $lv['items'][2]['amount'] = '299.00'; update_option( 'nhm_levels', $lv ); NHM_Levels::flush();
$iid = NHM_Invoices::for_plan( 2, 3, 'signup' );
run( 'dashboard', array( 'NHM_Admin_Dashboard', 'page' ) );
foreach ( array( '', 'pending', 'unpaid', 'paid', 'void' ) as $st ) { $_GET = array( 'status' => $st ); run( "invoices $st", array( 'NHM_Admin_Invoices', 'page' ) ); }
$_GET = array( 'action' => 'edit', 'id' => $iid ); run( 'invoice edit', array( 'NHM_Admin_Invoices', 'page' ) );
NHM_Invoices::mark_paid( $iid, '', 'Cash', '', false );
$_GET = array( 'action' => 'edit', 'id' => $iid ); run( 'invoice edit paid', array( 'NHM_Admin_Invoices', 'page' ) );
$_GET = array( 'action' => 'new', 'user' => 2, 'level' => 3 ); run( 'invoice new for user', array( 'NHM_Admin_Invoices', 'page' ) );
$_GET = array( 'action' => 'new', 'company' => $cid, 'level' => 4 ); run( 'invoice new for company', array( 'NHM_Admin_Invoices', 'page' ) );
$_GET = array(); run( 'import', array( 'NHM_Admin_Tools', 'page' ) );
NHM_Admin::menu();
$GLOBALS['wpdb']->insert( 'wp_nhm_payments', array( 'invoice_id' => 1, 'method' => 'bank', 'status' => 'submitted', 'amount' => 600, 'payer_name' => 'Payer', 'paid_at' => '2026-10-30 10:00:00', 'slip' => 'x.jpg', 'created_at' => '2026-10-30 10:00:00', 'updated_at' => '2026-10-30 10:00:00' ) );
foreach ( array( '', 'submitted', 'online', 'offline' ) as $st ) { $_GET = array( 'status' => $st ); run( "payments $st", array( 'NHM_Admin_Payments', 'page' ) ); }
$_GET = array( 'tab' => 'payments' ); run( 'settings payments', array( 'NHM_Admin_Settings', 'page' ) );
$_GET = array();
add_user( 5, 'spam@bad.my', 'Spammer' );
NHM_Block::block( 5 );
$_GET = array( 'view' => 'blocked' ); ob_start(); NHM_Admin_Members::page(); $h = ob_get_clean(); $n++; if ( false === strpos( $h, 'nhm-pill--blocked' ) ) { echo "FAIL: blocked tab\n"; }
$_GET = array(); ob_start(); NHM_Admin_Members::profile_section( get_userdata( 5 ) ); $h = ob_get_clean(); $n++; if ( false === strpos( $h, 'do=unblock' ) ) { echo "FAIL: unblock button\n"; }
ob_start(); NHM_Admin_Members::profile_section( get_userdata( 2 ) ); $h = ob_get_clean(); $n++; if ( false === strpos( $h, 'do=block' ) ) { echo "FAIL: block button\n"; }

// Refunds tab with a request.
$ri = NHM_Invoices::for_plan( 5, 3, 'signup' ); NHM_Invoices::mark_paid( $ri, '', 'Card', '', false );
$GLOBALS['wpdb']->insert( 'wp_nhm_refunds', array( 'invoice_id' => $ri, 'user_id' => 5, 'status' => 'requested', 'reason' => 'Not what I expected', 'details' => 'Too basic', 'paid_amount' => 299, 'amount' => 299, 'method' => 'manual', 'usage_info' => json_encode( array( 'views' => 4, 'downloads' => 2, 'items' => array( array( 'time' => '2026-10-30 10:00:00', 'action' => 'download', 'title' => 'Offer letter template', 'url' => 'https://example.test/t' ), array( 'time' => '2026-10-30 09:40:00', 'action' => 'view', 'title' => 'Termination guide', 'url' => 'https://example.test/g' ) ) ) ), 'token' => str_repeat( 'a', 32 ), 'created_at' => '2026-10-30 11:00:00', 'updated_at' => '2026-10-30 11:00:00' ) );
$_GET = array( 'status' => 'refunds' ); ob_start(); NHM_Admin_Payments::page(); $h = ob_get_clean(); $n++; if ( false === strpos( $h, 'opened 4, downloaded 2' ) || false === strpos( $h, 'Approve (I will pay it back)' ) ) { echo "FAIL: refunds tab\n"; }

$_GET = array(); ob_start(); NHM_Admin_Dashboard::page(); $h = ob_get_clean(); $n++; if ( false === strpos( $h, 'Net in' ) ) { echo "FAIL: dashboard net\n"; }

echo "$n admin screens rendered\n";
