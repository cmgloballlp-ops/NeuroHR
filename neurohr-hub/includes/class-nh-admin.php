<?php
/**
 * Admin: access-level meta box, list column and the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Admin {

	const NONCE = 'nh_hub_level_nonce';

	public static function init() {
		add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save_level' ), 10, 2 );

		add_filter( 'manage_' . NH_Post_Types::CPT . '_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_' . NH_Post_Types::CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media' ) );
		add_action( 'update_option_' . NH_Display::OPTION, array( 'NH_Frontend', 'flush_topic_cache' ) );
	}

	/* ---------- Access level meta box ---------- */

	public static function add_meta_box() {
		add_meta_box(
			'nh_access',
			__( 'Access level', 'neurohr-hub' ),
			array( __CLASS__, 'render_meta_box' ),
			NH_Post_Types::CPT,
			'side',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_level', self::NONCE );
		$own     = NH_Access::has_own_level( $post );
		$current = $own ? NH_Access::get_post_level( $post ) : 'default';
		$type    = nh_hub_get_type( $post );

		echo '<p>' . esc_html__( 'Minimum membership needed to read this resource:', 'neurohr-hub' ) . '</p>';

		$default_label = $type
			/* translators: 1: resource type, 2: level name */
			? sprintf( __( 'Type default (%1$s: %2$s)', 'neurohr-hub' ), $type->name, nh_hub_level_label( NH_Access::get_type_level( $post ) ) )
			: __( 'Type default (pick a Resource Type)', 'neurohr-hub' );
		printf(
			'<label style="display:block;margin:4px 0"><input type="radio" name="nh_min_level" value="default" %1$s> %2$s</label>',
			checked( $current, 'default', false ),
			esc_html( $default_label )
		);

		foreach ( nh_hub_level_labels() as $level => $label ) {
			printf(
				'<label style="display:block;margin:4px 0"><input type="radio" name="nh_min_level" value="%1$d" %2$s> %3$s</label>',
				(int) $level,
				checked( $current, $level, false ),
				esc_html( 0 === $level ? $label . ' ' . __( '(no login)', 'neurohr-hub' ) : $label . ( NH_Access::rank( $level ) < NH_Access::max_rank() ? ' +' : '' ) )
			);
		}
		echo '<p class="description">' . esc_html__( 'Use "Type default" unless this resource is an exception, e.g. an article opened to Free users. "Everyone" makes it a free sample: visitors can read it without logging in, and Google can list it.', 'neurohr-hub' ) . '</p>';
	}

	public static function save_level( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_save_level' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['nh_min_level'] ) ) {
			return;
		}
		$value = sanitize_key( wp_unslash( $_POST['nh_min_level'] ) );
		if ( 'default' === $value ) {
			delete_post_meta( $post_id, NH_Access::META );
			return;
		}
		$level = NH_Access::valid_level( $value );
		update_post_meta( $post_id, NH_Access::META, $level );
	}

	/* ---------- List column ---------- */

	public static function add_column( $columns ) {
		$columns['nh_level'] = __( 'Access', 'neurohr-hub' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'nh_level' === $column ) {
			echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $post_id ) ) );
			if ( ! NH_Access::has_own_level( $post_id ) ) {
				echo ' <span class="description">' . esc_html__( '(type default)', 'neurohr-hub' ) . '</span>';
			}
		}
	}

	public static function enqueue_media( $hook ) {
		if ( false !== strpos( (string) $hook, 'nh-hub-settings' ) ) {
			wp_enqueue_media();
		}
		$screen = get_current_screen();
		if ( $screen && ( NH_Post_Types::CPT === $screen->post_type || false !== strpos( (string) $hook, 'nh-hub' ) ) ) {
			wp_enqueue_style( 'nh-hub-admin', NH_HUB_URL . 'assets/admin.css', array(), NH_HUB_VERSION );
		}
	}

	/**
	 * Image picker (media library) used on the settings page and the topic screens.
	 * $name is the form field that stores the attachment ID.
	 */
	public static function image_field( $name, $image_id, $id ) {
		$image_id = absint( $image_id );
		?>
		<div class="nh-image-field" id="<?php echo esc_attr( $id ); ?>">
			<div class="nh-image-field__preview" style="margin-bottom:8px">
				<?php echo $image_id ? wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'style' => 'max-width:120px;height:auto' ) ) : ''; ?>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $image_id; ?>">
			<button type="button" class="button nh-image-field__pick"><?php esc_html_e( 'Choose image', 'neurohr-hub' ); ?></button>
			<button type="button" class="button-link nh-image-field__clear" style="margin-left:8px<?php echo $image_id ? '' : ';display:none'; ?>"><?php esc_html_e( 'Remove', 'neurohr-hub' ); ?></button>
		</div>
		<script>
		( function () {
			var box = document.getElementById( <?php echo wp_json_encode( $id ); ?> );
			if ( ! box ) { return; }
			var input = box.querySelector( 'input' ), preview = box.querySelector( '.nh-image-field__preview' ), clear = box.querySelector( '.nh-image-field__clear' ), frame;
			box.querySelector( '.nh-image-field__pick' ).addEventListener( 'click', function () {
				// Media library scripts load in the footer, after this box: check at click time.
				if ( ! window.wp || ! wp.media ) { return; }
				frame = frame || wp.media( { title: <?php echo wp_json_encode( __( 'Choose image', 'neurohr-hub' ) ); ?>, library: { type: 'image' }, multiple: false } );
				frame.off( 'select' ).on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var url = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					input.value = a.id;
					preview.innerHTML = '';
					var img = document.createElement( 'img' ); img.src = url; img.style.cssText = 'max-width:120px;height:auto';
					preview.appendChild( img ); clear.style.display = '';
				} );
				frame.open();
			} );
			clear.addEventListener( 'click', function () { input.value = 0; preview.innerHTML = ''; clear.style.display = 'none'; } );
		} )();
		</script>
		<?php
	}

	/* ---------- Settings page (tabs) ---------- */

	/**
	 * Tabs and the settings group each one submits. The appearance/section texts option is
	 * shared by several tabs; NH_Display::sanitize() only changes the fields a tab contains.
	 */
	private static function tabs() {
		return array(
			'general'   => array( __( 'General', 'neurohr-hub' ), 'dashicons-admin-generic' ),
			'sections'  => array( __( 'Sections', 'neurohr-hub' ), 'dashicons-screenoptions' ),
			'levels'    => array( __( 'Membership levels', 'neurohr-hub' ), 'dashicons-groups' ),
			'types'     => array( __( 'Resource types', 'neurohr-hub' ), 'dashicons-lock' ),
			'look'      => array( __( 'Look', 'neurohr-hub' ), 'dashicons-art' ),
			'shortcodes' => array( __( 'Shortcodes', 'neurohr-hub' ), 'dashicons-shortcode' ),
			'changelog' => array( __( 'Changelog', 'neurohr-hub' ), 'dashicons-backup' ),
		);
	}

	public static function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . NH_Post_Types::CPT,
			__( 'Hub Settings', 'neurohr-hub' ),
			__( 'Settings', 'neurohr-hub' ),
			'manage_options',
			'nh-hub-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		$display = array(
			'type'              => 'array',
			'sanitize_callback' => array( 'NH_Display', 'sanitize' ),
			'default'           => NH_Display::defaults(),
		);
		foreach ( array( 'nh_hub_general', 'nh_hub_sections', 'nh_hub_look' ) as $group ) {
			register_setting( $group, NH_Display::OPTION, $display );
		}
		register_setting( 'nh_hub_general', NH_Access::OPTION_PRICE, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		register_setting( 'nh_hub_levels', NH_Access::OPTION_MAP, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_map' ), 'default' => NH_Access::default_map() ) );
		register_setting( 'nh_hub_types', NH_Access::OPTION_TYPES, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_type_levels' ), 'default' => NH_Access::default_type_levels() ) );
	}

	public static function sanitize_map( $input ) {
		$clean = array();
		foreach ( (array) $input as $membership_id => $level ) {
			$membership_id = absint( $membership_id );
			$level         = min( NH_Access::MAX_LEVEL, absint( $level ) );
			if ( $membership_id && $level ) {
				$clean[ $membership_id ] = $level;
			}
		}
		return $clean;
	}

	/** Type ID => level. The same form sends each type's behaviour (nh_kind[ID]), saved as term meta. */
	public static function sanitize_type_levels( $input ) {
		$clean = array();
		foreach ( (array) $input as $id => $level ) {
			$id = absint( $id );
			if ( $id && term_exists( $id, NH_Post_Types::TAX_TYPE ) ) {
				$clean[ $id ] = NH_Access::valid_level( $level );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- options.php checked the settings nonce.
		$kinds = isset( $_POST['nh_kind'] ) ? (array) wp_unslash( $_POST['nh_kind'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $kinds as $id => $kind ) {
			if ( absint( $id ) && term_exists( absint( $id ), NH_Post_Types::TAX_TYPE ) && current_user_can( 'manage_options' ) ) {
				NH_Types::set_kind( absint( $id ), sanitize_key( $kind ) );
			}
		}
		NH_Frontend::flush_topic_cache();
		return $clean;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$url  = admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=nh-hub-settings' );
		?>
		<div class="wrap nh-admin">
			<h1><?php esc_html_e( 'Knowledge Hub Settings', 'neurohr-hub' ); ?></h1>
			<nav class="nh-admin-tabs">
				<?php foreach ( $tabs as $key => $t ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $url ) ); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>"><span class="dashicons <?php echo esc_attr( $t[1] ); ?>"></span><?php echo esc_html( $t[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
		</div>
		<?php
	}

	/** Opens a settings card. */
	private static function card( $title, $intro = '' ) {
		echo '<section class="nh-admin-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( $intro ) {
			echo '<p class="nh-admin-card__intro">' . esc_html( $intro ) . '</p>';
		}
	}

	private static function text_row( $label, $key, $value, $type = 'text' ) {
		$name = NH_Display::OPTION . '[' . $key . ']';
		$id   = 'nh-' . $key;
		echo '<div class="nh-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" rows="2" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '</div>';
	}

	private static function tab_general() {
		$d = NH_Display::get();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'nh_hub_general' ); ?>

			<?php self::card( __( 'Access', 'neurohr-hub' ) ); ?>
				<input type="hidden" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[require_login]" value="0">
				<label class="nh-toggle"><input type="checkbox" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[require_login]" value="1" <?php checked( ! empty( $d['require_login'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Login required for the whole Knowledge Hub', 'neurohr-hub' ); ?></strong><br>
					<?php esc_html_e( 'Guests are sent to the login page and back afterwards. Hub pages stay out of search engines, sitemaps, site search and the REST API.', 'neurohr-hub' ); ?></span></label>
			</section>

			<?php self::card( __( 'Languages', 'neurohr-hub' ), __( 'Content languages (the hub interface stays English). Link translations in the editor: each content is then listed once, in the reader\'s language; content without that translation shows in the language it has.', 'neurohr-hub' ) ); ?>
				<input type="hidden" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[lang_present]" value="1">
				<?php $nh_on = explode( ',', (string) $d['lang_enabled'] ); ?>
				<?php foreach ( NH_Lang::all() as $code => $info ) : ?>
					<label class="nh-toggle"><input type="checkbox" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[lang_enabled][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $nh_on, true ) ); ?>>
						<span><strong><?php echo esc_html( $info['name'] ); ?></strong><?php echo 'ms' === $code ? ' — ' . esc_html__( 'reserved: switch on when you start Malay content', 'neurohr-hub' ) : ''; ?></span></label>
				<?php endforeach; ?>
				<div class="nh-fields">
					<?php foreach ( array( 'lang_default_view' => __( 'Readers see first', 'neurohr-hub' ), 'lang_default_new' => __( 'Language of new resources', 'neurohr-hub' ) ) as $key => $label ) : ?>
						<div class="nh-field"><label><?php echo esc_html( $label ); ?></label>
							<select name="<?php echo esc_attr( NH_Display::OPTION . '[' . $key . ']' ); ?>">
								<?php foreach ( NH_Lang::enabled() as $code => $info ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $d[ $key ], $code ); ?>><?php echo esc_html( $info['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( 'lang_default_view' === $key ) : ?>
								<p class="description"><?php esc_html_e( 'Members can choose their own language in My account; the Language switch on section pages also changes it.', 'neurohr-hub' ); ?></p>
							<?php endif; ?></div>
					<?php endforeach; ?>
				</div>
			</section>

			<?php self::card( __( 'Content review', 'neurohr-hub' ), __( 'Update and re-check existing content instead of adding new versions. Tick "Reviewed today" in the editor (or use the bulk action in the list) after checking a resource.', 'neurohr-hub' ) ); ?>
				<div class="nh-fields">
					<div class="nh-field nh-field--short"><label for="nh-review-months"><?php esc_html_e( 'Due for review after (months)', 'neurohr-hub' ); ?></label>
						<input id="nh-review-months" type="number" min="1" max="60" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[review_months]" value="<?php echo (int) $d['review_months']; ?>">
						<p class="description"><?php esc_html_e( 'Listed on the Dashboard under Needs attention.', 'neurohr-hub' ); ?></p></div>
					<div class="nh-field nh-field--short"><label for="nh-updated-days"><?php esc_html_e( 'Show "Updated" badge for (days)', 'neurohr-hub' ); ?></label>
						<input id="nh-updated-days" type="number" min="0" max="365" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[updated_days]" value="<?php echo (int) $d['updated_days']; ?>">
						<p class="description"><?php esc_html_e( 'After a review, so members see what changed. 0 = never.', 'neurohr-hub' ); ?></p></div>
				</div>
			</section>

			<?php self::card( __( 'Section addresses', 'neurohr-hub' ), __( 'Change names, addresses and Resource Types in the Sections tab. Old addresses keep working (redirect). No normal page may use these addresses.', 'neurohr-hub' ) ); ?>
				<table class="nh-admin-table">
					<?php foreach ( NH_Sections::all() as $key => $section ) : ?>
						<tr><td><?php echo esc_html( $section['label'] ); ?></td><td><a href="<?php echo esc_url( NH_Rewrite::url( $key ) ); ?>" target="_blank"><?php echo esc_html( NH_Rewrite::url( $key ) ); ?></a></td></tr>
					<?php endforeach; ?>
					<tr><td><?php esc_html_e( 'Search all sections', 'neurohr-hub' ); ?></td><td><code><?php echo esc_html( NH_Rewrite::url( 'all' ) ); ?>?q=…</code></td></tr>
				</table>
			</section>

			<?php self::card( __( 'Upgrade buttons', 'neurohr-hub' ) ); ?>
				<div class="nh-field"><label for="nh-pricing"><?php esc_html_e( 'Pricing page', 'neurohr-hub' ); ?></label>
					<input id="nh-pricing" type="url" name="<?php echo esc_attr( NH_Access::OPTION_PRICE ); ?>" value="<?php echo esc_attr( get_option( NH_Access::OPTION_PRICE, '' ) ); ?>" placeholder="https://www.neurohr.com.my/pricing/">
					<p class="description"><?php esc_html_e( 'Used when a level has no upgrade link in NeuroHR Members (or no MemberPress sign-up page).', 'neurohr-hub' ); ?></p></div>
			</section>

			<?php self::card( __( 'Email (MailerLite)', 'neurohr-hub' ), __( 'Use this address in a MailerLite RSS campaign. It shows titles and excerpts only. Keep it private: the key is what lets MailerLite in.', 'neurohr-hub' ) ); ?>
				<code class="nh-code-block"><?php echo esc_html( NH_Login::feed_url() ); ?></code>
			</section>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_sections() {
		NH_Admin_Sections::render();
	}

	private static function tab_levels() {
		if ( NH_Access::members_active() ) {
			$max = NH_Access::max_rank();
			self::card( __( 'Membership levels', 'neurohr-hub' ), __( 'Levels come from NeuroHR Members. Rename, reorder, add or delete them there; the hub follows automatically. Higher levels read everything below them.', 'neurohr-hub' ) );
			echo '<table class="nh-admin-table"><thead><tr><th>' . esc_html__( 'Rank', 'neurohr-hub' ) . '</th><th>' . esc_html__( 'Level', 'neurohr-hub' ) . '</th><th>ID</th></tr></thead><tbody>';
			foreach ( NH_Access::levels() as $id => $level ) {
				echo '<tr><td>' . (int) $level['rank'] . '</td><td>' . esc_html( $level['name'] . ( $level['rank'] < $max ? ' +' : '' ) ) . '</td><td class="description">' . (int) $id . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( current_user_can( 'list_users' ) ) {
				echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-levels' ) ) . '">' . esc_html__( 'Edit levels in Members', 'neurohr-hub' ) . '</a></p>';
			}
			echo '</section>';
			return;
		}
		$map         = NH_Access::get_map();
		$memberships = get_posts( array( 'post_type' => 'memberpressproduct', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$labels      = nh_hub_level_labels();
		unset( $labels[0] );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'nh_hub_levels' ); ?>
			<?php self::card( __( 'MemberPress memberships', 'neurohr-hub' ), __( 'Map each membership to a hub level. Higher levels can read everything below them; a member with several memberships gets the highest.', 'neurohr-hub' ) ); ?>
				<?php if ( ! NH_Access::memberpress_active() ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'MemberPress is not active. Every logged-in user is treated as Free.', 'neurohr-hub' ); ?></p></div>
				<?php endif; ?>
				<table class="nh-admin-table">
					<thead><tr><th><?php esc_html_e( 'Membership', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'Hub level', 'neurohr-hub' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $memberships ) ) : ?>
						<tr><td colspan="2"><?php esc_html_e( 'No MemberPress memberships found.', 'neurohr-hub' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $memberships as $membership ) : ?>
						<?php $current = isset( $map[ $membership->ID ] ) ? $map[ $membership->ID ] : 0; ?>
						<tr>
							<td><?php echo esc_html( $membership->post_title ); ?> <span class="description">#<?php echo (int) $membership->ID; ?></span></td>
							<td><select name="<?php echo esc_attr( NH_Access::OPTION_MAP . '[' . $membership->ID . ']' ); ?>">
								<option value="0" <?php selected( $current, 0 ); ?>><?php esc_html_e( '— Not mapped —', 'neurohr-hub' ); ?></option>
								<?php foreach ( $labels as $level => $label ) : ?>
									<option value="<?php echo (int) $level; ?>" <?php selected( $current, $level ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_types() {
		$type_levels = NH_Access::get_type_levels();
		$type_terms  = NH_Types::terms();
		$sections    = NH_Sections::all();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'nh_hub_types' ); ?>
			<?php self::card( __( 'Resource types', 'neurohr-hub' ), __( 'Behaviour decides what a type is (its editor boxes and page layout); the level is used by every resource left on "Type default" (a level picked on a resource itself always wins). Types can be renamed freely in Knowledge Hub → Resource Types, also their slug.', 'neurohr-hub' ) ); ?>
				<table class="nh-admin-table" style="max-width:none">
					<thead><tr><th><?php esc_html_e( 'Resource type', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'Behaves as', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'Section', 'neurohr-hub' ); ?></th><th><?php esc_html_e( 'Default level', 'neurohr-hub' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $type_terms as $type_term ) : ?>
						<?php
						$tid     = (int) $type_term->term_id;
						$current = isset( $type_levels[ $tid ] ) ? $type_levels[ $tid ] : NH_Access::default_level();
						$owner   = NH_Sections::for_type_id( $tid );
						?>
						<tr>
							<td><strong><?php echo esc_html( $type_term->name ); ?></strong> <span class="description"><?php echo esc_html( $type_term->slug ); ?></span></td>
							<td><select name="nh_kind[<?php echo (int) $tid; ?>]">
								<?php foreach ( NH_Types::kinds() as $kind => $label ) : ?>
									<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( NH_Types::kind( $type_term ), $kind ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><?php echo $owner ? esc_html( $sections[ $owner ]['label'] ) : '<span style="color:#b32d2e">' . esc_html__( 'none — set it in Sections', 'neurohr-hub' ) . '</span>'; ?></td>
							<td><select name="<?php echo esc_attr( NH_Access::OPTION_TYPES . '[' . $tid . ']' ); ?>">
								<?php foreach ( nh_hub_level_labels( true ) as $level => $label ) : ?>
									<option value="<?php echo (int) $level; ?>" <?php selected( $current, $level ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_look() {
		$d = NH_Display::get();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'nh_hub_look' ); ?>
			<?php self::card( __( 'Brand colour', 'neurohr-hub' ), __( 'Titles, buttons, search, badges and the table of contents use it. It also overrides the theme\'s link and button colours inside the hub.', 'neurohr-hub' ) ); ?>
				<div class="nh-color-row">
					<input type="color" id="nh-color" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[color]" value="<?php echo esc_attr( $d['color'] ); ?>">
					<label for="nh-color"><code><?php echo esc_html( $d['color'] ); ?></code></label>
				</div>
			</section>

			<?php self::card( __( 'News posts', 'neurohr-hub' ) ); ?>
				<input type="hidden" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[style_posts]" value="0">
				<label class="nh-toggle"><input type="checkbox" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[style_posts]" value="1" <?php checked( ! empty( $d['style_posts'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Use the hub design for WordPress posts', 'neurohr-hub' ); ?></strong><br>
					<?php esc_html_e( 'Single posts, the posts page and post categories/tags. Posts are not part of the membership levels.', 'neurohr-hub' ); ?></span></label>
				<?php self::text_row( __( 'Name of the news section', 'neurohr-hub' ), 'news_label', $d['news_label'] ); ?>
				<div class="nh-field"><label for="nh-news-intro"><?php esc_html_e( 'Line under the title (news home)', 'neurohr-hub' ); ?></label>
					<textarea id="nh-news-intro" rows="2" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[news_intro]"><?php echo esc_textarea( $d['news_intro'] ); ?></textarea></div>
				<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: link */ __( 'News stays public. To tidy the categories once: <a href="%s">News Categories</a>.', 'neurohr-hub' ), esc_url( admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=nh-news-tidy' ) ) ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Set Settings → Reading → Posts page so the breadcrumb links to your news list.', 'neurohr-hub' ); ?></p>
			</section>

			<?php self::card( __( 'Print & PDF', 'neurohr-hub' ), __( 'Members who may read a resource get a "Print / PDF" button. The printout has only the article, with this notice at the end (and at the foot of every page in Chrome and Edge).', 'neurohr-hub' ) ); ?>
				<div class="nh-field"><label for="nh-print-notice"><?php esc_html_e( 'Copyright notice', 'neurohr-hub' ); ?></label>
					<textarea id="nh-print-notice" rows="3" name="<?php echo esc_attr( NH_Display::OPTION ); ?>[print_notice]"><?php echo esc_textarea( $d['print_notice'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'You can use: {year} {site} {name} {email} {date} {url} — {name} and {email} are the member who prints.', 'neurohr-hub' ); ?></p></div>
			</section>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_shortcodes() {
		NH_Shortcode_Docs::render();
	}

	private static function tab_changelog() {
		$log = include NH_HUB_DIR . 'includes/changelog.php';
		?>
		<?php self::card( sprintf( /* translators: %s: version */ __( 'Installed version %s', 'neurohr-hub' ), NH_HUB_VERSION ) ); ?>
			<?php foreach ( (array) $log as $version => $entry ) : ?>
				<div class="nh-changelog">
					<h3><?php echo esc_html( $version ); ?> <span><?php echo esc_html( $entry['date'] ); ?></span></h3>
					<ul>
						<?php foreach ( $entry['changes'] as $change ) : ?>
							<li><?php echo esc_html( $change ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}
}
