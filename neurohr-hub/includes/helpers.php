<?php
/**
 * Small template helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme header/footer that works with classic themes (header.php) and block themes.
 */
function nh_hub_header() {
	// Logged-in people: the member area layout of NeuroHR Members 0.3+.
	if ( function_exists( 'nhm_shell_active' ) && nhm_shell_active() ) {
		nhm_shell_document_open( 'div' );
		return;
	}
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php
		echo '<div class="nh-theme-header">';
		block_template_part( 'header' );
		echo '</div>';
		return;
	}
	get_header();
}

function nh_hub_footer() {
	if ( function_exists( 'nhm_shell_active' ) && nhm_shell_active() ) {
		nhm_shell_document_close();
		return;
	}
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		echo '<div class="nh-theme-footer">';
		block_template_part( 'footer' );
		echo '</div>';
		?>
</div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
		return;
	}
	get_footer();
}

/**
 * Level ID => name, in ladder order, with 0 = Public first (left out when the hub needs a login).
 * Names and order come from NeuroHR Members when it is active.
 */
function nh_hub_level_labels() {
	$labels = array( 0 => __( 'Public', 'neurohr-hub' ) );
	foreach ( NH_Access::levels() as $id => $level ) {
		$labels[ $id ] = $level['name'];
	}
	// Whole hub behind login: "Public" would mean the same as Free, so it is not offered.
	if ( class_exists( 'NH_Login' ) && NH_Login::required() ) {
		unset( $labels[0] );
	}
	return $labels;
}

function nh_hub_level_label( $level ) {
	$labels = nh_hub_level_labels();
	return isset( $labels[ $level ] ) ? $labels[ $level ] : '';
}

/**
 * First Resource Type term of a post, or null.
 */
function nh_hub_get_type( $post ) {
	$terms = get_the_terms( $post, NH_Post_Types::TAX_TYPE );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return null;
	}
	return $terms[0];
}

/**
 * Plain-text excerpt that never runs the_content (safe inside the access gate).
 */
function nh_hub_excerpt( $post, $words = 30 ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$text = has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );
	return wp_trim_words( wp_strip_all_tags( $text ), $words );
}

function nh_hub_type_badge( $post ) {
	$type = nh_hub_get_type( $post );
	if ( ! $type ) {
		return '';
	}
	return sprintf(
		'<span class="nh-badge nh-badge--%1$s">%2$s</span>',
		esc_attr( $type->slug ),
		esc_html( $type->name )
	);
}

function nh_hub_lock_badge( $post ) {
	if ( NH_Access::can_view( $post ) ) {
		return '';
	}
	return sprintf(
		'<span class="nh-lock" title="%1$s">&#128274; %2$s</span>',
		esc_attr__( 'Members only', 'neurohr-hub' ),
		esc_html( nh_hub_level_label( NH_Access::get_post_level( $post ) ) )
	);
}

function nh_hub_render_card( $post ) {
	$post = get_post( $post );
	?>
	<article class="nh-card">
		<div class="nh-card__meta">
			<?php echo nh_hub_type_badge( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
			<?php echo nh_hub_lock_badge( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
			<?php echo NH_Review::badge( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
		</div>
		<h2 class="nh-card__title"><a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></h2>
		<p class="nh-card__excerpt"><?php echo esc_html( nh_hub_excerpt( $post, 24 ) ); ?></p>
	</article>
	<?php
}

/**
 * One row of a list page: title, short excerpt, and on the right the topic or section
 * (when $show_meta) and a lock for members who cannot open it.
 */
function nh_hub_render_row( $post, $show_meta = false ) {
	$post    = get_post( $post );
	$section = NH_Sections::of_post( $post );
	$meta    = '';
	if ( $show_meta ) {
		$data  = NH_Sections::get( $section );
		$terms = $data['taxonomy'] ? get_the_terms( $post, $data['taxonomy'] ) : array();
		$meta  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : $data['label'];
	}
	$locked = is_user_logged_in() && ! NH_Access::can_view( $post );
	?>
	<a class="nh-row" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
		<span class="nh-row__main">
			<span class="nh-row__title"><?php echo esc_html( get_the_title( $post ) ); ?> <?php echo NH_Review::badge( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span>
			<span class="nh-row__excerpt"><?php echo esc_html( nh_hub_excerpt( $post, 26 ) ); ?></span>
		</span>
		<span class="nh-row__side">
			<?php if ( $meta ) : ?>
				<span class="nh-row__meta"><?php echo esc_html( $meta ); ?></span>
			<?php endif; ?>
			<?php if ( $locked ) : ?>
				<span class="nh-row__lock">&#128274; <?php echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $post ) ) ); ?></span>
			<?php endif; ?>
			<svg class="nh-row__arrow" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg>
		</span>
	</a>
	<?php
}

/**
 * FAQ row: answer shown inline when the user has access, teaser + link otherwise.
 */
function nh_hub_render_faq( $post ) {
	$post = get_post( $post );
	?>
	<details class="nh-faq">
		<summary>
			<span><?php echo esc_html( get_the_title( $post ) ); ?></span>
			<?php echo nh_hub_lock_badge( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
		</summary>
		<div class="nh-faq__answer">
			<?php
			if ( NH_Access::can_view( $post ) ) {
				echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
			} else {
				echo NH_Access::locked_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
			}
			?>
		</div>
	</details>
	<?php
}
