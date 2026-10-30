<?php
/**
 * Levels screen: rename, reorder (drag), pricing details, add, delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Levels {

	public static function init() {
		add_action( 'admin_post_nhm_save_levels', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nhm_add_level', array( __CLASS__, 'add' ) );
		add_action( 'admin_post_nhm_delete_level', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_nhm_pricing_preset', array( __CLASS__, 'preset' ) );
	}

	/** People and companies per level (own plans and company plans, active or not). */
	private static function usage() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS level, COUNT(*) AS n FROM $wpdb->usermeta WHERE meta_key = %s GROUP BY meta_value", NHM_Members::META_LEVEL ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['level'] ]['people'] = (int) $r['n'];
		}
		$table = NHM_DB::table( 'companies' );
		$rows  = $wpdb->get_results( "SELECT level_id AS level, COUNT(*) AS n FROM $table GROUP BY level_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['level'] ]['companies'] = (int) $r['n'];
		}
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$levels = NHM_Levels::all();
		$usage  = self::usage();
		$post   = admin_url( 'admin-post.php' );
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Levels', 'neurohr-members' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nhm-preset-form" style="float:right;margin:0 0 10px 16px">
			<input type="hidden" name="action" value="nhm_pricing_preset"><?php wp_nonce_field( 'nhm_pricing_preset' ); ?>
			<button type="submit" class="button" data-nhm-confirm="<?php esc_attr_e( 'Replace the pricing-table texts (line under the name, price text, note, label, button text, description, features, highlight) of Free, Lite, Pro and Corporate with the suggested texts? Prices, terms and access do not change.', 'neurohr-members' ); ?>"><?php esc_html_e( 'Fill in the suggested pricing texts', 'neurohr-members' ); ?></button>
		</form>
		<p class="description nhm-intro"><?php esc_html_e( 'The ladder from lowest to highest. A higher level can open everything a lower level can. Drag rows to change the order. "Same access as the level above" makes two levels equal (e.g. Consulting = Corporate). The first level is what every logged-in person has.', 'neurohr-members' ); ?></p>

		<form method="post" action="<?php echo esc_url( $post ); ?>" class="nhm-levels-form">
			<input type="hidden" name="action" value="nhm_save_levels">
			<?php wp_nonce_field( 'nhm_save_levels' ); ?>
			<div class="nhm-levels" data-nhm-sortable>
				<?php foreach ( $levels as $level ) : ?>
					<?php
					$id     = (int) $level['id'];
					$name   = 'levels[' . $id . ']';
					$people = isset( $usage[ $id ]['people'] ) ? $usage[ $id ]['people'] : 0;
					$comps  = isset( $usage[ $id ]['companies'] ) ? $usage[ $id ]['companies'] : 0;
					?>
					<details class="nhm-level<?php echo $level['is_base'] ? ' is-base' : ''; ?><?php echo $level['same_as_prev'] ? ' is-tied' : ''; ?>">
						<summary>
							<span class="nhm-level__handle dashicons <?php echo $level['is_base'] ? 'dashicons-lock' : 'dashicons-menu'; ?>" aria-hidden="true"></span>
							<span class="nhm-level__rank"><?php echo (int) $level['rank']; ?></span>
							<span class="nhm-level__name"><?php echo esc_html( $level['name'] ); ?></span>
							<?php if ( $level['same_as_prev'] ) : ?><span class="nhm-pill"><?php esc_html_e( '= level above', 'neurohr-members' ); ?></span><?php endif; ?>
							<?php if ( $level['is_base'] ) : ?><span class="nhm-pill"><?php esc_html_e( 'every logged-in person', 'neurohr-members' ); ?></span><?php else : ?><span class="nhm-pill nhm-pill--price"><?php echo esc_html( trim( NHM_Levels::price_text( $id ) . ' ' . NHM_Levels::term_label( $id ) ) ); ?></span><?php endif; ?>
							<span class="nhm-level__meta">
								<?php
								/* translators: 1: people, 2: companies */
								echo esc_html( $level['is_base'] ? '' : sprintf( __( '%1$d people · %2$d companies', 'neurohr-members' ), $people, $comps ) );
								?>
								<code>ID <?php echo (int) $id; ?></code>
							</span>
							<span class="nhm-level__edit"><?php esc_html_e( 'Edit', 'neurohr-members' ); ?></span>
						</summary>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo (int) $id; ?>">
						<div class="nhm-level__body">
							<?php if ( ! $level['is_base'] ) : ?>
								<div class="nhm-level__links">
									<span><?php esc_html_e( 'Registration shortcode', 'neurohr-members' ); ?> <code>[nhm_register level="<?php echo esc_html( $level['slug'] ); ?>"]</code></span>
									<span><?php esc_html_e( 'Link for your own buttons', 'neurohr-members' ); ?> <code><?php echo esc_html( NHM_Levels::register_url( $id ) ); ?></code></span>
								</div>
							<?php endif; ?>
							<?php if ( ! $level['is_base'] ) : ?>
							<h4 class="nhm-level__sub"><?php esc_html_e( 'Price and term', 'neurohr-members' ); ?></h4>
							<div class="nhm-fields nhm-term" data-nhm-term>
								<p class="nhm-field"><label><?php esc_html_e( 'Price', 'neurohr-members' ); ?> (<?php echo esc_html( NHM_Settings::get( 'currency' ) ); ?>)</label><input type="text" inputmode="decimal" name="<?php echo esc_attr( $name ); ?>[amount]" value="<?php echo esc_attr( number_format( (float) $level['amount'], 2, '.', '' ) ); ?>">
								<span class="description"><?php esc_html_e( '0 = free: choosing this level starts it at once, no invoice.', 'neurohr-members' ); ?></span></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Billing type', 'neurohr-members' ); ?></label>
									<select name="<?php echo esc_attr( $name ); ?>[billing]">
										<option value="one_time" <?php selected( $level['billing'], 'one_time' ); ?>><?php esc_html_e( 'One-time', 'neurohr-members' ); ?></option>
										<option value="recurring" <?php selected( $level['billing'], 'recurring' ); ?>><?php esc_html_e( 'Recurring (renewal invoice before the end)', 'neurohr-members' ); ?></option>
									</select></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Access', 'neurohr-members' ); ?></label>
									<select name="<?php echo esc_attr( $name ); ?>[access]" data-nhm-access>
										<option value="expire" <?php selected( $level['access'], 'expire' ); ?>><?php esc_html_e( 'Expire after…', 'neurohr-members' ); ?></option>
										<option value="lifetime" <?php selected( $level['access'], 'lifetime' ); ?>><?php esc_html_e( 'Lifetime', 'neurohr-members' ); ?></option>
										<option value="fixed" <?php selected( $level['access'], 'fixed' ); ?>><?php esc_html_e( 'Until a fixed date', 'neurohr-members' ); ?></option>
									</select></p>
								<p class="nhm-field" data-nhm-show="expire"><label><?php esc_html_e( 'Expire after', 'neurohr-members' ); ?></label>
									<span class="nhm-inline-fields"><input type="number" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[expire_n]" value="<?php echo (int) $level['expire_n']; ?>" style="width:80px">
									<select name="<?php echo esc_attr( $name ); ?>[expire_unit]">
										<?php foreach ( array( 'day' => __( 'days', 'neurohr-members' ), 'week' => __( 'weeks', 'neurohr-members' ), 'month' => __( 'months', 'neurohr-members' ), 'year' => __( 'years', 'neurohr-members' ) ) as $u => $ul ) : ?>
											<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $level['expire_unit'], $u ); ?>><?php echo esc_html( $ul ); ?></option>
										<?php endforeach; ?>
									</select></span>
									<label class="nhm-inline-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[early_renew]" value="1" <?php checked( $level['early_renew'] ); ?>> <?php esc_html_e( 'Allow early renewals (adds the term to the current last day)', 'neurohr-members' ); ?></label>
									<label class="nhm-inline-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[autorenew]" value="1" <?php checked( ! empty( $level['autorenew'] ) ); ?>> <?php esc_html_e( 'Offer card auto-renew (personal plans paid by card; off for company plans)', 'neurohr-members' ); ?></label></p>
								<p class="nhm-field" data-nhm-show="fixed"><label><?php esc_html_e( 'Last day', 'neurohr-members' ); ?></label><input type="date" name="<?php echo esc_attr( $name ); ?>[fixed_date]" value="<?php echo esc_attr( $level['fixed_date'] ); ?>"></p>
							</div>
							<p class="description nhm-term-preview">
							<?php
							$end = NHM_Levels::term_end( $id, NHM_Settings::today() );
							/* translators: 1: price, 2: term, 3: date */
							echo esc_html( sprintf( __( 'Now: %1$s %2$s. Bought today, it ends on %3$s.', 'neurohr-members' ), NHM_Levels::price_text( $id ), NHM_Levels::term_label( $id ), $end ? NHM_Emails::format_date( $end ) : __( 'never', 'neurohr-members' ) ) );
							?>
							</p>
							<h4 class="nhm-level__sub"><?php esc_html_e( 'Name and pricing table', 'neurohr-members' ); ?></h4>
							<?php endif; ?>
							<div class="nhm-fields">
								<p class="nhm-field"><label><?php esc_html_e( 'Name', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $level['name'] ); ?>"></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Short name for shortcodes', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $level['slug'] ); ?>">
								<span class="description"><?php echo esc_html( sprintf( '[nhm_if level="%s"]', $level['slug'] ) ); ?></span></p>
								<p class="nhm-field"><label><?php esc_html_e( 'One line under the name', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[tagline]" value="<?php echo esc_attr( $level['tagline'] ); ?>" placeholder="Simple tools for small teams"></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Price text on the pricing table', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( $level['price'] ); ?>" placeholder="<?php echo esc_attr( NHM_Levels::price_text( $id ) ); ?>">
								<span class="description"><?php esc_html_e( 'Empty = from the price above.', 'neurohr-members' ); ?></span></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Text after the price', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[period]" value="<?php echo esc_attr( $level['period'] ); ?>" placeholder="<?php echo esc_attr( NHM_Levels::term_label( $id ) ); ?>">
								<span class="description"><?php esc_html_e( 'Empty = from the term.', 'neurohr-members' ); ?></span></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Small text under the price', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[price_note]" value="<?php echo esc_attr( $level['price_note'] ); ?>" placeholder="≈ RM50 a month"></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Label above the card', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[badge]" value="<?php echo esc_attr( $level['badge'] ); ?>" placeholder="Most popular"></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Button text', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[button_label]" value="<?php echo esc_attr( $level['button_label'] ); ?>" placeholder="<?php esc_attr_e( 'Choose plan', 'neurohr-members' ); ?>"></p>
								<p class="nhm-field"><label><?php esc_html_e( 'Button / upgrade link', 'neurohr-members' ); ?></label><input type="text" name="<?php echo esc_attr( $name ); ?>[button_url]" value="<?php echo esc_attr( $level['button_url'] ); ?>" placeholder="/contact/ or https://wa.me/60…">
								<span class="description"><?php esc_html_e( 'Empty = the registration link above (members get an upgrade request with an invoice). Fill in only to send people elsewhere, e.g. a contact page.', 'neurohr-members' ); ?></span></p>
							</div>
							<p class="nhm-field"><label><?php esc_html_e( 'Short description', 'neurohr-members' ); ?></label><textarea rows="2" name="<?php echo esc_attr( $name ); ?>[description]"><?php echo esc_textarea( $level['description'] ); ?></textarea></p>
							<p class="nhm-field"><label><?php esc_html_e( 'What is included (one per line)', 'neurohr-members' ); ?></label><textarea rows="8" name="<?php echo esc_attr( $name ); ?>[features]"><?php echo esc_textarea( $level['features'] ); ?></textarea>
							<span class="description"><?php esc_html_e( 'Start a line with a minus (- HR videos) to show it greyed out as "not included", so every plan can list the same features in the same order. Put words between two stars to make them bold (Staff logins: **Up to 5**).', 'neurohr-members' ); ?></span></p>
							<p class="nhm-toggles">
								<?php if ( ! $level['is_base'] ) : ?>
									<label class="nhm-toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[same_as_prev]" value="1" <?php checked( $level['same_as_prev'] ); ?>> <span><strong><?php esc_html_e( 'Same access as the level above', 'neurohr-members' ); ?></strong></span></label>
								<?php endif; ?>
								<label class="nhm-toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[in_pricing]" value="1" <?php checked( $level['in_pricing'] ); ?>> <span><?php esc_html_e( 'Show on the pricing table', 'neurohr-members' ); ?></span></label>
								<label class="nhm-toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[highlight]" value="1" <?php checked( $level['highlight'] ); ?>> <span><?php esc_html_e( 'Highlight on the pricing table', 'neurohr-members' ); ?></span></label>
							</p>
							<?php if ( ! $level['is_base'] ) : ?>
								<p class="nhm-level__delete"><a href="#nhm-delete-<?php echo (int) $id; ?>" class="nhm-link-danger" data-nhm-open="nhm-delete-<?php echo (int) $id; ?>"><?php esc_html_e( 'Delete this level…', 'neurohr-members' ); ?></a></p>
							<?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
			<?php submit_button( __( 'Save levels', 'neurohr-members' ) ); ?>
		</form>

		<div class="nhm-admin-grid">
			<form class="nhm-admin-card" method="post" action="<?php echo esc_url( $post ); ?>">
				<input type="hidden" name="action" value="nhm_add_level">
				<?php wp_nonce_field( 'nhm_add_level' ); ?>
				<h2><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add a level', 'neurohr-members' ); ?></h2>
				<p class="nhm-admin-card__intro"><?php esc_html_e( 'It is added at the top of the ladder. Drag it to its place afterwards.', 'neurohr-members' ); ?></p>
				<p class="nhm-field"><input type="text" name="name" placeholder="<?php esc_attr_e( 'e.g. Enterprise', 'neurohr-members' ); ?>" required> <?php submit_button( __( 'Add level', 'neurohr-members' ), 'secondary', '', false ); ?></p>
			</form>
			<section class="nhm-admin-card">
				<h2><span class="dashicons dashicons-info-outline"></span><?php esc_html_e( 'Good to know', 'neurohr-members' ); ?></h2>
				<ul class="nhm-list">
					<li><?php esc_html_e( 'Renaming is safe: other plugins store the ID, not the name.', 'neurohr-members' ); ?></li>
					<li><?php esc_html_e( 'Moving a level changes what it can open in the Knowledge Hub and on protected pages.', 'neurohr-members' ); ?></li>
					<li><?php esc_html_e( 'Deleting asks where its people and companies go. Content and pages that needed it then need the next level up.', 'neurohr-members' ); ?></li>
				</ul>
			</section>
		</div>

		<?php foreach ( $levels as $level ) : ?>
			<?php
			if ( $level['is_base'] ) {
				continue;
			}
			$id = (int) $level['id'];
			?>
			<div class="nhm-modal" id="nhm-delete-<?php echo (int) $id; ?>" hidden>
				<form class="nhm-modal__box" method="post" action="<?php echo esc_url( $post ); ?>">
					<input type="hidden" name="action" value="nhm_delete_level">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'nhm_delete_level' ); ?>
					<h2>
					<?php
					/* translators: %s: level */
					echo esc_html( sprintf( __( 'Delete "%s"?', 'neurohr-members' ), $level['name'] ) );
					?>
					</h2>
					<p><label for="nhm-move-<?php echo (int) $id; ?>"><strong><?php esc_html_e( 'Move its people and companies to:', 'neurohr-members' ); ?></strong></label><br>
					<select name="move_to" id="nhm-move-<?php echo (int) $id; ?>">
						<?php foreach ( $levels as $other ) : ?>
							<?php if ( (int) $other['id'] !== $id ) : ?>
								<option value="<?php echo (int) $other['id']; ?>" <?php selected( NHM_Levels::successor( $id ), (int) $other['id'] ); ?>><?php echo esc_html( $other['name'] ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select></p>
					<p class="description">
					<?php
					/* translators: %s: level */
					echo esc_html( sprintf( __( 'Content and pages that needed this level will need %s.', 'neurohr-members' ), NHM_Levels::name( NHM_Levels::successor( $id ) ) ) );
					?>
					</p>
					<p class="nhm-modal__actions"><button type="button" class="button" data-nhm-close><?php esc_html_e( 'Keep it', 'neurohr-members' ); ?></button> <button type="submit" class="button button-primary nhm-button-danger"><?php esc_html_e( 'Delete level', 'neurohr-members' ); ?></button></p>
				</form>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	public static function save() {
		NHM_Admin::check( 'nhm_save_levels' );
		$rows = isset( $_POST['levels'] ) && is_array( $_POST['levels'] ) ? $_POST['levels'] : array(); // phpcs:ignore WordPress.Security -- sanitised in NHM_Levels::save() (it unslashes each field).
		$keyed = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$keyed[ (int) $row['id'] ] = $row;
			}
		}
		NHM_Levels::save( $keyed );
		NHM_Admin::flash( __( 'Levels saved.', 'neurohr-members' ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-levels' ) );
	}

	public static function preset() {
		NHM_Admin::check( 'nhm_pricing_preset' );
		$n = NHM_Levels::apply_pricing_preset();
		/* translators: %d: number of levels */
		NHM_Admin::flash( sprintf( __( 'Pricing texts filled in for %d levels. Check them below, then look at your pricing page.', 'neurohr-members' ), $n ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-levels' ) );
	}

	public static function add() {
		NHM_Admin::check( 'nhm_add_level' );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		NHM_Levels::add( $name );
		/* translators: %s: level */
		NHM_Admin::flash( sprintf( __( 'Level "%s" added at the top. Drag it to its place and save.', 'neurohr-members' ), esc_html( $name ) ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-levels' ) );
	}

	public static function delete() {
		NHM_Admin::check( 'nhm_delete_level' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$move = isset( $_POST['move_to'] ) ? absint( $_POST['move_to'] ) : 0;
		// phpcs:enable
		$name   = NHM_Levels::name( $id );
		$result = NHM_Levels::delete( $id, $move );
		if ( is_wp_error( $result ) ) {
			NHM_Admin::flash( $result->get_error_message(), 'error' );
		} else {
			/* translators: %s: level */
			NHM_Admin::flash( sprintf( __( 'Level "%s" deleted.', 'neurohr-members' ), esc_html( $name ) ) );
		}
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-levels' ) );
	}
}
