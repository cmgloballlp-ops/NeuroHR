<?php
/**
 * The member area: logged-in people see every page inside one layout
 * (sidebar on the left, top bar with search and their name, the page in the middle,
 * a small footer). Visitors who are not logged in keep the normal theme (Blocksy).
 *
 * Kept out of the layout:
 *  - the Elementor editor and its preview
 *  - pages with the "Elementor Canvas" template
 *  - pages where "Don't use the member layout" is ticked (box in the editor)
 *  - wp-admin, feeds, embeds, REST, the customizer
 *
 * Templates of other plugins (e.g. the Knowledge Hub) are not replaced; they call
 * nhm_shell_document_open() / nhm_shell_document_close() themselves.
 *
 * Note for caching (LiteSpeed): pages for logged-in people are not cached, visitors get
 * the normal cached site, so the two layouts never mix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Shell {

	const META_OFF = '_nhm_no_shell';

	private static $active = null;
	private static $opened = false;
	private static $tag    = 'main';

	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'menus' ), 20 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_theme_css' ), 9999 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function menus() {
		register_nav_menus(
			array(
				'nhm_sidebar'         => __( 'Member area: main menu', 'neurohr-members' ),
				'nhm_sidebar_account' => __( 'Member area: account menu', 'neurohr-members' ),
				'nhm_footer'          => __( 'Member area: footer links', 'neurohr-members' ),
			)
		);
	}

	/* ---------- When ---------- */

	/** The Elementor editor, its preview frame, template previews and Elementor's own requests. */
	public static function elementor_editing() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || isset( $_GET['elementor_preview'] ) || ( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'elementor', 'elementor_ajax' ), true ) ) ) {
			return true;
		}
		if ( function_exists( 'is_preview' ) && did_action( 'wp' ) && is_preview() ) {
			return true;
		}
		// phpcs:enable
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$p = \Elementor\Plugin::$instance;
			if ( ( isset( $p->preview ) && $p->preview->is_preview_mode() ) || ( isset( $p->editor ) && $p->editor->is_edit_mode() ) ) {
				return true;
			}
		}
		return false;
	}

	/** Should this request use the member layout? */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$on = (bool) NHM_Settings::get( 'shell_enabled' ) && is_user_logged_in() && ! is_admin()
			&& ! is_feed() && ! is_embed() && ! is_robots() && ! is_trackback() && ! is_customize_preview()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_doing_ajax() && ! self::elementor_editing();
		if ( $on && is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( get_post_meta( $id, self::META_OFF, true ) ) {
				$on = false;
			}
			$tpl = (string) get_page_template_slug( $id );
			if ( in_array( $tpl, array( 'elementor_canvas' ), true ) ) {
				$on = false;
			}
		}
		self::$active = (bool) apply_filters( 'nhm_shell_active', $on );
		return self::$active;
	}

	/** Only after the query has run (template_redirect or later). */
	public static function reset() {
		self::$active = null;
	}

	/** Replace theme templates; leave templates of plugins alone (they open the shell themselves). */
	public static function template( $template ) {
		if ( ! self::active() ) {
			return $template;
		}
		$theme_dirs = array( wp_normalize_path( get_stylesheet_directory() ), wp_normalize_path( get_template_directory() ), wp_normalize_path( ABSPATH . WPINC ) );
		$path       = wp_normalize_path( (string) $template );
		$is_theme   = '' === $path;
		foreach ( $theme_dirs as $dir ) {
			if ( 0 === strpos( $path, $dir ) ) {
				$is_theme = true;
			}
		}
		// Elementor's "Full width" template is fine inside the shell; "Canvas" never gets here.
		if ( false !== strpos( $path, '/elementor/modules/page-templates/templates/header-footer.php' ) ) {
			$is_theme = true;
		}
		if ( ! $is_theme ) {
			return $template;
		}
		$own = locate_template( 'neurohr-members/shell.php' );
		return $own ? $own : NHM_DIR . 'templates/shell.php';
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'nhm-shell-on';
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		if ( 'jakarta' === NHM_Settings::get( 'shell_font' ) ) {
			wp_enqueue_style( 'nhm-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'nhm-shell', NHM_URL . 'assets/shell.css', array(), NHM_VERSION );
		wp_add_inline_style( 'nhm-shell', ':root{--nhs-brand:' . NHM_Settings::brand() . ';}' );
		wp_enqueue_script( 'nhm-shell', NHM_URL . 'assets/shell.js', array(), NHM_VERSION, true );
		NHM_Forms::enqueue();
	}

	/** Blocksy's own styles would fight the layout; Elementor's and plugins' styles stay. */
	public static function drop_theme_css() {
		if ( ! self::active() || NHM_Settings::get( 'shell_theme_css' ) ) {
			return;
		}
		$wp_styles = wp_styles();
		$theme     = array( get_stylesheet_directory_uri(), get_template_directory_uri() );
		foreach ( (array) $wp_styles->queue as $handle ) {
			$src = isset( $wp_styles->registered[ $handle ] ) ? (string) $wp_styles->registered[ $handle ]->src : '';
			foreach ( $theme as $t ) {
				if ( $src && 0 === strpos( $src, $t ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}
	}

	/* ---------- Editor box ---------- */

	public static function meta_box() {
		foreach ( array( 'page', 'post' ) as $type ) {
			add_meta_box( 'nhm-shell', __( 'Member layout', 'neurohr-members' ), array( __CLASS__, 'render_box' ), $type, 'side', 'low' );
		}
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'nhm_shell_box', '_nhm_shell_nonce' );
		echo '<label><input type="checkbox" name="nhm_no_shell" value="1"' . checked( (bool) get_post_meta( $post->ID, self::META_OFF, true ), true, false ) . '> ' . esc_html__( 'Don\'t use the member layout', 'neurohr-members' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Logged-in people then see this page in the normal site design. Pages with the "Elementor Canvas" template never use it.', 'neurohr-members' ) . '</p>';
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['_nhm_shell_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nhm_shell_nonce'] ) ), 'nhm_shell_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['nhm_no_shell'] ) ) {
			delete_post_meta( $post_id, self::META_OFF );
		} else {
			update_post_meta( $post_id, self::META_OFF, 1 );
		}
	}

	/* ---------- Pieces ---------- */

	public static function icon( $name ) {
		$p = array(
			'home'      => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
			'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
			'book'      => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h13"/>',
			'news'      => '<path d="M4 4h13v16H6a2 2 0 0 1-2-2z"/><path d="M17 8h3v10a2 2 0 0 1-2 2"/><path d="M8 8h5M8 12h5M8 16h3"/>',
			'file'      => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M9 14h6M9 17h4"/>',
			'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
			'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'video'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3z"/>',
			'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
			'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
			'calc'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M8 15h2M12 15h4"/>',
			'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
			'scale'     => '<path d="M12 3v18M5 7h14M8 21h8"/><path d="M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
			'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
			'star'      => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
			'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'chat'      => '<path d="M4 5h16v11H9l-5 4z"/>',
			'help'      => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6"/><path d="M12 17h.01"/>',
			'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
			'logout'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
			'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
			'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
			'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
			'chevron'   => '<path d="M6 9l6 6 6-6"/>',
			'arrow'     => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
			'dot'       => '<circle cx="12" cy="12" r="2.5"/>',
		);
		$inner = isset( $p[ $name ] ) ? $p[ $name ] : $p['dot'];
		return '<svg class="nhs-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	public static function logo() {
		$img  = trim( (string) NHM_Settings::get( 'shell_logo' ) );
		$home = NHM_Settings::url( 'home_redirect', home_url( '/' ) );
		if ( $img ) {
			return '<a class="nhs-logo" href="' . esc_url( $home ) . '"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></a>';
		}
		$text   = (string) NHM_Settings::get( 'shell_logo_text' );
		$text   = '' !== $text ? $text : get_bloginfo( 'name' );
		$accent = (string) NHM_Settings::get( 'shell_logo_accent' );
		if ( '' !== $accent && substr( $text, -strlen( $accent ) ) === $accent ) {
			$html = esc_html( substr( $text, 0, -strlen( $accent ) ) ) . '<span>' . esc_html( $accent ) . '</span>';
		} else {
			$html = esc_html( $text );
		}
		return '<a class="nhs-logo nhs-logo--text" href="' . esc_url( $home ) . '">' . $html . '</a>';
	}

	/** [ action URL, field name ] — the Knowledge Hub search when the hub is active. */
	public static function search_target() {
		$target = array( home_url( '/' ), 's' );
		if ( class_exists( 'NH_Rewrite' ) && class_exists( 'NH_Sections' ) && NH_Sections::get( 'all' ) ) {
			$target = array( NH_Rewrite::url( 'all' ), 'q' );
		}
		return apply_filters( 'nhm_shell_search', $target );
	}

	/** The Knowledge Hub's live search (results while typing) is available. */
	public static function live_search() {
		return class_exists( 'NH_Search' ) && class_exists( 'NH_Sections' ) && NH_Sections::get( 'all' ) && method_exists( 'NH_Search', 'enqueue' );
	}

	public static function search_form( $class = '' ) {
		list( $action, $name ) = self::search_target();
		// phpcs:ignore WordPress.Security.NonceVerification
		$value = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		$label = (string) NHM_Settings::get( 'shell_search' );
		$live  = self::live_search() && 'q' === $name;
		$uid   = 'nhs-live-' . ( '' === $class ? 'top' : 'side' );
		if ( $live ) {
			NH_Search::enqueue();
		}
		return '<form class="nhs-search ' . esc_attr( $class ) . '" role="search" method="get" action="' . esc_url( $action ) . '"' . ( $live ? ' data-nh-live data-section="all" data-topic="0"' : '' ) . '>'
			. self::icon( 'search' )
			. '<input type="search" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $label ) . '" aria-label="' . esc_attr__( 'Search', 'neurohr-members' ) . '"'
			. ( $live ? ' autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . esc_attr( $uid ) . '"' : '' ) . '>'
			. ( $live ? '<div class="nh-live nhs-live" id="' . esc_attr( $uid ) . '" role="listbox" hidden></div>' : '' )
			. '</form>';
	}

	/** "Corporate · BrightPath Sdn Bhd", or just the plan. */
	public static function plan_line( $user_id ) {
		$m    = NHM_Members::membership( $user_id );
		$line = $m['name'];
		if ( $m['company_id'] ) {
			$c = NHM_Companies::get( $m['company_id'] );
			if ( $c && '' !== (string) $c['name'] ) {
				$line .= ' · ' . $c['name'];
			}
		}
		return $line;
	}

	public static function user_menu() {
		$user    = wp_get_current_user();
		$account = nhm_account_url();
		$m       = NHM_Members::membership( $user->ID );
		$h       = '<details class="nhs-user" data-nhs-user><summary aria-label="' . esc_attr__( 'Account menu', 'neurohr-members' ) . '">'
			. NHM_Avatar::img( $user->ID, 40, 'nhs-user__avatar' )
			. '<span class="nhs-user__text"><span class="nhs-user__name">' . esc_html( $user->display_name ) . '</span><span class="nhs-user__plan">' . esc_html( self::plan_line( $user->ID ) ) . '</span></span>'
			. self::icon( 'chevron' ) . '</summary><div class="nhs-user__menu">';
		if ( $account ) {
			$h .= '<a href="' . esc_url( $account ) . '">' . self::icon( 'user' ) . esc_html__( 'My account', 'neurohr-members' ) . '</a>';
			if ( 'owner' === $m['role'] ) {
				$h .= '<a href="' . esc_url( add_query_arg( 'tab', 'company', $account ) ) . '">' . self::icon( 'briefcase' ) . esc_html__( 'My company', 'neurohr-members' ) . '</a>';
			}
			$h .= '<a href="' . esc_url( add_query_arg( 'tab', 'billing', $account ) ) . '">' . self::icon( 'receipt' ) . esc_html__( 'Plan & billing', 'neurohr-members' ) . '</a>';
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$h .= '<a href="' . esc_url( admin_url() ) . '">' . self::icon( 'settings' ) . esc_html__( 'Site admin', 'neurohr-members' ) . '</a>';
		}
		$h .= '<a href="' . esc_url( wp_logout_url() ) . '">' . self::icon( 'logout' ) . esc_html__( 'Log out', 'neurohr-members' ) . '</a>';
		return $h . '</div></details>';
	}

	/** One sidebar menu (a menu location), or the built-in default list. */
	public static function nav( $location, $label ) {
		if ( has_nav_menu( $location ) ) {
			return wp_nav_menu(
				array(
					'theme_location'  => $location,
					'container'       => 'nav',
					'container_class' => 'nhs-nav',
					'container_aria_label' => $label,
					'menu_class'      => 'nhs-nav__list',
					'depth'           => 2,
					'fallback_cb'     => false,
					'echo'            => false,
					'walker'          => self::walker(),
				)
			);
		}
		return self::default_nav( $location, $label );
	}

	private static function walker() {
		require_once NHM_DIR . 'includes/class-nhm-shell-walker.php';
		return new NHM_Shell_Walker();
	}

	/** Address of the first published page that holds a shortcode (cached for a day). */
	public static function page_with( $shortcode ) {
		$key   = 'nhm_page_with_' . md5( $shortcode );
		$found = get_transient( $key );
		if ( false === $found ) {
			global $wpdb;
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'page' AND ( post_content LIKE %s OR ID IN ( SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_elementor_data' AND meta_value LIKE %s ) ) ORDER BY ID LIMIT 1", '%[' . $wpdb->esc_like( $shortcode ) . '%', '%[' . $wpdb->esc_like( $shortcode ) . '%' ) ); // phpcs:ignore WordPress.DB
			set_transient( $key, $found, DAY_IN_SECONDS );
		}
		return $found ? (string) get_permalink( $found ) : '';
	}

	/**
	 * Links the member area offers when no menu is set, and what "Create the menus" puts in:
	 * location => list of [ title, url, icon ].
	 */
	public static function suggested_links() {
		$main   = array();
		$main[] = array( __( 'Dashboard', 'neurohr-members' ), NHM_Settings::url( 'home_redirect', home_url( '/' ) ), 'grid' );
		if ( class_exists( 'NH_Sections' ) && class_exists( 'NH_Rewrite' ) ) {
			$icons = array( 'knowledge' => 'book', 'cases' => 'scale', 'faq' => 'help', 'templates' => 'file', 'videos' => 'video' );
			foreach ( NH_Sections::all() as $key => $sec ) {
				$main[] = array( $sec['label'], NH_Rewrite::url( $key ), isset( $icons[ $key ] ) ? $icons[ $key ] : 'folder' );
			}
		}
		$news = (int) get_option( 'page_for_posts' );
		if ( $news ) {
			$label  = class_exists( 'NH_Blog' ) && NH_Blog::label() ? NH_Blog::label() : get_the_title( $news );
			$main[] = array( $label, get_permalink( $news ), 'news' );
		}
		$payroll = shortcode_exists( 'nh_payroll_calculator' ) ? self::page_with( 'nh_payroll_calculator' ) : '';
		if ( $payroll ) {
			$main[] = array( __( 'Simple Payroll', 'neurohr-members' ), $payroll, 'calc' );
		}
		$account = array();
		if ( nhm_account_url() ) {
			$account[] = array( __( 'My account', 'neurohr-members' ), nhm_account_url(), 'user' );
		}
		$account[] = array( __( 'Log out', 'neurohr-members' ), '#nhm-login', 'logout' );
		return apply_filters( 'nhm_shell_suggested_links', array( 'nhm_sidebar' => $main, 'nhm_sidebar_account' => $account ) );
	}

	private static function default_nav( $location, $label ) {
		$all = self::suggested_links();
		if ( empty( $all[ $location ] ) ) {
			return '';
		}
		$current = untrailingslashit( strtok( NHM_Forms::current_url(), '?' ) );
		$h       = '<nav class="nhs-nav" aria-label="' . esc_attr( $label ) . '"><ul class="nhs-nav__list">';
		foreach ( $all[ $location ] as $l ) {
			$url = '#nhm-login' === $l[1] ? wp_logout_url() : $l[1];
			$on  = '#nhm-login' !== $l[1] && untrailingslashit( strtok( $url, '?' ) ) === $current;
			$h  .= '<li class="menu-item' . ( $on ? ' current-menu-item' : '' ) . '"><a href="' . esc_url( $url ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . self::icon( $l[2] ) . '<span>' . esc_html( $l[0] ) . '</span></a></li>';
		}
		return $h . '</ul></nav>';
	}

	/**
	 * Settings → Member area → "Create the member menus": real WordPress menus with the
	 * suggested links and icons, put in the member-area locations. You edit them in Appearance → Menus.
	 *
	 * @return int Number of menus made.
	 */
	public static function create_menus() {
		$names = array( 'nhm_sidebar' => __( 'Member area — main', 'neurohr-members' ), 'nhm_sidebar_account' => __( 'Member area — account', 'neurohr-members' ) );
		$locs  = get_theme_mod( 'nav_menu_locations' );
		$locs  = is_array( $locs ) ? $locs : array();
		$made  = 0;
		foreach ( self::suggested_links() as $loc => $links ) {
			if ( ! isset( $names[ $loc ] ) || ! empty( $locs[ $loc ] ) ) {
				continue;
			}
			$name = $names[ $loc ];
			$menu = wp_get_nav_menu_object( $name );
			$id   = $menu ? (int) $menu->term_id : wp_create_nav_menu( $name );
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			if ( ! $menu ) {
				foreach ( $links as $i => $l ) {
					$item = wp_update_nav_menu_item( $id, 0, array( 'menu-item-title' => $l[0], 'menu-item-url' => $l[1], 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1 ) );
					if ( $item && ! is_wp_error( $item ) ) {
						update_post_meta( $item, NHM_Visibility::META_ICON, $l[2] );
					}
				}
			}
			$locs[ $loc ] = $id;
			++$made;
		}
		set_theme_mod( 'nav_menu_locations', $locs );
		return $made;
	}

	public static function promo() {
		if ( ! NHM_Settings::get( 'shell_promo_on' ) ) {
			return '';
		}
		$title = (string) NHM_Settings::get( 'shell_promo_title' );
		$text  = (string) NHM_Settings::get( 'shell_promo_text' );
		$url   = NHM_Settings::url( 'shell_promo_url' );
		if ( '' === $title && '' === $text ) {
			return '';
		}
		$h = '<div class="nhs-promo"><span class="nhs-promo__title">' . esc_html( $title ) . '</span>';
		if ( '' !== $text ) {
			$h .= '<span class="nhs-promo__text">' . esc_html( $text ) . '</span>';
		}
		if ( $url ) {
			$h .= '<a class="nhs-promo__go" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title ? $title : __( 'Learn more', 'neurohr-members' ) ) . '">' . self::icon( 'arrow' ) . '</a>';
		}
		return $h . '</div>';
	}

	public static function footer_html() {
		$text = strtr( (string) NHM_Settings::get( 'shell_footer_text' ), array( '{year}' => wp_date( 'Y' ), '{site}' => get_bloginfo( 'name' ) ) );
		$h    = '<footer class="nhs-footer"><span>' . esc_html( $text ) . '</span>';
		if ( has_nav_menu( 'nhm_footer' ) ) {
			$h .= wp_nav_menu( array( 'theme_location' => 'nhm_footer', 'container' => 'nav', 'container_class' => 'nhs-footer__nav', 'depth' => 1, 'echo' => false, 'fallback_cb' => false ) );
		}
		return $h . '</footer>';
	}

	/* ---------- Open / close ---------- */

	/** Top bar, sidebar and the start of <main>. */
	public static function open( $tag = 'main' ) {
		if ( self::$opened ) {
			return '';
		}
		self::$opened = true;
		self::$tag    = 'div' === $tag ? 'div' : 'main';
		$h  = '<a class="nhs-skip" href="#nhs-main">' . esc_html__( 'Skip to content', 'neurohr-members' ) . '</a>';
		$h .= '<div class="nhs" data-nhs>';
		$h .= '<header class="nhs-top">';
		$h .= '<button type="button" class="nhs-burger" data-nhs-open aria-controls="nhs-side" aria-expanded="false" aria-label="' . esc_attr__( 'Open menu', 'neurohr-members' ) . '">' . self::icon( 'menu' ) . '</button>';
		$h .= self::logo();
		$h .= self::search_form();
		$h .= self::user_menu();
		$h .= '</header>';
		$h .= '<div class="nhs-body">';
		$h .= '<aside class="nhs-side" id="nhs-side" aria-label="' . esc_attr__( 'Member menu', 'neurohr-members' ) . '">';
		$h .= '<div class="nhs-side__head">' . self::logo() . '<button type="button" class="nhs-close" data-nhs-close aria-label="' . esc_attr__( 'Close menu', 'neurohr-members' ) . '">' . self::icon( 'close' ) . '</button></div>';
		$h .= self::search_form( 'nhs-search--side' );
		$h .= self::nav( 'nhm_sidebar', __( 'Main menu', 'neurohr-members' ) );
		$account = self::nav( 'nhm_sidebar_account', __( 'Account menu', 'neurohr-members' ) );
		if ( $account ) {
			$h .= '<div class="nhs-sep" role="presentation"></div>' . $account;
		}
		$h .= self::promo();
		$h .= '</aside><div class="nhs-scrim" data-nhs-close hidden></div>';
		$h .= '<' . self::$tag . ' class="nhs-main" id="nhs-main" tabindex="-1">';
		return apply_filters( 'nhm_shell_open_html', $h );
	}

	public static function close() {
		if ( ! self::$opened ) {
			return '';
		}
		self::$opened = false;
		return apply_filters( 'nhm_shell_close_html', '</' . self::$tag . '></div>' . self::footer_html() . '</div>' );
	}

	/**
	 * Whole document start, for templates of other plugins.
	 *
	 * @param string $tag 'div' when the template has its own <main>.
	 */
	public static function document_open( $tag = 'main' ) {
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
		wp_body_open();
		echo self::open( $tag ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	public static function document_close() {
		echo self::close(); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_footer();
		echo "\n</body>\n</html>\n";
	}

	/** Is the page built with Elementor (then it brings its own title)? */
	public static function built_with_elementor( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}
}

/** For other plugins / themes. */
function nhm_shell_active() {
	return class_exists( 'NHM_Shell' ) && NHM_Shell::active();
}

function nhm_shell_document_open( $tag = 'main' ) {
	NHM_Shell::document_open( $tag );
}

function nhm_shell_document_close() {
	NHM_Shell::document_close();
}
