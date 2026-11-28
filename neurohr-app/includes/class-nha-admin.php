<?php
/**
 * Members → Phone App: settings of NeuroHR App.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHA_Admin {

	const PAGE = 'nhm-app';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_nha_save', array( __CLASS__, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NHA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_submenu_page( 'nhm-dashboard', __( 'Phone App', 'neurohr-app' ), __( 'Phone App', 'neurohr-app' ), NHM_CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'neurohr-app' ) . '</a>' );
		return $links;
	}

	private static function toggle( $key, $title, $text = '' ) {
		echo '<label class="nhm-toggle"><input type="hidden" name="nha[' . esc_attr( $key ) . ']" value="0"><input type="checkbox" name="nha[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) NHA_Settings::get( $key ), true, false ) . '> <span><strong>' . esc_html( $title ) . '</strong>' . ( $text ? '<br>' . wp_kses_post( $text ) : '' ) . '</span></label>';
	}

	private static function text( $key, $label, $help = '', $extra = '' ) {
		echo '<p class="nhm-field"><label for="nha-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<span class="nhm-color-row"><input type="text" id="nha-' . esc_attr( $key ) . '" name="nha[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) NHA_Settings::get( $key ) ) . '">' . $extra . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed button markup.
		if ( $help ) {
			echo '<span class="description">' . wp_kses_post( $help ) . '</span>';
		}
		echo '</p>';
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Phone App', 'neurohr-app' ) );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'neurohr-app' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nha_save">';
		wp_nonce_field( 'nha_save' );

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-smartphone"></span>' . esc_html__( 'Phone Layout', 'neurohr-app' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'On phones the member area looks like an app: a floating bottom bar (Home · Hub · Search · Templates · More), a full-screen search, the More sheet and a small header with a back button on Knowledge Hub pages. Computers keep the sidebar.', 'neurohr-app' ) . '</p>';
		self::toggle( 'app_on', __( 'Use the app layout on phones', 'neurohr-app' ) );
		self::toggle( 'app_home', __( 'App home on the Dashboard page', 'neurohr-app' ), __( 'Phones see the greeting, the plan card with days left and "Latest for You" at the top of the page set in Members → Settings → Pages ("Logged-in members who open the home page go to"). The greeting texts are in Members → Settings → Member Area.', 'neurohr-app' ) );
		echo '<p class="description">' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'Shortcuts in the More sheet: <a href="%s">Appearance → Menus</a>, location "Phone: More shortcuts" (6 look best, each with an icon).', 'neurohr-app' ), esc_url( admin_url( 'nav-menus.php' ) ) ) ) . '</p>';
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-performance"></span>' . esc_html__( 'Speed', 'neurohr-app' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Members-only pages cannot be cached, so each page is made fresh. These make changing pages feel quicker.', 'neurohr-app' ) . '</p>';
		self::toggle( 'feedback', __( 'Answer every tap at once', 'neurohr-app' ), __( 'The tab changes colour, a bar runs along the top and grey shapes show where the page will appear.', 'neurohr-app' ) );
		self::toggle( 'prefetch', __( 'Start loading when a link is touched', 'neurohr-app' ), __( 'Chrome and Android phones. Saves about 0.2 to 1 second per page. iPhone (Safari) ignores it.', 'neurohr-app' ) );
		self::toggle( 'ahead', __( 'Load the bottom-bar pages in the background', 'neurohr-app' ), __( 'After a page has opened, Home, Hub and Templates are loaded quietly, so the tabs open almost at once. Each page view then asks the server for up to 2 more pages: turn this off if the site gets slow when many members are online.', 'neurohr-app' ) );
		echo '<p class="description">' . esc_html__( 'Never loaded early: log out, downloads, videos, payment and form links, admin pages. Loading early does not count as a view or a download.', 'neurohr-app' ) . '</p>';
		echo '</section>';

		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-download"></span>' . esc_html__( 'Install as an App (PWA)', 'neurohr-app' ) . '</h2>';
		echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Members can add NeuroHR to their home screen (Android: "Install the NeuroHR App" in the More sheet; iPhone: Share → Add to Home Screen). Members-only pages are never stored on the phone; only the look is kept, plus a friendly page when there is no internet.', 'neurohr-app' ) . '</p>';
		self::toggle( 'pwa_on', __( 'Let members install the app', 'neurohr-app' ) );
		echo '<div class="nhm-fields">';
		self::text( 'pwa_name', __( 'App Name', 'neurohr-app' ) );
		self::text( 'pwa_short', __( 'Name Under the Icon', 'neurohr-app' ), __( 'Short, about 12 letters.', 'neurohr-app' ) );
		self::text( 'pwa_icon', __( 'App Icon', 'neurohr-app' ), __( 'Square PNG, 512 × 512 px or bigger. Empty = the Site Icon (Appearance → Customize → Site Identity).', 'neurohr-app' ), ' <button type="button" class="button" data-nhm-media="nha-pwa_icon">' . esc_html__( 'Choose', 'neurohr-app' ) . '</button>' );
		self::text( 'pwa_color', __( 'Top Bar Colour', 'neurohr-app' ) );
		self::text( 'pwa_bg', __( 'Opening Screen Colour', 'neurohr-app' ) );
		echo '</div>';
		if ( NHA_Settings::get( 'pwa_on' ) && ! NHA_PWA::icon_url( 512 ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add an app icon (or a Site Icon) so phones can install the app.', 'neurohr-app' ) . '</p></div>';
		}
		echo '</section>';

		submit_button();
		echo '</form>';
		echo '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-backup"></span>' . esc_html__( 'Version History', 'neurohr-app' ) . '</h2>';
		foreach ( (array) include NHA_DIR . 'includes/changelog.php' as $ver => $entry ) {
			echo '<h3>' . esc_html( $ver . ' · ' . $entry['date'] ) . '</h3><ul class="ul-disc">';
			foreach ( $entry['items'] as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';
		echo '</div>';
	}

	public static function save() {
		if ( ! current_user_can( NHM_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'neurohr-app' ) );
		}
		check_admin_referer( 'nha_save' );
		$in  = isset( $_POST['nha'] ) && is_array( $_POST['nha'] ) ? wp_unslash( $_POST['nha'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		NHA_Settings::update( self::clean( $in ) );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url() ) );
		exit;
	}

	/** Only known keys, each cleaned for its kind. */
	public static function clean( array $in ) {
		$out = array();
		foreach ( NHA_Settings::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$v = $in[ $key ];
			if ( is_int( $default ) ) {
				$out[ $key ] = $v ? 1 : 0;
			} elseif ( 'pwa_icon' === $key ) {
				$url         = trim( (string) $v );
				$out[ $key ] = preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url ) : '';
			} elseif ( in_array( $key, array( 'pwa_color', 'pwa_bg' ), true ) ) {
				$c           = sanitize_hex_color( trim( (string) $v ) );
				$out[ $key ] = $c ? $c : $default;
			} else {
				$t           = sanitize_text_field( (string) $v );
				$out[ $key ] = '' !== $t ? $t : $default;
			}
		}
		return $out;
	}
}
