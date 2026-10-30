<?php
/**
 * Plugin Name:       NeuroHR Knowledge Hub
 * Description:       Lightweight knowledge hub for NeuroHR: Knowledge Hub, Legal Case Insights, FAQ, Templates (protected downloads) and Videos, with level-based access from NeuroHR Members (or MemberPress).
 * Version:           0.8.2
 * Author:            NeuroHR
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       neurohr-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NH_HUB_VERSION', '0.8.2' );
define( 'NH_HUB_FILE', __FILE__ );
define( 'NH_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'NH_HUB_URL', plugin_dir_url( __FILE__ ) );

require_once NH_HUB_DIR . 'includes/class-nh-post-types.php';
require_once NH_HUB_DIR . 'includes/class-nh-sections.php';
require_once NH_HUB_DIR . 'includes/class-nh-upgrade.php';
require_once NH_HUB_DIR . 'includes/class-nh-rewrite.php';
require_once NH_HUB_DIR . 'includes/class-nh-access.php';
require_once NH_HUB_DIR . 'includes/helpers.php';
require_once NH_HUB_DIR . 'includes/class-nh-frontend.php';
require_once NH_HUB_DIR . 'includes/class-nh-related.php';
require_once NH_HUB_DIR . 'includes/class-nh-stats.php';
require_once NH_HUB_DIR . 'includes/class-nh-toc.php';
require_once NH_HUB_DIR . 'includes/class-nh-display.php';
require_once NH_HUB_DIR . 'includes/class-nh-login.php';
require_once NH_HUB_DIR . 'includes/class-nh-search.php';
require_once NH_HUB_DIR . 'includes/class-nh-blog.php';
require_once NH_HUB_DIR . 'includes/class-nh-media.php';
require_once NH_HUB_DIR . 'includes/class-nh-review.php';
require_once NH_HUB_DIR . 'includes/class-nh-print.php';
require_once NH_HUB_DIR . 'includes/class-nh-usage.php';

NH_Post_Types::init();
NH_Rewrite::init();
NH_Access::init();
NH_Frontend::init();
NH_Related::init();
NH_Stats::init();
NH_Display::init();
NH_Login::init();
NH_Search::init();
NH_Blog::init();
NH_Media::init();
NH_Review::init();
NH_Print::init();
NH_Usage::init();

if ( is_admin() ) {
	require_once NH_HUB_DIR . 'includes/class-nh-admin.php';
	require_once NH_HUB_DIR . 'includes/class-nh-dashboard.php';
	require_once NH_HUB_DIR . 'includes/class-nh-import-betterdocs.php';
	require_once NH_HUB_DIR . 'includes/class-nh-topic-meta.php';
	require_once NH_HUB_DIR . 'includes/class-nh-admin-list.php';
	NH_Admin::init();
	NH_Dashboard::init();
	NH_Import_BetterDocs::init();
	NH_Topic_Meta::init();
	NH_Admin_List::init();
}

register_activation_hook( __FILE__, array( 'NH_Post_Types', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
