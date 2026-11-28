<?php
/**
 * HR News & Updates: ordinary WordPress posts shown as a section of the hub (0.16).
 * Posts stay public posts (same addresses, Google, RSS for MailerLite); the hub gives them
 * its design: a news home (title, search, newest item, category cards, latest list),
 * category / tag / search lists, and the hub article page. Members can save and search them.
 * Settings → Look → News posts (on by default).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Blog {

	const NEWER = '_nh_newer'; // A newer post on the same subject (shown on top of this one).

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_action( 'template_redirect', array( __CLASS__, 'old_category_redirect' ), 1 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_boxes' ) );
			add_action( 'save_post_post', array( 'NH_Related', 'save' ), 10, 2 );
			add_action( 'save_post_post', array( __CLASS__, 'save_newer' ), 10, 2 );
		}
	}

	public static function enabled() {
		return (bool) NH_Display::get( 'style_posts' );
	}

	/** News pages: a single post, the posts page, or a post category/tag page. Never the site front page. */
	public static function is_styled() {
		if ( ! self::enabled() || is_feed() || is_front_page() || NH_Frontend::is_hub() ) {
			return false;
		}
		return is_singular( 'post' ) || is_home() || is_category() || is_tag();
	}

	/** The first page of the posts page, without a search: the news home. */
	public static function is_home_page() {
		return self::is_styled() && is_home() && ! is_paged() && '' === self::keyword();
	}

	public static function keyword() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		return isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	}

	public static function template_include( $template ) {
		if ( ! self::is_styled() ) {
			return $template;
		}
		return NH_Frontend::locate( is_singular( 'post' ) ? 'single-post.php' : 'post-list.php', $template );
	}

	/** ?q= on the news pages searches the news. */
	public static function pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! self::enabled() ) {
			return;
		}
		if ( ( $query->is_home() || $query->is_category() || $query->is_tag() ) && '' !== self::keyword() ) {
			$query->set( 's', self::keyword() );
			$query->set( 'post_type', 'post' );
		}
	}

	/** Address of the news list: the WordPress "Posts page", or the first category of the post. */
	public static function news_url( $post = null ) {
		$page = (int) get_option( 'page_for_posts' );
		if ( $page ) {
			return get_permalink( $page );
		}
		if ( $post ) {
			$cats = get_the_category( $post->ID );
			if ( $cats ) {
				return get_category_link( $cats[0] );
			}
		}
		return home_url( '/' );
	}

	public static function label() {
		return (string) NH_Display::get( 'news_label' );
	}

	public static function intro() {
		return (string) NH_Display::get( 'news_intro' );
	}

	/** Categories that have news, in the suggested order (others after, by name). */
	public static function categories() {
		$order = array( 'employment-laws', 'payroll-statutory', 'public-holidays', 'workplace-safety-health', 'budget-policy', 'court-decisions', 'hr-trends', 'neurohr-updates' );
		$cats  = get_categories( array( 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_category' ) ) ) );
		usort(
			$cats,
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a->slug, $order, true );
				$ib = array_search( $b->slug, $order, true );
				$ia = false === $ia ? 99 : $ia;
				$ib = false === $ib ? 99 : $ib;
				return $ia === $ib ? strcmp( $a->name, $b->name ) : $ia - $ib;
			}
		);
		return $cats;
	}

	/** A small picture for each category, chosen from its address. */
	public static function category_icon( $cat ) {
		$slug  = is_object( $cat ) ? $cat->slug : (string) $cat;
		$paths = array(
			'law'      => '<path d="M12 3v18M5 7h14M7 7l-3 7h6zM17 7l-3 7h6z"/>',
			'payroll'  => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'holiday'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 14l1 2 2 .3-1.5 1.4.4 2.1-1.9-1-1.9 1 .4-2.1L9 16.3l2-.3z"/>',
			'safety'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
			'budget'   => '<path d="M3 21h18M5 21V10M10 21V10M14 21V10M19 21V10M3 10l9-6 9 6"/>',
			'court'    => '<path d="M14 4l6 6M12 6l6 6M4 20l7-7M9 9l6 6"/>',
			'trends'   => '<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
			'neurohr'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>',
			'default'  => '<path d="M4 4h12a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3z"/><path d="M8 9h7M8 13h5"/>',
		);
		$key = 'default';
		foreach ( array( 'holiday' => 'holiday', 'payroll' => 'payroll', 'statutory' => 'payroll', 'safety' => 'safety', 'budget' => 'budget', 'policy' => 'budget', 'court' => 'court', 'trend' => 'trends', 'neurohr' => 'neurohr', 'law' => 'law' ) as $needle => $icon ) {
			if ( false !== strpos( $slug, $needle ) ) {
				$key = $icon;
				break;
			}
		}
		return '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $key ] . '</svg>';
	}

	/** Search box of the news (live results while typing, public). */
	public static function search_box( $value = '' ) {
		NH_Search::enqueue();
		$uid = 'nh-s-' . wp_unique_id();
		/* translators: %s: section name */
		$placeholder = sprintf( __( 'Search %s', 'neurohr-hub' ), self::label() );
		return '<form class="nh-searchbox" method="get" action="' . esc_url( self::news_url() ) . '" role="search" data-section="news" data-topic="0">'
			. '<svg class="nh-searchbox__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
			. '<input type="search" name="q" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '…" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . esc_attr( $uid ) . '" aria-label="' . esc_attr( $placeholder ) . '">'
			. '<button type="submit" class="nh-searchbox__btn">' . esc_html__( 'Search', 'neurohr-hub' ) . '</button>'
			. '<div class="nh-live" id="' . esc_attr( $uid ) . '" role="listbox" hidden></div></form>';
	}

	/** Live search results for the news (used by NH_Search for section "news" and "all"). */
	public static function search_items( $q, $limit ) {
		$query = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => $limit ) );
		$items = array();
		foreach ( $query->posts as $p ) {
			$cats    = get_the_category( $p->ID );
			$items[] = array(
				'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'    => get_permalink( $p ),
				'meta'   => html_entity_decode( self::label() . ( $cats ? ' · ' . $cats[0]->name : '' ), ENT_QUOTES, 'UTF-8' ),
				'locked' => '',
			);
		}
		return array( $items, (int) $query->found_posts );
	}

	public static function render_row( $post, $first = false ) {
		$post = get_post( $post );
		$cats = get_the_category( $post->ID );
		?>
		<a class="nh-row nh-row--news<?php echo $first ? ' is-first' : ''; ?>" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
			<span class="nh-row__main">
				<span class="nh-row__title"><?php echo esc_html( get_the_title( $post ) ); ?></span>
				<span class="nh-row__excerpt"><?php echo esc_html( nh_hub_excerpt( $post, 26 ) ); ?></span>
				<span class="nh-row__sub"><?php echo esc_html( $cats ? $cats[0]->name : self::label() ); ?></span>
			</span>
			<span class="nh-row__date"><?php echo esc_html( get_the_date( 'j M', $post ) ); ?></span>
			<span class="nh-row__side">
				<span class="nh-row__meta"><?php echo esc_html( get_the_date( 'j M Y', $post ) ); ?></span>
				<svg class="nh-row__arrow" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg>
			</span>
		</a>
		<?php
	}

	/* ---------- A newer post on the same subject ---------- */

	public static function newer( $post_id ) {
		$id = (int) get_post_meta( $post_id, self::NEWER, true );
		return ( $id && $id !== (int) $post_id && 'publish' === get_post_status( $id ) && 'post' === get_post_type( $id ) ) ? $id : 0;
	}

	public static function newer_notice( $post_id ) {
		$id = self::newer( $post_id );
		if ( ! $id ) {
			return '';
		}
		return '<a class="nh-newer" href="' . esc_url( get_permalink( $id ) ) . '"><span class="nh-newer__k">' . esc_html__( 'There is a newer update on this', 'neurohr-hub' ) . '</span><span class="nh-newer__t">' . esc_html( get_the_title( $id ) ) . ' <span aria-hidden="true">→</span></span></a>';
	}

	/* ---------- Editor: hub references and "newer update" on posts ---------- */

	public static function meta_boxes() {
		add_meta_box( 'nh_related', __( 'Related guides and templates (Knowledge Hub)', 'neurohr-hub' ), array( 'NH_Related', 'render_meta_box' ), 'post', 'normal', 'default' );
		add_meta_box( 'nh_newer', __( 'Newer update', 'neurohr-hub' ), array( __CLASS__, 'render_newer_box' ), 'post', 'side', 'default' );
	}

	public static function render_newer_box( $post ) {
		wp_nonce_field( 'nh_newer_save', 'nh_newer_nonce' );
		$current = (int) get_post_meta( $post->ID, self::NEWER, true );
		$posts   = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 100, 'exclude' => array( $post->ID ) ) );
		echo '<p class="description">' . esc_html__( 'If a later post replaces this one, pick it: readers see "There is a newer update on this" at the top.', 'neurohr-hub' ) . '</p>';
		echo '<select name="nh_newer" style="width:100%"><option value="0">' . esc_html__( '— None —', 'neurohr-hub' ) . '</option>';
		foreach ( $posts as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . selected( $current, $p->ID, false ) . '>' . esc_html( get_the_date( 'Y-m-d', $p ) . ' · ' . get_the_title( $p ) ) . '</option>';
		}
		echo '</select>';
	}

	public static function save_newer( $post_id ) {
		if ( ! isset( $_POST['nh_newer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nh_newer_nonce'] ) ), 'nh_newer_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$id = isset( $_POST['nh_newer'] ) ? absint( $_POST['nh_newer'] ) : 0;
		if ( $id && $id !== (int) $post_id ) {
			update_post_meta( $post_id, self::NEWER, $id );
		} else {
			delete_post_meta( $post_id, self::NEWER );
		}
	}

	/* ---------- Removed categories keep working ---------- */

	/** Addresses of categories removed by the News Categories tool go to the news home (301). */
	public static function old_category_redirect() {
		if ( ! is_404() ) {
			return;
		}
		$gone = (array) get_option( 'nh_news_old_categories', array() );
		if ( ! $gone ) {
			return;
		}
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = trim( (string) get_option( 'category_base' ), '/' );
		$base = '' !== $base ? $base : 'category';
		foreach ( $gone as $slug ) {
			if ( preg_match( '#(^|/)' . preg_quote( $base . '/' . $slug, '#' ) . '(/|$)#', $path ) ) {
				wp_safe_redirect( self::news_url(), 301 );
				exit;
			}
		}
	}
}
