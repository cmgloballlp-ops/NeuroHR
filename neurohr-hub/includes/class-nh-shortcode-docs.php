<?php
/**
 * Settings → Shortcodes: every hub shortcode with what it does, its options (with the real
 * values of this site: topics, FAQ groups) and ready-to-copy examples.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Shortcode_Docs {

	/** Section keys => names, for the option lists. */
	private static function sections() {
		$out = array();
		foreach ( NH_Sections::visible() as $key => $section ) {
			$out[ $key ] = $section['label'] . ' (/' . $section['base'] . '/)';
		}
		$out['all'] = __( 'All sections', 'neurohr-hub' );
		return $out;
	}

	/** Slug => name of the terms of a taxonomy (with content or not). */
	private static function terms( $taxonomy ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[ $term->slug ] = $term->name;
		}
		return $out;
	}

	private static function values( $values ) {
		if ( ! $values ) {
			return '<span class="description">' . esc_html__( '(none yet)', 'neurohr-hub' ) . '</span>';
		}
		$html = '<span class="nh-sc-values">';
		foreach ( $values as $code => $name ) {
			$html .= '<span><code>' . esc_html( $code ) . '</code> ' . esc_html( $name ) . '</span>';
		}
		return $html . '</span>';
	}

	private static function example( $code, $note = '' ) {
		return '<div class="nh-sc-example"><code>' . esc_html( $code ) . '</code>'
			. '<button type="button" class="button button-small nh-sc-copy" data-code="' . esc_attr( $code ) . '">' . esc_html__( 'Copy', 'neurohr-hub' ) . '</button>'
			. ( $note ? '<span class="description">' . esc_html( $note ) . '</span>' : '' ) . '</div>';
	}

	/** One shortcode card. $options: array( name, what, values-html ). */
	private static function card( $tag, $title, $what, $options, $examples ) {
		?>
		<section class="nh-admin-card nh-sc-card" id="sc-<?php echo esc_attr( $tag ); ?>">
			<h2><code>[<?php echo esc_html( $tag ); ?>]</code> <?php echo esc_html( $title ); ?></h2>
			<p class="nh-admin-card__intro"><?php echo esc_html( $what ); ?></p>
			<?php if ( $options ) : ?>
				<table class="nh-admin-table nh-sc-table">
					<thead><tr><th><?php esc_html_e( 'Option', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'What it does', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'Values', 'neurohr-hub' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $options as $option ) : ?>
							<tr><td><code><?php echo esc_html( $option[0] ); ?></code></td><td><?php echo esc_html( $option[1] ); ?></td><td><?php echo $option[2]; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p><strong><?php esc_html_e( 'Examples', 'neurohr-hub' ); ?></strong></p>
			<?php foreach ( $examples as $example ) : ?>
				<?php echo self::example( $example[0], isset( $example[1] ) ? $example[1] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
			<?php endforeach; ?>
		</section>
		<?php
	}

	public static function render() {
		$sections   = self::sections();
		$no_all     = array_diff_key( $sections, array( 'all' => 1 ) );
		$hr_topics  = self::terms( NH_Post_Types::TAX_TOPIC );
		$case_topic = array();
		foreach ( array_keys( NH_Sections::topic_taxonomies() ) as $nh_tax ) {
			if ( NH_Post_Types::TAX_TOPIC !== $nh_tax && taxonomy_exists( $nh_tax ) ) {
				$case_topic = array_merge( $case_topic, self::terms( $nh_tax ) );
			}
		}
		$faq_groups = self::terms( NH_Post_Types::TAX_FAQ_GROUP );
		$first_hr   = $hr_topics ? key( $hr_topics ) : 'termination-dismissal';
		$first_grp  = $faq_groups ? key( $faq_groups ) : 'your-group';
		$sample     = get_posts( array( 'post_type' => NH_Post_Types::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'nh_all_langs' => true, 'tax_query' => array( NH_Sections::type_clause( 'templates' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$sample_id  = $sample ? (int) $sample[0] : 123;
		?>
		<section class="nh-admin-card">
			<h2><?php esc_html_e( 'How to use a shortcode', 'neurohr-hub' ); ?></h2>
			<p class="nh-admin-card__intro"><?php esc_html_e( 'Copy an example, then paste it into a page or post: in the WordPress editor use a Shortcode block, in Elementor the Shortcode widget, in Blocksy any text block. Change the values between the quotes; options you leave out use their default. Several values are separated by commas, without spaces.', 'neurohr-hub' ); ?></p>
			<p><?php esc_html_e( 'On this page:', 'neurohr-hub' ); ?>
				<?php foreach ( array( 'nh_hub_latest', 'nh_hub_search', 'nh_hub_topics', 'nh_hub_sections', 'nh_faq', 'nh_ref' ) as $tag ) : ?>
					<a href="#sc-<?php echo esc_attr( $tag ); ?>"><code>[<?php echo esc_html( $tag ); ?>]</code></a>
				<?php endforeach; ?>
			</p>
		</section>

		<?php
		self::card(
			'nh_hub_latest',
			__( 'Latest resources as cards', 'neurohr-hub' ),
			__( 'The newest resources of one or more sections as cards. With access="public" only free samples (Everyone): good for a public page that shows visitors what members get. Each content is shown once, in the reader\'s language.', 'neurohr-hub' ),
			array(
				array( 'section', __( 'Which section(s). Default: knowledge.', 'neurohr-hub' ), self::values( $sections ) ),
				array( 'topic', __( 'Only these topics (any topic group). Default: all topics.', 'neurohr-hub' ), self::values( array_merge( $hr_topics, $case_topic ) ) ),
				array( 'access', __( 'public = only free samples. Default: everything.', 'neurohr-hub' ), self::values( array( 'public' => __( 'free samples only', 'neurohr-hub' ) ) ) ),
				array( 'count', __( 'How many cards, 1–24. Default: 6.', 'neurohr-hub' ), '<code>6</code>' ),
			),
			array(
				array( '[nh_hub_latest section="all" access="public" count="6"]', __( 'Free samples from every section', 'neurohr-hub' ) ),
				array( '[nh_hub_latest section="knowledge,videos" access="public" count="4"]', __( 'Free articles and videos', 'neurohr-hub' ) ),
				array( '[nh_hub_latest section="all" topic="' . $first_hr . '" count="6"]', __( 'Newest on one topic', 'neurohr-hub' ) ),
				array( '[nh_hub_latest section="cases" count="3"]', __( 'Three newest legal cases', 'neurohr-hub' ) ),
			)
		);

		self::card(
			'nh_hub_search',
			__( 'Search box', 'neurohr-hub' ),
			__( 'A search box with results while typing. Both languages are searched; a content with two versions appears once.', 'neurohr-hub' ),
			array( array( 'section', __( 'Search in one section, or all. Default: knowledge.', 'neurohr-hub' ), self::values( $sections ) ) ),
			array(
				array( '[nh_hub_search section="all"]', __( 'Search everything (e.g. on your member dashboard)', 'neurohr-hub' ) ),
				array( '[nh_hub_search section="templates"]', __( 'Search templates only', 'neurohr-hub' ) ),
			)
		);

		self::card(
			'nh_hub_topics',
			__( 'Topic cards', 'neurohr-hub' ),
			__( 'The topic cards of a section, as on its home page, each with its first resources.', 'neurohr-hub' ),
			array(
				array( 'section', __( 'Which section. Default: knowledge.', 'neurohr-hub' ), self::values( array_filter( $no_all, function ( $k ) { return ! NH_Sections::is_faq( $k ); }, ARRAY_FILTER_USE_KEY ) ) ),
				array( 'items', __( 'Resources listed per card, 1–10.', 'neurohr-hub' ), '<code>5</code>' ),
				array( 'columns', __( 'Cards per row on a computer, 1–4. Default: 3.', 'neurohr-hub' ), '<code>3</code>' ),
			),
			array(
				array( '[nh_hub_topics section="cases" items="5" columns="3"]' ),
				array( '[nh_hub_topics section="videos" items="3" columns="2"]' ),
			)
		);

		self::card(
			'nh_hub_sections',
			__( 'Links to every section', 'neurohr-hub' ),
			__( 'One link per section (Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos) with the number of resources. No options.', 'neurohr-hub' ),
			array(),
			array( array( '[nh_hub_sections]' ) )
		);

		self::card(
			'nh_faq',
			__( 'FAQ list', 'neurohr-hub' ),
			__( 'Questions that open and close. Each question keeps its own level: "Everyone" answers are public (and given to Google as FAQ data); others show only the question and a log in / upgrade line. Manage groups in Knowledge Hub → FAQ Groups.', 'neurohr-hub' ),
			array(
				array( 'group', __( 'FAQ group(s). Leave out group and ids to list every question.', 'neurohr-hub' ), self::values( $faq_groups ) ),
				array( 'ids', __( 'Chosen questions by ID (see the ID in All Resources → FAQ, hover a title).', 'neurohr-hub' ), '<code>12,15</code>' ),
				array( 'title', __( 'Heading above the list. Default: none.', 'neurohr-hub' ), '<code>Membership questions</code>' ),
				array( 'schema', __( 'no = do not give Google FAQ data on this page.', 'neurohr-hub' ), '<code>no</code>' ),
			),
			array(
				array( '[nh_faq group="' . $first_grp . '" title="Frequently asked questions"]' ),
				array( '[nh_faq ids="12,15"]', __( 'Replace the numbers with your question IDs', 'neurohr-hub' ) ),
			)
		);

		self::card(
			'nh_ref',
			__( 'Inline card for one resource', 'neurohr-hub' ),
			__( 'Shows one resource as a small card inside a text, e.g. "Download the termination letter template". Easiest: in the editor\'s References box click "Copy card" next to a resource. Readers without access see a lock.', 'neurohr-hub' ),
			array(
				array( 'id', __( 'ID of the resource (required).', 'neurohr-hub' ), '<code>' . (int) $sample_id . '</code>' ),
				array( 'label', __( 'Button text. Default: Read / Watch / Download / Read the case.', 'neurohr-hub' ), '<code>Download the letter</code>' ),
			),
			array(
				array( '[nh_ref id="' . $sample_id . '"]' ),
				array( '[nh_ref id="' . $sample_id . '" label="Download the template"]' ),
			)
		);
		?>
		<style>
			.nh-sc-values{display:flex;flex-wrap:wrap;gap:4px 14px}.nh-sc-values span{white-space:nowrap}
			.nh-sc-table td{vertical-align:top}.nh-sc-table td:first-child{width:90px}.nh-sc-table td:nth-child(2){width:38%}
			.nh-sc-example{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0}.nh-sc-example code{padding:6px 10px;background:#f6f7f7;border-radius:6px}
			.nh-sc-card h2 code{font-size:14px;background:#fff3ea;color:#b45309;padding:2px 8px;border-radius:6px}
			.nh-admin .nh-sc-table{max-width:none}
		</style>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.nh-sc-copy' );
			if ( ! b ) { return; }
			var code = b.getAttribute( 'data-code' ), old = b.textContent;
			var done = function () { b.textContent = <?php echo wp_json_encode( __( 'Copied', 'neurohr-hub' ) ); ?>; setTimeout( function () { b.textContent = old; }, 1500 ); };
			if ( navigator.clipboard ) { navigator.clipboard.writeText( code ).then( done, function () { window.prompt( '', code ); } ); } else { window.prompt( '', code ); }
		} );
		</script>
		<?php
	}
}
