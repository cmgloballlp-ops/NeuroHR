<?php
/**
 * Members → Payments: every payment (online and bank transfer / DuitNow QR), with
 * "Waiting for check" first. Approve starts the plan; Reject emails the member the reason.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Payments {

	public static function init() {
		add_action( 'admin_post_nhm_payment_review', array( __CLASS__, 'review' ) );
		add_action( 'admin_post_nhm_payment_slip', array( __CLASS__, 'slip' ) );
		add_action( 'admin_post_nhm_refund', array( __CLASS__, 'refund_action' ) );
		add_action( 'admin_post_nhm_refund_pdf', array( __CLASS__, 'refund_pdf' ) );
	}

	/* ---------- Refunds ---------- */

	public static function refund_action() {
		NHM_Admin::check( 'nhm_refund' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$back = admin_url( 'admin.php?page=nhm-payments&status=refunds' );
		if ( 'approve' === $do ) {
			$amount = isset( $_POST['amount'] ) ? (float) NHM_Levels::clean_amount( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0;
			$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$r      = NHM_Refunds::approve( $id, $amount, $note );
			$ok     = NHM_Refunds::get( $id ) && 'refunded' === NHM_Refunds::get( $id )['status'] ? __( 'Refunded through Stripe. The plan has ended and the member got the Refund Note.', 'neurohr-members' ) : __( 'Approved. The plan has ended. Pay the money back, then click "Mark as refunded".', 'neurohr-members' );
		} elseif ( 'mark' === $do ) {
			$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
			$ref  = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
			$r    = NHM_Refunds::mark_refunded( $id, $date, $ref );
			$ok   = __( 'Marked as refunded. The member got the Refund Note.', 'neurohr-members' );
		} else {
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
			$r      = NHM_Refunds::reject( $id, $reason );
			$ok     = __( 'Not approved. The member got your reason and has access again.', 'neurohr-members' );
		}
		// phpcs:enable
		NHM_Admin::flash( is_wp_error( $r ) ? $r->get_error_message() : $ok, is_wp_error( $r ) ? 'error' : 'success' );
		wp_safe_redirect( $back );
		exit;
	}

	public static function refund_pdf() {
		NHM_Admin::check( 'nhm_refund_pdf' );
		$r = NHM_Refunds::get( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $r || '' === $r['number'] ) {
			wp_die( esc_html__( 'Not found.', 'neurohr-members' ), 404 );
		}
		NHM_Refunds::send_pdf( $r );
	}

	public static function refund_pdf_url( $r ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=nhm_refund_pdf&id=' . (int) $r['id'] ), 'nhm_refund_pdf' );
	}

	private static function refund_card( $r ) {
		$inv   = NHM_Invoices::get( $r['invoice_id'] );
		$user  = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
		$st    = NHM_Refunds::statuses();
		$post  = admin_url( 'admin-post.php' );
		$hid   = '<input type="hidden" name="action" value="nhm_refund"><input type="hidden" name="id" value="' . (int) $r['id'] . '">' . wp_nonce_field( 'nhm_refund', '_wpnonce', true, false );
		$by    = NHM_Refunds::complete_by( $r );
		$late  = in_array( $r['status'], array( 'requested', 'approved' ), true ) && $by <= wp_date( 'Y-m-d', strtotime( NHM_Settings::today() . ' 12:00:00 +2 days' ) );
		$h     = '<section class="nhm-admin-card nhm-refund-card"><h2>' . esc_html( $inv ? $inv['number'] : '#' . $r['invoice_id'] ) . ' · ' . esc_html( $user ? $user->display_name : ( $inv && isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ) ) . ' <span class="nhm-status nhm-status--' . esc_attr( 'refunded' === $r['status'] ? 'ok' : ( 'rejected' === $r['status'] ? 'muted' : 'open' ) ) . '">' . esc_html( $st[ $r['status'] ] ) . '</span></h2>';
		$h    .= '<dl class="nhm-proof__list">';
		$h    .= '<dt>' . esc_html__( 'Paid', 'neurohr-members' ) . '</dt><dd>' . esc_html( NHM_Settings::money( $r['paid_amount'] ) ) . ( $inv ? ' · ' . esc_html( $inv['payment_method'] ) . ' · ' . esc_html( '' !== $inv['paid_at'] ? mysql2date( 'j M Y, H:i', $inv['paid_at'] ) : NHM_Emails::format_date( $inv['paid_date'] ) ) : '' ) . ( $inv && $inv['level_id'] ? ' · ' . esc_html( NHM_Levels::name( $inv['level_id'] ) ) : '' ) . '</dd>';
		$h    .= '<dt>' . esc_html__( 'Asked', 'neurohr-members' ) . '</dt><dd>' . esc_html( mysql2date( 'j M Y, H:i', $r['created_at'] ) ) . ( 'stripe' === $r['source'] ? ' · ' . esc_html__( 'refunded in the Stripe dashboard', 'neurohr-members' ) : '' ) . '</dd>';
		$h    .= '<dt>' . esc_html__( 'Reason', 'neurohr-members' ) . '</dt><dd>' . esc_html( $r['reason'] ) . ( '' !== $r['details'] ? '<br><em>' . esc_html( $r['details'] ) . '</em>' : '' ) . '</dd>';
		if ( in_array( $r['status'], array( 'requested', 'approved' ), true ) ) {
			$h .= '<dt>' . esc_html__( 'Complete by', 'neurohr-members' ) . '</dt><dd' . ( $late ? ' class="nhm-exp--ended"' : '' ) . '>' . esc_html( NHM_Emails::format_date( $by ) ) . '</dd>';
		}
		if ( 'refunded' === $r['status'] ) {
			$h .= '<dt>' . esc_html__( 'Refunded', 'neurohr-members' ) . '</dt><dd>' . esc_html( NHM_Settings::money( $r['amount'] ) . ' · ' . NHM_Emails::format_date( $r['refund_date'] ) . ( '' !== $r['reference'] ? ' · ' . $r['reference'] : '' ) ) . ' · <a href="' . esc_url( self::refund_pdf_url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html( $r['number'] ) . '</a></dd>';
		}
		if ( 'rejected' === $r['status'] ) {
			$h .= '<dt>' . esc_html__( 'Not approved', 'neurohr-members' ) . '</dt><dd>' . esc_html( $r['reject_reason'] ) . '</dd>';
		}
		$h .= '</dl>';
		// Usage since paying.
		if ( is_array( $r['usage'] ) ) {
			$u  = $r['usage'];
			/* translators: 1: opened, 2: downloaded */
			$h .= '<details class="nhm-usage"' . ( 'requested' === $r['status'] ? ' open' : '' ) . '><summary><strong>' . esc_html( sprintf( __( 'Since paying: opened %1$d, downloaded %2$d', 'neurohr-members' ), $u['views'], $u['downloads'] ) ) . '</strong></summary>';
			if ( $u['items'] ) {
				$h .= '<ul class="nhm-usage__list">';
				foreach ( $u['items'] as $it ) {
					$h .= '<li><span class="nhm-usage__tag nhm-usage__tag--' . esc_attr( $it['action'] ) . '">' . esc_html( 'download' === $it['action'] ? __( 'Download', 'neurohr-members' ) : __( 'Opened', 'neurohr-members' ) ) . '</span> <a href="' . esc_url( $it['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $it['title'] ) . '</a> <span class="description">' . esc_html( mysql2date( 'j M, H:i', $it['time'] ) ) . '</span></li>';
				}
				$h .= '</ul>';
			} else {
				$h .= '<p class="description">' . esc_html__( 'Nothing opened or downloaded.', 'neurohr-members' ) . '</p>';
			}
			$h .= '</details>';
		} elseif ( 'requested' === $r['status'] ) {
			$h .= '<p class="description">' . esc_html__( 'Usage is recorded by Knowledge Hub 0.8.2 or later.', 'neurohr-members' ) . '</p>';
		}
		if ( 'requested' === $r['status'] ) {
			$fee  = NHM_Refunds::fee( $r );
			$sug  = NHM_Refunds::suggested( $r );
			$h   .= '<form method="post" action="' . esc_url( $post ) . '" class="nhm-refund__approve">' . $hid . '<input type="hidden" name="do" value="approve">';
			$h   .= '<p><label>' . esc_html__( 'Amount to refund (RM)', 'neurohr-members' ) . '<br><input type="number" step="0.01" min="0.01" max="' . esc_attr( number_format( $r['paid_amount'], 2, '.', '' ) ) . '" name="amount" value="' . esc_attr( number_format( $sug, 2, '.', '' ) ) . '" style="width:130px"></label> ';
			if ( null !== $fee && $fee > 0 ) {
				/* translators: %s: fee */
				$h .= '<span class="description">' . esc_html( sprintf( __( 'Paid amount less the Stripe fee of %s', 'neurohr-members' ), NHM_Settings::money( $fee ) ) ) . '</span>';
			}
			$h   .= '</p><p><label>' . esc_html__( 'Note for yourself (optional)', 'neurohr-members' ) . '<br><input type="text" name="note" class="regular-text"></label></p>';
			$label = 'stripe' === $r['method'] ? __( 'Approve and refund through Stripe', 'neurohr-members' ) : __( 'Approve (I will pay it back)', 'neurohr-members' );
			$h   .= '<button type="submit" class="button button-primary" data-nhm-confirm="' . esc_attr__( 'Approve this refund? The plan ends now.', 'neurohr-members' ) . '">' . esc_html( $label ) . '</button></form>';
			$h   .= '<details class="nhm-proof__reject"><summary>' . esc_html__( 'Do not approve…', 'neurohr-members' ) . '</summary><form method="post" action="' . esc_url( $post ) . '">' . $hid . '<input type="hidden" name="do" value="reject">';
			$h   .= '<p><label>' . esc_html__( 'Reason (the member sees it)', 'neurohr-members' ) . '<br><textarea name="reason" rows="3" class="widefat" required placeholder="' . esc_attr__( 'e.g. Under our Refund Policy we cannot refund after templates have been downloaded.', 'neurohr-members' ) . '"></textarea></label></p>';
			$h   .= '<button type="submit" class="button nhm-button-danger">' . esc_html__( 'Do not approve and email the member', 'neurohr-members' ) . '</button></form></details>';
		} elseif ( 'approved' === $r['status'] ) {
			/* translators: %s: amount */
			$h .= '<p><strong>' . esc_html( sprintf( __( 'Pay back %s to the member, then fill this in:', 'neurohr-members' ), NHM_Settings::money( $r['amount'] ) ) ) . '</strong></p>';
			$h .= '<form method="post" action="' . esc_url( $post ) . '" class="nhm-refund__mark">' . $hid . '<input type="hidden" name="do" value="mark"><label>' . esc_html__( 'Date paid back', 'neurohr-members' ) . ' <input type="date" name="date" value="' . esc_attr( NHM_Settings::today() ) . '" max="' . esc_attr( NHM_Settings::today() ) . '"></label> <label>' . esc_html__( 'Bank reference', 'neurohr-members' ) . ' <input type="text" name="reference"></label> <button type="submit" class="button button-primary">' . esc_html__( 'Mark as refunded', 'neurohr-members' ) . '</button></form>';
		}
		return $h . '</section>';
	}

	private static function refunds_tab() {
		$open = NHM_Refunds::query( array( 'status' => array( 'requested', 'approved' ), 'limit' => 100 ) );
		$done = NHM_Refunds::query( array( 'status' => array( 'refunded', 'rejected' ), 'limit' => 30 ) );
		if ( ! $open ) {
			echo '<div class="nhm-admin-card"><p>' . esc_html__( 'No refund requests waiting.', 'neurohr-members' ) . '</p></div>';
		}
		foreach ( $open as $r ) {
			echo self::refund_card( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		if ( $done ) {
			echo '<h2 class="nhm-admin-subhead">' . esc_html__( 'Recent', 'neurohr-members' ) . '</h2>';
			echo '<div class="nhm-admin-card nhm-admin-card--flush"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Asked', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Invoice', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Member', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Reason', 'neurohr-members' ) . '</th><th class="num">' . esc_html__( 'Refunded', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $done as $r ) {
				$inv  = NHM_Invoices::get( $r['invoice_id'] );
				$user = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
				echo '<tr><td>' . esc_html( mysql2date( 'j M Y', $r['created_at'] ) ) . '</td><td>' . ( $inv ? '<a href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) ) . '">' . esc_html( $inv['number'] ) . '</a>' : '—' ) . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td><td>' . esc_html( $r['reason'] ) . '</td>';
				echo '<td class="num">' . ( 'refunded' === $r['status'] ? esc_html( NHM_Settings::money( $r['amount'] ) ) : '—' ) . '</td><td>' . esc_html( NHM_Refunds::statuses()[ $r['status'] ] ) . '</td><td>' . ( '' !== $r['number'] ? '<a href="' . esc_url( self::refund_pdf_url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html( $r['number'] ) . '</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	public static function slip_url( $p ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=nhm_payment_slip&id=' . (int) $p['id'] ), 'nhm_payment_slip' );
	}

	/** Open a slip (staff only). */
	public static function slip() {
		NHM_Admin::check( 'nhm_payment_slip' );
		$p    = NHM_Payments::get( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$path = $p ? NHM_Payments::slip_path( $p ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'The file is not there any more.', 'neurohr-members' ), 404 );
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$type = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' );
		nocache_headers();
		header( 'Content-Type: ' . ( isset( $type[ $ext ] ) ? $type[ $ext ] : 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="slip-' . (int) $p['id'] . '.' . $ext . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function review() {
		NHM_Admin::check( 'nhm_payment_review' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$back   = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : admin_url( 'admin.php?page=nhm-payments' );
		// phpcs:enable
		$r = 'approve' === $do ? NHM_Payments::approve( $id ) : NHM_Payments::reject( $id, $reason );
		if ( is_wp_error( $r ) ) {
			NHM_Admin::flash( $r->get_error_message(), 'error' );
		} else {
			NHM_Admin::flash( 'approve' === $do ? __( 'Payment approved: the invoice is paid, the plan has started and the member got the paid invoice.', 'neurohr-members' ) : __( 'Payment rejected. The member got an email with your reason and can pay again.', 'neurohr-members' ) );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public static function status_badge( $p ) {
		$s     = NHM_Payments::statuses();
		$class = in_array( $p['status'], array( 'succeeded', 'approved' ), true ) ? 'ok' : ( in_array( $p['status'], array( 'failed', 'rejected' ), true ) ? 'late' : ( in_array( $p['status'], array( 'expired', 'pending' ), true ) ? 'muted' : 'open' ) );
		return '<span class="nhm-status nhm-status--' . esc_attr( $class ) . '">' . esc_html( isset( $s[ $p['status'] ] ) ? $s[ $p['status'] ] : $p['status'] ) . '</span>';
	}

	private static function method_text( $p ) {
		if ( 'stripe' === $p['method'] ) {
			return ( $p['stripe_method'] ? NHM_Stripe::method_label( $p['stripe_method'] ) : 'Stripe' ) . ( 'test' === $p['mode'] ? ' (test)' : '' );
		}
		return 'qr' === $p['method'] ? 'DuitNow QR' : __( 'Bank transfer', 'neurohr-members' );
	}

	/** Approve / reject buttons and the details of a proof. */
	public static function review_box( $p, $back ) {
		$h  = '<div class="nhm-proof">';
		$h .= '<dl class="nhm-proof__list">';
		$h .= '<dt>' . esc_html__( 'Paid by', 'neurohr-members' ) . '</dt><dd>' . esc_html( $p['payer_name'] ) . '</dd>';
		$h .= '<dt>' . esc_html__( 'Paid on', 'neurohr-members' ) . '</dt><dd>' . esc_html( mysql2date( 'j M Y, g:i a', $p['paid_at'] ) ) . '</dd>';
		$h .= '<dt>' . esc_html__( 'Amount', 'neurohr-members' ) . '</dt><dd>' . esc_html( NHM_Settings::money( $p['amount'] ) ) . '</dd>';
		$h .= '<dt>' . esc_html__( 'Reference', 'neurohr-members' ) . '</dt><dd>' . esc_html( '' !== $p['reference'] ? $p['reference'] : '—' ) . '</dd>';
		if ( '' !== $p['note'] ) {
			$h .= '<dt>' . esc_html__( 'Note', 'neurohr-members' ) . '</dt><dd>' . esc_html( $p['note'] ) . '</dd>';
		}
		$h .= '</dl>';
		if ( $p['slip'] ) {
			$h .= '<p><a class="button" href="' . esc_url( self::slip_url( $p ) ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-media-document" style="vertical-align:text-bottom"></span> ' . esc_html__( 'Open slip', 'neurohr-members' ) . '</a></p>';
		}
		if ( 'submitted' === $p['status'] ) {
			$post = admin_url( 'admin-post.php' );
			$h   .= '<form method="post" action="' . esc_url( $post ) . '" class="nhm-proof__approve"><input type="hidden" name="action" value="nhm_payment_review"><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="' . (int) $p['id'] . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">' . wp_nonce_field( 'nhm_payment_review', '_wpnonce', true, false );
			$h   .= '<button type="submit" class="button button-primary">' . esc_html__( 'Approve — start the plan', 'neurohr-members' ) . '</button></form>';
			$h   .= '<details class="nhm-proof__reject"><summary>' . esc_html__( 'Reject…', 'neurohr-members' ) . '</summary><form method="post" action="' . esc_url( $post ) . '"><input type="hidden" name="action" value="nhm_payment_review"><input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="' . (int) $p['id'] . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">' . wp_nonce_field( 'nhm_payment_review', '_wpnonce', true, false );
			$h   .= '<p><label>' . esc_html__( 'Reason (the member sees it)', 'neurohr-members' ) . '<br><textarea name="reason" rows="3" class="widefat" required placeholder="' . esc_attr__( 'e.g. We have not received this amount in our account. Please check the slip.', 'neurohr-members' ) . '"></textarea></label></p>';
			$h   .= '<button type="submit" class="button nhm-button-danger">' . esc_html__( 'Reject and email the member', 'neurohr-members' ) . '</button></form></details>';
		} elseif ( '' !== $p['admin_note'] ) {
			$h .= '<p class="description">' . esc_html( $p['admin_note'] ) . '</p>';
		}
		return $h . '</div>';
	}

	/** Box on the invoice screen. */
	public static function invoice_box( $inv ) {
		$rows = NHM_Payments::query( array( 'invoice_id' => $inv['id'], 'limit' => 20 ) );
		$h    = '';
		if ( 'unpaid' === $inv['status'] ) {
			$h .= '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-admin-links"></span>' . esc_html__( 'Payment link', 'neurohr-members' ) . '</h2>';
			$h .= '<p class="nhm-admin-card__intro">' . esc_html__( 'Anyone with this link can see and pay this invoice (also without logging in). It is in the invoice emails.', 'neurohr-members' ) . '</p>';
			$h .= '<input type="text" class="widefat" readonly value="' . esc_attr( NHM_Payments::pay_url( $inv ) ) . '" onclick="this.select()"></section>';
		}
		foreach ( NHM_Refunds::query( array( 'invoice_id' => $inv['id'], 'limit' => 10 ) ) as $r ) {
			$h .= self::refund_card( $r );
		}
		if ( ! $rows ) {
			return $h;
		}
		$back = NHM_Invoices::edit_url( $inv['id'] );
		$h   .= '<section class="nhm-admin-card"><h2><span class="dashicons dashicons-money-alt"></span>' . esc_html__( 'Payments', 'neurohr-members' ) . '</h2>';
		foreach ( $rows as $p ) {
			$h .= '<div class="nhm-proof__head"><strong>' . esc_html( self::method_text( $p ) ) . '</strong> · ' . esc_html( NHM_Settings::money( $p['amount'] ) ) . ' ' . self::status_badge( $p ) . '<br><span class="description">' . esc_html( mysql2date( 'j M Y, g:i a', $p['created_at'] ) ) . '</span></div>';
			if ( in_array( $p['method'], array( 'bank', 'qr' ), true ) ) {
				$h .= self::review_box( $p, $back );
			} elseif ( $p['stripe_intent'] ) {
				$h .= '<p><a href="' . esc_url( 'https://dashboard.stripe.com/' . ( 'test' === $p['mode'] ? 'test/' : '' ) . 'payments/' . rawurlencode( $p['stripe_intent'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in Stripe', 'neurohr-members' ) . '</a></p>';
			}
			if ( 'stripe' === $p['method'] && '' !== $p['admin_note'] ) {
				$h .= '<p class="description">' . esc_html( $p['admin_note'] ) . '</p>';
			}
		}
		return $h . '</section>';
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$tab   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$tabs = array(
			''          => __( 'All', 'neurohr-members' ),
			'submitted' => __( 'Waiting for check', 'neurohr-members' ),
			'online'    => __( 'Online', 'neurohr-members' ),
			'offline'   => __( 'Bank transfer & QR', 'neurohr-members' ),
			'refunds'   => __( 'Refund requests', 'neurohr-members' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : '';
		$per  = 50;
		$args = array( 'limit' => $per + 1, 'offset' => ( $paged - 1 ) * $per );
		if ( 'submitted' === $tab ) {
			$args['status'] = 'submitted';
		} elseif ( 'online' === $tab ) {
			$args['kind']   = 'online';
			$args['status'] = array( 'succeeded', 'processing', 'failed' );
		} elseif ( 'offline' === $tab ) {
			$args['kind'] = 'offline';
		} else {
			$args['status'] = array( 'succeeded', 'processing', 'failed', 'submitted', 'approved', 'rejected' );
		}
		$rows    = NHM_Payments::query( $args );
		$more    = count( $rows ) > $per;
		$rows    = array_slice( $rows, 0, $per );
		$waiting = NHM_Payments::count_waiting();
		$base    = admin_url( 'admin.php?page=nhm-payments' );

		echo '<div class="wrap nhm-admin"><div class="nhm-admin__head"><h1>' . esc_html__( 'Payments', 'neurohr-members' ) . '</h1></div>';
		NHM_Admin::render_flash();
		if ( ! NHM_Payments::available() ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf( /* translators: %s: link */ __( 'No way to pay is switched on yet. Set it up in <a href="%s">Settings → Payments</a>.', 'neurohr-members' ), esc_url( admin_url( 'admin.php?page=nhm-settings&tab=payments' ) ) ) ) . '</p></div>';
		}
		echo '<nav class="nhm-admin-tabs">';
		foreach ( $tabs as $key => $label ) {
			$count = 'submitted' === $key && $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '';
			if ( 'refunds' === $key && NHM_Refunds::count_open() ) {
				$count = ' <span class="awaiting-mod">' . (int) NHM_Refunds::count_open() . '</span>';
			}
			echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( $key ? add_query_arg( 'status', $key, $base ) : $base ) . '">' . esc_html( $label ) . $count . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $count is built from an int.
		}
		echo '</nav>';

		if ( 'refunds' === $tab ) {
			self::refunds_tab();
			echo '</div>';
			return;
		}
		if ( 'submitted' === $tab ) {
			if ( ! $rows ) {
				echo '<div class="nhm-admin-card"><p>' . esc_html__( 'Nothing to check. 🎉', 'neurohr-members' ) . '</p></div>';
			}
			foreach ( $rows as $p ) {
				$inv = NHM_Invoices::get( $p['invoice_id'] );
				echo '<section class="nhm-admin-card nhm-proof-card"><h2>' . esc_html( $inv ? $inv['number'] : '#' . $p['invoice_id'] ) . ' · ' . esc_html( self::method_text( $p ) ) . '</h2>';
				if ( $inv ) {
					/* translators: 1: total, 2: bill to */
					echo '<p class="nhm-admin-card__intro">' . esc_html( sprintf( __( 'Invoice total %1$s · %2$s', 'neurohr-members' ), NHM_Settings::money( $inv['total'] ), isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '' ) ) . ( $inv['level_id'] ? ' · ' . esc_html( NHM_Levels::name( $inv['level_id'] ) ) : '' ) . ' · <a href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) ) . '">' . esc_html__( 'Open invoice', 'neurohr-members' ) . '</a></p>';
					if ( NHM_Stripe::cents( $p['amount'] ) !== NHM_Stripe::cents( $inv['total'] ) ) {
						echo '<p class="nhm-exp--soon">' . esc_html__( 'The amount paid is not the invoice total — check before approving.', 'neurohr-members' ) . '</p>';
					}
				}
				echo self::review_box( $p, add_query_arg( 'status', 'submitted', $base ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				echo '</section>';
			}
			echo '</div>';
			return;
		}

		echo '<div class="nhm-admin-card nhm-admin-card--flush"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Invoice', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Paid by', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Method', 'neurohr-members' ) . '</th><th class="num">' . esc_html__( 'Amount', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payments yet.', 'neurohr-members' ) . '</td></tr>';
		}
		foreach ( $rows as $p ) {
			$inv   = NHM_Invoices::get( $p['invoice_id'] );
			$payer = $p['payer_name'];
			if ( '' === $payer && $inv ) {
				$payer = isset( $inv['bill_to']['name'] ) ? $inv['bill_to']['name'] : '';
			}
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y, g:i a', $p['created_at'] ) ) . '</td>';
			echo '<td>' . ( $inv ? '<a href="' . esc_url( NHM_Invoices::edit_url( $inv['id'] ) ) . '">' . esc_html( $inv['number'] ) . '</a>' : '—' ) . '</td>';
			echo '<td>' . esc_html( $payer ) . '</td><td>' . esc_html( self::method_text( $p ) ) . '</td>';
			echo '<td class="num">' . esc_html( NHM_Settings::money( $p['amount'] ) ) . '</td><td>' . self::status_badge( $p ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $p['slip'] ) {
				echo '<a href="' . esc_url( self::slip_url( $p ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Slip', 'neurohr-members' ) . '</a> ';
			}
			if ( $p['stripe_intent'] ) {
				echo '<a href="' . esc_url( 'https://dashboard.stripe.com/' . ( 'test' === $p['mode'] ? 'test/' : '' ) . 'payments/' . rawurlencode( $p['stripe_intent'] ) ) . '" target="_blank" rel="noopener">Stripe</a>';
			}
			if ( 'submitted' === $p['status'] ) {
				echo ' <a class="button button-small button-primary" href="' . esc_url( add_query_arg( 'status', 'submitted', $base ) ) . '">' . esc_html__( 'Check', 'neurohr-members' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $more || $paged > 1 ) {
			echo '<p class="nhm-pager">';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">' . esc_html__( '← Newer', 'neurohr-members' ) . '</a> ';
			}
			if ( $more ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Older →', 'neurohr-members' ) . '</a>';
			}
			echo '</p>';
		}
		echo '</div>';
	}
}
