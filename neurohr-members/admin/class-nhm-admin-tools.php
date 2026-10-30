<?php
/**
 * Members → Import: companies and people from a CSV file (Excel → Save as CSV UTF-8).
 * "Check only" shows what would happen without changing anything.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Admin_Tools {

	const REPORT = 'nhm_import_report_';

	public static function init() {
		add_action( 'admin_post_nhm_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_nhm_csv_template', array( __CLASS__, 'template' ) );
	}

	public static function company_columns() {
		return array( 'name', 'client_code', 'reg_no', 'tin', 'sst_no', 'address', 'postcode', 'city', 'state', 'country', 'phone', 'billing_email', 'contact_person', 'industry', 'employees', 'plan', 'start', 'last_day', 'seats', 'owner_email', 'notes' );
	}

	public static function member_columns() {
		$cols = array( 'email', 'name', 'plan', 'start', 'last_day', 'client_code', 'role', 'note' );
		foreach ( NHM_Fields::all() as $f ) {
			$cols[] = $f['key'];
		}
		return $cols;
	}

	public static function page() {
		if ( ! current_user_can( NHM_CAP ) ) {
			return;
		}
		$report = get_transient( self::REPORT . get_current_user_id() );
		echo '<div class="wrap nhm-admin">';
		NHM_Admin::header( __( 'Import', 'neurohr-members' ) );
		if ( is_array( $report ) ) {
			delete_transient( self::REPORT . get_current_user_id() );
			echo '<section class="nhm-admin-card"><h2>' . esc_html( $report['dry'] ? __( 'Check result (nothing was changed)', 'neurohr-members' ) : __( 'Import result', 'neurohr-members' ) ) . '</h2>';
			echo '<p><strong>' . esc_html( $report['summary'] ) . '</strong></p><table class="widefat striped"><tbody>';
			foreach ( array_slice( $report['lines'], 0, 500 ) as $line ) {
				echo '<tr class="' . esc_attr( 'error' === $line[0] ? 'nhm-row-error' : '' ) . '"><td style="width:70px">' . esc_html( $line[1] ) . '</td><td>' . esc_html( $line[2] ) . '</td></tr>';
			}
			echo '</tbody></table></section>';
		}
		$tpl = function ( $type ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=nhm_csv_template&type=' . $type ), 'nhm_csv_template' );
		};
		foreach ( array( 'companies' => __( 'Companies', 'neurohr-members' ), 'members' => __( 'People', 'neurohr-members' ) ) as $type => $title ) {
			$cols = 'companies' === $type ? self::company_columns() : self::member_columns();
			echo '<form class="nhm-admin-card" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="nhm_import"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'nhm_import' );
			echo '<h2><span class="dashicons dashicons-' . ( 'companies' === $type ? 'building' : 'groups' ) . '"></span>' . esc_html( $title ) . ' <a class="button button-small nhm-right" href="' . esc_url( $tpl( $type ) ) . '">' . esc_html__( 'Download template', 'neurohr-members' ) . '</a></h2>';
			echo '<p class="nhm-admin-card__intro">' . esc_html__( 'Columns (first row = these names, any order, missing columns are left alone):', 'neurohr-members' ) . ' <code>' . esc_html( implode( ', ', $cols ) ) . '</code></p>';
			if ( 'companies' === $type ) {
				echo '<p class="description">' . esc_html__( 'A row with an existing client_code updates that company; otherwise a new company is created. plan = level name or short name (e.g. corporate). last_day empty + plan = one term from start. owner_email: an existing account becomes owner; otherwise an invitation is sent (only when "Send emails" is ticked).', 'neurohr-members' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Existing emails are updated. role = owner or member (with client_code). Empty cells are left as they are.', 'neurohr-members' ) . '</p>';
			}
			echo '<p><input type="file" name="csv" accept=".csv,text/csv" required></p>';
			if ( 'members' === $type ) {
				echo '<label class="nhm-toggle"><input type="checkbox" name="create" value="1" checked> <span>' . esc_html__( 'Create accounts for new emails', 'neurohr-members' ) . '</span></label>';
			}
			echo '<label class="nhm-toggle"><input type="checkbox" name="emails" value="1"> <span>' . esc_html__( 'Send emails (new accounts: "Account Created" with a set-password link; company owners: invitation)', 'neurohr-members' ) . '</span></label>';
			echo '<label class="nhm-toggle"><input type="checkbox" name="dry" value="1" checked> <span><strong>' . esc_html__( 'Check only — change nothing', 'neurohr-members' ) . '</strong></span></label>';
			submit_button( __( 'Upload', 'neurohr-members' ), 'secondary', '', false );
			echo '</form>';
		}
		echo '</div>';
	}

	public static function template() {
		NHM_Admin::check( 'nhm_csv_template' );
		$type = isset( $_GET['type'] ) && 'companies' === $_GET['type'] ? 'companies' : 'members';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=nhm-' . $type . '-template.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		if ( 'companies' === $type ) {
			fputcsv( $out, self::company_columns() );
			fputcsv( $out, array( 'Example Sdn Bhd', '', '201901000001 (1300000-A)', 'C2584563200', '', 'Level 8, Menara Example', '50450', 'Kuala Lumpur', 'W.P. Kuala Lumpur', 'Malaysia', '03-1234 5678', 'hr@example.com.my', 'Siti Aminah', 'Manufacturing', '120', 'consulting', NHM_Settings::today(), '', '10', 'hr@example.com.my', 'Contract CT-2026-01' ) );
		} else {
			$cols = self::member_columns();
			fputcsv( $out, $cols );
			$row = array( 'ali@example.com', 'Ali bin Abu', 'pro', NHM_Settings::today(), '', '', '', 'Paid by bank transfer' );
			fputcsv( $out, array_pad( $row, count( $cols ), '' ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Rows as header => value arrays. */
	public static function read_csv( $path ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return array();
		}
		$first = fgets( $fh );
		$delim = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';
		rewind( $fh );
		$head = fgetcsv( $fh, 0, $delim );
		if ( ! $head ) {
			return array();
		}
		$head = array_map(
			function ( $h ) {
				$h = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
				return str_replace( array( ' ', '-' ), '_', strtolower( trim( $h ) ) );
			},
			$head
		);
		$rows = array();
		while ( ( $r = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			if ( array( null ) === $r || ! array_filter( $r, 'strlen' ) ) {
				continue;
			}
			$row = array();
			foreach ( $head as $i => $h ) {
				$row[ $h ] = isset( $r[ $i ] ) ? trim( (string) $r[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $rows;
	}

	/** d/m/Y, d-m-Y or Y-m-d → Y-m-d. */
	public static function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m ) ) {
			$value = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}
		return NHM_Members::clean_date( $value );
	}

	public static function import() {
		NHM_Admin::check( 'nhm_import' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$type   = isset( $_POST['type'] ) && 'companies' === $_POST['type'] ? 'companies' : 'members';
		$dry    = ! empty( $_POST['dry'] );
		$emails = ! empty( $_POST['emails'] );
		$create = ! empty( $_POST['create'] );
		$file   = isset( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$rows = ( $file && is_uploaded_file( $file ) ) ? self::read_csv( $file ) : array();
		$rep  = $rows ? ( 'companies' === $type ? self::import_companies( $rows, $dry, $emails ) : self::import_members( $rows, $dry, $emails, $create ) ) : array( 'lines' => array( array( 'error', '-', __( 'No rows found. Save the file as CSV (UTF-8) with the column names in the first row.', 'neurohr-members' ) ) ), 'summary' => '' );
		$rep['dry'] = $dry;
		set_transient( self::REPORT . get_current_user_id(), $rep, 600 );
		NHM_Admin::back( admin_url( 'admin.php?page=nhm-tools' ) );
	}

	public static function import_companies( array $rows, $dry, $emails ) {
		$lines = array();
		$new   = 0;
		$upd   = 0;
		$err   = 0;
		foreach ( $rows as $i => $r ) {
			$line = $i + 2;
			$name = isset( $r['name'] ) ? $r['name'] : '';
			$code = isset( $r['client_code'] ) ? $r['client_code'] : '';
			$ex   = $code ? NHM_Companies::by_code( $code ) : null;
			if ( ! $ex && '' === $name ) {
				$lines[] = array( 'error', $line, __( 'No name and no known client code — skipped.', 'neurohr-members' ) );
				$err++;
				continue;
			}
			$data = array();
			foreach ( array_merge( array_keys( NHM_Companies::text_fields() ), array( 'name', 'address', 'notes', 'employees', 'seats' ) ) as $k ) {
				if ( isset( $r[ $k ] ) && '' !== $r[ $k ] ) {
					$data[ $k ] = $r[ $k ];
				}
			}
			if ( isset( $data['address'] ) ) {
				$data['address'] = str_replace( array( ' | ', '|' ), "\n", $data['address'] );
			}
			if ( ! empty( $r['plan'] ) ) {
				$level = NHM_Levels::resolve( $r['plan'] );
				if ( ! $level ) {
					/* translators: %s: plan */
					$lines[] = array( 'error', $line, sprintf( __( 'Unknown plan "%s" — skipped.', 'neurohr-members' ), $r['plan'] ) );
					$err++;
					continue;
				}
				$start               = self::date( isset( $r['start'] ) ? $r['start'] : '' );
				$start               = $start ? $start : NHM_Settings::today();
				$last                = self::date( isset( $r['last_day'] ) ? $r['last_day'] : '' );
				$data['level_id']    = $level['id'];
				$data['start_date']  = $start;
				$data['expiry_date'] = $last ? $last : NHM_Levels::term_end( $level['id'], $start );
			}
			$label = ( $ex ? $ex['name'] : $name ) . ( $code ? ' (' . $code . ')' : '' );
			if ( $dry ) {
				$lines[] = array( 'ok', $line, ( $ex ? __( 'Would update', 'neurohr-members' ) : __( 'Would create', 'neurohr-members' ) ) . ': ' . $label );
				$ex ? $upd++ : $new++;
				continue;
			}
			if ( $ex ) {
				NHM_Companies::update( $ex['id'], $data );
				$id = $ex['id'];
				$upd++;
			} else {
				$id = NHM_Companies::create( $data );
				if ( is_wp_error( $id ) ) {
					$lines[] = array( 'error', $line, $id->get_error_message() );
					$err++;
					continue;
				}
				$new++;
			}
			$msg = ( $ex ? __( 'Updated', 'neurohr-members' ) : __( 'Created', 'neurohr-members' ) ) . ': ' . NHM_Companies::get( $id )['name'] . ' (' . NHM_Companies::get( $id )['client_code'] . ')';
			if ( ! empty( $r['owner_email'] ) && is_email( $r['owner_email'] ) ) {
				$u = get_user_by( 'email', $r['owner_email'] );
				if ( $u ) {
					$res = NHM_Companies::add_member( $id, $u->ID, 'owner' );
					$msg .= is_wp_error( $res ) ? ' — ' . $res->get_error_message() : ' — ' . __( 'owner set', 'neurohr-members' );
				} elseif ( $emails ) {
					$res = NHM_Companies::invite( $id, $r['owner_email'], 'owner', get_current_user_id() );
					$msg .= is_wp_error( $res ) ? ' — ' . $res->get_error_message() : ' — ' . __( 'owner invited', 'neurohr-members' );
				} else {
					$msg .= ' — ' . __( 'owner has no account yet (tick "Send emails" to invite)', 'neurohr-members' );
				}
			}
			$lines[] = array( 'ok', $line, $msg );
		}
		/* translators: 1: new, 2: updated, 3: errors */
		return array( 'lines' => $lines, 'summary' => sprintf( __( '%1$d new, %2$d updated, %3$d skipped.', 'neurohr-members' ), $new, $upd, $err ) );
	}

	public static function import_members( array $rows, $dry, $emails, $create ) {
		$lines  = array();
		$new    = 0;
		$upd    = 0;
		$err    = 0;
		$fields = NHM_Fields::all();
		$labels = array();
		foreach ( $fields as $f ) {
			$labels[ str_replace( array( ' ', '-' ), '_', strtolower( $f['label'] ) ) ] = $f['key'];
		}
		foreach ( $rows as $i => $r ) {
			$line  = $i + 2;
			$email = isset( $r['email'] ) ? sanitize_email( $r['email'] ) : '';
			if ( ! is_email( $email ) ) {
				$lines[] = array( 'error', $line, __( 'No valid email — skipped.', 'neurohr-members' ) );
				$err++;
				continue;
			}
			$level = ! empty( $r['plan'] ) ? NHM_Levels::resolve( $r['plan'] ) : null;
			if ( ! empty( $r['plan'] ) && ! $level ) {
				/* translators: %s: plan */
				$lines[] = array( 'error', $line, sprintf( __( 'Unknown plan "%s" — skipped.', 'neurohr-members' ), $r['plan'] ) );
				$err++;
				continue;
			}
			$company = ! empty( $r['client_code'] ) ? NHM_Companies::by_code( $r['client_code'] ) : null;
			if ( ! empty( $r['client_code'] ) && ! $company ) {
				/* translators: %s: code */
				$lines[] = array( 'error', $line, sprintf( __( 'Unknown client code "%s" — skipped. Import the companies first.', 'neurohr-members' ), $r['client_code'] ) );
				$err++;
				continue;
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user && ! $create ) {
				$lines[] = array( 'error', $line, $email . ': ' . __( 'no account (creating accounts is off) — skipped.', 'neurohr-members' ) );
				$err++;
				continue;
			}
			if ( $dry ) {
				$lines[] = array( 'ok', $line, ( $user ? __( 'Would update', 'neurohr-members' ) : __( 'Would create', 'neurohr-members' ) ) . ': ' . $email . ( $level ? ' → ' . $level['name'] : '' ) . ( $company ? ' @ ' . $company['name'] : '' ) );
				$user ? $upd++ : $new++;
				continue;
			}
			$created = false;
			if ( ! $user ) {
				$name = ! empty( $r['name'] ) ? sanitize_text_field( $r['name'] ) : strstr( $email, '@', true );
				$uid  = wp_insert_user(
					array(
						'user_login'   => NHM_Auth::username_from_email( $email ),
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 24 ),
						'display_name' => $name,
						'first_name'   => $name,
						'nickname'     => $name,
						'role'         => get_option( 'default_role', 'subscriber' ),
					)
				);
				if ( is_wp_error( $uid ) ) {
					$lines[] = array( 'error', $line, $email . ': ' . $uid->get_error_message() );
					$err++;
					continue;
				}
				$user    = get_userdata( $uid );
				$created = true;
				$new++;
			} else {
				if ( ! empty( $r['name'] ) ) {
					wp_update_user( array( 'ID' => $user->ID, 'display_name' => sanitize_text_field( $r['name'] ), 'first_name' => sanitize_text_field( $r['name'] ) ) );
				}
				$upd++;
			}
			foreach ( $r as $col => $value ) {
				$key = isset( $fields[ $col ] ) ? $col : ( isset( $labels[ $col ] ) ? $labels[ $col ] : '' );
				if ( $key && '' !== $value ) {
					$f   = $fields[ $key ];
					$src = array( 'x_' . $key => 'checkboxes' === $f['type'] ? array_map( 'trim', explode( ',', $value ) ) : $value );
					list( $clean ) = NHM_Fields::read( $f, $src, 'x_', false );
					NHM_Fields::store( $user->ID, $f, $clean );
				}
			}
			$msg = ( $created ? __( 'Created', 'neurohr-members' ) : __( 'Updated', 'neurohr-members' ) ) . ': ' . $email;
			if ( $level ) {
				$start = self::date( isset( $r['start'] ) ? $r['start'] : '' );
				$start = $start ? $start : NHM_Settings::today();
				$last  = self::date( isset( $r['last_day'] ) ? $r['last_day'] : '' );
				NHM_Members::set_plan( $user->ID, $level['id'], $start, $last ? $last : NHM_Levels::term_end( $level['id'], $start ), isset( $r['note'] ) && '' !== $r['note'] ? $r['note'] : null, 'Import' );
				$msg .= ' → ' . $level['name'];
			}
			if ( $company ) {
				$res  = NHM_Companies::add_member( $company['id'], $user->ID, isset( $r['role'] ) && 'owner' === strtolower( $r['role'] ) ? 'owner' : 'member' );
				$msg .= is_wp_error( $res ) ? ' — ' . $res->get_error_message() : ' @ ' . $company['name'];
			}
			if ( $created && $emails ) {
				NHM_Emails::account_created( $user->ID );
				$msg .= ' — ' . __( 'email sent', 'neurohr-members' );
			}
			$lines[] = array( 'ok', $line, $msg );
		}
		/* translators: 1: new, 2: updated, 3: errors */
		return array( 'lines' => $lines, 'summary' => sprintf( __( '%1$d new, %2$d updated, %3$d skipped.', 'neurohr-members' ), $new, $upd, $err ) );
	}
}
