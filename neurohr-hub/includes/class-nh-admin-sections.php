<?php
/**
 * Settings → Sections: manage the sections of the hub.
 * Per section: name, address, Resource Types, topic group, heading texts, order, shown or
 * hidden. Add a section; delete an own (empty) section. Topic groups: add your own, delete
 * an unused empty one. Saved through admin-post (NH_Sections::save checks the addresses).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Admin_Sections {

	const ACTION = 'nh_hub_save_sections';
	const NOTICE = 'nh_hub_section_notices';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'unassigned_notice' ) );
	}

	private static function url() {
		return admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=nh-hub-settings&tab=sections' );
	}

	/** Resource Types that are in no section. */
	public static function unassigned_types() {
		$out = array();
		foreach ( NH_Types::terms() as $term ) {
			if ( '' === NH_Sections::for_type_id( $term->term_id ) ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/** Warn on hub screens when a type is in no section (its resources would have no page). */
	public static function unassigned_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || NH_Post_Types::CPT !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$types = self::unassigned_types();
		if ( ! $types ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: type names */
				__( 'These Resource Types are in no section, so their resources are listed under the Knowledge Hub: %s.', 'neurohr-hub' ),
				implode( ', ', wp_list_pluck( $types, 'name' ) )
			)
		) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Put them in a section', 'neurohr-hub' ) . '</a></p></div>';
	}

	/* ---------- Screen ---------- */

	public static function render() {
		$messages = get_option( self::NOTICE );
		if ( $messages ) {
			delete_option( self::NOTICE );
			echo '<div class="notice notice-warning"><p>' . implode( '<br>', array_map( 'esc_html', (array) $messages ) ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sections saved.', 'neurohr-hub' ) . '</p></div>';
		}
		$sections = NH_Sections::all();
		$types    = NH_Types::terms();
		$groups   = NH_Sections::topic_taxonomies();
		$d        = NH_Display::get();
		$pages    = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
		$i        = 0;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nh-sections-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<?php foreach ( $sections as $key => $s ) : ?>
				<?php $field = 'nh_sec[' . $key . ']'; ?>
				<section class="nh-admin-card nh-sec-card<?php echo $s['visible'] ? '' : ' is-hidden'; ?>">
					<h2>
						<?php echo esc_html( $s['label'] ); ?>
						<a class="nh-sec-url" href="<?php echo esc_url( NH_Rewrite::url( $key ) ); ?>" target="_blank"><?php echo esc_html( '/' . $s['base'] . '/' ); ?> ↗</a>
						<?php if ( ! $s['visible'] ) : ?><span class="nh-sec-badge"><?php esc_html_e( 'Hidden', 'neurohr-hub' ); ?></span><?php endif; ?>
					</h2>
					<div class="nh-sec-grid">
						<div class="nh-field"><label><?php esc_html_e( 'Name', 'neurohr-hub' ); ?></label>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>">
							<p class="description"><?php esc_html_e( 'Breadcrumb, menus of the hub, search labels.', 'neurohr-hub' ); ?></p></div>
						<div class="nh-field"><label><?php esc_html_e( 'Address', 'neurohr-hub' ); ?></label>
							<span class="nh-sec-base"><span><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</span><input type="text" name="<?php echo esc_attr( $field ); ?>[base]" value="<?php echo esc_attr( $s['base'] ); ?>" pattern="[a-z0-9\-]+"><span>/</span></span>
							<p class="description"><?php esc_html_e( 'Lowercase letters, numbers and dashes. The old address keeps working (redirect).', 'neurohr-hub' ); ?></p></div>
						<div class="nh-field nh-field--short"><label><?php esc_html_e( 'Order', 'neurohr-hub' ); ?></label>
							<input type="number" name="<?php echo esc_attr( $field ); ?>[order]" value="<?php echo (int) $i++; ?>" min="0" max="99"></div>
					</div>

					<div class="nh-sec-grid">
						<div class="nh-field"><label><?php esc_html_e( 'Resource Types in this section', 'neurohr-hub' ); ?></label>
							<div class="nh-sec-types">
								<?php foreach ( $types as $type ) : ?>
									<?php $owner = NH_Sections::for_type_id( $type->term_id ); ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[types][]" value="<?php echo (int) $type->term_id; ?>" <?php checked( $owner, $key ); ?>>
										<?php echo esc_html( $type->name ); ?>
										<span class="description">(<?php echo esc_html( NH_Types::kinds()[ NH_Types::kind( $type ) ] ); ?><?php echo $owner && $owner !== $key ? ' · ' . esc_html( sprintf( /* translators: %s: section */ __( 'now in %s', 'neurohr-hub' ), $sections[ $owner ]['label'] ) ) : ''; ?>)</span></label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'A type belongs to one section: ticking a type that is in another section moves it here.', 'neurohr-hub' ); ?></p></div>
						<div class="nh-field"><label><?php esc_html_e( 'Topics', 'neurohr-hub' ); ?></label>
							<select name="<?php echo esc_attr( $field ); ?>[taxonomy]">
								<option value=""><?php esc_html_e( 'No topics (one list)', 'neurohr-hub' ); ?></option>
								<?php foreach ( $groups as $tax => $name ) : ?>
									<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $s['taxonomy'], $tax ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The topic cards of the section home. Several sections may share a topic group.', 'neurohr-hub' ); ?></p>
							<label class="nh-toggle" style="margin-top:10px"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[visible]" value="1" <?php checked( $s['visible'] ); ?>>
								<span><strong><?php esc_html_e( 'Shown', 'neurohr-hub' ); ?></strong> — <?php esc_html_e( 'untick to hide the section pages (its resources still open)', 'neurohr-hub' ); ?></span></label>
						</div>
					</div>

					<details class="nh-advanced" <?php echo 'knowledge' === $key ? 'open' : ''; ?>>
						<summary><?php esc_html_e( 'Top of the section page', 'neurohr-hub' ); ?></summary>
						<div class="nh-fields">
							<div class="nh-field"><label><?php esc_html_e( 'Small heading', 'neurohr-hub' ); ?></label><input type="text" name="<?php echo esc_attr( $field ); ?>[eyebrow]" value="<?php echo esc_attr( $s['eyebrow'] ); ?>"></div>
							<div class="nh-field"><label><?php esc_html_e( 'Title', 'neurohr-hub' ); ?></label><input type="text" name="<?php echo esc_attr( $field ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></div>
							<div class="nh-field"><label><?php esc_html_e( 'Introduction', 'neurohr-hub' ); ?></label><textarea rows="2" name="<?php echo esc_attr( $field ); ?>[intro]"><?php echo esc_textarea( $s['intro'] ); ?></textarea></div>
							<div class="nh-field"><label><?php esc_html_e( 'Image (optional)', 'neurohr-hub' ); ?></label><?php NH_Admin::image_field( $field . '[image_id]', $s['image_id'], 'nh-sec-image-' . $key ); ?></div>
						</div>
						<?php if ( 'knowledge' === $key ) : ?>
							<div class="nh-field"><label for="nh-home-page"><?php esc_html_e( 'Advanced: use this page instead of the built-in home', 'neurohr-hub' ); ?></label>
								<select id="nh-home-page" name="nh_display[home_page]">
									<option value="0"><?php esc_html_e( 'Built-in layout (recommended)', 'neurohr-hub' ); ?></option>
									<?php foreach ( $pages as $page ) : ?>
										<option value="<?php echo (int) $page->ID; ?>" <?php selected( (int) $d['home_page'], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Live parts for that page: see Settings → Shortcodes.', 'neurohr-hub' ); ?></p></div>
						<?php endif; ?>
					</details>

					<?php if ( ! $s['builtin'] ) : ?>
						<p><label class="nh-sec-delete"><input type="checkbox" name="nh_sec_delete[]" value="<?php echo esc_attr( $key ); ?>"> <?php esc_html_e( 'Delete this section (only when it has no Resource Types)', 'neurohr-hub' ); ?></label></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<div class="nh-admin-grid">
				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Add a section', 'neurohr-hub' ); ?></h2>
					<p class="nh-admin-card__intro"><?php esc_html_e( 'After saving, tick its Resource Types and choose its topics. Make a new Resource Type first if needed (Knowledge Hub → Resource Types) and set its behaviour in Settings → Type defaults.', 'neurohr-hub' ); ?></p>
					<div class="nh-field"><label><?php esc_html_e( 'Name', 'neurohr-hub' ); ?></label><input type="text" name="nh_new[label]" placeholder="<?php esc_attr_e( 'e.g. HR Guides', 'neurohr-hub' ); ?>"></div>
					<div class="nh-field"><label><?php esc_html_e( 'Address', 'neurohr-hub' ); ?></label><input type="text" name="nh_new[base]" placeholder="hr-guides" pattern="[a-z0-9\-]*"></div>
				</section>

				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Topic groups', 'neurohr-hub' ); ?></h2>
					<p class="nh-admin-card__intro"><?php esc_html_e( 'Each section can use a group of topics. Add a group of its own for a new section; its topics are then managed in the Knowledge Hub menu.', 'neurohr-hub' ); ?></p>
					<ul class="nh-sec-groups">
						<?php foreach ( $groups as $tax => $name ) : ?>
							<?php $used = NH_Sections::for_taxonomy( $tax ); ?>
							<li><strong><?php echo esc_html( $name ); ?></strong>
								<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax . '&post_type=' . NH_Post_Types::CPT ) ); ?>"><?php esc_html_e( 'Manage topics', 'neurohr-hub' ); ?></a>
								<?php if ( isset( NH_Sections::topic_groups()[ $tax ] ) && ! $used ) : ?>
									<label class="nh-sec-delete"><input type="checkbox" name="nh_group_delete[]" value="<?php echo esc_attr( $tax ); ?>"> <?php esc_html_e( 'Delete (only when it has no topics)', 'neurohr-hub' ); ?></label>
								<?php elseif ( $used ) : ?>
									<span class="description"><?php echo esc_html( sprintf( /* translators: %s: section */ __( 'used by %s', 'neurohr-hub' ), $sections[ $used ]['label'] ) ); ?></span>
								<?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
					<div class="nh-field"><label><?php esc_html_e( 'New topic group', 'neurohr-hub' ); ?></label><input type="text" name="nh_new_group" placeholder="<?php esc_attr_e( 'e.g. Guide Topics', 'neurohr-hub' ); ?>"></div>
				</section>

				<section class="nh-admin-card">
					<h2><?php esc_html_e( 'Topic cards', 'neurohr-hub' ); ?></h2>
					<div class="nh-field nh-field--short"><label for="nh-per-topic"><?php esc_html_e( 'Items shown per topic card', 'neurohr-hub' ); ?></label>
						<input id="nh-per-topic" type="number" min="1" max="10" name="nh_display[per_topic]" value="<?php echo (int) $d['per_topic']; ?>"></div>
					<p class="description"><?php esc_html_e( 'Icons and order are set per topic: Knowledge Hub → HR Topics / Case Topics → edit a topic.', 'neurohr-hub' ); ?></p>
				</section>
			</div>

			<?php submit_button( __( 'Save sections', 'neurohr-hub' ) ); ?>
		</form>
		<style>
			.nh-sec-card h2{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.nh-sec-url{font-size:13px;font-weight:400;text-decoration:none}
			.nh-sec-card.is-hidden{opacity:.75}.nh-sec-badge{font-size:11px;font-weight:600;background:#f0f0f1;color:#50575e;border-radius:999px;padding:2px 8px}
			.nh-sec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0 20px}
			.nh-sec-base{display:flex;align-items:center;gap:4px}.nh-sec-base span{color:#646970;white-space:nowrap}.nh-sec-base input{flex:1 1 auto;max-width:280px}
			.nh-sec-types{display:flex;flex-direction:column;gap:4px;padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;max-height:220px;overflow:auto}
			.nh-sec-groups{margin:0 0 10px}.nh-sec-groups li{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid #f0f0f1}
			.nh-sec-delete{color:#b32d2e}
		</style>
		<?php
	}

	/* ---------- Saving ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'neurohr-hub' ) );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each field is sanitized in NH_Sections::save().
		$posted   = isset( $_POST['nh_sec'] ) ? (array) wp_unslash( $_POST['nh_sec'] ) : array();
		$delete   = isset( $_POST['nh_sec_delete'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['nh_sec_delete'] ) ) : array();
		$new      = isset( $_POST['nh_new'] ) ? (array) wp_unslash( $_POST['nh_new'] ) : array();
		$display  = isset( $_POST['nh_display'] ) ? (array) wp_unslash( $_POST['nh_display'] ) : array();
		$group    = isset( $_POST['nh_new_group'] ) ? sanitize_text_field( wp_unslash( $_POST['nh_new_group'] ) ) : '';
		$g_delete = isset( $_POST['nh_group_delete'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['nh_group_delete'] ) ) : array();
		// phpcs:enable
		$messages = array();
		$before   = NH_Sections::all();

		// A type ticked in two sections goes to the one it was not in before (= it was moved).
		$ticked = array();
		foreach ( $posted as $key => $s ) {
			foreach ( isset( $s['types'] ) ? (array) $s['types'] : array() as $id ) {
				$ticked[ (int) $id ][] = sanitize_key( $key );
			}
		}
		foreach ( $ticked as $id => $keys ) {
			if ( count( $keys ) < 2 ) {
				continue;
			}
			$was    = NH_Sections::for_type_id( $id );
			$winner = $keys[0];
			foreach ( $keys as $k ) {
				if ( $k !== $was ) {
					$winner = $k;
					break;
				}
			}
			foreach ( $keys as $k ) {
				if ( $k !== $winner ) {
					$posted[ $k ]['types'] = array_values( array_diff( array_map( 'intval', (array) $posted[ $k ]['types'] ), array( $id ) ) );
				}
			}
		}

		foreach ( $delete as $key ) {
			if ( isset( $posted[ $key ] ) && empty( $before[ $key ]['builtin'] ) ) {
				if ( empty( $posted[ $key ]['types'] ) ) {
					unset( $posted[ $key ] );
				} else {
					/* translators: %s: section */
					$messages[] = sprintf( __( '%s was not deleted: untick its Resource Types first.', 'neurohr-hub' ), $before[ $key ]['label'] );
				}
			}
		}

		// New section.
		$label = isset( $new['label'] ) ? sanitize_text_field( $new['label'] ) : '';
		if ( '' !== $label ) {
			$base = sanitize_title( isset( $new['base'] ) && '' !== $new['base'] ? $new['base'] : $label );
			$key  = 'sec_' . substr( str_replace( '-', '_', $base ), 0, 24 );
			while ( isset( $posted[ $key ] ) || isset( $before[ $key ] ) ) {
				$key .= '_2';
			}
			$posted[ $key ] = array( 'label' => $label, 'base' => $base, 'types' => array(), 'taxonomy' => '', 'visible' => 1, 'title' => $label, 'order' => 90 );
		}

		// Topic groups.
		if ( '' !== $group ) {
			NH_Sections::add_topic_group( $group );
		}
		foreach ( $g_delete as $tax ) {
			if ( ! NH_Sections::delete_topic_group( $tax ) ) {
				$messages[] = sprintf( /* translators: %s: group */ __( 'Topic group %s was not deleted: it still has topics or a section uses it.', 'neurohr-hub' ), $tax );
			}
		}

		$messages = array_merge( $messages, NH_Sections::save( $posted ) );

		if ( $display ) {
			$keep = array_intersect_key( $display, array( 'home_page' => 1, 'per_topic' => 1 ) );
			update_option( NH_Display::OPTION, NH_Display::sanitize( $keep ) );
		}
		if ( $messages ) {
			update_option( self::NOTICE, $messages, false );
		}
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url() ) );
		exit;
	}
}
