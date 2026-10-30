<?php
/**
 * A WordPress post (HR News & Update) in the hub article layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nh_hub_header();

while ( have_posts() ) :
	the_post();
	$nh_post     = get_post();
	$nh_cats     = get_the_category();
	$nh_html     = str_replace( ']]>', ']]&gt;', apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
	$nh_toc      = NH_Toc::process( $nh_html );
	$nh_html     = $nh_toc['html'];
	$nh_toc_html = NH_Toc::render( $nh_toc['headings'] );
	?>
	<main id="primary" class="nh-hub nh-single nh-single--post">
		<div class="nh-wrap">
			<div class="nh-single__layout <?php echo $nh_toc_html ? 'has-toc' : ''; ?>">
				<div class="nh-single__main">
					<nav class="nh-breadcrumb nh-breadcrumb--pill" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
						<span aria-hidden="true">/</span>
						<a href="<?php echo esc_url( NH_Blog::news_url( $nh_post ) ); ?>"><?php echo esc_html( NH_Blog::label() ); ?></a>
						<?php if ( $nh_cats && (int) get_option( 'page_for_posts' ) ) : ?>
							<span aria-hidden="true">/</span>
							<a href="<?php echo esc_url( get_category_link( $nh_cats[0] ) ); ?>"><?php echo esc_html( $nh_cats[0]->name ); ?></a>
						<?php endif; ?>
						<span aria-hidden="true">/</span>
						<span class="nh-breadcrumb__current" aria-current="page"><?php the_title(); ?></span>
					</nav>

					<article <?php post_class( 'nh-article' ); ?>>
						<header class="nh-article__header">
							<h1 class="nh-article__title"><?php the_title(); ?></h1>
							<div class="nh-article__meta">
								<span class="nh-pill">
									<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
									<?php
									/* translators: %d: minutes */
									echo esc_html( sprintf( _n( '%d min read', '%d min read', NH_Toc::reading_minutes( $nh_post ), 'neurohr-hub' ), NH_Toc::reading_minutes( $nh_post ) ) );
									?>
								</span>
								<?php if ( $nh_cats ) : ?>
									<span class="nh-badge"><?php echo esc_html( $nh_cats[0]->name ); ?></span>
								<?php endif; ?>
								<span class="nh-article__updated"><?php echo esc_html( get_the_date() ); ?></span>
							</div>
						</header>

						<?php if ( $nh_toc_html ) : ?>
							<details class="nh-toc nh-toc--inline">
								<summary><?php esc_html_e( 'Table of contents', 'neurohr-hub' ); ?></summary>
								<?php echo $nh_toc_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in NH_Toc::render. ?>
							</details>
						<?php endif; ?>

						<?php if ( has_post_thumbnail() ) : ?>
							<figure class="nh-article__image"><?php the_post_thumbnail( 'large' ); ?></figure>
						<?php endif; ?>

						<div class="nh-article__content">
							<?php echo $nh_html; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output. ?>
						</div>

						<?php $nh_tags = get_the_tags(); ?>
						<?php if ( $nh_tags ) : ?>
							<p class="nh-tags">
								<?php foreach ( $nh_tags as $nh_tag ) : ?>
									<a href="<?php echo esc_url( get_tag_link( $nh_tag ) ); ?>">#<?php echo esc_html( $nh_tag->name ); ?></a>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</article>
				</div>

				<?php if ( $nh_toc_html ) : ?>
					<aside class="nh-single__side">
						<nav class="nh-toc nh-toc--side" aria-label="<?php esc_attr_e( 'Table of contents', 'neurohr-hub' ); ?>">
							<p class="nh-toc__title"><?php esc_html_e( 'Table of contents', 'neurohr-hub' ); ?></p>
							<?php echo $nh_toc_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in NH_Toc::render. ?>
						</nav>
					</aside>
				<?php endif; ?>
			</div>

			<?php
			$nh_more = get_posts(
				array(
					'post_type'      => 'post',
					'posts_per_page' => 4,
					'post__not_in'   => array( $nh_post->ID ),
					'category__in'   => $nh_cats ? wp_list_pluck( $nh_cats, 'term_id' ) : array(),
				)
			);
			?>
			<?php if ( $nh_more ) : ?>
				<section class="nh-related">
					<h2 class="nh-section__title"><?php esc_html_e( 'More news', 'neurohr-hub' ); ?></h2>
					<div class="nh-panel nh-rows">
						<?php foreach ( $nh_more as $nh_item ) : ?>
							<?php NH_Blog::render_row( $nh_item ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</main>
	<?php
	if ( $nh_toc_html ) {
		NH_Toc::print_script();
	}
endwhile;

nh_hub_footer();
