<?php
/**
 * Saved resources of the logged-in member (/knowledge/saved/).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nh_hub_header();
?>
<main id="primary" class="nh-hub nh-section-list nh-section--saved">
	<div class="nh-wrap nh-wrap--list">
		<header class="nh-list-head">
			<div>
				<h1 class="nh-list-head__title"><?php esc_html_e( 'Saved', 'neurohr-hub' ); ?></h1>
				<p class="nh-list-head__meta"><?php esc_html_e( 'Guides, cases and templates you saved for later.', 'neurohr-hub' ); ?></p>
			</div>
		</header>
		<?php if ( have_posts() && NH_App::saved_ids() ) : ?>
			<div class="nh-panel nh-rows">
				<?php
				while ( have_posts() ) :
					the_post();
					echo '<div class="nh-saved-row" data-nh-saved-row>';
					nh_hub_render_row( get_post(), true );
					echo NH_App::actions( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
					echo '</div>';
				endwhile;
				?>
			</div>
		<?php else : ?>
			<div class="nh-panel nh-empty-state">
				<p class="nh-empty-state__title"><?php esc_html_e( 'Nothing saved yet', 'neurohr-hub' ); ?></p>
				<p><?php esc_html_e( 'Tap the bookmark on any guide, case or template to keep it here.', 'neurohr-hub' ); ?></p>
				<a class="nh-btn" href="<?php echo esc_url( NH_Rewrite::url( 'knowledge' ) ); ?>"><?php esc_html_e( 'Browse the Knowledge Hub', 'neurohr-hub' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
nh_hub_footer();
