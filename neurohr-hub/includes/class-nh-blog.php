<?php
/**
 * Same look for ordinary WordPress posts (HR News & Update), so news pages match the hub:
 * single posts get the article layout (breadcrumb, reading time, table of contents),
 * the posts page and category/tag pages get the hub list design.
 * Settings → General → "Use the hub design for news posts" (on by default).
 * Posts are not part of the membership levels; who may read them is up to the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Blog {

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 20 );
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

	public static function template_include( $template ) {
		if ( ! self::is_styled() ) {
			return $template;
		}
		return NH_Frontend::locate( is_singular( 'post' ) ? 'single-post.php' : 'post-list.php', $template );
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

	public static function render_row( $post ) {
		$post = get_post( $post );
		?>
		<a class="nh-row" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
			<span class="nh-row__main">
				<span class="nh-row__title"><?php echo esc_html( get_the_title( $post ) ); ?></span>
				<span class="nh-row__excerpt"><?php echo esc_html( nh_hub_excerpt( $post, 26 ) ); ?></span>
			</span>
			<span class="nh-row__side">
				<span class="nh-row__meta"><?php echo esc_html( get_the_date( '', $post ) ); ?></span>
				<svg class="nh-row__arrow" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg>
			</span>
		</a>
		<?php
	}
}
