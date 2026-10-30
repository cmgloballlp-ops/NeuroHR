<?php
/**
 * Who sees a menu item or an Elementor section / container / widget.
 *
 * Rule (same everywhere):
 *   all      everyone (default)
 *   guests   only people who are not logged in
 *   members  only logged-in people (any level)
 *   min      logged in with this level or higher ("Pro and up"); staff always pass
 *   exact    logged in with exactly one of these levels (e.g. only Free → an "Upgrade" link)
 *
 * Menus: Appearance → Menus, open an item → "Who sees this". Children of a hidden item are
 * hidden too. The box "NeuroHR Members" on the left adds the special items:
 *   Log in / Log out · My account · Register · Upgrade · Member name + photo
 *
 * Elementor: Advanced tab of a section, container, column or widget → "NeuroHR: who sees this".
 * In the Elementor editor everything stays visible so you can edit it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Visibility {

	const META_MODE   = '_nhm_vis';
	const META_LEVEL  = '_nhm_vis_level';
	const META_LEVELS = '_nhm_vis_levels';
	const META_ICON   = '_nhm_icon';

	public static function init() {
		// Menus.
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'menu_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'menu_save' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_items' ), 20 );
		add_action( 'admin_head-nav-menus.php', array( __CLASS__, 'menu_meta_box' ) );
		add_action( 'nhm_level_deleted', array( __CLASS__, 'level_deleted' ), 10, 2 );
		add_filter( 'wp_setup_nav_menu_item', array( __CLASS__, 'label_specials' ) );

		// Elementor (only when it is active).
		add_action( 'elementor/init', array( __CLASS__, 'elementor' ) );
	}

	public static function modes() {
		return array(
			'all'     => __( 'Everyone', 'neurohr-members' ),
			'guests'  => __( 'Only visitors who are not logged in', 'neurohr-members' ),
			'members' => __( 'Only logged-in members (any plan)', 'neurohr-members' ),
			'min'     => __( 'Members with this plan or higher', 'neurohr-members' ),
			'exact'   => __( 'Only members with exactly these plans', 'neurohr-members' ),
		);
	}

	/**
	 * @param string    $mode   One of modes().
	 * @param int       $level  For 'min'.
	 * @param int[]     $levels For 'exact'.
	 */
	public static function allowed( $mode, $level = 0, $levels = array(), $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		switch ( $mode ) {
			case 'guests':
				return ! $user_id;
			case 'members':
				return (bool) $user_id;
			case 'min':
				return $user_id && NHM_Members::can( $level ? (int) $level : NHM_Levels::base_id(), $user_id );
			case 'exact':
				return $user_id && in_array( NHM_Members::level( $user_id ), array_map( 'intval', (array) $levels ), true );
			default:
				return true;
		}
	}

	/* ---------- Menus ---------- */

	public static function specials() {
		return array(
			'#nhm-login'    => __( 'Log in / Log out', 'neurohr-members' ),
			'#nhm-account'  => __( 'My account', 'neurohr-members' ),
			'#nhm-register' => __( 'Register', 'neurohr-members' ),
			'#nhm-upgrade'  => __( 'Upgrade', 'neurohr-members' ),
			'#nhm-user'     => __( 'Member name + photo', 'neurohr-members' ),
		);
	}

	/** Icons for the member-area sidebar (Settings → Member area uses these too). */
	public static function icons() {
		return array(
			''          => __( '— none —', 'neurohr-members' ),
			'home'      => __( 'Home', 'neurohr-members' ),
			'grid'      => __( 'Dashboard', 'neurohr-members' ),
			'book'      => __( 'Book / Knowledge', 'neurohr-members' ),
			'news'      => __( 'News', 'neurohr-members' ),
			'file'      => __( 'Document', 'neurohr-members' ),
			'folder'    => __( 'Folder', 'neurohr-members' ),
			'calendar'  => __( 'Calendar / Events', 'neurohr-members' ),
			'video'     => __( 'Video', 'neurohr-members' ),
			'users'     => __( 'People / Team', 'neurohr-members' ),
			'user'      => __( 'Person', 'neurohr-members' ),
			'briefcase' => __( 'Briefcase / Services', 'neurohr-members' ),
			'calc'      => __( 'Calculator / Payroll', 'neurohr-members' ),
			'chart'     => __( 'Chart', 'neurohr-members' ),
			'scale'     => __( 'Scale / Legal', 'neurohr-members' ),
			'receipt'   => __( 'Invoice', 'neurohr-members' ),
			'star'      => __( 'Star / Upgrade', 'neurohr-members' ),
			'mail'      => __( 'Mail', 'neurohr-members' ),
			'chat'      => __( 'Chat / Support', 'neurohr-members' ),
			'help'      => __( 'Help', 'neurohr-members' ),
			'settings'  => __( 'Settings', 'neurohr-members' ),
			'logout'    => __( 'Log out', 'neurohr-members' ),
		);
	}

	public static function menu_fields( $item_id, $item ) {
		$mode   = (string) get_post_meta( $item_id, self::META_MODE, true );
		$mode   = $mode ? $mode : 'all';
		$level  = (int) get_post_meta( $item_id, self::META_LEVEL, true );
		$levels = array_map( 'intval', (array) get_post_meta( $item_id, self::META_LEVELS, true ) );
		$icon   = (string) get_post_meta( $item_id, self::META_ICON, true );
		$id     = (int) $item_id;
		wp_nonce_field( 'nhm_menu_vis', '_nhm_menu_nonce', false );
		echo '<div class="nhm-menu-vis description-wide" style="margin:8px 0;padding:10px;border:1px solid #f0d9c6;border-radius:6px;background:#fffaf5">';
		echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'Who sees this (NeuroHR)', 'neurohr-members' ) . '</strong></p>';
		echo '<select name="nhm_vis[' . $id . ']" class="widefat" onchange="var b=this.parentNode;b.querySelector(\'.nhm-vis-min\').hidden=this.value!==\'min\';b.querySelector(\'.nhm-vis-exact\').hidden=this.value!==\'exact\';">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $id is an integer.
		foreach ( self::modes() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $mode, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="nhm-vis-min"' . ( 'min' === $mode ? '' : ' hidden' ) . ' style="margin:6px 0 0"><select name="nhm_vis_level[' . $id . ']" class="widefat">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( NHM_Levels::all() as $l ) {
			/* translators: %s: level name */
			echo '<option value="' . (int) $l['id'] . '"' . selected( $level, (int) $l['id'], false ) . '>' . esc_html( sprintf( __( '%s and higher', 'neurohr-members' ), $l['name'] ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="nhm-vis-exact"' . ( 'exact' === $mode ? '' : ' hidden' ) . ' style="margin:6px 0 0">';
		foreach ( NHM_Levels::all() as $l ) {
			echo '<label style="display:inline-block;margin-right:10px"><input type="checkbox" name="nhm_vis_levels[' . $id . '][]" value="' . (int) $l['id'] . '"' . checked( in_array( (int) $l['id'], $levels, true ), true, false ) . '> ' . esc_html( $l['name'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</p>';
		echo '<p style="margin:8px 0 0"><label>' . esc_html__( 'Icon in the member-area sidebar', 'neurohr-members' ) . '<br><select name="nhm_icon[' . $id . ']" class="widefat">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( self::icons() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $icon, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		if ( isset( $item->url ) && isset( self::specials()[ $item->url ] ) ) {
			echo '<p class="description" style="margin:8px 0 0">' . esc_html( self::special_help( $item->url ) ) . '</p>';
		}
		echo '</div>';
	}

	private static function special_help( $url ) {
		switch ( $url ) {
			case '#nhm-login':
				return __( 'Shows "Log in" to visitors and "Log out" to members. The label above is not used.', 'neurohr-members' );
			case '#nhm-account':
				return __( 'Goes to My account. Only members see it.', 'neurohr-members' );
			case '#nhm-register':
				return __( 'Goes to the registration page. Only visitors see it.', 'neurohr-members' );
			case '#nhm-upgrade':
				return __( 'Goes to the next plan up (or the pricing page). Hidden for members on the highest plan and for visitors.', 'neurohr-members' );
			default:
				return __( 'Shows the member\'s photo and name, links to My account. Only members see it. Sub-items work as a drop-down.', 'neurohr-members' );
		}
	}

	public static function menu_save( $menu_id, $item_id ) {
		if ( ! isset( $_POST['_nhm_menu_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nhm_menu_nonce'] ) ), 'nhm_menu_vis' ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$mode = isset( $_POST['nhm_vis'][ $item_id ] ) ? sanitize_key( wp_unslash( $_POST['nhm_vis'][ $item_id ] ) ) : 'all';
		$mode = isset( self::modes()[ $mode ] ) ? $mode : 'all';
		if ( 'all' === $mode ) {
			delete_post_meta( $item_id, self::META_MODE );
		} else {
			update_post_meta( $item_id, self::META_MODE, $mode );
		}
		$level = isset( $_POST['nhm_vis_level'][ $item_id ] ) ? absint( $_POST['nhm_vis_level'][ $item_id ] ) : 0;
		update_post_meta( $item_id, self::META_LEVEL, $level );
		$levels = isset( $_POST['nhm_vis_levels'][ $item_id ] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nhm_vis_levels'][ $item_id ] ) ) : array();
		update_post_meta( $item_id, self::META_LEVELS, $levels );
		$icon = isset( $_POST['nhm_icon'][ $item_id ] ) ? sanitize_key( wp_unslash( $_POST['nhm_icon'][ $item_id ] ) ) : '';
		if ( '' === $icon || ! isset( self::icons()[ $icon ] ) ) {
			delete_post_meta( $item_id, self::META_ICON );
		} else {
			update_post_meta( $item_id, self::META_ICON, $icon );
		}
	}

	/** In Appearance → Menus, show a clear type label on the special items. */
	public static function label_specials( $item ) {
		if ( is_admin() && isset( $item->url ) && isset( self::specials()[ $item->url ] ) ) {
			$item->type_label = __( 'NeuroHR', 'neurohr-members' );
		}
		return $item;
	}

	public static function item_allowed( $item ) {
		$mode = (string) get_post_meta( $item->ID, self::META_MODE, true );
		if ( '' === $mode || 'all' === $mode ) {
			return true;
		}
		return self::allowed( $mode, (int) get_post_meta( $item->ID, self::META_LEVEL, true ), (array) get_post_meta( $item->ID, self::META_LEVELS, true ) );
	}

	/** Remove hidden items (and everything under them) and fill in the special items. */
	public static function filter_items( $items ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $items;
		}
		$removed = array();
		$logged  = is_user_logged_in();
		$uid     = get_current_user_id();
		// Parents can come after their children in the array: repeat until nothing changes.
		do {
			$changed = false;
			foreach ( $items as $k => $item ) {
				$parent = (int) $item->menu_item_parent;
				if ( isset( $removed[ $parent ] ) || ! self::item_allowed( $item ) ) {
					$removed[ (int) $item->ID ] = true;
					unset( $items[ $k ] );
					$changed = true;
				}
			}
		} while ( $changed );

		foreach ( $items as $k => $item ) {
			if ( ! isset( self::specials()[ $item->url ] ) ) {
				continue;
			}
			$keep = true;
			switch ( $item->url ) {
				case '#nhm-login':
					if ( $logged ) {
						$item->title = __( 'Log out', 'neurohr-members' );
						$item->url   = wp_logout_url();
					} else {
						$item->title = __( 'Log in', 'neurohr-members' );
						$item->url   = nhm_login_url();
					}
					break;
				case '#nhm-account':
					$keep      = $logged && nhm_account_url();
					$item->url = nhm_account_url();
					break;
				case '#nhm-register':
					$keep      = ! $logged;
					$item->url = nhm_register_url();
					break;
				case '#nhm-upgrade':
					$keep      = $logged && ! NHM_Members::is_staff() && NHM_Members::rank() < NHM_Levels::max_rank();
					$item->url = $keep ? NHM_Levels::upgrade_from( NHM_Members::level() ) : '';
					break;
				case '#nhm-user':
					$keep = $logged;
					if ( $keep ) {
						$user          = wp_get_current_user();
						$item->title   = NHM_Avatar::img( $uid, 28, 'nhm-avatar nhm-menu-avatar' ) . '<span class="nhm-menu-name">' . esc_html( $user->display_name ) . '</span>';
						$item->url     = nhm_account_url() ? nhm_account_url() : '#';
						$item->classes = array_merge( (array) $item->classes, array( 'nhm-menu-user' ) );
					}
					break;
			}
			if ( ! $keep ) {
				$removed[ (int) $item->ID ] = true;
				unset( $items[ $k ] );
			}
		}
		// Children of removed special items.
		do {
			$changed = false;
			foreach ( $items as $k => $item ) {
				if ( isset( $removed[ (int) $item->menu_item_parent ] ) ) {
					$removed[ (int) $item->ID ] = true;
					unset( $items[ $k ] );
					$changed = true;
				}
			}
		} while ( $changed );
		return array_values( $items );
	}

	public static function menu_meta_box() {
		add_meta_box( 'nhm-menu-items', __( 'NeuroHR Members', 'neurohr-members' ), array( __CLASS__, 'render_meta_box' ), 'nav-menus', 'side', 'default' );
	}

	public static function render_meta_box() {
		echo '<div id="posttype-nhm" class="posttypediv"><div id="tabs-panel-nhm" class="tabs-panel tabs-panel-active"><ul id="nhm-checklist" class="categorychecklist form-no-clear">';
		$i = -1;
		foreach ( self::specials() as $url => $label ) {
			echo '<li><label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="menu-item[' . (int) $i . '][menu-item-object-id]" value="' . (int) $i . '"> ' . esc_html( $label ) . '</label>';
			echo '<input type="hidden" class="menu-item-type" name="menu-item[' . (int) $i . '][menu-item-type]" value="custom">';
			echo '<input type="hidden" class="menu-item-title" name="menu-item[' . (int) $i . '][menu-item-title]" value="' . esc_attr( $label ) . '">';
			echo '<input type="hidden" class="menu-item-url" name="menu-item[' . (int) $i . '][menu-item-url]" value="' . esc_attr( $url ) . '">';
			echo '<input type="hidden" class="menu-item-classes" name="menu-item[' . (int) $i . '][menu-item-classes]" value="nhm-menu-' . esc_attr( ltrim( str_replace( '#nhm-', '', $url ), '#' ) ) . '"></li>';
			--$i;
		}
		echo '</ul></div><p class="button-controls"><span class="add-to-menu"><button type="submit" class="button-secondary submit-add-to-menu right" value="' . esc_attr__( 'Add to menu', 'neurohr-members' ) . '" name="add-post-type-menu-item" id="submit-posttype-nhm">' . esc_html__( 'Add to menu', 'neurohr-members' ) . '</button><span class="spinner"></span></span></p></div>';
		echo '<p class="description">' . esc_html__( 'Each item shows itself only to the right people (e.g. Register only to visitors). Open an item after adding it to set more rules.', 'neurohr-members' ) . '</p>';
	}

	/** Menu items that needed a deleted level now need the next level up. */
	public static function level_deleted( $deleted, $content_to ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::META_LEVEL, (string) (int) $deleted ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META_LEVEL, (int) $content_to );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s", self::META_LEVELS ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			$list = array_map( 'intval', (array) get_post_meta( (int) $id, self::META_LEVELS, true ) );
			if ( in_array( (int) $deleted, $list, true ) ) {
				$list = array_values( array_unique( array_merge( array_diff( $list, array( (int) $deleted ) ), array( (int) $content_to ) ) ) );
				update_post_meta( (int) $id, self::META_LEVELS, $list );
			}
		}
	}

	/* ---------- Elementor ---------- */

	public static function elementor() {
		$add = array( __CLASS__, 'elementor_controls' );
		add_action( 'elementor/element/common/_section_style/after_section_end', $add, 10, 2 );
		add_action( 'elementor/element/common-optimized/_section_style/after_section_end', $add, 10, 2 );
		add_action( 'elementor/element/section/section_advanced/after_section_end', $add, 10, 2 );
		add_action( 'elementor/element/column/section_advanced/after_section_end', $add, 10, 2 );
		add_action( 'elementor/element/container/section_layout/after_section_end', $add, 10, 2 );
		foreach ( array( 'widget', 'section', 'column', 'container' ) as $type ) {
			add_filter( 'elementor/frontend/' . $type . '/should_render', array( __CLASS__, 'elementor_should_render' ), 10, 2 );
		}
	}

	/** Never let this box break the Elementor editor: skip on any problem. */
	public static function elementor_controls( $element, $args = array() ) {
		if ( ! class_exists( '\Elementor\Controls_Manager' ) || ! is_object( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}
		if ( method_exists( $element, 'get_controls' ) && $element->get_controls( 'nhm_vis' ) ) {
			return; // Already added to this element type.
		}
		try {
			self::add_elementor_controls( $element );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'NeuroHR Members, Elementor controls: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	private static function add_elementor_controls( $element ) {
		$levels = array();
		foreach ( NHM_Levels::all() as $l ) {
			$levels[ (string) $l['id'] ] = $l['name'];
		}
		$element->start_controls_section(
			'nhm_visibility',
			array(
				'label' => __( 'NeuroHR: who sees this', 'neurohr-members' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			'nhm_vis',
			array(
				'label'   => __( 'Show to', 'neurohr-members' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'all',
				'options' => self::modes(),
			)
		);
		$element->add_control(
			'nhm_vis_level',
			array(
				'label'     => __( 'Plan (or higher)', 'neurohr-members' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'options'   => $levels,
				'default'   => (string) NHM_Levels::base_id(),
				'condition' => array( 'nhm_vis' => 'min' ),
			)
		);
		$element->add_control(
			'nhm_vis_levels',
			array(
				'label'       => __( 'Plans', 'neurohr-members' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $levels,
				'label_block' => true,
				'condition'   => array( 'nhm_vis' => 'exact' ),
			)
		);
		$element->add_control(
			'nhm_vis_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Always visible while you edit. Check the result in a private window or with a test member.', 'neurohr-members' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$element->end_controls_section();
	}

	private static function elementor_editing() {
		return NHM_Shell::elementor_editing();
	}

	public static function elementor_should_render( $should, $element ) {
		if ( ! $should || self::elementor_editing() || ! is_object( $element ) || ! method_exists( $element, 'get_settings' ) ) {
			return $should;
		}
		$s    = (array) $element->get_settings();
		$mode = isset( $s['nhm_vis'] ) ? (string) $s['nhm_vis'] : 'all';
		if ( '' === $mode || 'all' === $mode ) {
			return $should;
		}
		return self::allowed( $mode, isset( $s['nhm_vis_level'] ) ? (int) $s['nhm_vis_level'] : 0, isset( $s['nhm_vis_levels'] ) ? (array) $s['nhm_vis_levels'] : array() );
	}
}
