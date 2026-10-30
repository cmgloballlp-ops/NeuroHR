<?php
/**
 * Single resource: breadcrumb, title, reading time, content, table of contents
 * (sticky sidebar on desktop, collapsible on mobile), attachments, tags, related.
 * Content is gated by NH_Access via the_content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nh_hub_header();

while ( have_posts() ) :
	the_post();
	$nh_post   = get_post();
	$nh_type    = nh_hub_get_type( $nh_post );
	$nh_section = NH_Sections::of_post( $nh_post );
	$nh_sdata   = NH_Sections::get( $nh_section );
	$nh_topics  = $nh_sdata['taxonomy'] ? get_the_terms( $nh_post, $nh_sdata['taxonomy'] ) : array();
	$nh_topics  = ( empty( $nh_topics ) || is_wp_error( $nh_topics ) ) ? array() : $nh_topics;
	$nh_topic  = $nh_topics ? $nh_topics[0] : null;
	$nh_can    = NH_Access::can_view( $nh_post );
	$nh_is_faq = $nh_type && 'faq' === $nh_type->slug;
	$nh_print  = NH_Print::applies( $nh_post );

	// Render the content once; add heading anchors when it is visible.
	$nh_html = str_replace( ']]>', ']]&gt;', apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
	if ( $nh_can ) {
		$nh_toc      = NH_Toc::process( $nh_html );
		$nh_html     = $nh_toc['html'];
		$nh_headings = $nh_toc['headings'];
	} else {
		// Locked: list the headings of the full text (no answers) as a teaser.
		$nh_headings = NH_Toc::headings_from_post( $nh_post );
	}
	$nh_toc_html = $nh_is_faq ? '' : NH_Toc::render( $nh_headings, ! $nh_can );
	?>
	<main id="primary" class="nh-hub nh-single">
		<div class="nh-wrap">
			<div class="nh-single__layout <?php echo $nh_toc_html ? 'has-toc' : ''; ?>">

				<div class="nh-single__main">
					<nav class="nh-breadcrumb nh-breadcrumb--pill" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
						<span aria-hidden="true">/</span>
						<a href="<?php echo esc_url( NH_Rewrite::url( $nh_section ) ); ?>"><?php echo esc_html( $nh_sdata['label'] ); ?></a>
						<?php if ( $nh_topic ) : ?>
							<span aria-hidden="true">/</span>
							<a href="<?php echo esc_url( NH_Rewrite::url( $nh_section, $nh_topic->slug ) ); ?>"><?php echo esc_html( $nh_topic->name ); ?></a>
						<?php endif; ?>
						<span aria-hidden="true">/</span>
						<span class="nh-breadcrumb__current" aria-current="page"><?php the_title(); ?></span>
					</nav>

					<article <?php post_class( 'nh-article' ); ?>>
						<?php echo $nh_print ? NH_Print::head_html( $nh_post ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
						<header class="nh-article__header">
							<h1 class="nh-article__title"><?php the_title(); ?></h1>
							<div class="nh-article__meta">
								<?php if ( ! $nh_is_faq && ! ( $nh_type && in_array( $nh_type->slug, array( 'video', 'download' ), true ) ) ) : ?>
									<span class="nh-pill">
										<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
										<?php
										/* translators: %d: minutes */
										echo esc_html( sprintf( _n( '%d min read', '%d min read', NH_Toc::reading_minutes( $nh_post ), 'neurohr-hub' ), NH_Toc::reading_minutes( $nh_post ) ) );
										?>
									</span>
								<?php endif; ?>
								<?php echo nh_hub_type_badge( $nh_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
								<?php echo nh_hub_lock_badge( $nh_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
								<?php echo NH_Review::badge( $nh_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
								<span class="nh-article__updated"><?php echo esc_html( NH_Review::label( $nh_post ) ); ?></span>
								<?php echo $nh_print ? NH_Print::button() : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
							</div>
						</header>

						<?php if ( $nh_toc_html ) : ?>
							<details class="nh-toc nh-toc--inline">
								<summary><?php esc_html_e( 'Table of contents', 'neurohr-hub' ); ?></summary>
								<?php echo $nh_toc_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in NH_Toc::render. ?>
							</details>
						<?php endif; ?>

						<?php
						// Video player / download card (members without access get a locked placeholder).
						if ( $nh_type && 'video' === $nh_type->slug ) {
							echo NH_Media::render_video( $nh_post, $nh_can ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
						} elseif ( $nh_type && 'download' === $nh_type->slug ) {
							echo NH_Media::render_download( $nh_post, $nh_can ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
						}
						?>

						<div class="nh-article__content">
							<?php echo $nh_html; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output. ?>
						</div>
						<?php echo $nh_print ? NH_Print::notice_html( $nh_post ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>

						<?php $nh_attachments = $nh_can ? NH_Related::get_attachments( $nh_post->ID ) : array(); ?>
						<?php if ( $nh_attachments ) : ?>
							<div class="nh-attachments">
								<h2 class="nh-attachments__title"><?php esc_html_e( 'Attachments', 'neurohr-hub' ); ?></h2>
								<ul>
									<?php foreach ( $nh_attachments as $nh_file ) : ?>
										<li><a href="<?php echo esc_url( $nh_file['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $nh_file['title'] ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php $nh_tags = get_the_terms( $nh_post, NH_Post_Types::TAX_TAG ); ?>
						<?php if ( ! empty( $nh_tags ) && ! is_wp_error( $nh_tags ) ) : ?>
							<p class="nh-tags">
								<?php foreach ( $nh_tags as $nh_tag ) : ?>
									<a href="<?php echo esc_url( get_term_link( $nh_tag ) ); ?>">#<?php echo esc_html( $nh_tag->name ); ?></a>
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
							<?php if ( ! $nh_can ) : ?>
								<p class="nh-toc__locked"><a href="#nh-locked">&#128274; <?php esc_html_e( 'Unlock the full analysis', 'neurohr-hub' ); ?></a></p>
							<?php endif; ?>
						</nav>
					</aside>
				<?php endif; ?>

			</div>

			<?php $nh_related = NH_Related::get_ids( $nh_post->ID, 4 ); ?>
			<?php if ( $nh_related ) : ?>
				<section class="nh-related">
					<h2 class="nh-section__title"><?php esc_html_e( 'Related resources', 'neurohr-hub' ); ?></h2>
					<div class="nh-grid nh-grid--related">
						<?php foreach ( $nh_related as $nh_rel_id ) : ?>
							<?php nh_hub_render_card( $nh_rel_id ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</main>
	<?php
	if ( $nh_toc_html && $nh_can ) {
		NH_Toc::print_script();
	}
endwhile;

nh_hub_footer();
