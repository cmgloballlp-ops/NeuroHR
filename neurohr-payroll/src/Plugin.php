<?php
/**
 * WordPress glue: shortcode, REST endpoint, assets and a small settings page.
 */

namespace NeuroHR\Payroll;

use NeuroHR\Payroll\Engine\Calculator;
use NeuroHR\Payroll\Engine\Epf;
use NeuroHR\Payroll\Engine\Rates;
use NeuroHR\Payroll\Engine\Socso;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const OPTION = 'nhp_settings';

	public static function init() {
		// Yearly PCB tables and LINDUNG 24 rates edited in the admin screens.
		Rates::configure( get_option( Admin::OPT_YEARS, null ), get_option( Admin::OPT_L24, null ) );

		add_shortcode( 'nh_payroll_calculator', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'nhm_level_deleted', array( __CLASS__, 'level_deleted' ), 10, 2 );
		if ( is_admin() ) {
			Admin::init();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'brand'        => '#f26522',
				'payslip_note' => 'This payslip is computer generated and requires no signature.',
				'disclaimer'   => 'Estimates based on the official KWSP, PERKESO and LHDN schedules. Please verify before making payment.',
				'upgrade_url'  => '',
				'lite_ids'     => '6373',
				'pro_ids'      => '6376',
				'corp_ids'     => '6379, 6382',
				'pro_level'    => 3, // NeuroHR Members level ID that unlocks the payslip extras (and above).
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Membership level                                                    */
	/* ------------------------------------------------------------------ */

	private static function ids( $text ) {
		if ( is_array( $text ) ) {
			$text = implode( ',', array_map( 'strval', $text ) );
		}
		return array_values( array_unique( array_filter( array_map( 'intval', preg_split( '/[^0-9]+/', (string) $text ) ) ) ) );
	}

	/** True when NeuroHR Members supplies the levels. */
	public static function members_active() {
		return function_exists( 'nhm_levels' ) && function_exists( 'nhm_user_level' );
	}

	/**
	 * Level key of a user: staff | guest | the NeuroHR Members level slug (free, lite, pro,
	 * corporate, consulting, …). Without NeuroHR Members: corporate | pro | lite | free from
	 * MemberPress active memberships.
	 */
	public static function level( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 'guest';
		}
		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return 'staff';
		}
		if ( self::members_active() ) {
			$levels = nhm_levels();
			$id     = (int) nhm_user_level( $user_id );
			$level  = isset( $levels[ $id ] ) ? $levels[ $id ]['slug'] : 'free';
			return apply_filters( 'nh_payroll_level', $level, $user_id );
		}
		$active = array();
		if ( class_exists( '\MeprUser' ) ) {
			$mepr   = new \MeprUser( $user_id );
			$active = array_map( 'intval', (array) $mepr->active_product_subscriptions( 'ids' ) );
		}
		$s     = self::settings();
		$level = 'free';
		foreach ( array( 'lite' => 'lite_ids', 'pro' => 'pro_ids', 'corporate' => 'corp_ids' ) as $name => $key ) {
			if ( array_intersect( $active, self::ids( $s[ $key ] ) ) ) {
				$level = $name; // later entries are higher levels
			}
		}
		return apply_filters( 'nh_payroll_level', $level, $user_id );
	}

	/** Pro features on the payslip: logo, company address, pay date. */
	public static function is_pro( $user_id = 0 ) {
		if ( self::members_active() ) {
			$user_id = $user_id ? $user_id : get_current_user_id();
			$need    = (int) self::settings()['pro_level'];
			$is_pro  = $user_id && ( user_can( $user_id, 'edit_others_posts' ) || nhm_user_can_level( $need ? $need : 'pro', $user_id ) );
		} else {
			$is_pro = in_array( self::level( $user_id ), array( 'staff', 'corporate', 'pro' ), true );
		}
		return (bool) apply_filters( 'nh_payroll_is_pro', $is_pro, $user_id );
	}

	/** Where the Upgrade button goes: the setting, else the NeuroHR Members upgrade link of the Pro level. */
	public static function upgrade_url() {
		$s = self::settings();
		if ( $s['upgrade_url'] ) {
			return $s['upgrade_url'];
		}
		if ( self::members_active() && function_exists( 'nhm_upgrade_url' ) ) {
			return nhm_upgrade_url( (int) $s['pro_level'] ? (int) $s['pro_level'] : null );
		}
		return '';
	}

	/** NeuroHR Members deleted a level: the payslip extras move to the next level up. */
	public static function level_deleted( $deleted, $content_to ) {
		$s = get_option( self::OPTION, array() );
		if ( is_array( $s ) && isset( $s['pro_level'] ) && (int) $s['pro_level'] === (int) $deleted ) {
			$s['pro_level'] = (int) $content_to;
			update_option( self::OPTION, $s );
		} elseif ( 3 === (int) $deleted && ( ! is_array( $s ) || ! isset( $s['pro_level'] ) ) ) {
			$s              = is_array( $s ) ? $s : array();
			$s['pro_level'] = (int) $content_to;
			update_option( self::OPTION, $s );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Usage statistics                                                    */
	/* ------------------------------------------------------------------ */

	const OPT_STATS = 'nhp_stats';

	/** Count an event for this month: 'use' (once per member per month), 'print', 'next'. */
	public static function track( $event ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! in_array( $event, array( 'use', 'print', 'next' ), true ) ) {
			return;
		}
		$month = current_time( 'Y-m' );
		if ( 'use' === $event ) {
			if ( get_user_meta( $user_id, 'nhp_used_month', true ) === $month ) {
				return;
			}
			update_user_meta( $user_id, 'nhp_used_month', $month );
		}
		$level = self::level( $user_id );
		$stats = get_option( self::OPT_STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();
		if ( ! isset( $stats[ $month ][ $event ][ $level ] ) ) {
			$stats[ $month ][ $event ][ $level ] = 0;
		}
		$stats[ $month ][ $event ][ $level ]++;
		krsort( $stats );
		update_option( self::OPT_STATS, array_slice( $stats, 0, 24, true ), false );
	}

	public static function rest_track( \WP_REST_Request $request ) {
		self::track( (string) $request->get_param( 'event' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function sanitize_settings( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$brand = isset( $in['brand'] ) ? sanitize_hex_color( $in['brand'] ) : '';
		return array(
			'brand'        => $brand ? $brand : '#f26522',
			'payslip_note' => isset( $in['payslip_note'] ) ? sanitize_text_field( $in['payslip_note'] ) : '',
			'disclaimer'   => isset( $in['disclaimer'] ) ? sanitize_text_field( $in['disclaimer'] ) : '',
			'upgrade_url'  => isset( $in['upgrade_url'] ) ? esc_url_raw( $in['upgrade_url'] ) : '',
			'lite_ids'     => isset( $in['lite_ids'] ) ? implode( ', ', self::ids( $in['lite_ids'] ) ) : '',
			'pro_ids'      => isset( $in['pro_ids'] ) ? implode( ', ', self::ids( $in['pro_ids'] ) ) : '',
			'corp_ids'     => isset( $in['corp_ids'] ) ? implode( ', ', self::ids( $in['corp_ids'] ) ) : '',
			'pro_level'    => isset( $in['pro_level'] ) ? absint( $in['pro_level'] ) : (int) self::settings()['pro_level'],
		);
	}

	public static function rest() {
		register_rest_route(
			'neurohr-payroll/v1',
			'/calculate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'callback'            => array( __CLASS__, 'rest_calculate' ),
			)
		);
		register_rest_route(
			'neurohr-payroll/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'callback'            => array( __CLASS__, 'rest_track' ),
			)
		);
	}

	public static function can_use() {
		return (bool) apply_filters( 'nh_payroll_can_use', is_user_logged_in() );
	}

	public static function rest_calculate( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$result = Calculator::calculate( is_array( $params ) ? $params : array() );
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		if ( ! wp_style_is( 'nhp-calculator', 'registered' ) ) {
			wp_register_style( 'nhp-calculator', NHP_URL . 'assets/calculator.css', array(), NHP_VERSION );
		}
		if ( ! wp_script_is( 'nhp-calculator', 'registered' ) ) {
			wp_register_script( 'nhp-calculator', NHP_URL . 'assets/calculator.js', array(), NHP_VERSION, true );
		}
	}

	private static function login_url() {
		$back = get_permalink();
		$url  = wp_login_url( $back ? $back : home_url( '/' ) );
		if ( function_exists( 'nhm_login_url' ) ) {
			$url = nhm_login_url( $back ? $back : home_url( '/' ) );
		} elseif ( class_exists( '\MeprUtils' ) && is_callable( array( '\MeprUtils', 'login_url' ) ) ) {
			$mepr = \MeprUtils::login_url();
			if ( $mepr ) {
				$url = add_query_arg( 'redirect_to', rawurlencode( $back ? $back : home_url( '/' ) ), $mepr );
			}
		}
		return apply_filters( 'nh_payroll_login_url', $url );
	}

	public static function shortcode( $atts = array() ) {
		$atts       = shortcode_atts( array( 'title' => 'yes' ), $atts, 'nh_payroll_calculator' );
		$show_title = ! in_array( strtolower( (string) $atts['title'] ), array( 'no', '0', 'false' ), true );
		$s          = self::settings();
		// Block themes render shortcodes before wp_enqueue_scripts runs.
		self::register_assets();
		wp_enqueue_style( 'nhp-calculator' );
		wp_add_inline_style( 'nhp-calculator', '.nhp,.nhp-payslip{--nhp-brand:' . esc_attr( $s['brand'] ) . '}' );

		if ( ! self::can_use() ) {
			return '<div class="nhp nhp-locked"><h2>Simple Payroll</h2><p>Log in to calculate EPF, SOCSO, EIS and PCB and print payslips.</p><a class="nhp-btn" href="' . esc_url( self::login_url() ) . '">Log in</a></div>';
		}

		$current     = Rates::normalisePeriod( current_time( 'Y-m' ) );
		$is_pro      = self::is_pro();
		$upgrade_url = self::upgrade_url();
		wp_enqueue_script( 'nhp-calculator' );
		wp_localize_script(
			'nhp-calculator',
			'NHP',
			array(
				'endpoint' => esc_url_raw( rest_url( 'neurohr-payroll/v1/calculate' ) ),
				'track'    => esc_url_raw( rest_url( 'neurohr-payroll/v1/track' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'note'     => $s['payslip_note'],
				'site'     => get_bloginfo( 'name' ),
				'pro'      => $is_pro,
				'store'    => 'nhp:' . get_current_user_id(),
			)
		);

		ob_start();
		include NHP_DIR . 'templates/calculator.php';
		return ob_get_clean();
	}

	/* Helpers used by the template */

	public static function rate_options( array $bps, $autoLabel ) {
		$html = '<option value="auto">' . esc_html( $autoLabel ) . '</option><option value="none">No EPF contribution</option>';
		foreach ( $bps as $bp ) {
			$v     = rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' );
			$html .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $v ) . '%</option>';
		}
		return $html;
	}

	public static function employee_rates() {
		return Epf::employeeRateOptions();
	}

	public static function employer_rates() {
		return Epf::employerRateOptions();
	}

	public static function socso_categories() {
		return Socso::categories();
	}

}
