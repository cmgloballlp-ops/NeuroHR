<?php
/**
 * Member area page (logged-in people). See NHM_Shell.
 * Copy to your-theme/neurohr-members/shell.php to change it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nhm_shell_document_open();

if ( is_singular() ) :
	while ( have_posts() ) :
		the_post();
		$nhm_elementor = NHM_Shell::built_with_elementor( get_the_ID() );
		// Pages that hold the plugin's own screens (My account, pricing …) bring their own cards.
		$nhm_app = ! $nhm_elementor && false !== strpos( (string) get_post_field( 'post_content', get_the_ID() ), '[nhm_' );
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( $nhm_elementor ? 'nhs-page nhs-page--builder' : 'nhs-page' ); ?>>
			<?php if ( ! $nhm_elementor ) : ?>
				<header class="nhs-head">
					<?php if ( 'post' === get_post_type() ) : ?>
						<p class="nhs-crumb"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
						<?php
						$nhm_cats = get_the_category();
						if ( $nhm_cats ) {
							echo ' · <a href="' . esc_url( get_category_link( $nhm_cats[0] ) ) . '">' . esc_html( $nhm_cats[0]->name ) . '</a>';
						}
						?>
						</p>
					<?php endif; ?>
					<h1 class="nhs-title"><?php the_title(); ?></h1>
				</header>
				<?php if ( 'post' === get_post_type() && has_post_thumbnail() ) : ?>
					<figure class="nhs-cover"><?php the_post_thumbnail( 'large' ); ?></figure>
				<?php endif; ?>
			<?php endif; ?>
			<div class="nhs-content<?php echo $nhm_elementor ? '' : ( $nhm_app ? ' nhs-app' : ' nhs-prose' ); ?>">
				<?php
				the_content();
				wp_link_pages();
				?>
			</div>
			<?php
			if ( ! $nhm_elementor && ( comments_open() || get_comments_number() ) ) {
				comments_template();
			}
			?>
		</article>
		<?php
	endwhile;

elseif ( is_404() ) :
	?>
	<div class="nhs-empty">
		<h1 class="nhs-title"><?php esc_html_e( 'Page not found', 'neurohr-members' ); ?></h1>
		<p><?php esc_html_e( 'This page does not exist or was moved. Try the search above.', 'neurohr-members' ); ?></p>
		<p><a class="nhm-btn nhm-btn--primary" href="<?php echo esc_url( NHM_Settings::url( 'home_redirect', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Back to the dashboard', 'neurohr-members' ); ?></a></p>
	</div>
	<?php

else :
	if ( is_search() ) {
		/* translators: %s: search words */
		$nhm_title = sprintf( __( 'Results for “%s”', 'neurohr-members' ), get_search_query() );
	} elseif ( is_home() ) {
		$nhm_title = get_option( 'page_for_posts' ) ? get_the_title( (int) get_option( 'page_for_posts' ) ) : __( 'Latest', 'neurohr-members' );
	} else {
		$nhm_title = wp_strip_all_tags( get_the_archive_title() );
	}
	?>
	<header class="nhs-head">
		<h1 class="nhs-title"><?php echo esc_html( $nhm_title ); ?></h1>
		<?php
		$nhm_desc = is_search() || is_home() ? '' : get_the_archive_description();
		if ( $nhm_desc ) {
			echo '<div class="nhs-lead">' . wp_kses_post( $nhm_desc ) . '</div>';
		}
		?>
	</header>
	<?php if ( have_posts() ) : ?>
		<div class="nhs-cards">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class( 'nhs-card' ); ?>>
					<?php if ( has_post_thumbnail() ) : ?>
						<a class="nhs-card__img" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php the_post_thumbnail( 'medium_large' ); ?></a>
					<?php endif; ?>
					<div class="nhs-card__body">
						<p class="nhs-crumb"><?php echo esc_html( get_the_date() ); ?></p>
						<h2 class="nhs-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="nhs-card__text"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26 ) ); ?></p>
					</div>
				</article>
			<?php endwhile; ?>
		</div>
		<?php
		the_posts_pagination(
			array(
				'class'     => 'nhs-pages',
				'prev_text' => __( 'Previous', 'neurohr-members' ),
				'next_text' => __( 'Next', 'neurohr-members' ),
			)
		);
		?>
	<?php else : ?>
		<div class="nhs-empty"><p><?php esc_html_e( 'Nothing found.', 'neurohr-members' ); ?></p></div>
	<?php endif; ?>
	<?php
endif;

nhm_shell_document_close();
