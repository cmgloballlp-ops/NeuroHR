<?php
/**
 * Phone / app features of the hub (0.9):
 * - Saved: members save resources (bookmark button on every resource) and find them at
 *   /knowledge/saved/. Stored in user meta nh_saved (newest first, up to 300).
 * - Share button (the phone's share sheet, or copy the link).
 * - Latest items for the NeuroHR Members app home (filter nhm_latest_items).
 * - Sticky "related template" bar on phones (single resource).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_App {

	const META     = 'nh_saved';
	const MAX      = 300;
	const AJAX     = 'nh_hub_save';
	const QVAR     = 'nh_saved';
	const RULES_V  = 2;

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'rules' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_filter( 'nhm_latest_items', array( __CLASS__, 'latest' ), 10, 2 );
		add_filter( 'nhm_app_links', array( __CLASS__, 'app_links' ) );
		add_filter( 'nha_page_context', array( __CLASS__, 'page_context' ) );
	}

	/* ---------- Saved ---------- */

	public static function query_vars( $vars ) {
		$vars[] = self::QVAR;
		return $vars;
	}

	public static function rules() {
		$base = self::base();
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/saved/?$', 'index.php?' . self::QVAR . '=1', 'top' );
		// Flush once after an update, and again when the Knowledge section gets a new address.
		$stamp = self::RULES_V . '|' . $base;
		if ( get_option( 'nh_app_rules' ) !== $stamp ) {
			update_option( 'nh_app_rules', $stamp, true );
			update_option( NH_Rewrite::OPTION_FLUSH, 1, true );
		}
	}

	/** Address of the Knowledge section (Settings → Sections can change it). */
	public static function base() {
		$data = NH_Sections::get( 'knowledge' );
		return trim( $data && ! empty( $data['base'] ) ? $data['base'] : NH_Post_Types::BASE, '/' );
	}

	public static function saved_url() {
		return home_url( user_trailingslashit( self::base() . '/saved' ) );
	}

	public static function is_saved_page() {
		return (bool) get_query_var( self::QVAR );
	}

	/** @return int[] newest first */
	public static function saved_ids( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$ids     = $user_id ? get_user_meta( $user_id, self::META, true ) : array();
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	public static function is_saved( $post_id, $user_id = 0 ) {
		return in_array( (int) $post_id, self::saved_ids( $user_id ), true );
	}

	/** @return bool saved now */
	public static function toggle( $post_id, $user_id ) {
		$ids = self::saved_ids( $user_id );
		if ( in_array( (int) $post_id, $ids, true ) ) {
			$ids = array_values( array_diff( $ids, array( (int) $post_id ) ) );
			update_user_meta( $user_id, self::META, $ids );
			return false;
		}
		array_unshift( $ids, (int) $post_id );
		update_user_meta( $user_id, self::META, array_slice( $ids, 0, self::MAX ) );
		return true;
	}

	public static function ajax() {
		check_ajax_referer( self::AJAX, 'nonce' );
		$uid  = get_current_user_id();
		$post = get_post( isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0 );
		if ( ! $uid || ! $post || ! in_array( $post->post_type, array( NH_Post_Types::CPT, 'post' ), true ) || 'publish' !== $post->post_status ) {
			wp_send_json_error( array(), 400 );
		}
		wp_send_json_success( array( 'saved' => self::toggle( $post->ID, $uid ) ) );
	}

	public static function pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->get( self::QVAR ) ) {
			return;
		}
		$ids = self::saved_ids();
		$query->set( 'post_type', array( NH_Post_Types::CPT, 'post' ) );
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', 50 );
		$query->set( 'ignore_sticky_posts', true );
		$query->is_home    = false;
		$query->is_archive = true;
	}

	public static function template( $template ) {
		if ( ! self::is_saved_page() ) {
			return $template;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		return NH_Frontend::locate( 'saved.php', $template );
	}

	/** Bookmark + share buttons for a resource or a news post. */
	public static function actions( $post ) {
		$post = get_post( $post );
		$h    = '<span class="nh-actions">';
		if ( is_user_logged_in() && in_array( $post->post_type, array( NH_Post_Types::CPT, 'post' ), true ) ) {
			$on = self::is_saved( $post->ID );
			$h .= '<button type="button" class="nh-iconbtn nh-save' . ( $on ? ' is-on' : '' ) . '" data-nh-save="' . (int) $post->ID . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '" aria-label="' . esc_attr__( 'Save', 'neurohr-hub' ) . '" title="' . esc_attr__( 'Save', 'neurohr-hub' ) . '">'
				. '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg></button>';
		}
		$h .= '<button type="button" class="nh-iconbtn nh-share" data-nh-share data-title="' . esc_attr( get_the_title( $post ) ) . '" data-url="' . esc_url( get_permalink( $post ) ) . '" aria-label="' . esc_attr__( 'Share', 'neurohr-hub' ) . '" title="' . esc_attr__( 'Share', 'neurohr-hub' ) . '">'
			. '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M16 6l-4-4-4 4M12 2v13"/></svg></button>';
		return $h . '</span>';
	}

	public static function assets() {
		if ( ! NH_Frontend::is_hub() && ! self::is_saved_page() && ! NH_Blog::is_styled() ) {
			return;
		}
		wp_enqueue_style( 'nh-hub', NH_HUB_URL . 'assets/hub.css', array(), NH_HUB_VERSION );
		wp_enqueue_script( 'nh-app', NH_HUB_URL . 'assets/app.js', array(), NH_HUB_VERSION, true );
		wp_localize_script(
			'nh-app',
			'nhApp',
			array(
				'url'     => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX,
				'nonce'   => is_user_logged_in() ? wp_create_nonce( self::AJAX ) : '',
				'strings' => array(
					'saved'   => __( 'Saved', 'neurohr-hub' ),
					'removed' => __( 'Removed from Saved', 'neurohr-hub' ),
					'copied'  => __( 'Link copied', 'neurohr-hub' ),
				),
			)
		);
	}

	/* ---------- Related template bar (phones) ---------- */

	/** The first template the resource references ("Templates to Download"), else a suggested one. */
	public static function related_template( $post_id ) {
		if ( 'download' === NH_Types::of_post( $post_id ) ) {
			return 0;
		}
		$groups = NH_Related::grouped( $post_id );
		$ids    = isset( $groups['download'] ) ? $groups['download']['ids'] : array();
		foreach ( NH_Related::get_ids( $post_id, 8 ) as $id ) {
			if ( 'download' === NH_Types::of_post( $id ) ) {
				$ids[] = $id;
			}
		}
		foreach ( $ids as $id ) {
			$id = class_exists( 'NH_Lang' ) ? NH_Lang::shown_id( $id ) : (int) $id;
			if ( $id !== (int) $post_id && 'publish' === get_post_status( $id ) ) {
				return $id;
			}
		}
		return 0;
	}

	/** Who may download it, its file type and where the button goes (bar on phones, card on computers). */
	public static function template_info( $id ) {
		$file = NH_Media::get_file( $id );
		$ext  = $file ? strtoupper( pathinfo( ! empty( $file['name'] ) ? $file['name'] : $file['path'], PATHINFO_EXTENSION ) ) : '';
		$can  = NH_Access::can_view( get_post( $id ) ) && NH_Media::has_download( $id );
		return array(
			'ext'   => $ext,
			'can'   => $can,
			'url'   => $can ? NH_Media::download_url( $id ) : get_permalink( $id ),
			'label' => $can ? __( 'Download', 'neurohr-hub' ) : __( 'See Template', 'neurohr-hub' ),
			'icon'  => $can ? '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>' : '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		);
	}

	public static function template_bar( $post_id ) {
		$id = self::related_template( $post_id );
		if ( ! $id ) {
			return '';
		}
		$t  = self::template_info( $id );
		$h  = '<div class="nh-tplbar" role="complementary" aria-label="' . esc_attr__( 'Related template', 'neurohr-hub' ) . '">';
		$h .= $t['ext'] ? '<span class="nh-tplbar__ext">' . esc_html( $t['ext'] ) . '</span>' : '';
		$h .= '<span class="nh-tplbar__txt"><small>' . esc_html__( 'Ready-to-use template', 'neurohr-hub' ) . '</small><b>' . esc_html( get_the_title( $id ) ) . '</b></span>';
		$h .= '<a class="nh-tplbar__btn' . ( $t['can'] ? '' : ' is-locked' ) . '" href="' . esc_url( $t['url'] ) . '"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' . $t['icon'] . '</svg>' . esc_html( $t['label'] ) . '</a>';
		return $h . '</div>';
	}

	/** Desktop sidebar card under the table of contents: the related template, with Download. */
	public static function side_template( $post_id ) {
		$id = self::related_template( $post_id );
		if ( ! $id ) {
			return '';
		}
		$t  = self::template_info( $id );
		$h  = '<div class="nh-tplcard"><span class="nh-tplcard__k">' . esc_html__( 'Ready-to-use template', 'neurohr-hub' ) . '</span>';
		$h .= '<a class="nh-tplcard__t" href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		$h .= $t['ext'] ? '<span class="nh-tplcard__s">' . esc_html( $t['ext'] ) . '</span>' : '';
		$h .= '<a class="nh-tplcard__btn' . ( $t['can'] ? '' : ' is-locked' ) . '" href="' . esc_url( $t['url'] ) . '"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' . $t['icon'] . '</svg>' . esc_html( $t['label'] ) . '</a>';
		return $h . '</div>';
	}


	/* ---------- For NeuroHR Members ---------- */

	/**
	 * Newest resources across all sections (and HR News posts) for the app home.
	 *
	 * @return array[] title, url, label, date (Y-m-d H:i:s, local), section
	 */
	public static function latest( $items, $limit = 5 ) {
		$limit = max( 1, (int) $limit );
		// suppress_filters false: one version per content, in the reader's language (NH_Lang).
		$posts = get_posts( array( 'post_type' => array( NH_Post_Types::CPT, 'post' ), 'post_status' => 'publish', 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false ) );
		$out   = array();
		foreach ( $posts as $p ) {
			if ( 'post' === $p->post_type ) {
				$cats  = get_the_category( $p->ID );
				$label = $cats ? $cats[0]->name : __( 'HR News & Update', 'neurohr-hub' );
			} else {
				$key   = NH_Sections::of_post( $p );
				$data  = NH_Sections::get( $key );
				$terms = $data && $data['taxonomy'] ? get_the_terms( $p, $data['taxonomy'] ) : array();
				$label = ( $data ? $data['label'] : '' ) . ( $terms && ! is_wp_error( $terms ) ? ' · ' . $terms[0]->name : '' );
			}
			$out[] = array(
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'   => get_permalink( $p ),
				'label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
				'date'  => $p->post_date,
			);
		}
		return array_merge( (array) $items, $out );
	}

	/** Addresses for the bottom bar and the More sheet of NeuroHR Members. */
	public static function app_links( $links ) {
		// Sections hidden in Settings → Sections get no link.
		$visible = NH_Sections::visible();
		foreach ( array( 'hub' => 'knowledge', 'templates' => 'templates', 'cases' => 'cases', 'videos' => 'videos', 'faq' => 'faq' ) as $link => $key ) {
			if ( isset( $visible[ $key ] ) ) {
				$links[ $link ]            = NH_Rewrite::url( $key );
				$links[ $link . '_label' ] = $visible[ $key ]['label']; // The name set in Settings → Sections.
			}
		}
		$links['saved'] = self::saved_url();
		return $links;
	}

	/* ---------- For NeuroHR App (phone layout) ---------- */

	/**
	 * Header of hub pages on phones: back button, title, buttons. Section homes have none
	 * (their own title is the header).
	 */
	public static function page_context( $ctx ) {
		if ( self::is_saved_page() ) {
			return array( 'title' => __( 'Saved', 'neurohr-hub' ), 'back' => NH_Rewrite::url( 'knowledge' ), 'kind' => 'list' );
		}
		if ( is_singular( NH_Post_Types::CPT ) || is_singular( 'post' ) ) {
			$post = get_queried_object();
			if ( 'post' === $post->post_type ) {
				$news = (int) get_option( 'page_for_posts' );
				$back = $news ? get_permalink( $news ) : home_url( '/' );
			} else {
				$key   = NH_Sections::of_post( $post );
				$data  = NH_Sections::get( $key );
				$terms = $data && $data['taxonomy'] ? get_the_terms( $post, $data['taxonomy'] ) : array();
				$back  = ( $terms && ! is_wp_error( $terms ) ) ? NH_Rewrite::url( $key, $terms[0]->slug ) : NH_Rewrite::url( $key );
			}
			return array(
				'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'back'    => $back,
				'actions' => self::actions( $post ),
				'search'  => false,
				'kind'    => 'single',
			);
		}
		if ( NH_Blog::is_styled() && ! is_singular() && ! NH_Blog::is_home_page() ) {
			return array( 'title' => NH_Blog::label(), 'back' => NH_Blog::news_url(), 'kind' => 'list' );
		}
		if ( NH_Frontend::is_tag_page() || ( NH_Frontend::is_section_page() && ! NH_Frontend::is_section_home() ) ) {
			$key  = NH_Frontend::is_tag_page() ? 'knowledge' : NH_Sections::current();
			$data = NH_Sections::get( $key );
			return array( 'title' => $data ? $data['label'] : '', 'back' => NH_Rewrite::url( $key ), 'kind' => 'list' );
		}
		return $ctx;
	}

	/**
	 * Phone-only parts of a section home (shown by NeuroHR App): the sections as chips
	 * (plus Saved) and the newest item of the section.
	 */
	public static function phone_extras( $section ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$h = '<nav class="nh-appchips" aria-label="' . esc_attr__( 'Sections', 'neurohr-hub' ) . '">';
		foreach ( NH_Sections::visible() as $key => $data ) {
			$on = $key === $section;
			$h .= '<a class="' . ( $on ? 'is-on' : '' ) . '" href="' . esc_url( NH_Rewrite::url( $key ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $data['label'] ) . '</a>';
		}
		if ( NH_Blog::enabled() && get_option( 'page_for_posts' ) ) {
			$on = 'news' === $section;
			$h .= '<a class="' . ( $on ? 'is-on' : '' ) . '" href="' . esc_url( NH_Blog::news_url() ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( NH_Blog::label() ) . '</a>';
		}
		$h .= '<a href="' . esc_url( self::saved_url() ) . '"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg> ' . esc_html__( 'Saved', 'neurohr-hub' ) . '</a></nav>';
		if ( 'news' === $section ) {
			$latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1 ) );
			if ( $latest ) {
				$p    = $latest[0];
				$new  = ( time() - get_post_time( 'U', true, $p ) ) < WEEK_IN_SECONDS;
				$cats = get_the_category( $p->ID );
				$h   .= '<a class="nh-appfeature" href="' . esc_url( get_permalink( $p ) ) . '"><span class="nh-appfeature__k">' . esc_html( $new ? __( 'New This Week', 'neurohr-hub' ) : __( 'Latest', 'neurohr-hub' ) ) . '</span><span class="nh-appfeature__t">' . esc_html( get_the_title( $p ) ) . '</span><span class="nh-appfeature__s">' . esc_html( get_the_date( 'j M Y', $p ) . ( $cats ? ' · ' . $cats[0]->name : '' ) ) . '</span></a>';
			}
			return $h;
		}
		$q = new WP_Query(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'tax_query'      => array( NH_Sections::type_clause( $section ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $q->posts ) {
			$p    = $q->posts[0];
			$new  = ( time() - get_post_time( 'U', true, $p ) ) < WEEK_IN_SECONDS;
			$data = NH_Sections::get( $section );
			$sub  = array();
			if ( 'download' !== NH_Types::of_post( $p ) && 'video' !== NH_Types::of_post( $p ) ) {
				/* translators: %d: minutes */
				$sub[] = sprintf( __( '%d min read', 'neurohr-hub' ), NH_Toc::reading_minutes( $p ) );
			}
			$terms = $data && $data['taxonomy'] ? get_the_terms( $p, $data['taxonomy'] ) : array();
			if ( $terms && ! is_wp_error( $terms ) ) {
				$sub[] = $terms[0]->name;
			}
			$h .= '<a class="nh-appfeature" href="' . esc_url( get_permalink( $p ) ) . '"><span class="nh-appfeature__k">' . esc_html( $new ? __( 'New This Week', 'neurohr-hub' ) : __( 'Latest', 'neurohr-hub' ) ) . '</span><span class="nh-appfeature__t">' . esc_html( get_the_title( $p ) ) . '</span><span class="nh-appfeature__s">' . esc_html( implode( ' · ', $sub ) ) . '</span></a>';
		}
		return $h;
	}
}
