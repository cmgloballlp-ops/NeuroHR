<?php
/**
 * Admin menu, assets, messages and small shared helpers. History screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NHM_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'memberpress_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_flash' ) );
	}

	/** Messages from saving the Membership section on a user's profile. */
	public static function profile_flash() {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'user-edit', 'profile' ), true ) ) {
			self::render_flash();
		}
	}

	public static function menu() {
		$pending = count( NHM_Invoices::query( array( 'pending' => true, 'limit' => 99 ) ) );
		$bubble  = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		$waiting = NHM_Payments::count_waiting();
		$check   = $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '';
		add_menu_page( __( 'Members', 'neurohr-members' ), __( 'Members', 'neurohr-members' ) . ( $waiting ? $check : $bubble ), NHM_CAP, 'nhm-dashboard', array( 'NHM_Admin_Dashboard', 'page' ), 'dashicons-groups', 70 );
		add_submenu_page( 'nhm-dashboard', __( 'Dashboard', 'neurohr-members' ), __( 'Dashboard', 'neurohr-members' ), NHM_CAP, 'nhm-dashboard', array( 'NHM_Admin_Dashboard', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Members', 'neurohr-members' ), __( 'All members', 'neurohr-members' ), NHM_CAP, 'nhm-members', array( 'NHM_Admin_Members', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Companies', 'neurohr-members' ), __( 'Companies', 'neurohr-members' ), NHM_CAP, 'nhm-companies', array( 'NHM_Admin_Companies', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Invoices', 'neurohr-members' ), __( 'Invoices', 'neurohr-members' ) . $bubble, NHM_CAP, 'nhm-invoices', array( 'NHM_Admin_Invoices', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Payments', 'neurohr-members' ), __( 'Payments', 'neurohr-members' ) . $check, NHM_CAP, 'nhm-payments', array( 'NHM_Admin_Payments', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Levels', 'neurohr-members' ), __( 'Levels', 'neurohr-members' ), NHM_CAP, 'nhm-levels', array( 'NHM_Admin_Levels', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'History', 'neurohr-members' ), __( 'History', 'neurohr-members' ), NHM_CAP, 'nhm-history', array( __CLASS__, 'history_page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Import', 'neurohr-members' ), __( 'Import', 'neurohr-members' ), NHM_CAP, 'nhm-tools', array( 'NHM_Admin_Tools', 'page' ) );
		add_submenu_page( 'nhm-dashboard', __( 'Settings', 'neurohr-members' ), __( 'Settings', 'neurohr-members' ), NHM_CAP, 'nhm-settings', array( 'NHM_Admin_Settings', 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=nhm-settings' ) ) . '">' . esc_html__( 'Settings', 'neurohr-members' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		$screens = array( 'user-edit.php', 'profile.php' );
		$ours    = false !== strpos( (string) $hook, 'nhm-' );
		if ( ! $ours && ! in_array( $hook, $screens, true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'nhm-admin', NHM_URL . 'assets/admin.css', array(), NHM_VERSION );
		wp_enqueue_script( 'nhm-admin', NHM_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), NHM_VERSION, true );
		wp_localize_script(
			'nhm-admin',
			'NHM_ADMIN',
			array(
				'today'  => NHM_Settings::today(),
				'terms'  => self::level_terms(),
				'choose' => __( 'Choose logo', 'neurohr-members' ),
			)
		);
	}

	/** While MemberPress is still active, explain the order of switching over. */
	public static function memberpress_notice() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'nhm-' ) || ! class_exists( 'MeprUser' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . wp_kses_post( __( '<strong>MemberPress is still active.</strong> Set up your levels, companies and pages here first. When the login, registration and account pages work, deactivate MemberPress. The Knowledge Hub and Simple Payroll already read levels from NeuroHR Members.', 'neurohr-members' ) ) . '</p></div>';
	}

	/** Level ID => [amount, term end from today] for the "+1 term" buttons and invoice forms. */
	public static function level_terms() {
		$out = array();
		foreach ( NHM_Levels::all() as $id => $level ) {
			$out[ $id ] = array(
				'amount' => (float) $level['amount'],
				'end'    => NHM_Levels::term_end( $id, NHM_Settings::today() ),
				'line'   => NHM_Invoices::plan_line( $id ),
				'access' => $level['access'],
				'n'      => (int) $level['expire_n'],
				'unit'   => $level['expire_unit'],
				'fixed'  => $level['fixed_date'],
			);
		}
		return $out;
	}

	/* ---------- Messages after admin-post actions ---------- */

	public static function flash( $message, $type = 'success' ) {
		$key  = 'nhm_flash_' . get_current_user_id();
		$list = get_transient( $key );
		$list = is_array( $list ) ? $list : array();
		$list[] = array( 'type' => $type, 'message' => $message );
		set_transient( $key, $list, 120 );
	}

	public static function render_flash() {
		$key  = 'nhm_flash_' . get_current_user_id();
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $list as $item ) {
			$class = 'error' === $item['type'] ? 'notice-error' : ( 'warning' === $item['type'] ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . wp_kses_post( $item['message'] ) . '</p></div>';
		}
	}

	public static function check( $action ) {
		if ( ! current_user_can( NHM_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'neurohr-members' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function back( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------- Shared helpers ---------- */

	public static function level_select( $name, $selected, $args = array() ) {
		$args = array_merge( array( 'any' => '', 'id' => '', 'class' => '', 'plus' => false ), $args );
		$h    = '<select name="' . esc_attr( $name ) . '"' . ( $args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : '' ) . ( $args['class'] ? ' class="' . esc_attr( $args['class'] ) . '"' : '' ) . '>';
		if ( '' !== $args['any'] ) {
			$h .= '<option value="0">' . esc_html( $args['any'] ) . '</option>';
		}
		$max = NHM_Levels::max_rank();
		foreach ( NHM_Levels::all() as $level ) {
			$label = $level['name'] . ( $args['plus'] && $level['rank'] < $max ? ' +' : '' );
			$h    .= '<option value="' . (int) $level['id'] . '"' . selected( (int) $selected, (int) $level['id'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h . '</select>';
	}

	public static function level_badge( $level_id, $muted = false ) {
		$level = NHM_Levels::get( $level_id );
		if ( ! $level ) {
			return '<span class="nhm-badge nhm-badge--muted">?</span>';
		}
		$class = $level['is_base'] || $muted ? 'nhm-badge nhm-badge--muted' : 'nhm-badge nhm-badge--rank' . min( 5, (int) $level['rank'] );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $level['name'] ) . '</span>';
	}

	/** "12 Mar 2027 · 40 days" with a colour class. */
	public static function expiry_html( $expiry, $active_check = true ) {
		if ( ! $expiry ) {
			return '<span class="nhm-muted">' . esc_html__( 'No end date', 'neurohr-members' ) . '</span>';
		}
		$days  = NHM_Members::days_left( $expiry );
		$date  = mysql2date( 'j M Y', $expiry );
		$class = $days < 0 ? 'nhm-exp nhm-exp--ended' : ( $days <= 30 ? 'nhm-exp nhm-exp--soon' : 'nhm-exp' );
		$note  = $days < 0
			/* translators: %d: days */
			? sprintf( _n( 'ended %d day ago', 'ended %d days ago', -$days, 'neurohr-members' ), -$days )
			/* translators: %d: days */
			: sprintf( _n( '%d day left', '%d days left', $days, 'neurohr-members' ), $days );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $date ) . '<small>' . esc_html( $note ) . '</small></span>';
	}

	/**
	 * Date input + "+1 term" (uses the level picked in $level_select_id) + "No end date".
	 */
	public static function expiry_input( $name, $value, $id, $level_select_id = '' ) {
		$h  = '<span class="nhm-expiry-field">';
		$h .= '<input type="date" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '">';
		$h .= ' <button type="button" class="button" data-nhm-plus-term="' . esc_attr( $id ) . '" data-nhm-level="' . esc_attr( $level_select_id ) . '">' . esc_html__( '+1 term', 'neurohr-members' ) . '</button>';
		$h .= ' <button type="button" class="button-link" data-nhm-clear="' . esc_attr( $id ) . '">' . esc_html__( 'No end date', 'neurohr-members' ) . '</button>';
		return $h . '</span>';
	}

	public static function header( $title, $actions = '' ) {
		echo '<div class="nhm-admin__head"><h1>' . esc_html( $title ) . '</h1>' . ( $actions ? '<div class="nhm-admin__actions">' . wp_kses_post( $actions ) . '</div>' : '' ) . '</div>';
		self::render_flash();
	}

	/* ---------- History ---------- */

	public static function history_page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 100;
		$rows   = NHM_Log::query( array( 'limit' => $per + 1, 'offset' => ( $paged - 1 ) * $per ) );
		$more   = count( $rows ) > $per;
		$rows   = array_slice( $rows, 0, $per );
		$last   = get_option( 'nhm_cron_last_run' );
		echo '<div class="wrap nhm-admin">';
		self::header( __( 'History', 'neurohr-members' ) );
		echo '<p class="description">' . esc_html__( 'Every plan change, company change, reminder and registration.', 'neurohr-members' ) . ' ';
		/* translators: %s: date and time */
		echo esc_html( $last ? sprintf( __( 'Daily check last ran: %s.', 'neurohr-members' ), mysql2date( 'j M Y H:i', $last ) ) : __( 'The daily check has not run yet.', 'neurohr-members' ) ) . '</p>';
		echo '<div class="nhm-admin-card">';
		NHM_Log::render( $rows, true );
		echo '<p class="nhm-pager">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">&larr; ' . esc_html__( 'Newer', 'neurohr-members' ) . '</a> ';
		}
		if ( $more ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Older', 'neurohr-members' ) . ' &rarr;</a>';
		}
		echo '</p></div></div>';
	}
}
