<?php
/**
 * Plugin Name:       NeuroHR Members
 * Description:       Membership levels with billing terms, company accounts with seats, invoices (PDF), expiry and reminders, login / registration / account pages by shortcode, page protection, a member-area layout, menu and Elementor visibility, and a hidden WordPress login. Replaces MemberPress for NeuroHR and supplies the level to the Knowledge Hub and Simple Payroll.
 * Version:           0.4.4
 * Author:            NeuroHR
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       neurohr-members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NHM_VERSION', '0.4.4' );
define( 'NHM_API_VERSION', 1 );
define( 'NHM_FILE', __FILE__ );
define( 'NHM_DIR', plugin_dir_path( __FILE__ ) );
define( 'NHM_URL', plugin_dir_url( __FILE__ ) );

/** Capability that may manage members, companies, levels and settings. */
define( 'NHM_CAP', 'list_users' );

require_once NHM_DIR . 'includes/class-nhm-settings.php';
require_once NHM_DIR . 'includes/class-nhm-db.php';
require_once NHM_DIR . 'includes/class-nhm-levels.php';
require_once NHM_DIR . 'includes/class-nhm-log.php';
require_once NHM_DIR . 'includes/class-nhm-fields.php';
require_once NHM_DIR . 'includes/class-nhm-members.php';
require_once NHM_DIR . 'includes/class-nhm-companies.php';
require_once NHM_DIR . 'includes/class-nhm-emails.php';
require_once NHM_DIR . 'includes/class-nhm-invoices.php';
require_once NHM_DIR . 'includes/class-nhm-pdf.php';
require_once NHM_DIR . 'includes/class-nhm-privacy.php';
require_once NHM_DIR . 'includes/class-nhm-cron.php';
require_once NHM_DIR . 'includes/class-nhm-mailerlite.php';
require_once NHM_DIR . 'includes/class-nhm-forms.php';
require_once NHM_DIR . 'includes/class-nhm-auth.php';
require_once NHM_DIR . 'includes/class-nhm-block.php';
require_once NHM_DIR . 'includes/class-nhm-hidewp.php';
require_once NHM_DIR . 'includes/class-nhm-avatar.php';
require_once NHM_DIR . 'includes/class-nhm-visibility.php';
require_once NHM_DIR . 'includes/class-nhm-shell.php';
require_once NHM_DIR . 'includes/class-nhm-stripe.php';
require_once NHM_DIR . 'includes/class-nhm-renew.php';
require_once NHM_DIR . 'includes/class-nhm-refunds.php';
require_once NHM_DIR . 'includes/class-nhm-payments.php';
require_once NHM_DIR . 'includes/class-nhm-mailer.php';
require_once NHM_DIR . 'includes/class-nhm-account.php';
require_once NHM_DIR . 'includes/class-nhm-shortcodes.php';
require_once NHM_DIR . 'includes/class-nhm-protect.php';
require_once NHM_DIR . 'includes/api.php';

NHM_DB::init();
NHM_Members::init();
NHM_Cron::init();
NHM_Forms::init();
NHM_Auth::init();
NHM_Block::init();
NHM_HideWP::init();
NHM_Avatar::init();
NHM_Visibility::init();
NHM_Shell::init();
NHM_Stripe::init();
NHM_Renew::init();
NHM_Refunds::init();
NHM_Payments::init();
NHM_Mailer::init();
NHM_Account::init();
NHM_Shortcodes::init();
NHM_Protect::init();
NHM_PDF::init();
NHM_Privacy::init();
add_action( 'nhm_company_joined', array( 'NHM_Emails', 'joined' ), 10, 2 );

if ( is_admin() ) {
	require_once NHM_DIR . 'admin/class-nhm-admin.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-levels.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-members.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-companies.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-settings.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-invoices.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-payments.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-dashboard.php';
	require_once NHM_DIR . 'admin/class-nhm-admin-tools.php';
	NHM_Admin::init();
	NHM_Admin_Levels::init();
	NHM_Admin_Members::init();
	NHM_Admin_Companies::init();
	NHM_Admin_Settings::init();
	NHM_Admin_Invoices::init();
	NHM_Admin_Payments::init();
	NHM_Admin_Dashboard::init();
	NHM_Admin_Tools::init();
}

register_activation_hook( __FILE__, array( 'NHM_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NHM_Cron', 'deactivate' ) );
