<?php
/**
 * Members list (filters, CSV export) and the "Membership" section on the user profile screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Members {

	const PER_PAGE = 50;
	const MAX_ROWS = 20000;

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_section' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_section' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'admin_post_nhm_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_nhm_bulk', array( __CLASS__, 'bulk' ) );
		add_action( 'admin_post_nhm_member_action', array( __CLASS__, 'member_action' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/* ---------- Data ---------- */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		return array(
			'view'    => isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all',
			'level'   => isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0,
			'company' => isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0,
			's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'paged'   => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ),
		);
		// phpcs:enable
	}

	/** Which tab a row belongs to. */
	private static function views_of( array $m, $user_id = 0 ) {
		if ( $user_id && NHM_Block::is_blocked( $user_id ) ) {
			return array( 'all', 'blocked' );
		}
		$views = array( 'all' );
		if ( 'base' === $m['source'] ) {
			$views[] = $m['expired_level'] ? 'expired' : 'free';
		} else {
			$views[] = 'paid';
			if ( null !== $m['days_left'] && $m['days_left'] <= 30 ) {
				$views[] = 'expiring';
			}
		}
		if ( $m['expired_level'] && 'base' !== $m['source'] && ! in_array( 'expired', $views, true ) ) {
			$views[] = 'expired';
		}
		return $views;
	}

	/**
	 * Rows for the current filters (all pages), plus counts per tab.
	 */
	public static function rows( array $f ) {
		$args = array(
			'number'  => self::MAX_ROWS,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => array( 'ID', 'display_name', 'user_email', 'user_registered', 'user_login' ),
		);
		if ( '' !== $f['s'] ) {
			$args['search']         = '*' . $f['s'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$users = get_users( $args );
		update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

		$counts = array( 'all' => 0, 'paid' => 0, 'expiring' => 0, 'expired' => 0, 'free' => 0, 'blocked' => 0 );
		$rows   = array();
		foreach ( $users as $u ) {
			$m = NHM_Members::membership( (int) $u->ID );
			if ( $f['level'] && (int) $m['level'] !== $f['level'] ) {
				continue;
			}
			if ( $f['company'] && (int) $m['company_id'] !== $f['company'] ) {
				continue;
			}
			$views = self::views_of( $m, (int) $u->ID );
			foreach ( $views as $v ) {
				$counts[ $v ]++;
			}
			if ( in_array( $f['view'], $views, true ) ) {
				$rows[] = array( 'user' => $u, 'm' => $m );
			}
		}
		if ( in_array( $f['view'], array( 'expiring', 'paid' ), true ) ) {
			usort(
				$rows,
				function ( $a, $b ) {
					$da = null === $a['m']['days_left'] ? PHP_INT_MAX : $a['m']['days_left'];
					$db = null === $b['m']['days_left'] ? PHP_INT_MAX : $b['m']['days_left'];
					return $da - $db;
				}
			);
		}
		return array( $rows, $counts );
	}

	/* ---------- List page ---------- */

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$f                      = self::filters();
		list( $rows, $counts )  = self::rows( $f );
		$total                  = count( $rows );
		$pages                  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows                   = array_slice( $rows, ( $f['paged'] - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$base                   = admin_url( 'admin.php?page=nhm-members' );
		$keep                   = array_filter( array( 'level' => $f['level'], 'company' => $f['company'], 's' => $f['s'] ) );
		$export                 = wp_nonce_url( add_query_arg( array_merge( $keep, array( 'action' => 'nhm_export', 'view' => $f['view'] ) ), admin_url( 'admin-post.php' ) ), 'nhm_export' );

		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header(
			__( 'Members', 'neurohr-members' ),
			'<a class="button" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Add a person', 'neurohr-members' ) . '</a> <a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'neurohr-members' ) . '</a>'
		);

		$tabs = array(
			'all'      => array( __( 'All', 'neurohr-members' ), 'groups' ),
			'paid'     => array( __( 'Paid plans', 'neurohr-members' ), 'awards' ),
			'expiring' => array( __( 'Ending within 30 days', 'neurohr-members' ), 'clock' ),
			'expired'  => array( __( 'Ended', 'neurohr-members' ), 'warning' ),
			'free'     => array( __( 'Free', 'neurohr-members' ), 'admin-users' ),
			'blocked'  => array( __( 'Blocked', 'neurohr-members' ), 'dismiss' ),
		);
		echo '<nav class="nhm-admin-tabs">';
		foreach ( $tabs as $key => $tab ) {
			$class = $key === $f['view'] ? 'is-active' : '';
			if ( 'expiring' === $key && $counts[ $key ] ) {
				$class .= ' is-warning';
			}
			if ( 'blocked' === $key && ! $counts[ $key ] && 'blocked' !== $f['view'] ) {
				continue;
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( array_merge( $keep, array( 'view' => $key ) ), $base ) ) . '"><span class="dashicons dashicons-' . esc_attr( $tab[1] ) . '"></span>' . esc_html( $tab[0] ) . ' <span class="count">' . (int) $counts[ $key ] . '</span></a>';
		}
		echo '</nav>';

		echo '<form class="nhm-filters" method="get"><input type="hidden" name="page" value="nhm-members"><input type="hidden" name="view" value="' . esc_attr( $f['view'] ) . '">';
		echo NHM_Admin::level_select( 'level', $f['level'], array( 'any' => __( 'All plans', 'neurohr-members' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper.
		$companies = NHM_Companies::query();
		if ( $companies ) {
			echo '<select name="company"><option value="0">' . esc_html__( 'All companies', 'neurohr-members' ) . '</option>';
			foreach ( $companies as $c ) {
				echo '<option value="' . (int) $c['id'] . '"' . selected( $f['company'], $c['id'], false ) . '>' . esc_html( $c['name'] ) . '</option>';
			}
			echo '</select>';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="' . esc_attr__( 'Name or email', 'neurohr-members' ) . '">';
		submit_button( __( 'Filter', 'neurohr-members' ), 'secondary', '', false );
		if ( $keep ) {
			echo ' <a class="button-link" href="' . esc_url( add_query_arg( 'view', $f['view'], $base ) ) . '">' . esc_html__( 'Clear', 'neurohr-members' ) . '</a>';
		}
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nhm-bulk-form">';
		echo '<input type="hidden" name="action" value="nhm_bulk">';
		wp_nonce_field( 'nhm_bulk' );
		echo '<div class="nhm-bulk"><select name="bulk" data-nhm-bulk><option value="">' . esc_html__( 'Bulk actions', 'neurohr-members' ) . '</option><option value="set_plan">' . esc_html__( 'Set own plan…', 'neurohr-members' ) . '</option><option value="renew">' . esc_html__( 'Renew own plan by one term', 'neurohr-members' ) . '</option><option value="free">' . esc_html__( 'Back to the free plan', 'neurohr-members' ) . '</option><option value="export">' . esc_html__( 'Export selected (CSV)', 'neurohr-members' ) . '</option><optgroup label="' . esc_attr__( 'Account', 'neurohr-members' ) . '"><option value="block">' . esc_html__( 'Block', 'neurohr-members' ) . '</option><option value="unblock">' . esc_html__( 'Unblock', 'neurohr-members' ) . '</option>' . ( current_user_can( 'delete_users' ) ? '<option value="delete">' . esc_html__( 'Delete account…', 'neurohr-members' ) . '</option>' : '' ) . '</optgroup></select> ';
		echo '<span class="nhm-bulk__delete" hidden><label><input type="checkbox" name="bulk_records" value="1"> ' . esc_html__( 'also delete their invoices and payments (for test accounts)', 'neurohr-members' ) . '</label></span> ';
		echo '<span class="nhm-bulk__plan" hidden>' . NHM_Admin::level_select( 'bulk_level', 0, array( 'id' => 'nhm-bulk-level' ) ) . ' <label>' . esc_html__( 'last day', 'neurohr-members' ) . ' <input type="date" name="bulk_expiry"></label> <span class="description">' . esc_html__( 'empty = one term from today', 'neurohr-members' ) . '</span></span> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button type="submit" class="button" data-nhm-confirm="' . esc_attr__( 'Apply to the selected people?', 'neurohr-members' ) . '">' . esc_html__( 'Apply', 'neurohr-members' ) . '</button></div>';
		echo '<div class="nhm-admin-card nhm-admin-card--flush"><table class="widefat striped nhm-members-table"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" data-nhm-check-all></td><th>' . esc_html__( 'Name', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Plan', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Until', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Company', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Registered', 'neurohr-members' ) . '</th><th>' . esc_html__( 'Last login', 'neurohr-members' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7" class="nhm-empty">' . esc_html__( 'Nobody here.', 'neurohr-members' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$u       = $row['user'];
			$m       = $row['m'];
			$company = $m['company_id'] ? NHM_Companies::get( $m['company_id'] ) : null;
			$edit    = get_edit_user_link( $u->ID ) . '#nhm';
			$login   = get_user_meta( $u->ID, NHM_Members::META_LOGIN, true );
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="users[]" value="' . (int) $u->ID . '"></th>';
			echo '<td><a class="nhm-name" href="' . esc_url( $edit ) . '">' . esc_html( $u->display_name ? $u->display_name : $u->user_login ) . '</a>' . ( NHM_Block::is_blocked( $u->ID ) ? ' <span class="nhm-pill nhm-pill--blocked">' . esc_html__( 'Blocked', 'neurohr-members' ) . '</span>' : '' ) . '<br><span class="nhm-muted">' . esc_html( $u->user_email ) . '</span>';
			$own_company = get_user_meta( $u->ID, 'nhm_company_name', true );
			if ( $own_company && ! $company ) {
				echo '<br><span class="nhm-muted">' . esc_html( $own_company ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . NHM_Admin::level_badge( $m['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( 'company' === $m['source'] ) {
				echo ' <span class="nhm-muted">' . esc_html__( 'via company', 'neurohr-members' ) . '</span>';
			}
			if ( NHM_Renew::is_on( $u->ID ) ) {
				echo ' <span class="nhm-pill nhm-pill--ok" title="' . esc_attr__( 'Card auto-renew is on', 'neurohr-members' ) . '">' . esc_html__( 'Auto-renew', 'neurohr-members' ) . '</span>';
			}
			if ( $m['expired_level'] ) {
				/* translators: %s: level */
				echo '<br><span class="nhm-exp nhm-exp--ended"><small>' . esc_html( sprintf( __( '%s ended', 'neurohr-members' ), NHM_Levels::name( $m['expired_level'] ) ) ) . '</small></span>';
			}
			echo '</td>';
			echo '<td>' . ( 'base' === $m['source'] ? ( $m['expired_level'] ? NHM_Admin::expiry_html( $m['personal']['expiry'] ? $m['personal']['expiry'] : ( $company ? $company['expiry_date'] : '' ) ) : '<span class="nhm-muted">—</span>' ) : NHM_Admin::expiry_html( $m['expiry'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>';
			if ( $company ) {
				echo '<a href="' . esc_url( NHM_Companies::edit_url( $company['id'] ) ) . '">' . esc_html( $company['name'] ) . '</a>';
				if ( 'owner' === $m['role'] ) {
					echo ' <span class="nhm-pill">' . esc_html__( 'owner', 'neurohr-members' ) . '</span>';
				}
			} else {
				echo '<span class="nhm-muted">—</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( mysql2date( 'j M Y', get_date_from_gmt( $u->user_registered ) ) ) . '</td>';
			echo '<td>' . ( $login ? esc_html( mysql2date( 'j M Y', $login ) ) : '<span class="nhm-muted">—</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></form>';

		if ( $pages > 1 ) {
			echo '<p class="nhm-pager">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg( array_merge( $keep, array( 'view' => $f['view'], 'paged' => $p ) ), $base );
				echo $p === $f['paged'] ? '<span class="button disabled">' . (int) $p . '</span> ' : '<a class="button" href="' . esc_url( $url ) . '">' . (int) $p . '</a> ';
			}
			echo '</p>';
		}
		echo '<p class="description">' . esc_html__( 'To change someone\'s plan, click their name. Company plans are changed on the company page.', 'neurohr-members' ) . '</p>';
		echo '</div>';
	}

	public static function bulk() {
		NHM_Admin::check( 'nhm_bulk' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$ids    = isset( $_POST['users'] ) ? array_map( 'absint', (array) $_POST['users'] ) : array();
		$action = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
		$level  = isset( $_POST['bulk_level'] ) ? absint( $_POST['bulk_level'] ) : 0;
		$expiry = isset( $_POST['bulk_expiry'] ) ? NHM_Members::clean_date( sanitize_text_field( wp_unslash( $_POST['bulk_expiry'] ) ) ) : '';
		// phpcs:enable
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=nhm-members' );
		if ( ! $ids || ! $action ) {
			NHM_Admin::flash( __( 'Tick at least one person and choose an action.', 'neurohr-members' ), 'warning' );
			NHM_Admin::back( $back );
		}
		if ( 'export' === $action ) {
			self::export_rows( array_map( function ( $id ) { return array( 'user' => get_userdata( $id ), 'm' => NHM_Members::membership( $id ) ); }, array_filter( $ids, 'get_userdata' ) ) );
		}
		if ( in_array( $action, array( 'block', 'unblock', 'delete' ), true ) ) {
			$records = ! empty( $_POST['bulk_records'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
			list( $done, $errors ) = self::account_action( $action, $ids, $records );
			$msg = array(
				/* translators: %d: number of people */
				'block'   => _n( '%d person blocked.', '%d people blocked.', $done, 'neurohr-members' ),
				/* translators: %d: number of people */
				'unblock' => _n( '%d person unblocked.', '%d people unblocked.', $done, 'neurohr-members' ),
				/* translators: %d: number of people */
				'delete'  => _n( '%d account deleted.', '%d accounts deleted.', $done, 'neurohr-members' ),
			);
			NHM_Admin::flash( sprintf( $msg[ $action ], $done ) . ( $errors ? ' ' . implode( ' ', array_unique( $errors ) ) : '' ), $errors ? 'warning' : 'success' );
			NHM_Admin::back( 'delete' === $action ? admin_url( 'admin.php?page=nhm-members' ) : $back );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			if ( ! get_userdata( $id ) ) {
				continue;
			}
			$p = NHM_Members::personal( $id );
			if ( 'set_plan' === $action && NHM_Levels::get( $level ) ) {
				NHM_Members::set_plan( $id, $level, NHM_Settings::today(), $expiry ? $expiry : NHM_Levels::term_end( $level, NHM_Settings::today() ), null, 'Bulk action' );
				$done++;
			} elseif ( 'renew' === $action && $p['level'] !== NHM_Levels::base_id() ) {
				$from = ( $p['expiry'] && $p['expiry'] >= NHM_Settings::today() ) ? $p['expiry'] : NHM_Settings::today();
				NHM_Members::set_plan( $id, $p['level'], $p['start'] ? $p['start'] : NHM_Settings::today(), NHM_Levels::term_end( $p['level'], $from ), null, 'Bulk renewal' );
				$done++;
			} elseif ( 'free' === $action ) {
				NHM_Members::set_plan( $id, NHM_Levels::base_id(), '', '', null, 'Bulk action' );
				$done++;
			}
		}
		/* translators: %d: number of people */
		NHM_Admin::flash( sprintf( _n( '%d person updated.', '%d people updated.', $done, 'neurohr-members' ), $done ) );
		NHM_Admin::back( $back );
	}

	/** @return array{0:int,1:string[]} done, error messages */
	private static function account_action( $action, array $ids, $records = false ) {
		$done   = 0;
		$errors = array();
		if ( 'delete' === $action && ! current_user_can( 'delete_users' ) ) {
			return array( 0, array( __( 'You are not allowed to delete accounts.', 'neurohr-members' ) ) );
		}
		foreach ( $ids as $id ) {
			if ( ! get_userdata( $id ) ) {
				continue;
			}
			if ( 'unblock' === $action ) {
				if ( NHM_Block::is_blocked( $id ) ) {
					NHM_Block::unblock( $id );
					$done++;
				}
				continue;
			}
			if ( 'block' === $action && NHM_Block::is_blocked( $id ) ) {
				continue;
			}
			$r = 'block' === $action ? NHM_Block::block( $id ) : NHM_Block::delete_member( $id, $records );
			if ( is_wp_error( $r ) ) {
				$errors[] = $r->get_error_message();
			} else {
				$done++;
			}
		}
		return array( $done, $errors );
	}

	/** Buttons on the profile screen (links, because they sit inside the profile form). */
	public static function member_action() {
		NHM_Admin::check( 'nhm_member_action' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$uid     = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$action  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$records = ! empty( $_GET['records'] );
		// phpcs:enable
		if ( 'hold_off' === $action ) {
			NHM_Refunds::release( $uid );
			NHM_Log::add( array( 'user_id' => $uid, 'event' => 'hold_off', 'message' => 'Access restored by staff' ) );
			NHM_Admin::flash( __( 'Access restored.', 'neurohr-members' ) );
			NHM_Admin::back( get_edit_user_link( $uid ) . '#nhm-account' );
		}
		if ( in_array( $action, array( 'ar_off', 'ar_charge' ), true ) ) {
			if ( 'ar_off' === $action ) {
				NHM_Renew::turn_off( $uid, '', 'admin' );
				NHM_Admin::flash( __( 'Auto-renew is off.', 'neurohr-members' ) );
			} else {
				$r = NHM_Renew::charge( $uid, 'staff' );
				$m = array(
					'paid'       => array( __( 'Charged. The plan is renewed and the paid invoice was emailed.', 'neurohr-members' ), 'success' ),
					'processing' => array( __( 'The charge is processing at the bank. The plan renews when Stripe confirms it.', 'neurohr-members' ), 'warning' ),
					'failed'     => array( __( 'The card could not be charged. The member got the "payment failed" email; see the history below.', 'neurohr-members' ), 'error' ),
					'skipped'    => array( __( 'Nothing charged: auto-renew is off, there is no card for this Stripe mode, or Stripe is not set up.', 'neurohr-members' ), 'warning' ),
				);
				NHM_Admin::flash( $m[ $r ][0], $m[ $r ][1] );
			}
			NHM_Admin::back( get_edit_user_link( $uid ) . '#nhm-renew' );
		}
		if ( ! in_array( $action, array( 'block', 'unblock', 'delete' ), true ) ) {
			NHM_Admin::back( admin_url( 'admin.php?page=nhm-members' ) );
		}
		list( $done, $errors ) = self::account_action( $action, array( $uid ), $records );
		if ( $errors ) {
			NHM_Admin::flash( implode( ' ', $errors ), 'error' );
		} elseif ( $done ) {
			$msg = array(
				'block'   => __( 'Blocked. They are logged out, cannot log in, and their email cannot be used to register again.', 'neurohr-members' ),
				'unblock' => __( 'Unblocked. They can log in again (their plan is now Free).', 'neurohr-members' ),
				'delete'  => $records ? __( 'Account, invoices and payments deleted.', 'neurohr-members' ) : __( 'Account deleted. Their invoices are kept under Members → Invoices.', 'neurohr-members' ),
			);
			NHM_Admin::flash( $msg[ $action ] );
		}
		NHM_Admin::back( 'delete' === $action && ! $errors ? admin_url( 'admin.php?page=nhm-members' ) : get_edit_user_link( $uid ) . '#nhm-account' );
	}

	public static function action_url( $user_id, $do, $records = false ) {
		$args = array( 'action' => 'nhm_member_action', 'user' => (int) $user_id, 'do' => $do );
		if ( $records ) {
			$args['records'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'nhm_member_action' );
	}

	public static function export() {
		if ( ! current_user_can( NHM_CAP ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'nhm_export' );
		list( $rows ) = self::rows( self::filters() );
		self::export_rows( $rows );
	}

	private static function export_rows( array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=members-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads UTF-8 with this mark.
		$fields = NHM_Fields::all();
		fputcsv( $out, array_merge( array( 'Name', 'Email' ), array_map( function ( $f ) { return $f['label']; }, array_values( $fields ) ), array( 'Plan', 'Plan via', 'Until', 'Company account', 'Client code', 'Role', 'Registered', 'Last login', 'Newsletter' ) ) );
		foreach ( $rows as $row ) {
			$u       = $row['user'];
			$m       = $row['m'];
			$company = $m['company_id'] ? NHM_Companies::get( $m['company_id'] ) : null;
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array_merge(
						array( $u->display_name, $u->user_email ),
						array_map( function ( $f ) use ( $u ) { return NHM_Fields::text( $u->ID, $f ); }, array_values( $fields ) ),
						array(
						$m['name'],
						$m['source'],
						$m['expiry'],
						$company ? $company['name'] : '',
						$company ? $company['client_code'] : '',
						$m['role'],
						get_date_from_gmt( $u->user_registered, 'Y-m-d' ),
						substr( (string) get_user_meta( $u->ID, NHM_Members::META_LOGIN, true ), 0, 10 ),
						get_user_meta( $u->ID, 'nhm_newsletter', true ) ? 'yes' : '',
						)
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Stop spreadsheet formulas in exported text. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $value : $value;
	}

	/* ---------- Users screen column ---------- */

	public static function users_column( $columns ) {
		$columns['nhm_plan'] = __( 'Plan', 'neurohr-members' );
		return $columns;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'nhm_plan' !== $column ) {
			return $value;
		}
		$m = NHM_Members::membership( $user_id );
		return NHM_Admin::level_badge( $m['level'] ) . ( $m['expiry'] ? '<br><small>' . esc_html( mysql2date( 'j M Y', $m['expiry'] ) ) . '</small>' : '' );
	}

	/* ---------- Profile screen ---------- */

	public static function profile_section( $user ) {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$p         = NHM_Members::personal( $user->ID );
		$m         = NHM_Members::membership( $user->ID );
		$companies = NHM_Companies::query();
		$company   = $m['company_id'];
		wp_nonce_field( 'nhm_profile', '_nhm_profile_nonce' );
		?>
		<div class="nhm-admin nhm-profile-box" id="nhm">
			<h2><?php esc_html_e( 'Membership', 'neurohr-members' ); ?></h2>
			<p class="nhm-now">
				<?php esc_html_e( 'Plan now:', 'neurohr-members' ); ?>
				<?php echo NHM_Admin::level_badge( $m['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				if ( 'company' === $m['source'] ) {
					esc_html_e( 'through the company', 'neurohr-members' );
				}
				?>
				<?php echo 'base' !== $m['source'] ? NHM_Admin::expiry_html( $m['expiry'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="nhm-level"><?php esc_html_e( 'Own plan', 'neurohr-members' ); ?></label></th>
					<td><?php echo NHM_Admin::level_select( 'nhm_level', $p['level'], array( 'id' => 'nhm-level' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p class="description"><?php esc_html_e( 'For a person who pays for themselves. Staff of a client company get their plan from the company instead (below).', 'neurohr-members' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="nhm-start"><?php esc_html_e( 'Start date', 'neurohr-members' ); ?></label></th>
					<td><input type="date" id="nhm-start" name="nhm_start" value="<?php echo esc_attr( $p['start'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="nhm-expiry"><?php esc_html_e( 'Last day', 'neurohr-members' ); ?></label></th>
					<td><?php echo NHM_Admin::expiry_input( 'nhm_expiry', $p['expiry'], 'nhm-expiry', 'nhm-level' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p class="description"><?php esc_html_e( 'Access ends after this day; the person goes back to the free plan. Empty = no end date. "+1 term" adds the term of the plan picked above (e.g. 1 year).', 'neurohr-members' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nhm-invoices&action=new&user=' . (int) $user->ID . '&level=' . (int) ( $p['level'] !== NHM_Levels::base_id() ? $p['level'] : 0 ) ) ); ?>"><?php esc_html_e( 'Create invoice / renewal', 'neurohr-members' ); ?></a>
					<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( admin_url( 'admin.php?page=nhm-invoices&user=' . (int) $user->ID ) ); ?>"><?php esc_html_e( 'Invoices of this person', 'neurohr-members' ); ?></a></p></td>
				</tr>
				<tr>
					<th><label for="nhm-company"><?php esc_html_e( 'Company account', 'neurohr-members' ); ?></label></th>
					<td>
						<select id="nhm-company" name="nhm_company">
							<option value="0"><?php esc_html_e( '— none —', 'neurohr-members' ); ?></option>
							<?php foreach ( $companies as $c ) : ?>
								<option value="<?php echo (int) $c['id']; ?>" <?php selected( $company, $c['id'] ); ?>>
									<?php echo esc_html( $c['name'] . ' (' . NHM_Levels::name( $c['level_id'] ) . ', ' . NHM_Companies::seats_used( $c['id'] ) . '/' . $c['seats'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<label style="margin-left:10px"><input type="checkbox" name="nhm_owner" value="1" <?php checked( 'owner', $m['role'] ); ?>> <?php esc_html_e( 'Account owner (manages staff)', 'neurohr-members' ); ?></label>
						<?php if ( $company ) : ?>
							<a class="button-link" style="margin-left:10px" href="<?php echo esc_url( NHM_Companies::edit_url( $company ) ); ?>"><?php esc_html_e( 'Open company', 'neurohr-members' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="nhm-note"><?php esc_html_e( 'Note', 'neurohr-members' ); ?></label></th>
					<td><textarea id="nhm-note" name="nhm_note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Invoice INV-2026-014, paid by bank transfer', 'neurohr-members' ); ?>"><?php echo esc_textarea( $p['note'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Only admins see this.', 'neurohr-members' ); ?></p></td>
				</tr>
			</table>
			<h3><?php esc_html_e( 'Registration answers', 'neurohr-members' ); ?></h3>
			<div class="nhm-admin-fields nhm">
				<?php
				foreach ( NHM_Fields::all() as $f ) {
					$f['required'] = 0;
					echo NHM_Fields::render( $f, NHM_Fields::value( $user->ID, $f ), 'nhmpf_' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the field builder.
				}
				?>
			</div>
			<h3><?php esc_html_e( 'Billing details (personal invoices)', 'neurohr-members' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php foreach ( NHM_Account::billing_fields() as $key => $bf ) : ?>
					<tr><th><label for="nhm-b-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $bf[0] ); ?></label></th>
					<td><?php if ( 'textarea' === $bf[1] ) : ?><textarea class="large-text" rows="3" id="nhm-b-<?php echo esc_attr( $key ); ?>" name="nhmb[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( get_user_meta( $user->ID, $key, true ) ); ?></textarea><?php else : ?><input type="text" class="regular-text" id="nhm-b-<?php echo esc_attr( $key ); ?>" name="nhmb[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( get_user_meta( $user->ID, $key, true ) ); ?>"><?php endif; ?></td></tr>
				<?php endforeach; ?>
			</table>
			<?php self::renew_box( $user ); ?>
			<?php self::account_box( $user ); ?>
			<h3><?php esc_html_e( 'History', 'neurohr-members' ); ?></h3>
			<?php NHM_Log::render( NHM_Log::query( array( 'user_id' => $user->ID, 'limit' => 30 ) ) ); ?>
		</div>
		<?php
	}

	private static function renew_box( $user ) {
		$a = NHM_Renew::get( $user->ID );
		$p = NHM_Members::personal( $user->ID );
		echo '<h3 id="nhm-renew">' . esc_html__( 'Card auto-renew', 'neurohr-members' ) . '</h3>';
		$consent = get_user_meta( $user->ID, 'nhm_consent', true );
		echo '<table class="form-table" role="presentation">';
		if ( empty( $a['on'] ) ) {
			echo '<tr><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><td>' . esc_html__( 'Off', 'neurohr-members' );
			if ( $a['off_reason'] ) {
				/* translators: %s: reason */
				echo ' <span class="description">' . esc_html( sprintf( __( '(reason: %s)', 'neurohr-members' ), $a['off_reason'] ) ) . '</span>';
			}
			if ( $a['last4'] ) {
				echo '<br><span class="description">' . esc_html( sprintf( /* translators: %s: card */ __( 'Card on file: %s', 'neurohr-members' ), NHM_Renew::card_label( $a ) ) ) . '</span>';
			}
			echo '<p class="description">' . esc_html__( 'Members turn it on when they pay by card, or in My account → Plan & billing.', 'neurohr-members' ) . '</p></td></tr>';
		} else {
			echo '<tr><th>' . esc_html__( 'Status', 'neurohr-members' ) . '</th><td><span class="nhm-pill nhm-pill--ok">' . esc_html__( 'On', 'neurohr-members' ) . '</span> ' . esc_html( NHM_Renew::card_label( $a ) ) . ' <span class="description">(' . esc_html( $a['mode'] ) . ')</span>';
			/* translators: 1: amount, 2: date */
			echo '<br>' . esc_html( sprintf( __( 'Next charge: %1$s on %2$s', 'neurohr-members' ), NHM_Settings::money( NHM_Renew::total( $user->ID ) ), NHM_Emails::format_date( '' !== $a['retry'] ? $a['retry'] : $p['expiry'] ) ) );
			if ( '' !== $a['retry'] ) {
				/* translators: %s: error */
				echo '<br><span class="nhm-exp--ended">' . esc_html( sprintf( __( 'First try failed: %s. Access continues until the second try.', 'neurohr-members' ), $a['last_error'] ) ) . '</span>';
			}
			echo '</td></tr>';
			echo '<tr><th><label for="nhm-ar-price">' . esc_html__( 'Locked price per term', 'neurohr-members' ) . '</label></th><td><input type="number" step="0.01" min="0" id="nhm-ar-price" name="nhm_ar_price" value="' . esc_attr( number_format( (float) $a['price'], 2, '.', '' ) ) . '" class="small-text" style="width:110px"> <span class="description">' . esc_html__( 'Before SST. If the plan price is lower, the lower price is charged. Saved with "Update User".', 'neurohr-members' ) . '</span></td></tr>';
			echo '<tr><th></th><td><a class="button" href="' . esc_url( self::action_url( $user->ID, 'ar_charge' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Charge the saved card now for the next term? The new term is added to the current last day.', 'neurohr-members' ) . '">' . esc_html( 'test' === NHM_Stripe::mode() ? __( 'Run renewal now (test mode)', 'neurohr-members' ) : __( 'Charge renewal now', 'neurohr-members' ) ) . '</a> ';
			echo '<a class="button-link nhm-link-danger" href="' . esc_url( self::action_url( $user->ID, 'ar_off' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Turn off auto-renew for this person? Their locked price is cleared.', 'neurohr-members' ) . '">' . esc_html__( 'Turn off auto-renew', 'neurohr-members' ) . '</a></td></tr>';
		}
		if ( is_array( $consent ) && ! empty( $consent['date'] ) ) {
			echo '<tr><th>' . esc_html__( 'Agreed to terms', 'neurohr-members' ) . '</th><td>' . esc_html( mysql2date( 'j M Y, H:i', $consent['date'] ) ) . ' <span class="description">' . esc_html__( 'Terms of Use and Privacy Policy, at registration', 'neurohr-members' ) . '</span></td></tr>';
		}
		echo '</table>';
	}

	private static function account_box( $user ) {
		echo '<h3 id="nhm-account">' . esc_html__( 'Block or delete', 'neurohr-members' ) . '</h3>';
		if ( NHM_Block::protected_user( $user->ID ) ) {
			echo '<p class="description">' . esc_html__( 'Staff accounts and your own account cannot be blocked or deleted here.', 'neurohr-members' ) . '</p>';
			return;
		}
		$hold = NHM_Refunds::on_hold( $user->ID );
		if ( $hold ) {
			echo '<div class="nhm-account-box is-blocked"><p><span class="nhm-pill nhm-pill--blocked">' . esc_html__( 'Access paused', 'neurohr-members' ) . '</span> ' . esc_html( 'dispute' === $hold['why'] ? __( 'Chargeback opened at the bank.', 'neurohr-members' ) : __( 'Refund request being reviewed (Payments → Refund requests).', 'neurohr-members' ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::action_url( $user->ID, 'hold_off' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Give access back now?', 'neurohr-members' ) . '">' . esc_html__( 'Restore access', 'neurohr-members' ) . '</a></p></div><br>';
		}
		$info = NHM_Block::info( $user->ID );
		echo '<div class="nhm-account-box' . ( $info ? ' is-blocked' : '' ) . '">';
		if ( $info ) {
			echo '<p><span class="nhm-pill nhm-pill--blocked">' . esc_html__( 'Blocked', 'neurohr-members' ) . '</span> ';
			if ( $info['date'] ) {
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'since %s', 'neurohr-members' ), mysql2date( 'j M Y, g:i a', $info['date'] ) ) );
			}
			echo '</p><p><a class="button" href="' . esc_url( self::action_url( $user->ID, 'unblock' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Unblock this person? They can log in again and register with this email.', 'neurohr-members' ) . '">' . esc_html__( 'Unblock', 'neurohr-members' ) . '</a>';
		} else {
			echo '<p class="description">' . esc_html__( 'Block: they are logged out at once, cannot log in, their own plan goes back to Free and their email cannot register again. You can unblock later.', 'neurohr-members' ) . '</p>';
			echo '<p><a class="button nhm-button-warn" href="' . esc_url( self::action_url( $user->ID, 'block' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Block this person now?', 'neurohr-members' ) . '">' . esc_html__( 'Block this person', 'neurohr-members' ) . '</a>';
		}
		if ( current_user_can( 'delete_users' ) ) {
			echo ' <a class="button nhm-button-danger" href="' . esc_url( self::action_url( $user->ID, 'delete' ) ) . '" data-nhm-confirm="' . esc_attr__( 'Delete this account for good? Paid invoices are kept for your records, unpaid ones are voided. This cannot be undone.', 'neurohr-members' ) . '">' . esc_html__( 'Delete account', 'neurohr-members' ) . '</a>';
			echo ' <a class="button-link nhm-link-danger" href="' . esc_url( self::action_url( $user->ID, 'delete', true ) ) . '" data-nhm-confirm="' . esc_attr__( 'Delete this account AND all of its invoices, payments and history? Use this only for test accounts. This cannot be undone.', 'neurohr-members' ) . '">' . esc_html__( 'Delete with invoices and payments (test accounts)', 'neurohr-members' ) . '</a>';
		}
		echo '</p></div>';
	}

	public static function profile_save( $user_id ) {
		if ( ! current_user_can( NHM_CAP ) || ! isset( $_POST['_nhm_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nhm_profile_nonce'] ) ), 'nhm_profile' ) ) {
			return;
		}
		$level  = isset( $_POST['nhm_level'] ) ? absint( $_POST['nhm_level'] ) : NHM_Levels::base_id();
		$start  = isset( $_POST['nhm_start'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_start'] ) ) : '';
		$expiry = isset( $_POST['nhm_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['nhm_expiry'] ) ) : '';
		$note   = isset( $_POST['nhm_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nhm_note'] ) ) : '';
		if ( $level !== NHM_Levels::base_id() && '' === $start ) {
			$start = NHM_Settings::today();
		}
		NHM_Members::set_plan( $user_id, $level, $start, $expiry, $note );
		if ( isset( $_POST['nhm_ar_price'] ) && NHM_Renew::is_on( $user_id ) ) {
			$a     = NHM_Renew::get( $user_id );
			$price = round( (float) NHM_Levels::clean_amount( sanitize_text_field( wp_unslash( $_POST['nhm_ar_price'] ) ) ), 2 );
			if ( $price > 0 && abs( $price - (float) $a['price'] ) > 0.001 ) {
				NHM_Log::add( array( 'user_id' => $user_id, 'event' => 'autorenew_on', 'message' => sprintf( 'Locked price %s → %s (staff)', NHM_Settings::money( $a['price'] ), NHM_Settings::money( $price ) ) ) );
				$a['price'] = $price;
				NHM_Renew::save( $user_id, $a );
			}
		}

		$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field.
		foreach ( NHM_Fields::all() as $f ) {
			list( $value ) = NHM_Fields::read( $f, $posted, 'nhmpf_', false );
			NHM_Fields::store( $user_id, $f, $value );
		}
		$bill = isset( $posted['nhmb'] ) && is_array( $posted['nhmb'] ) ? $posted['nhmb'] : array();
		foreach ( NHM_Account::billing_fields() as $key => $bf ) {
			$value = isset( $bill[ $key ] ) ? ( 'textarea' === $bf[1] ? sanitize_textarea_field( $bill[ $key ] ) : sanitize_text_field( $bill[ $key ] ) ) : '';
			'' === $value ? delete_user_meta( $user_id, $key ) : update_user_meta( $user_id, $key, $value );
		}

		$new   = isset( $_POST['nhm_company'] ) ? absint( $_POST['nhm_company'] ) : 0;
		$owner = ! empty( $_POST['nhm_owner'] );
		$old   = (int) get_user_meta( $user_id, NHM_Members::META_COMPANY, true );
		if ( $old && $old !== $new ) {
			NHM_Companies::remove_member( $old, $user_id );
		}
		if ( $new ) {
			$result = NHM_Companies::add_member( $new, $user_id, $owner ? 'owner' : 'member' );
			if ( is_wp_error( $result ) ) {
				NHM_Admin::flash( $result->get_error_message(), 'error' );
			} elseif ( $owner ) {
				NHM_Companies::set_owner( $new, $user_id );
			} elseif ( 'owner' === get_user_meta( $user_id, NHM_Members::META_ROLE, true ) ) {
				NHM_Companies::set_owner( $new, 0 );
				update_user_meta( $user_id, NHM_Members::META_ROLE, 'member' );
			}
		}
		NHM_Members::flush( $user_id );
	}
}
