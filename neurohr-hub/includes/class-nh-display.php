<?php
/**
 * Appearance settings and the building blocks of hub pages, also offered as shortcodes
 * for a custom page (Blocksy "Shortcode" block, Elementor, …):
 *
 *   [nh_hub_sections]                                   links to every section, with counts
 *   [nh_hub_search section="knowledge"]                 live search box
 *   [nh_hub_topics section="cases" items="5" columns="3"] topic cards
 *   [nh_hub_latest section="cases" count="6"]           latest resources as cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Display {

	const OPTION = 'nh_hub_display';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'inline_colors' ), 20 );
		add_shortcode( 'nh_hub_sections', array( __CLASS__, 'sc_sections' ) );
		add_shortcode( 'nh_hub_types', array( __CLASS__, 'sc_sections' ) ); // Old name (v0.3.1).
		add_shortcode( 'nh_hub_search', array( __CLASS__, 'sc_search' ) );
		add_shortcode( 'nh_hub_topics', array( __CLASS__, 'sc_topics' ) );
		add_shortcode( 'nh_hub_latest', array( __CLASS__, 'sc_latest' ) );
	}

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'eyebrow'       => 'Welcome to',
			'title'         => 'NeuroHR Knowledge Hub',
			'intro'         => 'Explore practical HR guides, compliance tips and trend insights tailored for Malaysian SMEs.',
			'image_id'      => 0,
			'cases_title'   => 'Legal Case Insights',
			'cases_intro'   => 'Malaysian Industrial Court decisions, explained for employers and HR practitioners.',
			'faq_title'     => 'Frequently Asked Questions',
			'faq_intro'     => 'Quick answers about NeuroHR and how to use it.',
			'templates_title' => 'HR Templates',
			'templates_intro' => 'Ready-to-use letters, forms and policies for Malaysian employers.',
			'videos_title'    => 'Videos',
			'videos_intro'    => 'Short HR training and explainer videos.',

			'color'         => '#f47b20',
			'home_page'     => 0,
			'per_topic'     => 5,
			'require_login' => 1,
			'style_posts'   => 1,
			'news_label'    => 'HR News & Update',
			'review_months' => 12,
			'updated_days'  => 30,
			'print_notice'  => NH_Print::default_notice(),
		);
	}

	public static function get( $key = null ) {
		$saved    = get_option( self::OPTION, array() );
		$settings = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return null === $key ? $settings : ( isset( $settings[ $key ] ) ? $settings[ $key ] : null );
	}

	/**
	 * Settings are spread over several tabs but stored in one option, so only the fields
	 * the submitted tab contains are changed; everything else keeps its saved value.
	 * (Checkboxes send a hidden 0 first, so an unticked box is still "contained".)
	 */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$d     = self::defaults();
		$out   = self::get(); // Current values (saved + defaults).
		$out   = array_intersect_key( $out, $d ); // Drop keys of removed features.

		foreach ( array( 'eyebrow', 'title', 'cases_title', 'faq_title', 'templates_title', 'videos_title', 'news_label' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		foreach ( array( 'intro', 'cases_intro', 'faq_intro', 'templates_intro', 'videos_intro', 'print_notice' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_textarea_field( $input[ $key ] );
			}
		}
		foreach ( array( 'image_id', 'home_page' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = absint( $input[ $key ] );
			}
		}
		if ( isset( $input['review_months'] ) ) {
			$out['review_months'] = max( 1, min( 60, absint( $input['review_months'] ) ) );
		}
		if ( isset( $input['updated_days'] ) ) {
			$out['updated_days'] = min( 365, absint( $input['updated_days'] ) );
		}
		if ( isset( $input['per_topic'] ) ) {
			$out['per_topic'] = max( 1, min( 10, absint( $input['per_topic'] ) ) );
		}
		if ( isset( $input['color'] ) ) {
			$color        = sanitize_hex_color( $input['color'] );
			$out['color'] = $color ? $color : $d['color'];
		}
		foreach ( array( 'require_login', 'style_posts' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}
		return $out;
	}

	/** Hero texts of a section. */
	public static function hero_texts( $section ) {
		$s = self::get();
		if ( 'cases' === $section ) {
			return array( 'eyebrow' => $s['eyebrow'], 'title' => $s['cases_title'], 'intro' => $s['cases_intro'], 'image_id' => 0 );
		}
		if ( 'faq' === $section ) {
			return array( 'eyebrow' => '', 'title' => $s['faq_title'], 'intro' => $s['faq_intro'], 'image_id' => 0 );
		}
		if ( 'templates' === $section || 'videos' === $section ) {
			return array( 'eyebrow' => '', 'title' => $s[ $section . '_title' ], 'intro' => $s[ $section . '_intro' ], 'image_id' => 0 );
		}
		return array( 'eyebrow' => $s['eyebrow'], 'title' => $s['title'], 'intro' => $s['intro'], 'image_id' => (int) $s['image_id'] );
	}

	/* ---------- Colours and assets ---------- */

	public static function inline_colors() {
		if ( wp_style_is( 'nh-hub', 'enqueued' ) ) {
			wp_add_inline_style( 'nh-hub', self::color_css() );
		}
	}

	public static function color_css() {
		$color = (string) self::get( 'color' );
		if ( 4 === strlen( $color ) ) { // #abc -> #aabbcc, so the 10% tint below is valid.
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		// Darker shade for hover states (computed here so older browsers without color-mix() get it too).
		$dark = '#';
		foreach ( array( 1, 3, 5 ) as $i ) {
			$dark .= str_pad( dechex( (int) round( hexdec( substr( $color, $i, 2 ) ) * 0.82 ) ), 2, '0', STR_PAD_LEFT );
		}
		return '.nh-hub{--nh-primary:' . $color . ';--nh-primary-soft:' . $color . '1a;--nh-primary-dark:' . $dark . ';}';
	}

	/** Shortcodes can sit on any page: load the stylesheet when one renders. */
	public static function ensure_assets() {
		if ( ! wp_style_is( 'nh-hub', 'enqueued' ) ) {
			wp_enqueue_style( 'nh-hub', NH_HUB_URL . 'assets/hub.css', array(), NH_HUB_VERSION );
			wp_add_inline_style( 'nh-hub', self::color_css() );
		}
	}

	/* ---------- Custom Knowledge Hub home ---------- */

	public static function custom_home_page() {
		$id = (int) self::get( 'home_page' );
		return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	public static function render_custom_home( $page_id ) {
		// The global post here is a resource from the main query. Point it at the page while
		// rendering, otherwise content filters (incl. the access gate) act on that resource.
		global $post;
		$previous = $post;
		$post     = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
		setup_postdata( $post );

		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance->documents->get( $page_id ) && \Elementor\Plugin::$instance->documents->get( $page_id )->is_built_with_elementor() ) {
			$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $page_id, true );
		} else {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
		}

		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restoring.
		if ( $previous ) {
			setup_postdata( $previous );
		}
		return $html;
	}

	/* ---------- Building blocks ---------- */

	public static function render_hero( $section ) {
		$t = self::hero_texts( $section );
		ob_start();
		?>
		<section class="nh-hero<?php echo $t['image_id'] ? ' nh-hero--media' : ''; ?>">
			<?php if ( $t['image_id'] ) : ?>
				<div class="nh-hero__media"><?php echo wp_get_attachment_image( $t['image_id'], 'large', false, array( 'class' => 'nh-hero__img', 'loading' => 'eager' ) ); ?></div>
			<?php endif; ?>
			<div class="nh-hero__text">
				<?php if ( $t['eyebrow'] ) : ?>
					<p class="nh-hero__eyebrow"><?php echo esc_html( $t['eyebrow'] ); ?></p>
				<?php endif; ?>
				<h1 class="nh-hero__title"><?php echo esc_html( $t['title'] ); ?></h1>
				<?php if ( $t['intro'] ) : ?>
					<p class="nh-hero__intro"><?php echo esc_html( $t['intro'] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Live search box. Searches one section (and optionally one topic) as you type;
	 * Enter opens the full results page of that section/topic.
	 */
	public static function search_box( $section = 'knowledge', $topic = null, $value = '' ) {
		self::ensure_assets();
		NH_Search::enqueue();
		$data   = NH_Sections::get( $section );
		$scope  = $topic ? $topic->name : ( $data ? $data['label'] : '' );
		$action = NH_Rewrite::url( $section, $topic ? $topic->slug : '' );
		/* translators: %s: section or topic name */
		$placeholder = sprintf( __( 'Search %s', 'neurohr-hub' ), $scope );
		$uid         = 'nh-s-' . wp_unique_id();
		ob_start();
		?>
		<form class="nh-searchbox" method="get" action="<?php echo esc_url( $action ); ?>" role="search"
			data-section="<?php echo esc_attr( $section ); ?>" data-topic="<?php echo $topic ? (int) $topic->term_id : 0; ?>">
			<svg class="nh-searchbox__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
			<input type="search" name="q" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>…"
				autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $uid ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>">
			<button type="submit" class="nh-searchbox__btn"><?php esc_html_e( 'Search', 'neurohr-hub' ); ?></button>
			<div class="nh-live" id="<?php echo esc_attr( $uid ); ?>" role="listbox" hidden></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Topic cards of a section: icon, name, count, first items, "Explore more". */
	public static function topic_cards( $section, $items = null, $columns = 3 ) {
		self::ensure_assets();
		$items   = max( 1, min( 10, (int) ( $items ? $items : self::get( 'per_topic' ) ) ) );
		$columns = max( 1, min( 4, (int) $columns ) );
		$topics  = NH_Sections::topics( $section );
		if ( ! $topics ) {
			return '<p class="nh-empty">' . esc_html__( 'Nothing here yet.', 'neurohr-hub' ) . '</p>';
		}

		$cache_key = 'nh_cards_' . $section . '_' . $items;
		$cards     = get_transient( $cache_key );
		if ( ! is_array( $cards ) ) {
			$cards = array();
			foreach ( $topics as $topic ) {
				$q = new WP_Query(
					array(
						'post_type'      => NH_Post_Types::CPT,
						'post_status'    => 'publish',
						'posts_per_page' => $items,
						'fields'         => 'ids',
						'orderby'        => 'title',
						'order'          => 'ASC',
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- cached.
							NH_Sections::type_clause( $section ),
							array( 'taxonomy' => $topic->taxonomy, 'terms' => $topic->term_id ),
						),
					)
				);
				$cards[ $topic->term_id ] = array( 'ids' => $q->posts, 'total' => (int) $q->found_posts );
			}
			set_transient( $cache_key, $cards, DAY_IN_SECONDS );
			NH_Frontend::remember_cache_key( $cache_key );
		}

		ob_start();
		?>
		<?php wp_enqueue_script( 'nh-topics-masonry', NH_HUB_URL . 'assets/topics-masonry.js', array(), NH_HUB_VERSION, true ); ?>
		<div class="nh-topics-grid" style="--nh-cols:<?php echo (int) $columns; ?>">
			<?php foreach ( $topics as $topic ) : ?>
				<?php
				$card = isset( $cards[ $topic->term_id ] ) ? $cards[ $topic->term_id ] : array( 'ids' => array(), 'total' => 0 );
				if ( ! $card['ids'] ) {
					continue;
				}
				$url = NH_Rewrite::url( $section, $topic->slug );
				?>
				<section class="nh-topic-card">
					<header class="nh-topic-card__head">
						<span class="nh-topic-card__icon"><?php echo self::topic_icon( $topic ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span>
						<h2 class="nh-topic-card__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $topic->name ); ?></a></h2>
						<span class="nh-topic-card__count"><?php echo (int) $card['total']; ?></span>
					</header>
					<ul class="nh-topic-card__list">
						<?php foreach ( $card['ids'] as $id ) : ?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
								<?php echo NH_Review::badge( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
								<?php echo self::lock_icon( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $card['total'] > count( $card['ids'] ) ) : ?>
						<a class="nh-topic-card__more" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Explore more', 'neurohr-hub' ); ?> ›</a>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** All FAQs as an accordion (the FAQ section has no topics). */
	public static function faq_list() {
		self::ensure_assets();
		$faqs = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'tax_query'      => array( NH_Sections::type_clause( 'faq' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( ! $faqs ) {
			return '<p class="nh-empty">' . esc_html__( 'No questions yet.', 'neurohr-hub' ) . '</p>';
		}
		global $post;
		$previous = $post;
		ob_start();
		echo '<div class="nh-panel nh-faq-list">';
		foreach ( $faqs as $faq ) {
			$post = $faq; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the access gate reads the global post; restored below.
			setup_postdata( $post );
			nh_hub_render_faq( $faq );
		}
		echo '</div>';
		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restoring.
		if ( $previous ) {
			setup_postdata( $previous );
		}
		return ob_get_clean();
	}

	/** Lock icon for members who cannot open an item (guests are never on hub pages when login is required). */
	public static function lock_icon( $id ) {
		if ( ! is_user_logged_in() || NH_Access::can_view( $id ) ) {
			return '';
		}
		return sprintf(
			'<span class="nh-lock-icon" title="%1$s" aria-label="%2$s">&#128274;</span>',
			esc_attr( nh_hub_level_label( NH_Access::get_post_level( $id ) ) ),
			esc_attr__( 'Members only', 'neurohr-hub' )
		);
	}

	/** Uploaded topic icon, or a neutral book icon in the brand colour. */
	public static function topic_icon( $topic ) {
		$image_id = (int) get_term_meta( $topic->term_id, 'nh_image_id', true );
		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			return wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) );
		}
		return '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5A2.5 2.5 0 0 1 4 20.5z"/><path d="M9 7h7M9 10.5h5"/></svg>';
	}

	/* ---------- Shortcodes ---------- */

	private static function section_att( $atts ) {
		$key = isset( $atts['section'] ) ? sanitize_key( $atts['section'] ) : 'knowledge';
		return NH_Sections::get( $key ) ? $key : 'knowledge';
	}

	public static function sc_sections() {
		self::ensure_assets();
		ob_start();
		echo '<div class="nh-hub nh-sc"><nav class="nh-sections" aria-label="' . esc_attr__( 'Sections', 'neurohr-hub' ) . '">';
		foreach ( NH_Sections::all() as $key => $section ) {
			printf(
				'<a href="%1$s">%2$s <span class="nh-count">%3$d</span></a>',
				esc_url( NH_Rewrite::url( $key ) ),
				esc_html( $section['label'] ),
				(int) NH_Sections::count( $key )
			);
		}
		echo '</nav></div>';
		return ob_get_clean();
	}

	public static function sc_search( $atts = array() ) {
		return '<div class="nh-hub nh-sc">' . self::search_box( self::section_att( (array) $atts ) ) . '</div>';
	}

	public static function sc_topics( $atts = array() ) {
		$atts = shortcode_atts( array( 'section' => 'knowledge', 'items' => self::get( 'per_topic' ), 'columns' => 3 ), $atts, 'nh_hub_topics' );
		return '<div class="nh-hub nh-sc">' . self::topic_cards( self::section_att( $atts ), $atts['items'], $atts['columns'] ) . '</div>';
	}

	public static function sc_latest( $atts = array() ) {
		self::ensure_assets();
		$atts  = shortcode_atts( array( 'section' => 'knowledge', 'count' => 6 ), $atts, 'nh_hub_latest' );
		$posts = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 24, (int) $atts['count'] ) ),
				'tax_query'      => array( NH_Sections::type_clause( self::section_att( $atts ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( ! $posts ) {
			return '';
		}
		ob_start();
		echo '<div class="nh-hub nh-sc"><div class="nh-grid">';
		foreach ( $posts as $p ) {
			nh_hub_render_card( $p );
		}
		echo '</div></div>';
		return ob_get_clean();
	}
}
