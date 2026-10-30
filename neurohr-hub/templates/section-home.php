<?php
/**
 * Section home: /knowledge/, /legal-case-insights/, /faq/
 * Hero, live search, then topic cards (FAQ: the questions).
 * The Knowledge Hub home can be replaced by a page chosen in Settings → General.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nh_section = NH_Sections::current();
$nh_custom  = 'knowledge' === $nh_section ? NH_Display::custom_home_page() : 0;

nh_hub_header();
?>
<main id="primary" class="nh-hub nh-section-home nh-section--<?php echo esc_attr( $nh_section ); ?>">
	<?php if ( $nh_custom ) : ?>
		<?php echo NH_Display::render_custom_home( $nh_custom ); // phpcs:ignore WordPress.Security.EscapeOutput -- page builder / the_content output. ?>
	<?php else : ?>
		<div class="nh-wrap">
			<?php echo NH_Display::render_hero( $nh_section ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
			<div class="nh-section-home__search">
				<?php echo NH_Display::search_box( $nh_section ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
			</div>
			<?php
			if ( 'faq' === $nh_section ) {
				echo NH_Display::faq_list(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
			} else {
				echo NH_Display::topic_cards( $nh_section ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
			}
			?>
		</div>
	<?php endif; ?>
</main>
<?php
nh_hub_footer();
