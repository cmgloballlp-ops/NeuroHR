<?php
/**
 * List page in the same look as the section home:
 * a topic (/legal-case-insights/dismissal-cases/), search results (/knowledge/?q=…),
 * further pages of a section (/knowledge/page/2/) or a tag (/knowledge/tag/payroll/).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nh_tag     = NH_Frontend::is_tag_page() ? get_queried_object() : null;
$nh_section = $nh_tag ? 'knowledge' : NH_Sections::current();
$nh_data    = NH_Sections::get( $nh_section );
$nh_topic   = $nh_tag ? null : NH_Sections::current_topic( $nh_section );
$nh_keyword = NH_Frontend::keyword();
$nh_total   = (int) $GLOBALS['wp_query']->found_posts;

if ( '' !== $nh_keyword ) {
	/* translators: %s: search words */
	$nh_title = sprintf( __( 'Results for “%s”', 'neurohr-hub' ), $nh_keyword );
} elseif ( $nh_topic ) {
	$nh_title = $nh_topic->name;
} elseif ( $nh_tag instanceof WP_Term ) {
	$nh_title = '#' . $nh_tag->name;
} else {
	$nh_title = $nh_data['label'];
}

nh_hub_header();
?>
<main id="primary" class="nh-hub nh-section-list nh-section--<?php echo esc_attr( $nh_section ); ?>">
	<div class="nh-wrap nh-wrap--list">

		<nav class="nh-breadcrumb nh-breadcrumb--pill" aria-label="<?php esc_attr_e( 'Breadcrumb', 'neurohr-hub' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'neurohr-hub' ); ?></a>
			<span aria-hidden="true">/</span>
			<a href="<?php echo esc_url( NH_Rewrite::url( $nh_section ) ); ?>"><?php echo esc_html( $nh_data['label'] ); ?></a>
			<?php if ( $nh_topic && '' !== $nh_keyword ) : ?>
				<span aria-hidden="true">/</span>
				<a href="<?php echo esc_url( NH_Rewrite::url( $nh_section, $nh_topic->slug ) ); ?>"><?php echo esc_html( $nh_topic->name ); ?></a>
			<?php endif; ?>
			<span aria-hidden="true">/</span>
			<span class="nh-breadcrumb__current" aria-current="page"><?php echo esc_html( $nh_title ); ?></span>
		</nav>

		<header class="nh-list-head">
			<?php if ( $nh_topic && '' === $nh_keyword ) : ?>
				<span class="nh-list-head__icon"><?php echo NH_Display::topic_icon( $nh_topic ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span>
			<?php endif; ?>
			<div>
				<h1 class="nh-list-head__title"><?php echo esc_html( $nh_title ); ?></h1>
				<p class="nh-list-head__meta">
					<?php
					/* translators: 1: number, 2: "articles", "cases" … */
					echo esc_html( sprintf( __( '%1$d %2$s', 'neurohr-hub' ), $nh_total, $nh_tag ? __( 'resources', 'neurohr-hub' ) : $nh_data['item'] ) );
					?>
				</p>
				<?php if ( $nh_topic && $nh_topic->description && '' === $nh_keyword ) : ?>
					<p class="nh-list-head__desc"><?php echo esc_html( $nh_topic->description ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<div class="nh-list-search">
			<?php echo NH_Display::search_box( $nh_section, $nh_topic, $nh_keyword ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
		</div>

		<?php if ( have_posts() ) : ?>
			<div class="nh-panel <?php echo 'faq' === $nh_section ? 'nh-faq-list' : 'nh-rows'; ?>">
				<?php
				while ( have_posts() ) :
					the_post();
					if ( 'faq' === $nh_section && ! $nh_tag ) {
						nh_hub_render_faq( get_post() );
					} else {
						nh_hub_render_row( get_post(), '' !== $nh_keyword || $nh_tag );
					}
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
				<p class="nh-empty-state__title"><?php esc_html_e( 'Nothing found', 'neurohr-hub' ); ?></p>
				<p><?php esc_html_e( 'Try fewer or different words, or browse the topics.', 'neurohr-hub' ); ?></p>
				<a class="nh-btn" href="<?php echo esc_url( NH_Rewrite::url( $nh_section ) ); ?>">
					<?php
					/* translators: %s: section name */
					echo esc_html( sprintf( __( 'Back to %s', 'neurohr-hub' ), $nh_data['label'] ) );
					?>
				</a>
			</div>
		<?php endif; ?>

	</div>
</main>
<?php
nh_hub_footer();
