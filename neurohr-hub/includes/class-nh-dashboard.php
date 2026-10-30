<?php
/**
 * Knowledge Hub → Dashboard: sections at a glance, most viewed and downloaded,
 * content that needs attention, searches that found nothing, content by level.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Dashboard {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 9 );
		add_filter( 'manage_' . NH_Post_Types::CPT . '_posts_columns', array( __CLASS__, 'add_views_column' ), 20 );
		add_action( 'manage_' . NH_Post_Types::CPT . '_posts_custom_column', array( __CLASS__, 'render_views_column' ), 10, 2 );
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . NH_Post_Types::CPT,
			__( 'Knowledge Hub Dashboard', 'neurohr-hub' ),
			__( 'Dashboard', 'neurohr-hub' ),
			'edit_posts',
			'nh-hub-dashboard',
			array( __CLASS__, 'render' ),
			0
		);
	}

	public static function add_views_column( $columns ) {
		$columns['nh_views']     = __( 'Views', 'neurohr-hub' );
		$columns['nh_downloads'] = __( 'Downloads', 'neurohr-hub' );
		return $columns;
	}

	public static function render_views_column( $column, $post_id ) {
		if ( 'nh_views' === $column ) {
			echo (int) NH_Stats::get_views( $post_id );
		}
		if ( 'nh_downloads' === $column ) {
			echo NH_Media::has_download( $post_id ) ? (int) get_post_meta( $post_id, NH_Media::COUNT_META, true ) : '—';
		}
	}

	private static function resources() {
		return get_posts(
			array(
				'post_type'              => NH_Post_Types::CPT,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
			)
		);
	}

	private static function list_url( $section = '' ) {
		$url = admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT );
		return $section ? add_query_arg( NH_Admin_List::PARAM, $section, $url ) : $url;
	}

	private static function section_icon( $key ) {
		$icons = array(
			'knowledge' => 'dashicons-book-alt',
			'cases'     => 'dashicons-businessperson',
			'faq'       => 'dashicons-editor-help',
			'templates' => 'dashicons-media-document',
			'videos'    => 'dashicons-video-alt3',
		);
		return isset( $icons[ $key ] ) ? $icons[ $key ] : 'dashicons-admin-page';
	}

	private static function title_link( $post ) {
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_post_link( $post->ID ) ), esc_html( get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'neurohr-hub' ) ) );
	}

	private static function section_pill( $post ) {
		$section = NH_Sections::get( NH_Sections::of_post( $post ) );
		return '<span class="nh-pill">' . esc_html( $section['label'] ) . '</span>';
	}

	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$posts     = self::resources();
		$published = array_values( array_filter( $posts, function ( $p ) { return 'publish' === $p->post_status; } ) );
		$drafts    = count( $posts ) - count( $published );

		$by_section = array_fill_keys( array_keys( NH_Sections::all() ), 0 );
		$labels     = nh_hub_level_labels();
		$by_level   = array_fill_keys( array_keys( $labels ), 0 );
		$views      = 0;
		$downloads  = 0;
		foreach ( $published as $p ) {
			if ( nh_hub_get_type( $p ) ) {
				$by_section[ NH_Sections::of_post( $p ) ]++;
			}
			$level = NH_Access::get_post_level( $p );
			if ( isset( $by_level[ $level ] ) ) {
				$by_level[ $level ]++;
			}
			$views     += NH_Stats::get_views( $p->ID );
			$downloads += (int) get_post_meta( $p->ID, NH_Media::COUNT_META, true );
		}

		$top = $published;
		usort( $top, function ( $a, $b ) { return NH_Stats::get_views( $b->ID ) - NH_Stats::get_views( $a->ID ); } );
		$top = array_slice( $top, 0, 8 );

		$dl = array_values( array_filter( $published, function ( $p ) { return NH_Media::has_download( $p->ID ); } ) );
		usort( $dl, function ( $a, $b ) { return (int) get_post_meta( $b->ID, NH_Media::COUNT_META, true ) - (int) get_post_meta( $a->ID, NH_Media::COUNT_META, true ); } );
		$dl = array_slice( $dl, 0, 6 );

		// Upgrade interest: resources members opened above their level.
		$interest = array_values( array_filter( $published, function ( $p ) { return NH_Stats::total( $p->ID, NH_Stats::LOCKED_META ) > 0; } ) );
		usort( $interest, function ( $a, $b ) { return NH_Stats::total( $b->ID, NH_Stats::LOCKED_META ) - NH_Stats::total( $a->ID, NH_Stats::LOCKED_META ); } );
		$interest_total = array( 'views' => 0, 'clicks' => 0 );
		foreach ( $interest as $p ) {
			$interest_total['views']  += NH_Stats::total( $p->ID, NH_Stats::LOCKED_META );
			$interest_total['clicks'] += NH_Stats::total( $p->ID, NH_Stats::CLICKS_META );
		}
		$interest = array_slice( $interest, 0, 10 );
		// Columns of the Upgrade interest table: every level below the top of the ladder.
		$below_top = array();
		foreach ( NH_Access::levels() as $lvl_id => $lvl ) {
			if ( $lvl['rank'] < NH_Access::max_rank() && isset( $labels[ $lvl_id ] ) ) {
				$below_top[] = $lvl_id;
			}
		}

		// Content that needs attention.
		$reserved = NH_Rewrite::reserved_slugs();
		$titles   = array();
		$checks   = array(
			'type'      => array( 'label' => __( 'No resource type', 'neurohr-hub' ), 'hint' => __( 'Not in any section; only admins can open it.', 'neurohr-hub' ), 'items' => array() ),
			'slug'      => array( 'label' => __( 'Address clashes with a topic or section', 'neurohr-hub' ), 'hint' => __( 'Change the slug, or it cannot be opened.', 'neurohr-hub' ), 'items' => array() ),
			'topic'     => array( 'label' => __( 'No topic', 'neurohr-hub' ), 'hint' => __( 'Will not appear on any topic card.', 'neurohr-hub' ), 'items' => array() ),
			'duplicate' => array( 'label' => __( 'Duplicate titles', 'neurohr-hub' ), 'hint' => __( 'Probably the same content twice.', 'neurohr-hub' ), 'items' => array() ),
			'excerpt'   => array( 'label' => __( 'No excerpt', 'neurohr-hub' ), 'hint' => __( 'Lists and locked pages show the first words of the text instead.', 'neurohr-hub' ), 'items' => array() ),
			/* translators: %d: months */
			'review'    => array( 'label' => __( 'Due for review', 'neurohr-hub' ), 'hint' => sprintf( __( 'Not reviewed for %d months or more. Check it, then tick "Reviewed today".', 'neurohr-hub' ), NH_Review::months() ), 'items' => array() ),
		);
		foreach ( $posts as $p ) {
			$type = nh_hub_get_type( $p );
			if ( ! $type ) {
				$checks['type']['items'][] = $p;
			} else {
				$section = NH_Sections::get( NH_Sections::of_post( $p ) );
				if ( $section['taxonomy'] ) {
					$topics = get_the_terms( $p, $section['taxonomy'] );
					if ( empty( $topics ) || is_wp_error( $topics ) ) {
						$checks['topic']['items'][] = $p;
					}
				}
				if ( ! has_excerpt( $p ) && 'faq' !== $type->slug ) {
					$checks['excerpt']['items'][] = $p;
				}
			}
			if ( 'publish' === $p->post_status && NH_Review::is_due( $p ) ) {
				$checks['review']['items'][] = $p;
			}
			if ( in_array( $p->post_name, $reserved, true ) ) {
				$checks['slug']['items'][] = $p;
			}
			$titles[ strtolower( trim( $p->post_title ) ) ][] = $p;
		}
		foreach ( $titles as $group ) {
			if ( count( $group ) > 1 ) {
				$checks['duplicate']['items'] = array_merge( $checks['duplicate']['items'], $group );
			}
		}
		$open_issues = 0;
		foreach ( $checks as $check ) {
			$open_issues += count( $check['items'] ) ? 1 : 0;
		}

		$searches  = NH_Stats::zero_searches( 12 );
		$max_level = max( 1, max( $by_level ) );
		?>
		<div class="wrap nh-admin nh-dash">
			<header class="nh-dash__head">
				<div>
					<h1><?php esc_html_e( 'Knowledge Hub', 'neurohr-hub' ); ?></h1>
					<p><?php echo esc_html( sprintf( /* translators: 1: published, 2: drafts */ __( '%1$d published · %2$d not published', 'neurohr-hub' ), count( $published ), $drafts ) ); ?></p>
				</div>
				<div class="nh-dash__actions">
					<a class="button" href="<?php echo esc_url( self::list_url() ); ?>"><?php esc_html_e( 'All resources', 'neurohr-hub' ); ?></a>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . NH_Post_Types::CPT ) ); ?>"><?php esc_html_e( 'Add resource', 'neurohr-hub' ); ?></a>
				</div>
			</header>

			<div class="nh-dash__tiles">
				<?php foreach ( NH_Sections::all() as $key => $section ) : ?>
					<a class="nh-dash__tile" href="<?php echo esc_url( self::list_url( $key ) ); ?>">
						<span class="dashicons <?php echo esc_attr( self::section_icon( $key ) ); ?>"></span>
						<strong><?php echo (int) $by_section[ $key ]; ?></strong>
						<span><?php echo esc_html( $section['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
				<div class="nh-dash__tile nh-dash__tile--stat">
					<span class="dashicons dashicons-visibility"></span>
					<strong><?php echo esc_html( number_format_i18n( $views ) ); ?></strong>
					<span><?php esc_html_e( 'Views', 'neurohr-hub' ); ?></span>
				</div>
				<div class="nh-dash__tile nh-dash__tile--stat">
					<span class="dashicons dashicons-download"></span>
					<strong><?php echo esc_html( number_format_i18n( $downloads ) ); ?></strong>
					<span><?php esc_html_e( 'Downloads', 'neurohr-hub' ); ?></span>
				</div>
			</div>

			<div class="nh-dash__row nh-dash__row--main">
				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Most viewed', 'neurohr-hub' ); ?></h2>
					<ol class="nh-rank">
						<?php foreach ( $top as $p ) : ?>
							<li>
								<span class="nh-rank__title"><?php echo self::title_link( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?> <?php echo self::section_pill( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span>
								<span class="nh-rank__num"><?php echo esc_html( number_format_i18n( NH_Stats::get_views( $p->ID ) ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>

				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Needs attention', 'neurohr-hub' ); ?>
						<?php if ( $open_issues ) : ?><span class="nh-badge-count"><?php echo (int) $open_issues; ?></span><?php endif; ?>
					</h2>
					<?php foreach ( $checks as $check ) : ?>
						<?php $n = count( $check['items'] ); ?>
						<details class="nh-check<?php echo $n ? '' : ' is-ok'; ?>">
							<summary>
								<span class="nh-check__count"><?php echo $n ? (int) $n : '✓'; ?></span>
								<span class="nh-check__text"><strong><?php echo esc_html( $check['label'] ); ?></strong><small><?php echo esc_html( $check['hint'] ); ?></small></span>
							</summary>
							<?php if ( $n ) : ?>
								<ul>
									<?php foreach ( array_slice( $check['items'], 0, 60 ) as $p ) : ?>
										<li><?php echo self::title_link( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</details>
					<?php endforeach; ?>
				</section>
			</div>

			<div class="nh-dash__row">
				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Most downloaded', 'neurohr-hub' ); ?></h2>
					<?php if ( $dl ) : ?>
						<ol class="nh-rank">
							<?php foreach ( $dl as $p ) : ?>
								<li><span class="nh-rank__title"><?php echo self::title_link( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></span><span class="nh-rank__num"><?php echo (int) get_post_meta( $p->ID, NH_Media::COUNT_META, true ); ?></span></li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="nh-empty-note"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'No templates yet. Add one under Templates.', 'neurohr-hub' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Searches with no results', 'neurohr-hub' ); ?></h2>
					<?php if ( $searches ) : ?>
						<ol class="nh-rank">
							<?php foreach ( $searches as $keyword => $count ) : ?>
								<li><span class="nh-rank__title">“<?php echo esc_html( $keyword ); ?>”</span><span class="nh-rank__num"><?php echo (int) $count; ?>×</span></li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="nh-empty-note"><span class="dashicons dashicons-search"></span><?php esc_html_e( 'Nothing yet. Words members search for without finding anything show up here: ideas for new content.', 'neurohr-hub' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Content by level', 'neurohr-hub' ); ?></h2>
					<ul class="nh-bars">
						<?php foreach ( $labels as $level => $label ) : ?>
							<li>
								<span class="nh-bars__label"><?php echo esc_html( $label ); ?></span>
								<span class="nh-bars__track"><span style="width:<?php echo (int) round( 100 * $by_level[ $level ] / $max_level ); ?>%"></span></span>
								<span class="nh-bars__num"><?php echo (int) $by_level[ $level ]; ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			</div>

			<section class="nh-admin-card">
				<h2><?php esc_html_e( 'Upgrade interest', 'neurohr-hub' ); ?></h2>
				<p class="nh-admin-card__intro"><?php esc_html_e( 'Resources members opened but could not read with their plan, and how often they then clicked the upgrade button. This is the content that sells upgrades.', 'neurohr-hub' ); ?></p>
				<?php if ( $interest ) : ?>
					<table class="nh-interest">
						<thead><tr>
							<th><?php esc_html_e( 'Resource', 'neurohr-hub' ); ?></th>
							<th><?php esc_html_e( 'Needs', 'neurohr-hub' ); ?></th>
							<?php foreach ( $below_top as $lvl ) : ?>
								<th class="num"><?php echo esc_html( sprintf( /* translators: %s: level */ __( '%s views', 'neurohr-hub' ), $labels[ $lvl ] ) ); ?></th>
							<?php endforeach; ?>
							<th class="num"><?php esc_html_e( 'Upgrade clicks', 'neurohr-hub' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $interest as $p ) : ?>
								<?php $lc = NH_Stats::level_counts( $p->ID, NH_Stats::LOCKED_META ); ?>
								<tr>
									<td><?php echo self::title_link( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?> <?php echo self::section_pill( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method. ?></td>
									<td><?php echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $p ) ) ); ?></td>
									<?php foreach ( $below_top as $lvl ) : ?>
										<td class="num"><?php echo empty( $lc[ $lvl ] ) ? '<span class="nh-muted">–</span>' : (int) $lc[ $lvl ]; ?></td>
									<?php endforeach; ?>
									<td class="num"><strong><?php echo (int) NH_Stats::total( $p->ID, NH_Stats::CLICKS_META ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot><tr>
							<td colspan="<?php echo (int) ( 2 + count( $below_top ) ); ?>"><?php esc_html_e( 'All resources', 'neurohr-hub' ); ?>: <?php echo esc_html( sprintf( /* translators: %d: views */ __( '%d locked views', 'neurohr-hub' ), $interest_total['views'] ) ); ?></td>
							<td class="num"><strong><?php echo (int) $interest_total['clicks']; ?></strong></td>
						</tr></tfoot>
					</table>
				<?php else : ?>
					<p class="nh-empty-note"><span class="dashicons dashicons-chart-bar"></span><?php esc_html_e( 'Nothing yet. When a Free or Lite member opens a Pro resource, it shows up here.', 'neurohr-hub' ); ?></p>
				<?php endif; ?>
			</section>

			<p class="description"><?php esc_html_e( 'Views are counted in the browser, so cached pages count too. Editors and admins are not counted.', 'neurohr-hub' ); ?></p>
		</div>
		<?php
	}
}
