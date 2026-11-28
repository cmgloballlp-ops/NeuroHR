<?php
/**
 * NeuroHR App: the member area as a phone app.
 * Bottom bar (Home · Hub · Search · Templates · More), full-screen search, the More sheet
 * (shortcuts from Appearance → Menus → "Phone: More shortcuts"), the app home on the
 * Dashboard page, and an app header on Knowledge Hub pages. Phones only; computers keep
 * the NeuroHR Members sidebar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHA_App {

	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'menus' ), 21 );
		add_filter( 'nhm_shell_open_html', array( __CLASS__, 'open_html' ), 20 );
		add_filter( 'nhm_shell_close_html', array( __CLASS__, 'chrome' ), 5 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 21 );
		add_filter( 'nhm_app_active', array( __CLASS__, 'on' ) );
	}

	public static function menus() {
		register_nav_menus( array( 'nhm_more' => __( 'Phone: More shortcuts', 'neurohr-app' ) ) );
	}

	public static function on() {
		return (bool) NHA_Settings::get( 'app_on' ) && NHM_Shell::active();
	}
	/* ---------- Links ---------- */

	public static function links() {
		$account = (string) nhm_account_url();
		$news    = (int) get_option( 'page_for_posts' );
		$links   = array(
			'home'          => NHM_Settings::url( 'home_redirect', home_url( '/' ) ),
			'hub'           => '',
			'templates'     => '',
			'account'       => $account,
			'billing'       => $account ? add_query_arg( 'tab', 'billing', $account ) : '',
			'company'       => $account ? add_query_arg( 'tab', 'company', $account ) : '',
			'notifications' => $account ? add_query_arg( 'tab', 'notifications', $account ) : '',
			'news'          => $news ? (string) get_permalink( $news ) : '',
			'payroll'       => shortcode_exists( 'nh_payroll_calculator' ) ? NHM_Shell::page_with( 'nh_payroll_calculator' ) : '',
			'logout'        => wp_logout_url(),
		);
		$links = apply_filters( 'nhm_app_links', $links );
		if ( '' === $links['hub'] ) {
			$links['hub'] = $links['news'] ? $links['news'] : $links['home'];
		}
		return $links;
	}

	private static function path( $url ) {
		$p = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return '/' . trim( $p, '/' ) . ( '' === trim( $p, '/' ) ? '' : '/' );
	}

	/** Which bottom-bar tab the current page belongs to. */
	public static function current_tab( array $links ) {
		$req  = self::path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$best = '';
		$len  = 0;
		foreach ( array( 'home', 'hub', 'templates', 'account' ) as $key ) {
			if ( empty( $links[ $key ] ) ) {
				continue;
			}
			$p = self::path( $links[ $key ] );
			if ( '/' !== $p && 0 === strpos( $req, $p ) && strlen( $p ) > $len ) {
				$best = $key;
				$len  = strlen( $p );
			}
		}
		return 'account' === $best ? 'more' : $best;
	}

	/* ---------- Page chrome ---------- */

	public static function body_class( $classes ) {
		if ( self::on() ) {
			$classes[] = 'nhm-app';
			$classes[] = 'nha-app';
			if ( self::is_home_page() ) {
				$classes[] = 'nhm-has-apphome';
			}
			if ( self::context() ) {
				$classes[] = 'nha-has-head';
			}
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::on() ) {
			return;
		}
		wp_enqueue_style( 'nha-app', NHA_URL . 'assets/app.css', array( 'nhm-shell' ), NHA_VERSION );
		wp_enqueue_script( 'nha-app', NHA_URL . 'assets/app.js', array(), NHA_VERSION, true );
		list( $action, $name ) = NHM_Shell::search_target();
		wp_localize_script(
			'nha-app',
			'nhmApp',
			array(
				'live'    => NHM_Shell::live_search() ? array( 'url' => admin_url( 'admin-ajax.php' ), 'action' => 'nh_hub_live' ) : null,
				'search'  => array( 'url' => $action, 'name' => $name ),
				'sw'      => NHA_PWA::on() ? home_url( '/nhm-sw.js' ) : '',
				'ahead'    => NHA_Speed::ahead_urls(),
				'feedback' => (bool) NHA_Settings::get( 'feedback' ),
				'prefetch' => NHA_Speed::on(),
				'never'    => array( '/wp-', '/logout', '/nhm-', '/feed' ),
				'strings' => array(
					'none'    => __( 'No results yet. Press Enter to search everything.', 'neurohr-app' ),
					'loading' => __( 'Searching…', 'neurohr-app' ),
					'all'     => __( 'See All Results', 'neurohr-app' ),
					'more'    => __( 'Swipe the table to see more', 'neurohr-app' ),
				),
			)
		);
	}

	private static function ico( $name ) {
		return NHM_Shell::icon( $name );
	}

	/** Bottom bar (a floating capsule), search screen, More sheet and the loading screen. Phones only, by CSS. */
	public static function chrome( $html ) {
		if ( ! self::on() ) {
			return $html;
		}
		$l   = self::links();
		$cur = self::current_tab( $l );
		$tab = function ( $key, $url, $icon, $label ) use ( $cur ) {
			$on = $key === $cur;
			return '<a class="nha-tab' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '" data-nha-tab' . ( $on ? ' aria-current="page"' : '' ) . '>' . self::ico( $icon ) . '<span>' . esc_html( $label ) . '</span></a>';
		};
		$h  = '<nav class="nha-tabbar" aria-label="' . esc_attr__( 'App menu', 'neurohr-app' ) . '"><div class="nha-tabbar__in">';
		$h .= $tab( 'home', $l['home'], 'home', __( 'Home', 'neurohr-app' ) );
		$h .= $tab( 'hub', $l['hub'], 'book', __( 'Hub', 'neurohr-app' ) );
		$h .= '<button type="button" class="nha-tab" data-nhm-search-open>' . self::ico( 'search' ) . '<span>' . esc_html__( 'Search', 'neurohr-app' ) . '</span></button>';
		$h .= $l['templates'] ? $tab( 'templates', $l['templates'], 'file', __( 'Templates', 'neurohr-app' ) ) : $tab( 'news', $l['news'] ? $l['news'] : $l['home'], 'news', __( 'News', 'neurohr-app' ) );
		$h .= '<button type="button" class="nha-tab' . ( 'more' === $cur ? ' is-on' : '' ) . '" data-nhm-more-open aria-haspopup="dialog">' . self::ico( 'more' ) . '<span>' . esc_html__( 'More', 'neurohr-app' ) . '</span></button>';
		$h .= '</div></nav>';
		$h .= self::search_screen();
		$h .= self::more_sheet( $l );
		$h .= self::loading_html();
		return $html . $h;
	}

	/** Shown the moment a link is tapped, until the next page appears. */
	private static function loading_html() {
		$h  = '<div class="nha-loading" aria-hidden="true"><i class="nha-loading__bar"></i><div class="nha-skel">';
		$h .= '<i class="nha-skel__t"></i><i class="nha-skel__s"></i><span class="nha-skel__chips"><i></i><i></i><i></i></span>';
		$h .= '<i class="nha-skel__c"></i><i class="nha-skel__c"></i><i class="nha-skel__c"></i><i class="nha-skel__c"></i>';
		return $h . '</div></div>';
	}

	/* ---------- App header (Knowledge Hub pages, news) ---------- */

	/**
	 * What the page tells the app header (filter nha_page_context, from the Knowledge Hub):
	 * title, back (address), actions (HTML of buttons, escaped by the provider).
	 */
	public static function context( $fresh = false ) {
		static $ctx = false;
		if ( false === $ctx || $fresh ) {
			$ctx = apply_filters( 'nha_page_context', null );
			$ctx = is_array( $ctx ) ? wp_parse_args( $ctx, array( 'title' => '', 'back' => '', 'actions' => '', 'search' => true ) ) : null;
		}
		return $ctx;
	}

	public static function header_html() {
		$ctx = self::context();
		if ( ! $ctx ) {
			return '';
		}
		$back = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>';
		$kind = isset( $ctx['kind'] ) ? sanitize_html_class( $ctx['kind'] ) : 'page';
		$h    = '<header class="nha-head nha-head--' . $kind . ( $ctx['back'] ? '' : ' nha-head--top' ) . '" data-nha-head>';
		$h   .= $ctx['back'] ? '<a class="nha-head__btn" href="' . esc_url( $ctx['back'] ) . '" data-nha-back aria-label="' . esc_attr__( 'Back', 'neurohr-app' ) . '">' . $back . '</a>' : '';
		$h   .= '<p class="nha-head__title" data-nha-title>' . esc_html( $ctx['title'] ) . '</p>';
		$h   .= '<span class="nha-head__end">' . $ctx['actions']; // Escaped by the provider (Knowledge Hub).
		if ( $ctx['search'] ) {
			$h .= '<button type="button" class="nha-head__btn" data-nhm-search-open aria-label="' . esc_attr__( 'Search', 'neurohr-app' ) . '">' . self::ico( 'search' ) . '</button>';
		}
		$h .= '</span>';
		if ( 'single' === $kind ) {
			$h .= '<i class="nha-head__prog" data-nha-prog aria-hidden="true"></i>'; // How much of the article has been read.
		}
		return $h . '</header>';
	}

	private static function search_screen() {
		list( $action, $name ) = NHM_Shell::search_target();
		$h  = '<div class="nhm-searchscreen" id="nhm-searchscreen" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Search', 'neurohr-app' ) . '" hidden>';
		$h .= '<div class="nhm-searchscreen__head"><h2>' . esc_html__( 'Search', 'neurohr-app' ) . '</h2><button type="button" class="nhm-linkbtn" data-nhm-search-close>' . esc_html__( 'Cancel', 'neurohr-app' ) . '</button></div>';
		$h .= '<form class="nhm-searchscreen__form" role="search" method="get" action="' . esc_url( $action ) . '">' . self::ico( 'search' ) . '<input type="search" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( (string) NHM_Settings::get( 'shell_search' ) ) . '" autocomplete="off" enterkeyhint="search" aria-label="' . esc_attr__( 'Search', 'neurohr-app' ) . '"></form>';
		$h .= '<div class="nhm-searchscreen__recent" data-nhm-recent hidden><p class="nhm-searchscreen__label">' . esc_html__( 'Recent', 'neurohr-app' ) . '</p><div class="nhm-chips"></div></div>';
		$h .= '<div class="nhm-searchscreen__results" data-nhm-results aria-live="polite"></div>';
		return $h . '</div>';
	}

	/** Shortcuts: the "Phone: More shortcuts" menu, else a sensible default. */
	public static function shortcuts( array $l ) {
		$out = array();
		if ( has_nav_menu( 'nhm_more' ) ) {
			$locations = get_nav_menu_locations();
			$items     = NHM_Visibility::filter_items( (array) wp_get_nav_menu_items( $locations['nhm_more'] ) );
			foreach ( $items as $item ) {
				if ( (int) $item->menu_item_parent ) {
					continue;
				}
				$icon  = (string) get_post_meta( $item->ID, NHM_Visibility::META_ICON, true );
				$out[] = array( $item->title, $item->url, '' !== $icon ? $icon : 'dot' );
			}
			return array_slice( $out, 0, 9 );
		}
		$defaults = array(
			array( __( 'HR News', 'neurohr-app' ), isset( $l['news'] ) ? $l['news'] : '', 'news' ),
			array( ! empty( $l['cases_label'] ) ? $l['cases_label'] : __( 'Case Insights', 'neurohr-app' ), isset( $l['cases'] ) ? $l['cases'] : '', 'scale' ),
			array( ! empty( $l['videos_label'] ) ? $l['videos_label'] : __( 'Videos', 'neurohr-app' ), isset( $l['videos'] ) ? $l['videos'] : '', 'video' ),
			array( __( 'Saved', 'neurohr-app' ), isset( $l['saved'] ) ? $l['saved'] : '', 'bookmark' ),
			array( ! empty( $l['faq_label'] ) ? $l['faq_label'] : __( 'FAQ', 'neurohr-app' ), isset( $l['faq'] ) ? $l['faq'] : '', 'help' ),
			array( __( 'Payroll', 'neurohr-app' ), isset( $l['payroll'] ) ? $l['payroll'] : '', 'calc' ),
		);
		foreach ( $defaults as $d ) {
			if ( '' !== (string) $d[1] ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	private static function more_sheet( array $l ) {
		$user = wp_get_current_user();
		$m    = NHM_Members::membership( $user->ID );
		$co   = $m['company_id'] ? NHM_Companies::get( $m['company_id'] ) : null;
		$h    = '<div class="nhm-sheet-back" data-nhm-more-close hidden></div>';
		$h   .= '<div class="nhm-moresheet" id="nhm-moresheet" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'More', 'neurohr-app' ) . '" hidden><span class="nhm-grab" data-nhm-more-close></span>';
		$h   .= '<a class="nhm-moresheet__me" href="' . esc_url( $l['account'] ) . '">' . NHM_Avatar::img( $user->ID, 50, 'nhm-moresheet__avatar' ) . '<span><b>' . esc_html( $user->display_name ) . '</b><small>' . esc_html( $co ? $co['name'] : $user->user_email ) . '</small></span><em>' . esc_html( $m['name'] ) . '</em></a>';
		$cuts = self::shortcuts( $l );
		if ( $cuts ) {
			$h .= '<div class="nhm-moresheet__grid">';
			foreach ( $cuts as $c ) {
				$h .= '<a href="' . esc_url( $c[1] ) . '"><i>' . self::ico( $c[2] ) . '</i><span>' . esc_html( $c[0] ) . '</span></a>';
			}
			$h .= '</div>';
		}
		$rows = array();
		if ( $l['account'] ) {
			$rows[] = array( __( 'My Account', 'neurohr-app' ), $l['account'], '' );
			$rows[] = array( __( 'Plan & Billing', 'neurohr-app' ), $l['billing'], '' );
			if ( $m['company_id'] ) {
				$rows[] = array( __( 'My Company', 'neurohr-app' ), $l['company'], '' );
			}
			$rows[] = array( __( 'Notifications', 'neurohr-app' ), $l['notifications'], '' );
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$rows[] = array( __( 'Site Admin', 'neurohr-app' ), admin_url(), '' );
		}
		$h .= '<div class="nhm-moresheet__list">';
		foreach ( $rows as $r ) {
			$h .= '<a href="' . esc_url( $r[1] ) . '">' . esc_html( $r[0] ) . '<span aria-hidden="true">›</span></a>';
		}
		if ( NHA_PWA::on() ) {
			$h .= '<button type="button" class="nhm-install" data-nhm-install hidden>' . esc_html__( 'Install the NeuroHR App', 'neurohr-app' ) . '<span aria-hidden="true">›</span></button>';
		}
		$h .= '<a class="is-out" href="' . esc_url( $l['logout'] ) . '">' . esc_html__( 'Log Out', 'neurohr-app' ) . '<span aria-hidden="true">›</span></a>';
		$h .= '</div></div>';
		if ( NHA_PWA::on() ) {
			$h .= NHA_PWA::ios_sheet();
		}
		return $h;
	}

	/* ---------- App home (Dashboard on phones) ---------- */

	/** The app home goes at the top of the Dashboard page (CSS shows it on phones only). */
	public static function open_html( $html ) {
		return self::on() ? $html . self::header_html() . self::home_html() : $html;
	}

	public static function is_home_page() {
		$is = false;
		if ( NHA_Settings::get( 'app_home' ) && is_singular( 'page' ) ) {
			$home = (int) url_to_postid( NHM_Settings::url( 'home_redirect', '' ) );
			$is   = $home && (int) get_queried_object_id() === $home;
		}
		return (bool) apply_filters( 'nhm_app_is_home', $is );
	}

	/** Called by the shell template before the page; shown on phones only. */
	public static function home_html() {
		if ( ! self::on() || ! self::is_home_page() ) {
			return '';
		}
		$uid  = get_current_user_id();
		$now  = NHM_Greeting::now( $uid );
		$l    = self::links();
		$h    = '<section class="nhm-apphome" aria-label="' . esc_attr__( 'Home', 'neurohr-app' ) . '">';
		$h   .= '<header class="nhm-apphome__top"><div><p class="nhm-apphome__date">' . esc_html( wp_date( 'l, j M Y', $now->getTimestamp(), NHM_Greeting::timezone( $uid ) ) ) . '</p>';
		$h   .= '<h1 class="nhm-apphome__hello">' . esc_html( NHM_Greeting::greeting( $uid ) ) . '</h1></div>';
		$h   .= '<a class="nhm-apphome__me" href="' . esc_url( $l['account'] ) . '" aria-label="' . esc_attr__( 'My Account', 'neurohr-app' ) . '">' . NHM_Avatar::img( $uid, 46, 'nhm-apphome__avatar' ) . '</a></header>';
		$h   .= '<div class="nhm-cards" data-nhm-cards>' . self::plan_card( $uid, $l ) . self::line_card( $uid ) . self::action_card( $uid, $l ) . '</div>';
		$h   .= '<div class="nhm-cards__dots" aria-hidden="true"><i class="is-on"></i><i></i><i></i></div>';
		$h   .= self::latest_html( $uid, $l );
		$h   .= '</section>';
		return $h;
	}

	private static function plan_card( $uid, $l ) {
		$m     = NHM_Members::membership( $uid );
		$days  = $m['days_left'];
		$total = 365;
		$p     = $m['personal'];
		if ( 'personal' === $m['source'] && $p['start'] && $p['expiry'] ) {
			$total = max( 1, (int) round( ( strtotime( $p['expiry'] ) - strtotime( $p['start'] ) ) / DAY_IN_SECONDS ) );
		} elseif ( 'company' === $m['source'] && $m['company_id'] ) {
			$c = NHM_Companies::get( $m['company_id'] );
			if ( $c && ! empty( $c['start_date'] ) && $m['expiry'] ) {
				$total = max( 1, (int) round( ( strtotime( $m['expiry'] ) - strtotime( $c['start_date'] ) ) / DAY_IN_SECONDS ) );
			}
		}
		$h = '<a class="nhm-card-hero" href="' . esc_url( $l['billing'] ? $l['billing'] : $l['account'] ) . '"><p class="nhm-card-hero__k">' . esc_html__( 'Your Plan', 'neurohr-app' ) . '</p><p class="nhm-card-hero__t">' . esc_html( $m['name'] ) . '</p>';
		if ( $m['expiry'] ) {
			/* translators: %s: date */
			$h .= '<p class="nhm-card-hero__s">' . esc_html( sprintf( __( 'Active until %s', 'neurohr-app' ), NHM_Emails::format_date( $m['expiry'] ) ) ) . '</p>';
		} else {
			$h .= '<p class="nhm-card-hero__s">' . esc_html( (int) $m['level'] === (int) NHM_Levels::base_id() ? __( 'Free account', 'neurohr-app' ) : __( 'No end date', 'neurohr-app' ) ) . '</p>';
		}
		if ( NHM_Renew::is_on( $uid ) ) {
			$h .= '<span class="nhm-card-hero__pill">' . esc_html__( 'Auto-renew on', 'neurohr-app' ) . '</span>';
		} elseif ( $m['company_id'] ) {
			$c  = NHM_Companies::get( $m['company_id'] );
			$h .= $c ? '<span class="nhm-card-hero__pill">' . esc_html( $c['name'] ) . '</span>' : '';
		}
		if ( null !== $days && $m['expiry'] ) {
			$r    = 44;
			$len  = 2 * M_PI * $r;
			$fill = max( 0, min( 1, $days / $total ) );
			$h   .= '<span class="nhm-ring" aria-label="' . esc_attr( sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days, 'neurohr-app' ), $days ) ) . '"><svg width="104" height="104" viewBox="0 0 104 104" aria-hidden="true"><circle cx="52" cy="52" r="' . $r . '" stroke="rgba(255,255,255,.28)" stroke-width="9" fill="none"/><circle cx="52" cy="52" r="' . $r . '" stroke="#fff" stroke-width="9" fill="none" stroke-linecap="round" stroke-dasharray="' . round( $len, 1 ) . '" stroke-dashoffset="' . round( $len * ( 1 - $fill ), 1 ) . '" transform="rotate(-90 52 52)"/></svg><b>' . (int) $days . '<small>' . esc_html( _n( 'day left', 'days left', $days, 'neurohr-app' ) ) . '</small></b></span>';
		}
		return $h . '</a>';
	}

	private static function line_card( $uid ) {
		return '<div class="nhm-card-soft"><p class="nhm-card-soft__k">' . esc_html__( 'For You Today', 'neurohr-app' ) . '</p><p class="nhm-card-soft__t">' . esc_html( NHM_Greeting::line( $uid ) ) . '</p></div>';
	}

	/** Renew soon / upgrade / explore. */
	private static function action_card( $uid, $l ) {
		$m    = NHM_Members::membership( $uid );
		$days = $m['days_left'];
		if ( (int) $m['level'] === (int) NHM_Levels::base_id() ) {
			return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( nhm_upgrade_url( $m['level'] ) ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Upgrade', 'neurohr-app' ) . '</p><p class="nhm-card-soft__t">' . esc_html__( 'Unlock every guide, case and template.', 'neurohr-app' ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'See Plans', 'neurohr-app' ) . ' ›</span></a>';
		}
		if ( null !== $days && $days <= 30 && 'personal' === $m['source'] && ! NHM_Renew::is_on( $uid ) ) {
			/* translators: %d: days */
			return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( $l['billing'] ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Renew', 'neurohr-app' ) . '</p><p class="nhm-card-soft__t">' . esc_html( sprintf( _n( 'Your plan ends in %d day.', 'Your plan ends in %d days.', $days, 'neurohr-app' ), $days ) ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'Renew Now', 'neurohr-app' ) . ' ›</span></a>';
		}
		return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( $l['templates'] ? $l['templates'] : $l['hub'] ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Templates', 'neurohr-app' ) . '</p><p class="nhm-card-soft__t">' . esc_html__( 'Letters and forms, ready to download.', 'neurohr-app' ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'Open', 'neurohr-app' ) . ' ›</span></a>';
	}

	public static function latest( $limit = 5 ) {
		$items = apply_filters( 'nhm_latest_items', array(), $limit );
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return array_slice( $items, 0, $limit );
	}

	private static function latest_html( $uid, $l ) {
		$items = self::latest( 5 );
		$h     = '<section class="nhm-latest"><span class="nhm-grab" aria-hidden="true"></span><h2 class="nhm-latest__title">' . esc_html__( 'Latest for You', 'neurohr-app' ) . '<a href="' . esc_url( $l['hub'] ) . '">' . esc_html__( 'See All', 'neurohr-app' ) . '</a></h2>';
		if ( ! $items ) {
			return $h . '<p class="nhm-muted">' . esc_html__( 'New guides and updates will show here.', 'neurohr-app' ) . '</p></section>';
		}
		$tz    = NHM_Greeting::timezone( $uid );
		$today = NHM_Greeting::now( $uid )->format( 'Y-m-d' );
		$week  = NHM_Greeting::now( $uid )->modify( '-6 days' )->format( 'Y-m-d' );
		$group = '';
		$first = true;
		foreach ( $items as $it ) {
			$d    = new DateTimeImmutable( $it['date'], wp_timezone() );
			$d    = $d->setTimezone( $tz );
			$day  = $d->format( 'Y-m-d' );
			$name = $day === $today ? __( 'Today', 'neurohr-app' ) : ( $day >= $week ? __( 'This Week', 'neurohr-app' ) : __( 'Earlier', 'neurohr-app' ) );
			if ( $name !== $group ) {
				$h    .= ( '' === $group ? '' : '</ul>' ) . '<p class="nhm-latest__grp">' . esc_html( $name ) . '</p><ul class="nhm-tl">';
				$group = $name;
			}
			$when = $day === $today ? $d->format( 'H:i' ) : ( $day >= $week ? wp_date( 'D', $d->getTimestamp(), $tz ) : wp_date( 'j M', $d->getTimestamp(), $tz ) );
			$h   .= '<li' . ( $first ? ' class="is-first"' : '' ) . '><a href="' . esc_url( $it['url'] ) . '"><i aria-hidden="true"></i><span><b>' . esc_html( $it['title'] ) . '</b><small>' . esc_html( $it['label'] ) . '</small></span><em>' . esc_html( $when ) . '</em></a></li>';
			$first = false;
		}
		return $h . '</ul></section>';
	}
}
