<?php
/**
 * A news post (HR News & Updates) in the hub article design (0.16): public, with the date
 * up front, "In This Guide", Save / Share, related hub guides and templates, and an
 * invitation to sign up for visitors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nh_hub_header();

while ( have_posts() ) :
	the_post();
	$nh_post     = get_post();
	$nh_cats     = get_the_category();
	$nh_cat      = $nh_cats ? $nh_cats[0] : null;
	$nh_html     = str_replace( ']]>', ']]&gt;', apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
	$nh_toc      = NH_Toc::process( $nh_html );
	$nh_html     = $nh_toc['html'];
	$nh_toc_html = NH_Toc::render( $nh_toc['headings'] );
	$nh_tpl      = NH_App::side_template( $nh_post->ID );
	$nh_side     = $nh_toc_html || $nh_tpl;
	?>
	<main id="primary" class="nh-hub nh-single nh-single--post">
		<div class="nh-wrap">
			<div class="nh-single__layout <?php echo $nh_side ? 'has-toc' : ''; ?>">
				<div class="nh-single__main">
					<nav class="nh-breadcrumb nh-breadcrumb--quiet" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
						<span aria-hidden="true">›</span>
						<a href="<?php echo esc_url( NH_Blog::news_url( $nh_post ) ); ?>"><?php echo esc_html( NH_Blog::label() ); ?></a>
						<?php if ( $nh_cat && (int) get_option( 'page_for_posts' ) ) : ?>
							<span aria-hidden="true">›</span>
							<a href="<?php echo esc_url( get_category_link( $nh_cat ) ); ?>"><?php echo esc_html( $nh_cat->name ); ?></a>
						<?php endif; ?>
					</nav>

					<article <?php post_class( 'nh-article' ); ?>>
						<?php echo NH_Blog::newer_notice( $nh_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
						<header class="nh-article__header">
							<?php if ( $nh_cat ) : ?>
								<p class="nh-chiprow"><a class="nh-topicchip nh-apptopic" href="<?php echo esc_url( get_category_link( $nh_cat ) ); ?>"><?php echo esc_html( $nh_cat->name ); ?></a></p>
							<?php endif; ?>
							<h1 class="nh-article__title"><?php the_title(); ?></h1>
							<div class="nh-article__meta">
								<span class="nh-article__info">
									<span class="nh-article__date"><?php echo esc_html( get_the_date( 'j M Y' ) ); ?></span>
									<i class="nh-dot" aria-hidden="true"></i>
									<span>
										<?php
										/* translators: %d: minutes */
										echo esc_html( sprintf( _n( '%d min read', '%d min read', NH_Toc::reading_minutes( $nh_post ), 'neurohr-hub' ), NH_Toc::reading_minutes( $nh_post ) ) );
										?>
									</span>
								</span>
								<span class="nh-article__tools"><?php echo NH_App::actions( $nh_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span>
							</div>
						</header>

						<?php if ( $nh_toc_html ) : ?>
							<details class="nh-toc nh-toc--inline">
								<summary><span><?php esc_html_e( 'In This Guide', 'neurohr-hub' ); ?></span><span class="nh-toc__pct" data-nh-pct></span></summary>
								<div class="nh-toc__bar" aria-hidden="true"><i data-nh-bar></i></div>
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

						<?php if ( ! is_user_logged_in() ) : ?>
							<div class="nh-sample-cta nh-news-cta">
								<p class="nh-sample-cta__title"><?php esc_html_e( 'Turn the news into action', 'neurohr-hub' ); ?></p>
								<p><?php esc_html_e( 'NeuroHR members get step-by-step HR guides, legal case insights and ready-to-use templates for Malaysian employers. Start with a free account.', 'neurohr-hub' ); ?></p>
								<div class="nh-locked__actions">
									<a class="nh-btn nh-btn--primary" href="<?php echo esc_url( NH_Access::upgrade_url( NH_Access::base_level() ) ); ?>"><?php esc_html_e( 'Create a free account', 'neurohr-hub' ); ?></a>
									<a class="nh-btn" href="<?php echo esc_url( NH_Login::login_url( get_permalink( $nh_post ) ) ); ?>"><?php esc_html_e( 'Log in', 'neurohr-hub' ); ?></a>
								</div>
							</div>
						<?php endif; ?>
					</article>
				</div>

				<?php if ( $nh_side ) : ?>
					<aside class="nh-single__side">
						<div class="nh-single__sticky">
							<?php if ( $nh_toc_html ) : ?>
								<nav class="nh-toc nh-toc--side" aria-label="<?php esc_attr_e( 'Table of contents', 'neurohr-hub' ); ?>">
									<p class="nh-toc__title"><?php esc_html_e( 'In This Guide', 'neurohr-hub' ); ?> <span class="nh-toc__pct" data-nh-pct></span></p>
									<div class="nh-toc__bar" aria-hidden="true"><i data-nh-bar></i></div>
									<?php echo $nh_toc_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in NH_Toc::render. ?>
								</nav>
							<?php endif; ?>
							<?php echo $nh_tpl; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
						</div>
					</aside>
				<?php endif; ?>
			</div>

			<?php echo NH_Related::render_sections( $nh_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. Hub guides and templates picked for this post. ?>

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
				<section class="nh-related nh-related--news">
					<h2 class="nh-section__title"><?php esc_html_e( 'More News', 'neurohr-hub' ); ?></h2>
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
	echo NH_App::template_bar( $nh_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
	echo '<div class="nh-readbar" aria-hidden="true"><i data-nh-read></i></div>';
	echo '<button type="button" class="nh-totop" data-nh-totop hidden aria-label="' . esc_attr__( 'Back to top', 'neurohr-hub' ) . '"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>';
	if ( $nh_toc_html ) {
		NH_Toc::print_script();
	}
endwhile;

nh_hub_footer();
