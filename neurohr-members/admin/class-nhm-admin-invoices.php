<?php
/**
 * Invoices: list (tabs per status), write / edit, mark paid, send, void, PDF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Invoices {

	public static function init() {
		add_action( 'admin_post_nhm_save_invoice', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nhm_invoice_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_nhm_invoice_pdf', array( __CLASS__, 'pdf' ) );
		add_action( 'admin_post_nhm_invoice_preview', array( __CLASS__, 'preview' ) );
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

	public static function status_badge( $inv ) {
		$label = NHM_Invoices::status_label( $inv );
		$class = 'paid' === $inv['status'] ? 'ok' : ( 'void' === $inv['status'] ? 'muted' : ( 'Overdue' === $label ? 'late' : 'open' ) );
		return '<span class="nhm-status nhm-status--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function list_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$user    = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$company = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0;
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$per     = 50;
		$args    = array( 'search' => $search, 'user_id' => $user, 'company_id' => $company, 'limit' => $per + 1, 'offset' => ( $paged - 1 ) * $per );
		if ( 'pending' === $status ) {
			$args['pending'] = true;
		} elseif ( $status ) {
			$args['status'] = $status;
		}
		$rows    = NHM_Invoices::query( $args );
		$more    = count( $rows ) > $per;
		$rows    = array_slice( $rows, 0, $per );
		$sum     = NHM_Invoices::summary();
		$pending = count( NHM_Invoices::query( array( 'pending' => true, 'limit' => 999 ) ) );
		$base    = admin_url( 'admin.php?page=nhm-invoices' );
		$keep    = array_filter( array( 'user' => $user, 'company' => $company, 's' => $search ) );

		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Invoices', 'neurohr-members' ), '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices&action=new' ) ) . '">' . esc_html__( 'New invoice', 'neurohr-members' ) . '</a>' );
		$tabs = array(
			''        => array( __( 'All', 'neurohr-members' ), array_sum( wp_list_pluck( $sum, 'n' ) ) ),
			'pending' => array( __( 'Waiting to start a plan', 'neurohr-members' ), $pending ),
			'unpaid'  => array( __( 'Unpaid', 'neurohr-members' ), $sum['unpaid']['n'] ),
			'paid'    => array( __( 'Paid', 'neurohr-members' ), $sum['paid']['n'] ),
			'void'    => array( __( 'Void', 'neurohr-members' ), $sum['void']['n'] ),
		);
		echo '<nav class="nhm-admin-tabs">';
		foreach ( $tabs as $key => $t ) {
			$class = $key === $status ? 'is-active' : '';
			if ( 'pending' === $key && $t[1] ) {
				$class .= ' is-warning';
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( array_merge( $keep, array( 'status' => $key ) ), $base ) ) . '">' . esc_html( $t[0] ) . ' <span class="count">' . (int) $t[1] . '</span></a>';
		}
		echo '</nav>';
		/* translators: 1: unpaid total, 2: paid total */
		echo '<p class="description">' . esc_html( sprintf( __( 'Unpaid: %1$s · Paid (all time): %2$s', 'neurohr-members' ), NHM_Settings::money( $sum['unpaid']['amount'] ), NHM_Settings::money( $sum['paid']['amount'] ) ) ) . '</p>';
		echo '<form class="nhm-filters" method="get"><input type="hidden" name="page" value="nhm-invoices"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Invoice no., name, company', 'neurohr-members' ) . '">';
		submit_button( __( 'Search', 'neurohr-members' ), 'secondary', '', false );
		if ( $keep ) {
			echo ' <a class="button-link" href="' . esc_url( add_query_arg( 'status', $status, $base ) ) . '">' . esc_html__( 'Show all', 'neurohr-members' ) . '</a>';
		}
		echo '</form>';

		echo '<div class="nhm-admin-card nhm-admin-card--flush"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Invoice', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Date', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Bill to', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Plan', 'neurohr-members' ) . '</th><th class="num">' . esc_html__( 'Total', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7" class="nhm-empty">' . esc_html__( 'No invoices here.', 'neurohr-members' ) . '</td></tr>';
		}
		foreach ( $rows as $inv ) {
			echo '<tr><td><a class="nhm-name" href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) ) . '">' . esc_html( $inv['number'] ) . '</a>' . ( 'manual' !== $inv['kind'] ? '<br><span class="nhm-muted">' . esc_html( ucfirst( $inv['kind'] ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( mysql2date( 'j M Y', $inv['issue_date'] ) ) . '</td>';
			echo '<td>' . esc_html( isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ) . '<br><span class="nhm-muted">' . esc_html( isset( $inv['bill_to']['email'] ) ? $inv['bill_to']['email'] : '' ) . '</span></td>';
			echo '<td>' . ( $inv['level_id'] ? NHM_Admin::level_badge( $inv['level_id'], ! $inv['granted'] ) . ( $inv['granted'] ? '' : ' <span class="nhm-muted">' . esc_html__( 'starts when paid', 'neurohr-members' ) . '</span>' ) : '<span class="nhm-muted">—</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="num"><strong>' . esc_html( NHM_Settings::money( $inv['total'] ) ) . '</strong></td>';
			echo '<td>' . self::status_badge( $inv ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="nhm-actions-cell"><a class="button button-small" href="' . esc_url( NHM_Invoices::admin_pdf_url( $inv ) ) . '" target="_blank">PDF</a>';
			if ( 'unpaid' === $inv['status'] ) {
				echo ' <a class="button button-small button-primary" href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) . '#nhm-pay' ) . '">' . esc_html__( 'Mark paid', 'neurohr-members' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="nhm-pager">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">&larr;</a> ';
		}
		if ( $more ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">&rarr;</a>';
		}
		echo '</p></div>';
	}

	private static function edit_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only.
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$inv  = $id ? NHM_Invoices::get( $id ) : null;
		$pre_user    = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$pre_company = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0;
		$pre_level   = isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0;
		// phpcs:enable
		if ( $id && ! $inv ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Invoice not found.', 'neurohr-members' ) . '</p></div>';
			return;
		}
		$editable = ! $inv || 'unpaid' === $inv['status'];
		if ( ! $inv ) {
			$level = NHM_Levels::get( $pre_level );
			$level = $level && ! $level['is_base'] ? $level : null;
			$inv   = array(
				'id'            => 0,
				'number'        => '',
				'user_id'       => $pre_company ? 0 : $pre_user,
				'company_id'    => $pre_company,
				'status'        => 'unpaid',
				'issue_date'    => NHM_Settings::today(),
				'due_date'      => NHM_Invoices::due_date(),
				'items'         => array(),
				'discount'      => 0,
				'tax_rate'      => NHM_Invoices::default_tax_rate(),
				'tax_inclusive' => NHM_Invoices::default_inclusive() ? 1 : 0,
				'terms'         => 1,
				'notes'         => '',
				'level_id'      => $level ? $level['id'] : 0,
				'grant_to'      => $pre_company ? 'company' : 'user',
				'granted'       => 0,
				'bill_to'       => NHM_Invoices::bill_to_for( $pre_user, $pre_company ),
				'total'         => $level ? (float) $level['amount'] : 0,
			);
		}
		// Price per term: the invoice's own (it may be a negotiated price), else the level price.
		$unit = '';
		if ( ! empty( $inv['items'] ) ) {
			$unit = (float) $inv['items'][0]['price'];
		} elseif ( $inv['level_id'] && NHM_Levels::get( $inv['level_id'] ) ) {
			$unit = (float) NHM_Levels::get( $inv['level_id'] )['amount'];
		}
		$legacy = count( $inv['items'] ) > 1 || ( $inv['id'] && ! $inv['level_id'] );
		$user  = $inv['user_id'] ? get_userdata( $inv['user_id'] ) : null;
		$post  = admin_url( 'admin-post.php' );
		$title = $inv['id'] ? sprintf( /* translators: %s: number */ __( 'Invoice %s', 'neurohr-members' ), $inv['number'] ) : __( 'New invoice', 'neurohr-members' );
		$acts  = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=nhm-invoices' ) ) . '">&larr; ' . esc_html__( 'All invoices', 'neurohr-members' ) . '</a>';
		if ( $inv['id'] ) {
			$acts .= ' <a class="button" target="_blank" href="' . esc_url( NHM_Invoices::admin_pdf_url( $inv ) ) . '">' . esc_html__( 'View PDF', 'neurohr-members' ) . '</a>';
		}
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( $title, $acts );
		?>
		<div class="nhm-admin-grid nhm-admin-grid--invoice">
		<form class="nhm-admin-card" method="post" action="<?php echo esc_url( $post ); ?>" data-nhm-invoice>
			<input type="hidden" name="action" value="nhm_save_invoice">
			<input type="hidden" name="id" value="<?php echo (int) $inv['id']; ?>">
			<?php wp_nonce_field( 'nhm_save_invoice' ); ?>
			<h2><span class="dashicons dashicons-media-spreadsheet"></span><?php esc_html_e( 'Invoice', 'neurohr-members' ); ?> <?php echo $inv['id'] ? self::status_badge( $inv ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
			<?php if ( ! $editable ) : ?>
				<p class="description"><?php esc_html_e( 'Paid and void invoices cannot be changed (they are accounting records). You can still edit the note.', 'neurohr-members' ); ?></p>
			<?php endif; ?>
			<fieldset <?php disabled( ! $editable ); ?>>
			<div class="nhm-fields">
				<p class="nhm-field"><label><?php esc_html_e( 'Bill to', 'neurohr-members' ); ?></label>
					<label class="nhm-radio"><input type="radio" name="bill" value="user" <?php checked( ! $inv['company_id'] ); ?> data-nhm-bill> <?php esc_html_e( 'A person', 'neurohr-members' ); ?></label>
					<label class="nhm-radio"><input type="radio" name="bill" value="company" <?php checked( (bool) $inv['company_id'] ); ?> data-nhm-bill> <?php esc_html_e( 'A company', 'neurohr-members' ); ?></label></p>
				<p class="nhm-field" data-nhm-bill-user><label for="nhm-i-user"><?php esc_html_e( 'Person (email)', 'neurohr-members' ); ?></label><input type="email" id="nhm-i-user" name="user_email" value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>" placeholder="name@example.com"></p>
				<p class="nhm-field" data-nhm-bill-company><label for="nhm-i-company"><?php esc_html_e( 'Company', 'neurohr-members' ); ?></label>
					<select id="nhm-i-company" name="company_id"><option value="0"><?php esc_html_e( '— choose —', 'neurohr-members' ); ?></option>
					<?php foreach ( NHM_Companies::query() as $c ) : ?>
						<option value="<?php echo (int) $c['id']; ?>" <?php selected( $inv['company_id'], $c['id'] ); ?>><?php echo esc_html( $c['name'] . ( $c['client_code'] ? ' (' . $c['client_code'] . ')' : '' ) ); ?></option>
					<?php endforeach; ?>
					</select></p>
				<p class="nhm-field"><label for="nhm-i-date"><?php esc_html_e( 'Invoice date', 'neurohr-members' ); ?></label><input type="date" id="nhm-i-date" name="issue_date" value="<?php echo esc_attr( $inv['issue_date'] ); ?>"></p>
				<p class="nhm-field"><label for="nhm-i-due"><?php esc_html_e( 'Due date', 'neurohr-members' ); ?></label><input type="date" id="nhm-i-due" name="due_date" value="<?php echo esc_attr( $inv['due_date'] ); ?>"></p>
			</div>

			<h3 class="nhm-subhead"><?php esc_html_e( 'Plan', 'neurohr-members' ); ?></h3>
			<?php if ( $legacy ) : ?>
				<p class="description"><?php esc_html_e( 'This invoice was made with free lines (version 0.2). It is shown as it is.', 'neurohr-members' ); ?></p>
				<ul class="nhm-list"><?php foreach ( $inv['items'] as $it ) : ?><li><?php echo esc_html( $it['description'] . ' — ' . NHM_Settings::money( $it['amount'] ) ); ?></li><?php endforeach; ?></ul>
			<?php else : ?>
			<div class="nhm-fields">
				<p class="nhm-field"><label for="nhm-i-level"><?php esc_html_e( 'Plan', 'neurohr-members' ); ?> <span class="nhm-req">*</span></label>
					<select id="nhm-i-level" name="level_id" required data-nhm-plan>
						<option value=""><?php esc_html_e( '— choose a plan —', 'neurohr-members' ); ?></option>
						<?php foreach ( NHM_Levels::all() as $lv ) : ?>
							<?php
							if ( $lv['is_base'] ) {
								continue;
							}
							?>
							<option value="<?php echo (int) $lv['id']; ?>" data-amount="<?php echo esc_attr( number_format( (float) $lv['amount'], 2, '.', '' ) ); ?>" data-term="<?php echo esc_attr( NHM_Levels::duration_label( $lv['id'], 1 ) ); ?>" <?php selected( (int) $inv['level_id'], (int) $lv['id'] ); ?>><?php echo esc_html( $lv['name'] . ' — ' . NHM_Levels::price_text( $lv['id'] ) . ' ' . NHM_Levels::term_label( $lv['id'] ) ); ?></option>
						<?php endforeach; ?>
					</select></p>
				<p class="nhm-field nhm-field--short"><label for="nhm-i-terms"><?php esc_html_e( 'Terms', 'neurohr-members' ); ?></label>
					<select id="nhm-i-terms" name="terms" data-nhm-calc>
						<?php for ( $t = 1; $t <= 3; $t++ ) : ?><option value="<?php echo (int) $t; ?>" <?php selected( (int) $inv['terms'], $t ); ?>><?php echo (int) $t; ?></option><?php endfor; ?>
					</select>
					<span class="description" data-nhm-term-label></span></p>
				<p class="nhm-field"><label for="nhm-i-price"><?php esc_html_e( 'Price per term', 'neurohr-members' ); ?> (<?php echo esc_html( NHM_Settings::get( 'currency' ) ); ?>)</label><input type="text" inputmode="decimal" id="nhm-i-price" name="unit_price" value="<?php echo esc_attr( '' === $unit ? '' : number_format( (float) $unit, 2, '.', '' ) ); ?>" data-nhm-calc>
					<span class="description"><?php esc_html_e( 'Filled from the plan. Change it for a negotiated price.', 'neurohr-members' ); ?></span></p>
			</div>
			<p class="description"><?php esc_html_e( 'The line on the invoice is written from the plan, e.g. "NeuroHR Corporate membership — 1 year". When the invoice is paid the plan starts (or is extended) and the membership period is printed on it.', 'neurohr-members' ); ?></p>
			<p class="description" data-nhm-bill-company><?php esc_html_e( 'Billed to a company: the plan is for the whole company (all its seats).', 'neurohr-members' ); ?></p>
			<?php endif; ?>
			<div class="nhm-fields nhm-fields--totals">
				<p class="nhm-field"><label for="nhm-i-disc"><?php esc_html_e( 'Discount', 'neurohr-members' ); ?> (<?php echo esc_html( NHM_Settings::get( 'currency' ) ); ?>)</label><input type="text" inputmode="decimal" id="nhm-i-disc" name="discount" value="<?php echo esc_attr( $inv['discount'] ? number_format( (float) $inv['discount'], 2, '.', '' ) : '' ); ?>" data-nhm-calc></p>
				<p class="nhm-field"><label for="nhm-i-tax"><?php esc_html_e( 'SST (%)', 'neurohr-members' ); ?></label><input type="text" inputmode="decimal" id="nhm-i-tax" name="tax_rate" value="<?php echo esc_attr( (float) $inv['tax_rate'] ? rtrim( rtrim( number_format( (float) $inv['tax_rate'], 2, '.', '' ), '0' ), '.' ) : '0' ); ?>" data-nhm-calc>
				<label class="nhm-inline-check"><input type="checkbox" name="tax_inclusive" value="1" <?php checked( ! empty( $inv['tax_inclusive'] ) ); ?> data-nhm-calc> <?php esc_html_e( 'Price already includes SST', 'neurohr-members' ); ?></label>
				<span class="description"><?php echo NHM_Settings::get( 'sst_enabled' ) ? esc_html__( 'SST is on in Business info.', 'neurohr-members' ) : esc_html__( '0 = no SST (Business info → SST is off).', 'neurohr-members' ); ?></span></p>
				<p class="nhm-field nhm-total"><label><?php esc_html_e( 'Total', 'neurohr-members' ); ?></label><strong data-nhm-total data-currency="<?php echo esc_attr( NHM_Settings::get( 'currency' ) ); ?>"><?php echo esc_html( NHM_Settings::money( isset( $inv['total'] ) ? $inv['total'] : 0 ) ); ?></strong></p>
			</div>
			<?php if ( $inv['level_id'] && $inv['granted'] ) : ?><p class="nhm-exp"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'The plan was started when this invoice was paid.', 'neurohr-members' ); ?></p><?php endif; ?>
			</fieldset>
			<p class="nhm-field"><label for="nhm-i-notes"><?php esc_html_e( 'Note printed on the invoice', 'neurohr-members' ); ?></label><textarea id="nhm-i-notes" name="notes" rows="2"><?php echo esc_textarea( $inv['notes'] ); ?></textarea></p>
			<?php if ( $editable && $inv['id'] ) : ?>
				<label class="nhm-inline-check"><input type="checkbox" name="refresh_bill_to" value="1"> <?php esc_html_e( 'Update the "Bill to" block from the current company / person details', 'neurohr-members' ); ?></label>
			<?php endif; ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $inv['id'] ? esc_html__( 'Save invoice', 'neurohr-members' ) : esc_html__( 'Create invoice', 'neurohr-members' ); ?></button>
				<?php if ( ! $inv['id'] ) : ?>
					<label class="nhm-inline-check" style="margin-left:12px"><input type="checkbox" name="send_now" value="1" checked> <?php esc_html_e( 'Email it to the customer now', 'neurohr-members' ); ?></label>
				<?php endif; ?>
			</p>
		</form>

		<div>
			<?php if ( $inv['id'] ) : ?>
				<?php if ( ! empty( $inv['bill_to'] ) ) : ?>
					<section class="nhm-admin-card nhm-billto">
						<h2><?php esc_html_e( 'Bill to (on the invoice)', 'neurohr-members' ); ?></h2>
						<strong><?php echo esc_html( isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ); ?></strong><br>
						<?php echo wp_kses_post( implode( '<br>', array_map( 'esc_html', array_filter( array_merge( array( ! empty( $inv['bill_to']['attention'] ) ? 'Attn: ' . $inv['bill_to']['attention'] : '' ), (array) ( isset( $inv['bill_to']['address'] ) ? $inv['bill_to']['address'] : array() ), array( ! empty( $inv['bill_to']['tin'] ) ? 'TIN: ' . $inv['bill_to']['tin'] : '', isset( $inv['bill_to']['email'] ) ? $inv['bill_to']['email'] : '' ) ) ) ) ) ); ?>
					</section>
				<?php endif; ?>
				<?php echo NHM_Admin_Payments::invoice_box( $inv ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<?php if ( 'unpaid' === $inv['status'] ) : ?>
					<form class="nhm-admin-card nhm-pay" id="nhm-pay" method="post" action="<?php echo esc_url( $post ); ?>">
						<input type="hidden" name="action" value="nhm_invoice_action"><input type="hidden" name="do" value="paid"><input type="hidden" name="id" value="<?php echo (int) $inv['id']; ?>">
						<?php wp_nonce_field( 'nhm_invoice_action' ); ?>
						<h2><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Mark as paid', 'neurohr-members' ); ?></h2>
						<?php if ( $inv['level_id'] ) : ?>
							<p class="nhm-admin-card__intro">
							<?php
							/* translators: %s: level */
							echo esc_html( sprintf( __( 'This also starts the %s plan and emails the paid invoice.', 'neurohr-members' ), NHM_Levels::name( $inv['level_id'] ) ) );
							?>
							</p>
						<?php endif; ?>
						<p class="nhm-field"><label><?php esc_html_e( 'Paid on', 'neurohr-members' ); ?></label><input type="date" name="paid_date" value="<?php echo esc_attr( NHM_Settings::today() ); ?>"></p>
						<p class="nhm-field"><label><?php esc_html_e( 'Method', 'neurohr-members' ); ?></label><select name="method"><?php foreach ( NHM_Invoices::methods() as $mth ) : ?><option><?php echo esc_html( $mth ); ?></option><?php endforeach; ?></select></p>
						<p class="nhm-field"><label><?php esc_html_e( 'Reference', 'neurohr-members' ); ?></label><input type="text" name="ref" placeholder="<?php esc_attr_e( 'Bank reference / cheque no.', 'neurohr-members' ); ?>"></p>
						<label class="nhm-inline-check"><input type="checkbox" name="send" value="1" checked> <?php esc_html_e( 'Email the paid invoice (Payment Received)', 'neurohr-members' ); ?></label>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Mark as paid', 'neurohr-members' ); ?></button></p>
					</form>
				<?php elseif ( 'paid' === $inv['status'] ) : ?>
					<section class="nhm-admin-card">
						<h2><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Paid', 'neurohr-members' ); ?></h2>
						<p><?php echo esc_html( mysql2date( 'j M Y', $inv['paid_date'] ) . ( $inv['payment_method'] ? ' · ' . $inv['payment_method'] : '' ) . ( $inv['payment_ref'] ? ' · ' . $inv['payment_ref'] : '' ) ); ?></p>
					</section>
				<?php endif; ?>
				<section class="nhm-admin-card">
					<h2><span class="dashicons dashicons-email-alt"></span><?php esc_html_e( 'Send', 'neurohr-members' ); ?></h2>
					<?php
					/* translators: %s: email */
					echo '<p class="nhm-admin-card__intro">' . esc_html( sprintf( __( 'To %s, with the PDF attached.', 'neurohr-members' ), NHM_Invoices::email_to( $inv ) ) ) . '</p>';
					self::action_button( $inv['id'], 'send', 'paid' === $inv['status'] ? __( 'Email paid invoice', 'neurohr-members' ) : __( 'Email invoice', 'neurohr-members' ) );
					?>
				</section>
				<?php if ( 'void' !== $inv['status'] ) : ?>
					<p class="nhm-danger"><?php self::action_button( $inv['id'], 'void', __( 'Void this invoice', 'neurohr-members' ), __( 'Void this invoice? It stays in the list as VOID and its plan will not start.', 'neurohr-members' ), 'button-link nhm-link-danger' ); ?></p>
				<?php endif; ?>
				<section class="nhm-admin-card"><h2><?php esc_html_e( 'History', 'neurohr-members' ); ?></h2>
				<?php
				NHM_Log::render(
					array_values(
						array_filter(
							NHM_Log::query( array( 'user_id' => $inv['user_id'], 'company_id' => $inv['company_id'], 'limit' => 100 ) ),
							function ( $r ) use ( $inv ) { return false !== strpos( (string) $r['message'], $inv['number'] ); }
						)
					)
				);
				?>
				</section>
			<?php else : ?>
				<section class="nhm-admin-card"><h2><?php esc_html_e( 'How it works', 'neurohr-members' ); ?></h2>
					<ul class="nhm-list">
						<li><?php esc_html_e( 'The invoice number is given when you create it.', 'neurohr-members' ); ?></li>
						<li><?php esc_html_e( '"Bill to" is copied from the company or person (their Billing details) at that moment.', 'neurohr-members' ); ?></li>
						<li><?php esc_html_e( 'Pick a plan to start it automatically when you mark the invoice paid.', 'neurohr-members' ); ?></li>
						<li><?php esc_html_e( 'Members see their invoices in My account and can download the PDF.', 'neurohr-members' ); ?></li>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		</div>
		</div>
		<?php
	}

	private static function action_button( $id, $do, $label, $confirm = '', $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nhm-inline"><input type="hidden" name="action" value="nhm_invoice_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'nhm_invoice_action' );
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $confirm ? ' data-nhm-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/* ---------- Actions ---------- */

	public static function save() {
		NHM_Admin::check( 'nhm_save_invoice' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$bill = isset( $_POST['bill'] ) && 'company' === $_POST['bill'] ? 'company' : 'user';
		$level_id = isset( $_POST['level_id'] ) ? absint( $_POST['level_id'] ) : 0;
		$terms    = isset( $_POST['terms'] ) ? max( 1, min( 3, absint( $_POST['terms'] ) ) ) : 1;
		$unit     = isset( $_POST['unit_price'] ) ? (float) preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['unit_price'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data     = array(
			'issue_date'    => isset( $_POST['issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_date'] ) ) : '',
			'due_date'      => isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '',
			'discount'      => isset( $_POST['discount'] ) ? (float) preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['discount'] ) ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'tax_rate'      => isset( $_POST['tax_rate'] ) ? max( 0, min( 100, (float) $_POST['tax_rate'] ) ) : 0,
			'tax_inclusive' => ! empty( $_POST['tax_inclusive'] ),
			'notes'         => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'grant_to'      => 'company' === $bill ? 'company' : 'user',
		);
		$level = NHM_Levels::get( $level_id );
		if ( $level && ! $level['is_base'] ) {
			$data['level_id'] = $level_id;
			$data['terms']    = $terms;
			$data['items']    = array( array( 'description' => NHM_Invoices::plan_line( $level_id, '', '', $terms ), 'qty' => $terms, 'price' => $unit ) );
		}
		if ( 'company' === $bill ) {
			$data['company_id'] = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
			$data['user_id']    = 0;
		} else {
			$email              = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
			$user               = $email ? get_user_by( 'email', $email ) : null;
			$data['user_id']    = $user ? $user->ID : 0;
			$data['company_id'] = 0;
			if ( $email && ! $user ) {
				NHM_Admin::flash( __( 'No account with that email. Create the person first (Users → Add New) or bill a company.', 'neurohr-members' ), 'error' );
				NHM_Admin::back( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=nhm-invoices&action=new' ) );
			}
		}
		$data['refresh_bill_to'] = ! empty( $_POST['refresh_bill_to'] );
		$send_now                = ! empty( $_POST['send_now'] );
		// phpcs:enable

		if ( $id ) {
			$inv = NHM_Invoices::get( $id );
			if ( $inv && 'unpaid' !== $inv['status'] ) {
				$data = array( 'notes' => $data['notes'] );
			}
			$result = NHM_Invoices::update( $id, $data );
		} else {
			if ( empty( $data['items'] ) ) {
				NHM_Admin::flash( __( 'Choose a plan.', 'neurohr-members' ), 'error' );
				NHM_Admin::back( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=nhm-invoices&action=new' ) );
			}
			$result = NHM_Invoices::create( $data );
			$id     = is_wp_error( $result ) ? 0 : (int) $result;
		}
		if ( is_wp_error( $result ) ) {
			NHM_Admin::flash( $result->get_error_message(), 'error' );
			NHM_Admin::back( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=nhm-invoices' ) );
		}
		if ( $send_now ) {
			NHM_Emails::invoice_email( 'invoice', NHM_Invoices::get( $id ) );
			/* translators: %s: email */
			NHM_Admin::flash( sprintf( __( 'Invoice created and emailed to %s.', 'neurohr-members' ), esc_html( NHM_Invoices::email_to( NHM_Invoices::get( $id ) ) ) ) );
		} else {
			NHM_Admin::flash( __( 'Invoice saved.', 'neurohr-members' ) );
		}
		NHM_Admin::back( NHM_Invoices::edit_url( $id ) );
	}

	public static function action() {
		NHM_Admin::check( 'nhm_invoice_action' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$inv = NHM_Invoices::get( $id );
		if ( ! $inv ) {
			NHM_Admin::back( admin_url( 'admin.php?page=nhm-invoices' ) );
		}
		switch ( $do ) {
			case 'paid':
				$result = NHM_Invoices::mark_paid(
					$id,
					isset( $_POST['paid_date'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_date'] ) ) : '',
					isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : '',
					isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '',
					! empty( $_POST['send'] )
				);
				if ( is_wp_error( $result ) ) {
					NHM_Admin::flash( $result->get_error_message(), 'error' );
				} else {
					$inv = NHM_Invoices::get( $id );
					NHM_Admin::flash( $inv['level_id'] && $inv['granted'] ? sprintf( /* translators: %s: level */ __( 'Marked as paid. The %s plan has started.', 'neurohr-members' ), esc_html( NHM_Levels::name( $inv['level_id'] ) ) ) : __( 'Marked as paid.', 'neurohr-members' ) );
				}
				break;
			case 'send':
				$ok = NHM_Emails::invoice_email( 'paid' === $inv['status'] ? 'payment' : 'invoice', $inv );
				NHM_Admin::flash( $ok ? __( 'Email sent.', 'neurohr-members' ) : __( 'The email could not be sent (switched off in Settings → Emails, or the site\'s mail failed).', 'neurohr-members' ), $ok ? 'success' : 'error' );
				break;
			case 'void':
				NHM_Invoices::void( $id );
				NHM_Admin::flash( __( 'Invoice voided.', 'neurohr-members' ) );
				break;
		}
		// phpcs:enable
		NHM_Admin::back( NHM_Invoices::edit_url( $id ) );
	}

	public static function pdf() {
		if ( ! current_user_can( NHM_CAP ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'nhm_invoice_pdf' );
		$inv = NHM_Invoices::get( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( ! $inv ) {
			wp_die( esc_html__( 'Invoice not found.', 'neurohr-members' ) );
		}
		NHM_PDF::send( $inv );
	}

	/** Sample invoice with the current Business info (Settings → Business info → Preview). */
	public static function preview() {
		if ( ! current_user_can( NHM_CAP ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'nhm_invoice_preview' );
		NHM_PDF::send( NHM_Admin_Settings::sample_invoice( ! empty( $_GET['paid'] ) ) );
	}
}
