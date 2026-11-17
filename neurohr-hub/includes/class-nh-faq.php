<?php
/**
 * FAQ anywhere.
 *
 * FAQ items stay hub resources (type FAQ), so each question has its own access level:
 * "Everyone" = public answer, anything else = members of that level and up. Questions are
 * put in FAQ Groups (Knowledge Hub → FAQ Groups) and shown:
 * - on any page or post: [nh_faq group="payroll"] (several: group="payroll,leave"),
 *   or chosen questions: [nh_faq ids="12,15"]; optional title="…"
 * - under any resource: the "FAQ" box in the editor (a group and/or chosen questions)
 * - on /faq/: every question, grouped.
 * Locked answers show the question with a short "log in / upgrade" line, never the answer.
 * Public answers are also given to Google as FAQ structured data (once per page).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Faq {

	const GROUP_META = '_nh_faq_group'; // Term ID shown under a resource.
	const IDS_META   = '_nh_faq_ids';   // Chosen FAQ IDs shown under a resource.
	const NONCE      = 'nh_hub_faq_nonce';

	private static $schema_done = false;
	private static $depth       = 0;

	public static function init() {
		add_shortcode( 'nh_faq', array( __CLASS__, 'sc_faq' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 10, 2 );
		}
	}

	/* ---------- Data ---------- */

	/** Published FAQ items, by group slugs and/or IDs (none of both = all), in display order. */
	public static function items( $groups = array(), $ids = array() ) {
		$args = array(
			'post_type'      => NH_Post_Types::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'no_found_rows'  => true,
			'tax_query'      => array( NH_Types::kind_clause( 'faq' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( $groups && $ids ) {
			// Both: chosen questions first (in their order), then the group's others.
			$from_groups = self::items( $groups );
			$chosen      = self::items( array(), $ids );
			$seen        = wp_list_pluck( $chosen, 'ID' );
			foreach ( $from_groups as $p ) {
				if ( ! in_array( $p->ID, $seen, true ) ) {
					$chosen[] = $p;
				}
			}
			return $chosen;
		}
		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} elseif ( $groups ) {
			$args['tax_query'][] = array(
				'taxonomy' => NH_Post_Types::TAX_FAQ_GROUP,
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', (array) $groups ),
			);
		}
		return get_posts( $args );
	}

	/* ---------- Output ---------- */

	/**
	 * One question as <details>. The answer is built with the FAQ item as the global post,
	 * so the access gate checks the question itself (not the page it is shown on).
	 */
	public static function render_item( $faq ) {
		$faq = get_post( $faq );
		$can = NH_Access::can_view( $faq );
		ob_start();
		?>
		<details class="nh-faq" id="faq-<?php echo (int) $faq->ID; ?>">
			<summary>
				<span><?php echo esc_html( get_the_title( $faq ) ); ?></span>
				<?php if ( ! $can ) : ?>
					<span class="nh-lock" title="<?php esc_attr_e( 'Members only', 'neurohr-hub' ); ?>">&#128274; <?php echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $faq ) ) ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="nh-faq__answer">
				<?php
				if ( $can ) {
					echo wp_kses_post( self::answer_html( $faq ) );
				} else {
					echo self::locked_line( $faq ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
				}
				?>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}

	private static function answer_html( $faq ) {
		global $post;
		$previous = $post;
		$post     = $faq; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the access gate reads the global post; restored below.
		setup_postdata( $post );
		self::$depth++;
		$html = self::$depth > 2 ? wpautop( esc_html( wp_strip_all_tags( $faq->post_content ) ) ) : apply_filters( 'the_content', $faq->post_content );
		self::$depth--;
		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restoring.
		if ( $previous ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}
		return $html;
	}

	/** Short "log in / upgrade" line instead of a locked answer. */
	private static function locked_line( $faq ) {
		$level = NH_Access::get_post_level( $faq );
		$here  = is_singular() ? get_permalink() : home_url( '/' );
		if ( ! is_user_logged_in() ) {
			return '<p class="nh-faq__locked">' . esc_html__( 'The answer is for members.', 'neurohr-hub' ) . ' '
				. '<a href="' . esc_url( NH_Login::login_url( $here ) ) . '">' . esc_html__( 'Log in', 'neurohr-hub' ) . '</a> · '
				. '<a href="' . esc_url( NH_Access::upgrade_url( $level ) ) . '">' . esc_html__( 'Create a free account', 'neurohr-hub' ) . '</a></p>';
		}
		/* translators: %s: level name */
		return '<p class="nh-faq__locked">' . esc_html( sprintf( __( 'The answer is for %s members.', 'neurohr-hub' ), nh_hub_level_label( $level ) ) ) . ' '
			. '<a href="' . esc_url( NH_Access::upgrade_url( $level ) ) . '">' . esc_html__( 'Upgrade', 'neurohr-hub' ) . '</a></p>';
	}

	/** A list of questions, with optional heading. */
	public static function render_list( $faqs, $title = '', $schema = true ) {
		if ( ! $faqs ) {
			return '';
		}
		NH_Display::ensure_assets();
		$html = '<div class="nh-hub nh-faq-block">';
		if ( '' !== $title ) {
			$html .= '<h2 class="nh-section__title">' . esc_html( $title ) . '</h2>';
		}
		$html .= '<div class="nh-panel nh-faq-list">';
		foreach ( $faqs as $faq ) {
			$html .= self::render_item( $faq );
		}
		$html .= '</div></div>';
		return $html . ( $schema ? self::schema( $faqs ) : '' );
	}

	/**
	 * FAQPage structured data with the public answers only (what everyone, Google
	 * included, can read on the page). Once per page.
	 */
	private static function schema( $faqs ) {
		if ( self::$schema_done ) {
			return '';
		}
		$entities = array();
		foreach ( $faqs as $faq ) {
			if ( ! NH_Access::is_public( $faq ) ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => html_entity_decode( wp_strip_all_tags( get_the_title( $faq ) ), ENT_QUOTES, 'UTF-8' ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( self::answer_html( $faq ) ), ENT_QUOTES, 'UTF-8' ) ) ),
				),
			);
		}
		if ( ! $entities ) {
			return '';
		}
		self::$schema_done = true;
		return '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>';
	}

	/** [nh_faq group="payroll,leave" ids="12,15" title="Payroll questions" schema="no"] */
	public static function sc_faq( $atts ) {
		if ( self::$depth > 1 ) {
			return '';
		}
		$atts   = shortcode_atts( array( 'group' => '', 'ids' => '', 'title' => '', 'schema' => 'yes' ), $atts, 'nh_faq' );
		$groups = array_filter( array_map( 'trim', explode( ',', (string) $atts['group'] ) ) );
		$ids    = array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );
		return self::render_list( self::items( $groups, $ids ), (string) $atts['title'], 'no' !== $atts['schema'] );
	}

	/** Questions chosen for a resource (group and/or single ones), without the resource itself. */
	public static function for_resource( $post_id ) {
		$group = (int) get_post_meta( $post_id, self::GROUP_META, true );
		$ids   = get_post_meta( $post_id, self::IDS_META, true );
		$ids   = is_array( $ids ) ? $ids : array();
		$term  = $group ? get_term( $group, NH_Post_Types::TAX_FAQ_GROUP ) : null;
		if ( ! $ids && ( ! $term || is_wp_error( $term ) ) ) {
			return array();
		}
		$faqs = self::items( ( $term && ! is_wp_error( $term ) ) ? array( $term->slug ) : array(), $ids );
		return array_values( array_filter( $faqs, function ( $f ) use ( $post_id ) { return (int) $f->ID !== (int) $post_id; } ) );
	}

	public static function render_for_resource( $post_id ) {
		return self::render_list( self::for_resource( $post_id ), __( 'Frequently Asked Questions', 'neurohr-hub' ) );
	}

	/** /faq/: every question, under its group's name (questions without a group last). */
	public static function render_section() {
		$all    = self::items();
		if ( ! $all ) {
			return '<p class="nh-empty">' . esc_html__( 'No questions yet.', 'neurohr-hub' ) . '</p>';
		}
		$groups = get_terms( array( 'taxonomy' => NH_Post_Types::TAX_FAQ_GROUP, 'hide_empty' => true, 'orderby' => 'name' ) );
		if ( ! $groups || is_wp_error( $groups ) ) {
			return self::render_list( $all );
		}
		$html = '';
		$done = array();
		foreach ( $groups as $group ) {
			$faqs = array_values( array_filter( $all, function ( $f ) use ( $group ) { return has_term( $group->term_id, NH_Post_Types::TAX_FAQ_GROUP, $f ); } ) );
			if ( ! $faqs ) {
				continue;
			}
			$done  = array_merge( $done, wp_list_pluck( $faqs, 'ID' ) );
			$html .= self::render_list( $faqs, $group->name );
		}
		$rest = array_values( array_filter( $all, function ( $f ) use ( $done ) { return ! in_array( $f->ID, $done, true ); } ) );
		if ( $rest ) {
			$html .= self::render_list( $rest, __( 'Other Questions', 'neurohr-hub' ) );
		}
		return '<div class="nh-faq-groups">' . $html . '</div>';
	}

	/* ---------- Editor ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_faq', __( 'FAQ under this resource', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'normal', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_faq', self::NONCE );
		$group  = (int) get_post_meta( $post->ID, self::GROUP_META, true );
		$ids    = get_post_meta( $post->ID, self::IDS_META, true );
		$ids    = is_array( $ids ) ? $ids : array();
		$groups = get_terms( array( 'taxonomy' => NH_Post_Types::TAX_FAQ_GROUP, 'hide_empty' => false ) );
		$faqs   = self::items();
		?>
		<p class="description"><?php esc_html_e( 'Questions shown under this resource (open/close list). Each question keeps its own access level: "Everyone" answers are public, others are for members.', 'neurohr-hub' ); ?></p>
		<p><label for="nh-faq-group"><strong><?php esc_html_e( 'A whole FAQ group', 'neurohr-hub' ); ?></strong></label><br>
			<select id="nh-faq-group" name="nh_faq_group">
				<option value="0"><?php esc_html_e( '— none —', 'neurohr-hub' ); ?></option>
				<?php foreach ( ( is_array( $groups ) ? $groups : array() ) as $term ) : ?>
					<option value="<?php echo (int) $term->term_id; ?>" <?php selected( $group, $term->term_id ); ?>><?php echo esc_html( $term->name . ' (' . $term->count . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . NH_Post_Types::TAX_FAQ_GROUP . '&post_type=' . NH_Post_Types::CPT ) ); ?>" target="_blank"><?php esc_html_e( 'Manage FAQ groups', 'neurohr-hub' ); ?></a></p>
		<p><strong><?php esc_html_e( 'And/or single questions', 'neurohr-hub' ); ?></strong></p>
		<?php if ( $faqs ) : ?>
			<div class="nh-faq-pick">
				<?php foreach ( $faqs as $faq ) : ?>
					<?php if ( (int) $faq->ID === (int) $post->ID ) { continue; } ?>
					<label><input type="checkbox" name="nh_faq_ids[]" value="<?php echo (int) $faq->ID; ?>" <?php checked( in_array( (int) $faq->ID, array_map( 'intval', $ids ), true ) ); ?>>
						<?php echo esc_html( get_the_title( $faq ) ); ?>
						<span class="description">(<?php echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $faq ) ) ); ?>)</span></label>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No FAQ items yet: add a resource with Resource Type FAQ.', 'neurohr-hub' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'On any page or post use the shortcode [nh_faq group="group-slug"] or [nh_faq ids="12,15"].', 'neurohr-hub' ); ?></p>
		<style>.nh-faq-pick{max-height:220px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;padding:6px 10px}.nh-faq-pick label{display:block;padding:3px 0}</style>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_save_faq' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$group = isset( $_POST['nh_faq_group'] ) ? absint( $_POST['nh_faq_group'] ) : 0;
		if ( $group && term_exists( $group, NH_Post_Types::TAX_FAQ_GROUP ) ) {
			update_post_meta( $post_id, self::GROUP_META, $group );
		} else {
			delete_post_meta( $post_id, self::GROUP_META );
		}
		$ids = isset( $_POST['nh_faq_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['nh_faq_ids'] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- absint.
		if ( $ids ) {
			update_post_meta( $post_id, self::IDS_META, $ids );
		} else {
			delete_post_meta( $post_id, self::IDS_META );
		}
	}
}
