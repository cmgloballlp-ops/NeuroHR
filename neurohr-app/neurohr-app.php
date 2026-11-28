<?php
/**
 * Plugin Name:       NeuroHR App
 * Description:       The NeuroHR member area as a phone app: floating bottom bar, full-screen search, More sheet, app home, app header on Knowledge Hub pages, faster page changes and "install as an app" (PWA). Needs NeuroHR Members; works with NeuroHR Knowledge Hub.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NeuroHR
 * Text Domain:       neurohr-app
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NHA_VERSION', '0.1.1' );
define( 'NHA_FILE', __FILE__ );
define( 'NHA_DIR', plugin_dir_path( __FILE__ ) );
define( 'NHA_URL', plugin_dir_url( __FILE__ ) );
define( 'NHA_NEEDS_MEMBERS', '0.6.0' );

require_once NHA_DIR . 'includes/class-nha-settings.php';
require_once NHA_DIR . 'includes/class-nha-app.php';
require_once NHA_DIR . 'includes/class-nha-speed.php';
require_once NHA_DIR . 'includes/class-nha-pwa.php';
require_once NHA_DIR . 'includes/class-nha-admin.php';

/** True when a NeuroHR Members that has handed the phone app over to this plugin is active. */
function nha_members_ready() {
	return defined( 'NHM_VERSION' ) && class_exists( 'NHM_Shell' ) && class_exists( 'NHM_Greeting' ) && version_compare( NHM_VERSION, NHA_NEEDS_MEMBERS, '>=' );
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! nha_members_ready() ) {
			add_action( 'admin_notices', 'nha_needs_members_notice' );
			return;
		}
		NHA_App::init();
		NHA_Speed::init();
		NHA_PWA::init();
		if ( is_admin() ) {
			NHA_Admin::init();
		}
	},
	20
);

function nha_needs_members_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	/* translators: %s: version */
	echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'NeuroHR App needs NeuroHR Members %s or newer. Until then the phone app is off.', 'neurohr-app' ), NHA_NEEDS_MEMBERS ) ) . '</p></div>';
}
