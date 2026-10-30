<?php
/**
 * Companies: list, add / edit (plan, seats, owner), people and invitations, delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Companies {

	public static function init() {
		add_action( 'admin_post_nhm_save_company', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nhm_company_people', array( __CLASS__, 'people' ) );
		add_action( 'admin_post_nhm_delete_company', array( __CLASS__, 'delete' ) );
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $action || 'new' === $action ) {
			self::edit_page();
			return;
		}
		self::list_page();
	}

	private static function list_page() {
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$companies = NHM_Companies::query( array( 'search' => $search ) );
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Companies', 'neurohr-members' ), '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=nhm-companies&action=new' ) ) . '">' . esc_html__( 'Add company', 'neurohr-members' ) . '</a>' );
		echo '<p class="description">' . esc_html__( 'A company has one plan and a number of seats. Its owner invites and removes staff from My account; you can do the same here.', 'neurohr-members' ) . '</p>';
		echo '<form class="nhm-filters" method="get"><input type="hidden" name="page" value="nhm-companies"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, client code, SSM no. or email', 'neurohr-members' ) . '">';
		submit_button( __( 'Search', 'neurohr-members' ), 'secondary', '', false );
		echo '</form>';
		echo '<div class="nhm-admin-card nhm-admin-card--flush"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Company', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Plan', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Until', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Seats', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Owner', 'neurohr-members' ) . '</th></tr></thead><tbody>';
		if ( ! $companies ) {
			echo '<tr><td colspan="6" class="nhm-empty">' . esc_html__( 'No companies yet. Add your Corporate and Consulting clients here.', 'neurohr-members' ) . '</td></tr>';
		}
		foreach ( $companies as $c ) {
			$owner = $c['owner_id'] ? get_userdata( $c['owner_id'] ) : null;
			$used  = NHM_Companies::seats_used( $c['id'] );
			echo '<tr><td><code>' . esc_html( $c['client_code'] ) . '</code></td><td><a class="nhm-name" href="' . esc_url( NHM_Companies::edit_url( $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a>';
			$sub = array_filter( array( $c['reg_no'] ? 'SSM ' . $c['reg_no'] : '', $c['city'], $c['industry'] ) );
			if ( $sub ) {
				echo '<br><span class="nhm-muted">' . esc_html( implode( ' · ', $sub ) ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . NHM_Admin::level_badge( $c['level_id'], ! NHM_Companies::is_active( $c ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . NHM_Admin::expiry_html( $c['expiry_date'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><span class="' . ( $used >= $c['seats'] ? 'nhm-exp--soon' : '' ) . '">' . (int) $used . ' / ' . (int) $c['seats'] . '</span></td>';
			echo '<td>' . ( $owner ? esc_html( $owner->display_name ) . '<br><span class="nhm-muted">' . esc_html( $owner->user_email ) . '</span>' : '<span class="nhm-muted">' . esc_html__( 'none yet', 'neurohr-members' ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function edit_page() {
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$company = $id ? NHM_Companies::get( $id ) : null;
		if ( $id && ! $company ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Company not found.', 'neurohr-members' ) . '</p></div>';
			return;
		}
		$c = $company ? $company : array(
			'id'          => 0,
			'name'        => '',
			'level_id'    => ( NHM_Levels::top() ? NHM_Levels::top()['id'] : NHM_Levels::base_id() ),
			'start_date'  => NHM_Settings::today(),
			'expiry_date' => NHM_Members::plus_year( '' ),
			'seats'       => 5,
			'owner_id'    => 0,
			'notes'       => '',
			'address'     => '',
			'employees'   => 0,
		);
		foreach ( NHM_Companies::text_fields() as $k => $len ) {
			if ( ! isset( $c[ $k ] ) ) {
				$c[ $k ] = 'country' === $k ? 'Malaysia' : '';
			}
		}
		$owner = $c['owner_id'] ? get_userdata( $c['owner_id'] ) : null;

		echo '<div class="wrap nhm-admin">';
		$actions = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-companies' ) ) . '">&larr; ' . esc_html__( 'All companies', 'neurohr-members' ) . '</a>';
		if ( $company ) {
			$actions .= ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices&company=' . (int) $company['id'] ) ) . '">' . esc_html__( 'Invoices', 'neurohr-members' ) . '</a>';
			$actions .= ' <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices&action=new&company=' . (int) $company['id'] . '&level=' . (int) $company['level_id'] ) ) . '">' . esc_html__( 'Create invoice / renewal', 'neurohr-members' ) . '</a>';
		}
		NHM_Admin::header( $company ? $company['name'] . ( $company['client_code'] ? ' · ' . $company['client_code'] : '' ) : __( 'Add company', 'neurohr-members' ), $actions );
		?>
		<div class="nhm-admin-grid nhm-admin-grid--company">
		<form class="nhm-admin-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="nhm_save_company">
			<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
			<?php wp_nonce_field( 'nhm_save_company' ); ?>
			<h2><span class="dashicons dashicons-building"></span><?php esc_html_e( 'Company and plan', 'neurohr-members' ); ?></h2>
			<div class="nhm-fields">
				<p class="nhm-field"><label for="nhm-c-name"><?php esc_html_e( 'Company name', 'neurohr-members' ); ?></label><input type="text" id="nhm-c-name" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" required></p>
				<p class="nhm-field"><label for="nhm-c-level"><?php esc_html_e( 'Plan', 'neurohr-members' ); ?></label><?php echo NHM_Admin::level_select( 'level_id', $c['level_id'], array( 'id' => 'nhm-c-level' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<p class="nhm-field"><label for="nhm-c-code"><?php esc_html_e( 'Client code', 'neurohr-members' ); ?></label><input type="text" id="nhm-c-code" name="client_code" value="<?php echo esc_attr( $c['client_code'] ); ?>" placeholder="<?php esc_attr_e( 'Your own code, e.g. BP-2026-014', 'neurohr-members' ); ?>"><span class="description"><?php esc_html_e( 'Same as in your own records. Optional; must be unique.', 'neurohr-members' ); ?></span></p>
				<p class="nhm-field"><label for="nhm-c-start"><?php esc_html_e( 'Start date', 'neurohr-members' ); ?></label><input type="date" id="nhm-c-start" name="start_date" value="<?php echo esc_attr( $c['start_date'] ); ?>"></p>
				<p class="nhm-field"><label for="nhm-c-exp"><?php esc_html_e( 'Last day', 'neurohr-members' ); ?></label><?php echo NHM_Admin::expiry_input( 'expiry_date', $c['expiry_date'], 'nhm-c-exp', 'nhm-c-level' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<p class="nhm-field nhm-field--short"><label for="nhm-c-seats"><?php esc_html_e( 'Seats', 'neurohr-members' ); ?></label><input type="number" min="1" id="nhm-c-seats" name="seats" value="<?php echo (int) $c['seats']; ?>">
				<?php if ( $company ) : ?>
					<span class="description">
					<?php
					/* translators: %d: seats used */
					echo esc_html( sprintf( __( '%d in use (people + open invitations)', 'neurohr-members' ), NHM_Companies::seats_used( $c['id'] ) ) );
					?>
					</span>
				<?php endif; ?></p>
				<p class="nhm-field"><label for="nhm-c-owner"><?php esc_html_e( 'Owner email', 'neurohr-members' ); ?></label><input type="email" id="nhm-c-owner" name="owner_email" value="<?php echo esc_attr( $owner ? $owner->user_email : '' ); ?>" placeholder="hr@client.com.my">
				<span class="description"><?php esc_html_e( 'The person at the client who manages staff accounts. If they have no account yet, they get an invitation email and become owner when they sign up.', 'neurohr-members' ); ?></span></p>
			</div>
			<h3 class="nhm-subhead"><?php esc_html_e( 'Billing details (shown on invoices)', 'neurohr-members' ); ?></h3>
			<div class="nhm-fields">
				<?php
				$labels = array(
					'reg_no'         => __( 'Registration no. (SSM)', 'neurohr-members' ),
					'tin'            => __( 'TIN', 'neurohr-members' ),
					'sst_no'         => __( 'SST no.', 'neurohr-members' ),
					'contact_person' => __( 'Contact person', 'neurohr-members' ),
					'billing_email'  => __( 'Billing email', 'neurohr-members' ),
					'phone'          => __( 'Phone', 'neurohr-members' ),
				);
				foreach ( $labels as $k => $label ) :
					?>
					<p class="nhm-field"><label for="nhm-c-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input type="<?php echo 'billing_email' === $k ? 'email' : 'text'; ?>" id="nhm-c-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $c[ $k ] ); ?>"></p>
				<?php endforeach; ?>
			</div>
			<p class="nhm-field"><label for="nhm-c-address"><?php esc_html_e( 'Address', 'neurohr-members' ); ?></label><textarea id="nhm-c-address" name="address" rows="3" placeholder="<?php esc_attr_e( 'Street, building, unit (one line each)', 'neurohr-members' ); ?>"><?php echo esc_textarea( $c['address'] ); ?></textarea></p>
			<div class="nhm-fields">
				<?php
				$labels = array(
					'postcode' => __( 'Postcode', 'neurohr-members' ),
					'city'     => __( 'City', 'neurohr-members' ),
					'state'    => __( 'State', 'neurohr-members' ),
					'country'  => __( 'Country', 'neurohr-members' ),
					'industry' => __( 'Industry', 'neurohr-members' ),
				);
				foreach ( $labels as $k => $label ) :
					?>
					<p class="nhm-field"><label for="nhm-c-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input type="text" id="nhm-c-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $c[ $k ] ); ?>"<?php echo 'state' === $k ? ' list="nhm-states"' : ''; ?>></p>
				<?php endforeach; ?>
				<p class="nhm-field nhm-field--short"><label for="nhm-c-employees"><?php esc_html_e( 'Employees', 'neurohr-members' ); ?></label><input type="number" min="0" id="nhm-c-employees" name="employees" value="<?php echo (int) $c['employees']; ?>"></p>
			</div>
			<datalist id="nhm-states"><?php foreach ( array( 'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya' ) as $st ) : ?><option value="<?php echo esc_attr( $st ); ?>"><?php endforeach; ?></datalist>
			<p class="nhm-field"><label for="nhm-c-notes"><?php esc_html_e( 'Notes (only admins)', 'neurohr-members' ); ?></label><textarea id="nhm-c-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Contract no., invoice, contact person…', 'neurohr-members' ); ?>"><?php echo esc_textarea( $c['notes'] ); ?></textarea></p>
			<?php submit_button( $company ? __( 'Save company', 'neurohr-members' ) : __( 'Create company', 'neurohr-members' ) ); ?>
		</form>

		<?php if ( $company ) : ?>
			<?php self::people_card( $company ); ?>
		<?php endif; ?>
		</div>

		<?php if ( $company ) : ?>
			<section class="nhm-admin-card">
				<h2><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'History', 'neurohr-members' ); ?></h2>
				<?php NHM_Log::render( NHM_Log::query( array( 'company_id' => $company['id'], 'limit' => 50 ) ), true ); ?>
			</section>
			<form class="nhm-danger" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nhm_delete_company">
				<input type="hidden" name="id" value="<?php echo (int) $company['id']; ?>">
				<?php wp_nonce_field( 'nhm_delete_company' ); ?>
				<button type="submit" class="button-link nhm-link-danger" data-nhm-confirm="<?php esc_attr_e( 'Delete this company? Its people keep their accounts but lose the company plan.', 'neurohr-members' ); ?>"><?php esc_html_e( 'Delete company', 'neurohr-members' ); ?></button>
			</form>
		<?php endif; ?>
		</div>
		<?php
	}

	private static function people_card( $company ) {
		$members = NHM_Companies::members( $company['id'] );
		$invites = NHM_Companies::invites( $company['id'] );
		$post    = admin_url( 'admin-post.php' );
		?>
		<section class="nhm-admin-card">
			<h2><span class="dashicons dashicons-groups"></span><?php esc_html_e( 'People', 'neurohr-members' ); ?>
				<span class="nhm-pill"><?php echo (int) NHM_Companies::seats_used( $company['id'] ) . ' / ' . (int) $company['seats']; ?></span></h2>
			<table class="widefat striped">
				<tbody>
				<?php if ( ! $members && ! $invites ) : ?>
					<tr><td class="nhm-empty"><?php esc_html_e( 'Nobody yet.', 'neurohr-members' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $members as $u ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) . '#nhm' ); ?>"><?php echo esc_html( $u->display_name ); ?></a>
							<?php if ( (int) $u->ID === (int) $company['owner_id'] ) : ?><span class="nhm-pill"><?php esc_html_e( 'owner', 'neurohr-members' ); ?></span><?php endif; ?>
							<br><span class="nhm-muted"><?php echo esc_html( $u->user_email ); ?></span></td>
						<td class="nhm-actions-cell">
							<?php if ( (int) $u->ID !== (int) $company['owner_id'] ) : ?>
								<?php self::people_button( $company['id'], 'owner', $u->ID, __( 'Make owner', 'neurohr-members' ) ); ?>
							<?php endif; ?>
							<?php self::people_button( $company['id'], 'remove', $u->ID, __( 'Remove', 'neurohr-members' ), __( 'Remove this person from the company?', 'neurohr-members' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $invites as $inv ) : ?>
					<tr class="nhm-pending">
						<td><span class="nhm-pill nhm-pill--muted"><?php echo 'owner' === $inv['role'] ? esc_html__( 'Invited as owner', 'neurohr-members' ) : esc_html__( 'Invited', 'neurohr-members' ); ?></span> <?php echo esc_html( $inv['email'] ); ?>
							<br><span class="nhm-muted">
							<?php
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'link valid until %s', 'neurohr-members' ), mysql2date( 'j M Y', $inv['expires_at'] ) ) );
							?>
							</span></td>
						<td class="nhm-actions-cell">
							<?php self::people_button( $company['id'], 'resend', $inv['id'], __( 'Send again', 'neurohr-members' ) ); ?>
							<?php self::people_button( $company['id'], 'cancel', $inv['id'], __( 'Cancel', 'neurohr-members' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( $post ); ?>" class="nhm-add-people">
				<input type="hidden" name="action" value="nhm_company_people">
				<input type="hidden" name="do" value="add">
				<input type="hidden" name="id" value="<?php echo (int) $company['id']; ?>">
				<?php wp_nonce_field( 'nhm_company_people' ); ?>
				<p class="nhm-field"><label for="nhm-add-emails"><?php esc_html_e( 'Add people (one email per line)', 'neurohr-members' ); ?></label>
				<textarea id="nhm-add-emails" name="emails" rows="3" placeholder="staff1@client.com.my&#10;staff2@client.com.my"></textarea>
				<span class="description"><?php esc_html_e( 'People who already have an account are added straight away. Everyone else gets an invitation email.', 'neurohr-members' ); ?></span></p>
				<?php submit_button( __( 'Add / invite', 'neurohr-members' ), 'secondary', '', false ); ?>
			</form>
		</section>
		<?php
	}

	private static function people_button( $company_id, $do, $target, $label, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nhm-inline">';
		echo '<input type="hidden" name="action" value="nhm_company_people"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<input type="hidden" name="id" value="' . (int) $company_id . '"><input type="hidden" name="target" value="' . (int) $target . '">';
		wp_nonce_field( 'nhm_company_people' );
		echo '<button type="submit" class="button button-small"' . ( $confirm ? ' data-nhm-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}

	/* ---------- Actions ---------- */

	public static function save() {
		NHM_Admin::check( 'nhm_save_company' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in NHM_Companies.
			'level_id'    => isset( $_POST['level_id'] ) ? absint( $_POST['level_id'] ) : 0,
			'start_date'  => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			'expiry_date' => isset( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : '',
			'seats'       => isset( $_POST['seats'] ) ? absint( $_POST['seats'] ) : 1,
			'notes'       => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'address'     => isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'employees'   => isset( $_POST['employees'] ) ? absint( $_POST['employees'] ) : 0,
		);
		foreach ( NHM_Companies::text_fields() as $k => $len ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = wp_unslash( $_POST[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in NHM_Companies.
			}
		}
		$owner_email = isset( $_POST['owner_email'] ) ? sanitize_email( wp_unslash( $_POST['owner_email'] ) ) : '';
		// phpcs:enable

		if ( $id ) {
			if ( ! NHM_Companies::get( $id ) ) {
				NHM_Admin::back( admin_url( 'admin.php?page=nhm-companies' ) );
			}
			$code_before = NHM_Companies::get( $id )['client_code'];
			NHM_Companies::update( $id, $data );
			if ( isset( $data['client_code'] ) && '' !== trim( $data['client_code'] ) && NHM_Companies::get( $id )['client_code'] !== trim( $data['client_code'] ) ) {
				/* translators: %s: code */
				NHM_Admin::flash( sprintf( __( 'Client code %s is already used by another company; the old code was kept.', 'neurohr-members' ), esc_html( trim( $data['client_code'] ) ) ), 'warning' );
			}
			NHM_Admin::flash( __( 'Company saved.', 'neurohr-members' ) );
		} else {
			$id = NHM_Companies::create( $data );
			if ( is_wp_error( $id ) ) {
				NHM_Admin::flash( $id->get_error_message(), 'error' );
				NHM_Admin::back( admin_url( 'admin.php?page=nhm-companies&action=new' ) );
			}
			NHM_Admin::flash( __( 'Company created.', 'neurohr-members' ) );
		}

		if ( $owner_email ) {
			self::set_owner_by_email( $id, $owner_email );
		}
		NHM_Admin::back( NHM_Companies::edit_url( $id ) );
	}

	private static function set_owner_by_email( $company_id, $email ) {
		$company = NHM_Companies::get( $company_id );
		$current = $company['owner_id'] ? get_userdata( $company['owner_id'] ) : null;
		if ( $current && strtolower( $current->user_email ) === strtolower( $email ) ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$result = NHM_Companies::add_member( $company_id, $user->ID, 'owner' );
			if ( is_wp_error( $result ) ) {
				NHM_Admin::flash( $result->get_error_message(), 'error' );
				return;
			}
			NHM_Companies::set_owner( $company_id, $user->ID );
			/* translators: %s: name */
			NHM_Admin::flash( sprintf( __( '%s is now the owner.', 'neurohr-members' ), esc_html( $user->display_name ) ) );
			return;
		}
		$result = NHM_Companies::invite( $company_id, $email, 'owner', get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			NHM_Admin::flash( $result->get_error_message(), 'error' );
			return;
		}
		/* translators: %s: email */
		NHM_Admin::flash( sprintf( __( 'Invitation sent to %s. They become owner when they accept.', 'neurohr-members' ), esc_html( $email ) ) );
	}

	public static function people() {
		NHM_Admin::check( 'nhm_company_people' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$target  = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		$company = NHM_Companies::get( $id );
		if ( ! $company ) {
			NHM_Admin::back( admin_url( 'admin.php?page=nhm-companies' ) );
		}
		switch ( $do ) {
			case 'add':
				$emails = isset( $_POST['emails'] ) ? preg_split( '/[\s,;]+/', (string) wp_unslash( $_POST['emails'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each email sanitised below.
				$added  = 0;
				$sent   = 0;
				foreach ( array_filter( array_map( 'sanitize_email', $emails ) ) as $email ) {
					$user   = get_user_by( 'email', $email );
					$result = $user ? NHM_Companies::add_member( $id, $user->ID ) : NHM_Companies::invite( $id, $email, 'member', get_current_user_id() );
					if ( is_wp_error( $result ) ) {
						NHM_Admin::flash( esc_html( $email ) . ': ' . $result->get_error_message(), 'error' );
					} elseif ( $user ) {
						$added++;
					} else {
						$sent++;
					}
				}
				if ( $added || $sent ) {
					/* translators: 1: number added, 2: number invited */
					NHM_Admin::flash( sprintf( __( '%1$d added, %2$d invited.', 'neurohr-members' ), $added, $sent ) );
				}
				break;
			case 'remove':
				NHM_Companies::remove_member( $id, $target );
				NHM_Admin::flash( __( 'Removed from the company.', 'neurohr-members' ) );
				break;
			case 'owner':
				if ( (int) get_user_meta( $target, NHM_Members::META_COMPANY, true ) === $id ) {
					NHM_Companies::set_owner( $id, $target );
					NHM_Admin::flash( __( 'Owner changed.', 'neurohr-members' ) );
				}
				break;
			case 'resend':
				$invite = NHM_Companies::invite_row( $target );
				if ( $invite && (int) $invite['company_id'] === $id ) {
					NHM_Companies::resend( $target, get_current_user_id() );
					NHM_Admin::flash( __( 'Invitation sent again.', 'neurohr-members' ) );
				}
				break;
			case 'cancel':
				$invite = NHM_Companies::invite_row( $target );
				if ( $invite && (int) $invite['company_id'] === $id ) {
					NHM_Companies::cancel_invite( $target );
					NHM_Admin::flash( __( 'Invitation cancelled.', 'neurohr-members' ) );
				}
				break;
		}
		// phpcs:enable
		NHM_Admin::back( NHM_Companies::edit_url( $id ) );
	}

	public static function delete() {
		NHM_Admin::check( 'nhm_delete_company' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		NHM_Companies::delete( $id );
		NHM_Admin::flash( __( 'Company deleted.', 'neurohr-members' ) );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-companies' ) );
	}
}
