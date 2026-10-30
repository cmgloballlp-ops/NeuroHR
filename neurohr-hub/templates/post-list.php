<?php
/**
 * News list (the WordPress posts page, or a post category/tag) in the hub list design.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nh_label = NH_Blog::label();
if ( is_category() || is_tag() ) {
	$nh_title = single_term_title( '', false );
	$nh_desc  = term_description();
} else {
	$nh_title = $nh_label;
	$nh_desc  = '';
}

nh_hub_header();
?>
<main id="primary" class="nh-hub nh-section-list nh-section--news">
	<div class="nh-wrap nh-wrap--list">
		<nav class="nh-breadcrumb nh-breadcrumb--pill" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
			<?php if ( is_category() || is_tag() ) : ?>
				<span aria-hidden="true">/</span>
				<a href="<?php echo esc_url( NH_Blog::news_url() ); ?>"><?php echo esc_html( $nh_label ); ?></a>
			<?php endif; ?>
			<span aria-hidden="true">/</span>
			<span class="nh-breadcrumb__current" aria-current="page"><?php echo esc_html( $nh_title ); ?></span>
		</nav>

		<header class="nh-list-head">
			<div>
				<h1 class="nh-list-head__title"><?php echo esc_html( $nh_title ); ?></h1>
				<?php if ( $nh_desc ) : ?>
					<div class="nh-list-head__desc"><?php echo wp_kses_post( $nh_desc ); ?></div>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( have_posts() ) : ?>
			<div class="nh-panel nh-rows">
				<?php
				while ( have_posts() ) :
					the_post();
					NH_Blog::render_row( get_post() );
				endwhile;
				?>
			</div>
			<?php
			the_posts_pagination(
				array(
					'class'     => 'nh-pagination',
					'prev_text' => __( 'Previous', 'neurohr-hub' ),
					'next_text' => __( 'Next', 'neurohr-hub' ),
				)
			);
			?>
		<?php else : ?>
			<div class="nh-panel nh-empty-state"><p class="nh-empty-state__title"><?php esc_html_e( 'No news yet', 'neurohr-hub' ); ?></p></div>
		<?php endif; ?>
	</div>
</main>
<?php
nh_hub_footer();
