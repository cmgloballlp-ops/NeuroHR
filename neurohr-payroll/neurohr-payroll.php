<?php
/**
 * Plugin Name:       NeuroHR Simple Payroll
 * Description:       Malaysian payroll calculator for members: EPF, SOCSO, LINDUNG 24 Jam, EIS and PCB (LHDN computerised method), with a printable payslip. Use the shortcode [nh_payroll_calculator].
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NeuroHR
 * Text Domain:       neurohr-payroll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NHP_VERSION', '0.5.0' );
define( 'NHP_FILE', __FILE__ );
define( 'NHP_DIR', plugin_dir_path( __FILE__ ) );
define( 'NHP_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'Money', 'Rates', 'Epf', 'Socso', 'Pcb', 'Calculator', 'SelfTest' ) as $nhp_class ) {
	require_once NHP_DIR . 'src/Engine/' . $nhp_class . '.php';
}
require_once NHP_DIR . 'src/Plugin.php';
require_once NHP_DIR . 'src/Admin.php';

add_action( 'plugins_loaded', array( 'NeuroHR\\Payroll\\Plugin', 'init' ) );
