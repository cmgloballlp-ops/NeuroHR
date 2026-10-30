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

