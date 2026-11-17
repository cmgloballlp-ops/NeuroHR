<?php
/**
 * The member area as a phone app (0.5):
 * - [nhm_greeting]: "Good morning, Marcus!" by the member's time of day, and
 *   [nhm_greeting part="line"]: one of the rotating lines, never the same twice in a row.
 * - On phones: bottom bar (Home · Hub · Search · Templates · More), full-screen search,
 *   the More sheet (shortcuts from Appearance → Menus → "Phone: More shortcuts"), and the
 *   app home on the Dashboard page (greeting, plan cards, Latest for You).
 * Desktop keeps the sidebar layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_App {

	const LAST_LINE = 'nhm_greet_last';

	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'menus' ), 21 );
		add_shortcode( 'nhm_greeting', array( __CLASS__, 'shortcode' ) );
		add_filter( 'nhm_shell_open_html', array( __CLASS__, 'open_html' ), 20 );
		add_filter( 'nhm_shell_close_html', array( __CLASS__, 'chrome' ), 5 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 21 );
	}

	public static function menus() {
		register_nav_menus( array( 'nhm_more' => __( 'Phone: More shortcuts', 'neurohr-members' ) ) );
	}

	public static function on() {
		return (bool) NHM_Settings::get( 'app_on' ) && NHM_Shell::active();
	}

	/* ---------- Greeting ---------- */

	public static function default_lines() {
		return array(
			"Here's what's new in Malaysian HR today.",
			'Ready to make HR a little easier today?',
			'Your next great HR decision starts here.',
			'Good HR is built one small step at a time.',
			"Let's turn today's HR questions into answers.",
			"Great teams start with great HR. You've got this.",
			'A quick read today can save a big headache tomorrow.',
			'Stay ahead of the latest employment law updates.',
			'Templates, guides and tools, all ready when you are.',
			'Every policy you get right makes your team stronger.',
			'Take a moment to explore something new today.',
			'HR made simpler, one resource at a time.',
			"Your people are your business. We're here to help.",
			'Clear policies, happy teams, fewer surprises.',
			'Small improvements in HR add up to big results.',
			'Have a question? The answer may be just a search away.',
			'Thanks for building a better workplace.',
			'Smart HR starts with the right knowledge.',
			"Let's make today a productive one.",
			"Good to see you again. Let's get started.",
		);
	}

	public static function lines() {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) NHM_Settings::get( 'greet_lines' ) ) ) ) );
		return $lines ? $lines : self::default_lines();
	}

	/** The member's time zone (My account), else the site's. */
	public static function timezone( $user_id ) {
		$tz = $user_id ? (string) get_user_meta( $user_id, 'nhm_tz', true ) : '';
		if ( '' !== $tz ) {
			try {
				return new DateTimeZone( $tz );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
		return wp_timezone();
	}

	public static function now( $user_id ) {
		return new DateTimeImmutable( 'now', self::timezone( $user_id ) );
	}

	/** morning 5–11 · afternoon 12–17 · evening 18–20 · late 21–4 */
	public static function part_of_day( $hour ) {
		$hour = (int) $hour;
		if ( $hour >= 5 && $hour < 12 ) {
			return 'morning';
		}
		if ( $hour >= 12 && $hour < 18 ) {
			return 'afternoon';
		}
		if ( $hour >= 18 && $hour < 21 ) {
			return 'evening';
		}
		return 'late';
	}

	public static function first_name( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$first = trim( (string) $user->first_name );
		if ( '' === $first ) {
			// "Marcus Lim" → Marcus; a single word (often the username) is not used.
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
			$first = count( $parts ) > 1 && false === strpos( $parts[0], '@' ) ? $parts[0] : '';
		}
		return $first;
	}

	public static function greeting( $user_id, $hour = null ) {
		$hour = null === $hour ? (int) self::now( $user_id )->format( 'G' ) : (int) $hour;
		$part = self::part_of_day( $hour );
		$fmt  = (string) NHM_Settings::get( 'greet_' . $part );
		$name = self::first_name( $user_id );
		if ( '' === $name ) {
			$fmt = preg_replace( '/,?\s*\{name\}/', '', $fmt );
		}
		return trim( str_replace( '{name}', $name, $fmt ) );
	}

	/** A random line, never the one this member saw last time. */
	public static function line( $user_id ) {
		$lines = self::lines();
		$last  = $user_id ? (int) get_user_meta( $user_id, self::LAST_LINE, true ) : -1;
		$pick  = array_keys( $lines );
		if ( count( $pick ) > 1 ) {
			$pick = array_values( array_diff( $pick, array( $last ) ) );
		}
		$i = $pick[ wp_rand( 0, count( $pick ) - 1 ) ];
		if ( $user_id ) {
			update_user_meta( $user_id, self::LAST_LINE, $i );
		}
		return $lines[ $i ];
	}

	/** [nhm_greeting] or [nhm_greeting part="line"] */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'part' => 'greeting', 'guest' => 'Welcome!' ), $atts, 'nhm_greeting' );
		$uid  = get_current_user_id();
		if ( 'line' === $atts['part'] ) {
			return '<span class="nhm-greeting-line">' . esc_html( self::line( $uid ) ) . '</span>';
		}
		return '<span class="nhm-greeting">' . esc_html( $uid ? self::greeting( $uid ) : $atts['guest'] ) . '</span>';
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
			if ( self::is_home_page() ) {
				$classes[] = 'nhm-has-apphome';
			}
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::on() ) {
			return;
		}
		wp_enqueue_style( 'nhm-app', NHM_URL . 'assets/app.css', array( 'nhm-shell' ), NHM_VERSION );
		wp_enqueue_script( 'nhm-app', NHM_URL . 'assets/app.js', array(), NHM_VERSION, true );
		list( $action ) = NHM_Shell::search_target();
		wp_localize_script(
			'nhm-app',
			'nhmApp',
			array(
				'live'    => NHM_Shell::live_search() ? array( 'url' => admin_url( 'admin-ajax.php' ), 'action' => 'nh_hub_live' ) : null,
				'search'  => array( 'url' => $action, 'name' => NHM_Shell::search_target()[1] ),
				'sw'      => NHM_PWA::on() ? home_url( '/nhm-sw.js' ) : '',
				'strings' => array(
					'none'    => __( 'No results yet. Press Enter to search everything.', 'neurohr-members' ),
					'loading' => __( 'Searching…', 'neurohr-members' ),
					'all'     => __( 'See All Results', 'neurohr-members' ),
				),
			)
		);
	}

	private static function ico( $name ) {
		return NHM_Shell::icon( $name );
	}

	/** Bottom bar, search screen and More sheet (shown on phones only, by CSS). */
	public static function chrome( $html ) {
		if ( ! self::on() ) {
			return $html;
		}
		$l   = self::links();
		$cur = self::current_tab( $l );
		$tab = function ( $key, $url, $icon, $label ) use ( $cur ) {
			return '<a class="nhm-tbi' . ( $key === $cur ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $key === $cur ? ' aria-current="page"' : '' ) . '>' . self::ico( $icon ) . '<span>' . esc_html( $label ) . '</span></a>';
		};
		$h  = '<nav class="nhm-tabbar" aria-label="' . esc_attr__( 'App menu', 'neurohr-members' ) . '">';
		$h .= $tab( 'home', $l['home'], 'home', __( 'Home', 'neurohr-members' ) );
		$h .= $tab( 'hub', $l['hub'], 'book', __( 'Hub', 'neurohr-members' ) );
		$h .= '<button type="button" class="nhm-tabfab" data-nhm-search-open aria-label="' . esc_attr__( 'Search', 'neurohr-members' ) . '">' . self::ico( 'search' ) . '</button>';
		$h .= $l['templates'] ? $tab( 'templates', $l['templates'], 'file', __( 'Templates', 'neurohr-members' ) ) : $tab( 'news', $l['news'] ? $l['news'] : $l['home'], 'news', __( 'News', 'neurohr-members' ) );
		$h .= '<button type="button" class="nhm-tbi' . ( 'more' === $cur ? ' is-on' : '' ) . '" data-nhm-more-open aria-haspopup="dialog">' . self::ico( 'more' ) . '<span>' . esc_html__( 'More', 'neurohr-members' ) . '</span></button>';
		$h .= '</nav>';
		$h .= self::search_screen();
		$h .= self::more_sheet( $l );
		return $html . $h;
	}

	private static function search_screen() {
		list( $action, $name ) = NHM_Shell::search_target();
		$h  = '<div class="nhm-searchscreen" id="nhm-searchscreen" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Search', 'neurohr-members' ) . '" hidden>';
		$h .= '<div class="nhm-searchscreen__head"><h2>' . esc_html__( 'Search', 'neurohr-members' ) . '</h2><button type="button" class="nhm-linkbtn" data-nhm-search-close>' . esc_html__( 'Cancel', 'neurohr-members' ) . '</button></div>';
		$h .= '<form class="nhm-searchscreen__form" role="search" method="get" action="' . esc_url( $action ) . '">' . self::ico( 'search' ) . '<input type="search" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( (string) NHM_Settings::get( 'shell_search' ) ) . '" autocomplete="off" enterkeyhint="search" aria-label="' . esc_attr__( 'Search', 'neurohr-members' ) . '"></form>';
		$h .= '<div class="nhm-searchscreen__recent" data-nhm-recent hidden><p class="nhm-searchscreen__label">' . esc_html__( 'Recent', 'neurohr-members' ) . '</p><div class="nhm-chips"></div></div>';
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
			array( __( 'HR News', 'neurohr-members' ), isset( $l['news'] ) ? $l['news'] : '', 'news' ),
			array( ! empty( $l['cases_label'] ) ? $l['cases_label'] : __( 'Case Insights', 'neurohr-members' ), isset( $l['cases'] ) ? $l['cases'] : '', 'scale' ),
			array( ! empty( $l['videos_label'] ) ? $l['videos_label'] : __( 'Videos', 'neurohr-members' ), isset( $l['videos'] ) ? $l['videos'] : '', 'video' ),
			array( __( 'Saved', 'neurohr-members' ), isset( $l['saved'] ) ? $l['saved'] : '', 'bookmark' ),
			array( ! empty( $l['faq_label'] ) ? $l['faq_label'] : __( 'FAQ', 'neurohr-members' ), isset( $l['faq'] ) ? $l['faq'] : '', 'help' ),
			array( __( 'Payroll', 'neurohr-members' ), isset( $l['payroll'] ) ? $l['payroll'] : '', 'calc' ),
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
		$h   .= '<div class="nhm-moresheet" id="nhm-moresheet" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'More', 'neurohr-members' ) . '" hidden><span class="nhm-grab" data-nhm-more-close></span>';
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
			$rows[] = array( __( 'My Account', 'neurohr-members' ), $l['account'], '' );
			$rows[] = array( __( 'Plan & Billing', 'neurohr-members' ), $l['billing'], '' );
			if ( $m['company_id'] ) {
				$rows[] = array( __( 'My Company', 'neurohr-members' ), $l['company'], '' );
			}
			$rows[] = array( __( 'Notifications', 'neurohr-members' ), $l['notifications'], '' );
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$rows[] = array( __( 'Site Admin', 'neurohr-members' ), admin_url(), '' );
		}
		$h .= '<div class="nhm-moresheet__list">';
		foreach ( $rows as $r ) {
			$h .= '<a href="' . esc_url( $r[1] ) . '">' . esc_html( $r[0] ) . '<span aria-hidden="true">›</span></a>';
		}
		if ( NHM_PWA::on() ) {
			$h .= '<button type="button" class="nhm-install" data-nhm-install hidden>' . esc_html__( 'Install the NeuroHR App', 'neurohr-members' ) . '<span aria-hidden="true">›</span></button>';
		}
		$h .= '<a class="is-out" href="' . esc_url( $l['logout'] ) . '">' . esc_html__( 'Log Out', 'neurohr-members' ) . '<span aria-hidden="true">›</span></a>';
		$h .= '</div></div>';
		if ( NHM_PWA::on() ) {
			$h .= NHM_PWA::ios_sheet();
		}
		return $h;
	}

	/* ---------- App home (Dashboard on phones) ---------- */

	/** The app home goes at the top of the Dashboard page (CSS shows it on phones only). */
	public static function open_html( $html ) {
		return $html . self::home_html();
	}

	public static function is_home_page() {
		$is = false;
		if ( NHM_Settings::get( 'app_home' ) && is_singular( 'page' ) ) {
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
		$now  = self::now( $uid );
		$l    = self::links();
		$h    = '<section class="nhm-apphome" aria-label="' . esc_attr__( 'Home', 'neurohr-members' ) . '">';
		$h   .= '<header class="nhm-apphome__top"><div><p class="nhm-apphome__date">' . esc_html( wp_date( 'l, j M Y', $now->getTimestamp(), self::timezone( $uid ) ) ) . '</p>';
		$h   .= '<h1 class="nhm-apphome__hello">' . esc_html( self::greeting( $uid ) ) . '</h1></div>';
		$h   .= '<a class="nhm-apphome__me" href="' . esc_url( $l['account'] ) . '" aria-label="' . esc_attr__( 'My Account', 'neurohr-members' ) . '">' . NHM_Avatar::img( $uid, 46, 'nhm-apphome__avatar' ) . '</a></header>';
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
		$h = '<a class="nhm-card-hero" href="' . esc_url( $l['billing'] ? $l['billing'] : $l['account'] ) . '"><p class="nhm-card-hero__k">' . esc_html__( 'Your Plan', 'neurohr-members' ) . '</p><p class="nhm-card-hero__t">' . esc_html( $m['name'] ) . '</p>';
		if ( $m['expiry'] ) {
			/* translators: %s: date */
			$h .= '<p class="nhm-card-hero__s">' . esc_html( sprintf( __( 'Active until %s', 'neurohr-members' ), NHM_Emails::format_date( $m['expiry'] ) ) ) . '</p>';
		} else {
			$h .= '<p class="nhm-card-hero__s">' . esc_html( (int) $m['level'] === (int) NHM_Levels::base_id() ? __( 'Free account', 'neurohr-members' ) : __( 'No end date', 'neurohr-members' ) ) . '</p>';
		}
		if ( NHM_Renew::is_on( $uid ) ) {
			$h .= '<span class="nhm-card-hero__pill">' . esc_html__( 'Auto-renew on', 'neurohr-members' ) . '</span>';
		} elseif ( $m['company_id'] ) {
			$c  = NHM_Companies::get( $m['company_id'] );
			$h .= $c ? '<span class="nhm-card-hero__pill">' . esc_html( $c['name'] ) . '</span>' : '';
		}
		if ( null !== $days && $m['expiry'] ) {
			$r    = 44;
			$len  = 2 * M_PI * $r;
			$fill = max( 0, min( 1, $days / $total ) );
			$h   .= '<span class="nhm-ring" aria-label="' . esc_attr( sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days, 'neurohr-members' ), $days ) ) . '"><svg width="104" height="104" viewBox="0 0 104 104" aria-hidden="true"><circle cx="52" cy="52" r="' . $r . '" stroke="rgba(255,255,255,.28)" stroke-width="9" fill="none"/><circle cx="52" cy="52" r="' . $r . '" stroke="#fff" stroke-width="9" fill="none" stroke-linecap="round" stroke-dasharray="' . round( $len, 1 ) . '" stroke-dashoffset="' . round( $len * ( 1 - $fill ), 1 ) . '" transform="rotate(-90 52 52)"/></svg><b>' . (int) $days . '<small>' . esc_html( _n( 'day left', 'days left', $days, 'neurohr-members' ) ) . '</small></b></span>';
		}
		return $h . '</a>';
	}

	private static function line_card( $uid ) {
		return '<div class="nhm-card-soft"><p class="nhm-card-soft__k">' . esc_html__( 'For You Today', 'neurohr-members' ) . '</p><p class="nhm-card-soft__t">' . esc_html( self::line( $uid ) ) . '</p></div>';
	}

	/** Renew soon / upgrade / explore. */
	private static function action_card( $uid, $l ) {
		$m    = NHM_Members::membership( $uid );
		$days = $m['days_left'];
		if ( (int) $m['level'] === (int) NHM_Levels::base_id() ) {
			return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( nhm_upgrade_url( $m['level'] ) ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Upgrade', 'neurohr-members' ) . '</p><p class="nhm-card-soft__t">' . esc_html__( 'Unlock every guide, case and template.', 'neurohr-members' ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'See Plans', 'neurohr-members' ) . ' ›</span></a>';
		}
		if ( null !== $days && $days <= 30 && 'personal' === $m['source'] && ! NHM_Renew::is_on( $uid ) ) {
			/* translators: %d: days */
			return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( $l['billing'] ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Renew', 'neurohr-members' ) . '</p><p class="nhm-card-soft__t">' . esc_html( sprintf( _n( 'Your plan ends in %d day.', 'Your plan ends in %d days.', $days, 'neurohr-members' ), $days ) ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'Renew Now', 'neurohr-members' ) . ' ›</span></a>';
		}
		return '<a class="nhm-card-soft nhm-card-soft--cta" href="' . esc_url( $l['templates'] ? $l['templates'] : $l['hub'] ) . '"><p class="nhm-card-soft__k">' . esc_html__( 'Templates', 'neurohr-members' ) . '</p><p class="nhm-card-soft__t">' . esc_html__( 'Letters and forms, ready to download.', 'neurohr-members' ) . '</p><span class="nhm-card-soft__go">' . esc_html__( 'Open', 'neurohr-members' ) . ' ›</span></a>';
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
		$h     = '<section class="nhm-latest"><span class="nhm-grab" aria-hidden="true"></span><h2 class="nhm-latest__title">' . esc_html__( 'Latest for You', 'neurohr-members' ) . '<a href="' . esc_url( $l['hub'] ) . '">' . esc_html__( 'See All', 'neurohr-members' ) . '</a></h2>';
		if ( ! $items ) {
			return $h . '<p class="nhm-muted">' . esc_html__( 'New guides and updates will show here.', 'neurohr-members' ) . '</p></section>';
		}
		$tz    = self::timezone( $uid );
		$today = self::now( $uid )->format( 'Y-m-d' );
		$week  = self::now( $uid )->modify( '-6 days' )->format( 'Y-m-d' );
		$group = '';
		$first = true;
		foreach ( $items as $it ) {
			$d    = new DateTimeImmutable( $it['date'], wp_timezone() );
			$d    = $d->setTimezone( $tz );
			$day  = $d->format( 'Y-m-d' );
			$name = $day === $today ? __( 'Today', 'neurohr-members' ) : ( $day >= $week ? __( 'This Week', 'neurohr-members' ) : __( 'Earlier', 'neurohr-members' ) );
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
