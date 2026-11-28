<?php
/**
 * HR News & Updates (WordPress posts) as a hub section.
 * - News home (posts page, first page, no search): title, search, newest item (phones),
 *   category cards with their latest news, then the latest news as a list.
 * - Category / tag / search / later pages: the hub list design.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nh_label   = NH_Blog::label();
$nh_keyword = NH_Blog::keyword();
$nh_is_home = NH_Blog::is_home_page();
$nh_term    = ( is_category() || is_tag() ) ? get_queried_object() : null;

nh_hub_header();

if ( $nh_is_home ) :
	?>
	<main id="primary" class="nh-hub nh-section-home nh-section--news">
		<div class="nh-wrap">
			<section class="nh-hero">
				<div class="nh-hero__text">
					<h1 class="nh-hero__title"><?php echo esc_html( $nh_label ); ?></h1>
					<?php if ( NH_Blog::intro() ) : ?>
						<p class="nh-hero__intro"><?php echo esc_html( NH_Blog::intro() ); ?></p>
					<?php endif; ?>
				</div>
			</section>
			<div class="nh-section-home__search"><?php echo NH_Blog::search_box(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></div>
			<?php echo NH_App::phone_extras( 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. Shown on phones by NeuroHR App. ?>

			<?php $nh_cats = NH_Blog::categories(); ?>
			<?php if ( $nh_cats ) : ?>
				<div class="nh-topics-grid nh-topics-grid--news">
					<?php foreach ( $nh_cats as $nh_cat ) : ?>
						<?php $nh_items = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 3, 'cat' => $nh_cat->term_id ) ); ?>
						<section class="nh-topic-card">
							<header class="nh-topic-card__head">
								<span class="nh-topic-card__icon"><?php echo NH_Blog::category_icon( $nh_cat ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?></span>
								<h2 class="nh-topic-card__title"><a href="<?php echo esc_url( get_category_link( $nh_cat ) ); ?>"><?php echo esc_html( $nh_cat->name ); ?></a></h2>
								<span class="nh-topic-card__count"><?php echo (int) $nh_cat->count; ?></span>
							</header>
							<?php if ( $nh_cat->description ) : ?>
								<p class="nh-topic-card__desc"><?php echo esc_html( wp_strip_all_tags( $nh_cat->description ) ); ?></p>
							<?php endif; ?>
							<ul class="nh-topic-card__list">
								<?php foreach ( $nh_items as $nh_item ) : ?>
									<li><a href="<?php echo esc_url( get_permalink( $nh_item ) ); ?>"><?php echo esc_html( get_the_title( $nh_item ) ); ?></a></li>
								<?php endforeach; ?>
							</ul>
							<a class="nh-topic-card__more" href="<?php echo esc_url( get_category_link( $nh_cat ) ); ?>"><?php esc_html_e( 'See all', 'neurohr-hub' ); ?> ›</a>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( have_posts() ) : ?>
				<section class="nh-news-latest">
					<h2 class="nh-section__title"><?php esc_html_e( 'Latest News', 'neurohr-hub' ); ?></h2>
					<div class="nh-panel nh-rows">
						<?php
						$nh_first = true;
						while ( have_posts() ) :
							the_post();
							NH_Blog::render_row( get_post(), $nh_first );
							$nh_first = false;
						endwhile;
						?>
					</div>
					<?php
					the_posts_pagination(
						array(
							'class'     => 'nh-pagination',
							'prev_text' => __( 'Previous', 'neurohr-hub' ),
							'next_text' => __( 'Older News', 'neurohr-hub' ),
						)
					);
					?>
				</section>
			<?php endif; ?>
		</div>
	</main>
	<?php
else :
	if ( $nh_term ) {
		$nh_title = $nh_term->name;
		$nh_desc  = wp_strip_all_tags( (string) $nh_term->description );
		/* translators: %d: number of news posts */
		$nh_meta = sprintf( _n( '%d news', '%d news', (int) $nh_term->count, 'neurohr-hub' ), (int) $nh_term->count );
	} elseif ( '' !== $nh_keyword ) {
		/* translators: %s: search words */
		$nh_title = sprintf( __( 'Results for "%s"', 'neurohr-hub' ), $nh_keyword );
		$nh_desc  = '';
		/* translators: %d: number of results */
		$nh_meta = sprintf( _n( '%d result', '%d results', (int) $GLOBALS['wp_query']->found_posts, 'neurohr-hub' ), (int) $GLOBALS['wp_query']->found_posts );
	} else {
		$nh_title = $nh_label;
		$nh_desc  = '';
		$nh_meta  = '';
	}
	?>
	<main id="primary" class="nh-hub nh-section-list nh-section--news<?php echo '' !== $nh_keyword ? ' has-keyword' : ''; ?>">
		<div class="nh-wrap nh-wrap--list">
			<nav class="nh-breadcrumb nh-breadcrumb--quiet" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
				<span aria-hidden="true">›</span>
				<a href="<?php echo esc_url( NH_Blog::news_url() ); ?>"><?php echo esc_html( $nh_label ); ?></a>
			</nav>

			<header class="nh-list-head">
				<span class="nh-list-head__icon"><?php echo NH_Blog::category_icon( $nh_term ? $nh_term : 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG. ?></span>
				<div>
					<h1 class="nh-list-head__title"><?php echo esc_html( $nh_title ); ?></h1>
					<?php if ( $nh_meta ) : ?>
						<p class="nh-list-head__meta"><?php echo esc_html( $nh_meta ); ?></p>
					<?php endif; ?>
					<?php if ( $nh_desc ) : ?>
						<p class="nh-list-head__desc"><?php echo esc_html( $nh_desc ); ?></p>
					<?php endif; ?>
				</div>
			</header>

			<div class="nh-list-search"><?php echo NH_Blog::search_box( $nh_keyword ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></div>

			<?php if ( have_posts() ) : ?>
				<div class="nh-panel nh-rows">
					<?php
					$nh_first = true;
					while ( have_posts() ) :
						the_post();
						NH_Blog::render_row( get_post(), $nh_first );
						$nh_first = false;
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
				<div class="nh-panel nh-empty-state">
					<p class="nh-empty-state__title"><?php echo '' !== $nh_keyword ? esc_html__( 'No news found', 'neurohr-hub' ) : esc_html__( 'No news yet', 'neurohr-hub' ); ?></p>
					<a class="nh-btn" href="<?php echo esc_url( NH_Blog::news_url() ); ?>"><?php echo esc_html( sprintf( /* translators: %s: section name */ __( 'Back to %s', 'neurohr-hub' ), $nh_label ) ); ?></a>
				</div>
			<?php endif; ?>
		</div>
	</main>
	<?php
endif;

nh_hub_footer();
