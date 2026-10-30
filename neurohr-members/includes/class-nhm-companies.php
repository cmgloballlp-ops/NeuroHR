<?php
/**
 * Company accounts: one plan (level + expiry) shared by a limited number of staff accounts.
 * Seats used = people in the company (owner included) + invitations not yet accepted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Companies {

	private static $cache = array();

	public static function flush() {
		self::$cache = array();
		NHM_Members::flush();
	}

	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$cache ) ) {
			$table              = NHM_DB::table( 'companies' );
			$row                = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			self::$cache[ $id ] = $row ? self::normalise( $row ) : null;
		}
		return self::$cache[ $id ];
	}

	private static function normalise( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['level_id']    = (int) $row['level_id'];
		$row['seats']       = (int) $row['seats'];
		$row['owner_id']    = (int) $row['owner_id'];
		$row['employees']   = isset( $row['employees'] ) ? (int) $row['employees'] : 0;
		$row['address']     = isset( $row['address'] ) ? (string) $row['address'] : '';
		foreach ( self::text_fields() as $key => $len ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
		$row['start_date']  = $row['start_date'] && '0000-00-00' !== $row['start_date'] ? $row['start_date'] : '';
		$row['expiry_date'] = $row['expiry_date'] && '0000-00-00' !== $row['expiry_date'] ? $row['expiry_date'] : '';
		return $row;
	}

	/**
	 * @param array $args search, level, limit, offset.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'search' => '', 'level' => 0, 'limit' => 500, 'offset' => 0 ), $args );
		$table = NHM_DB::table( 'companies' );
		$where = array( '1=1' );
		$vals  = array();
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR client_code LIKE %s OR reg_no LIKE %s OR billing_email LIKE %s)';
			array_push( $vals, $like, $like, $like, $like );
		}
		if ( $args['level'] ) {
			$where[] = 'level_id = %d';
			$vals[]  = (int) $args['level'];
		}
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT %d OFFSET %d', $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$row                     = self::normalise( $row );
			self::$cache[ $row['id'] ] = $row;
			$out[]                   = $row;
		}
		return $out;
	}

	public static function edit_url( $id ) {
		return admin_url( 'admin.php?page=nhm-companies&action=edit&id=' . (int) $id );
	}

	public static function is_active( $company ) {
		return $company && NHM_Members::date_active( $company['expiry_date'] );
	}

	/** Billing / profile columns => max length. */
	public static function text_fields() {
		return array(
			'client_code'    => 40,
			'reg_no'         => 60,
			'tin'            => 40,
			'sst_no'         => 40,
			'postcode'       => 20,
			'city'           => 80,
			'state'          => 80,
			'country'        => 80,
			'phone'          => 40,
			'billing_email'  => 190,
			'contact_person' => 120,
			'industry'       => 120,
		);
	}

	public static function by_code( $code ) {
		global $wpdb;
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return null;
		}
		$table = NHM_DB::table( 'companies' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE client_code = %s", $code ) ); // phpcs:ignore WordPress.DB
		return $id ? self::get( $id ) : null;
	}

	/** Address as lines for invoices and the admin list. */
	public static function address_lines( $c ) {
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $c['address'] ) ) );
		$city  = trim( $c['postcode'] . ' ' . $c['city'] );
		if ( $city ) {
			$lines[] = $city;
		}
		$region = trim( implode( ', ', array_filter( array( $c['state'], $c['country'] ) ) ) );
		if ( $region ) {
			$lines[] = $region;
		}
		return array_values( $lines );
	}

	/** Snapshot for an invoice "Bill to" block. */
	public static function bill_to( $c ) {
		$owner = $c['owner_id'] ? get_userdata( $c['owner_id'] ) : null;
		return array(
			'name'      => $c['name'],
			'attention' => $c['contact_person'] ? $c['contact_person'] : ( $owner ? $owner->display_name : '' ),
			'reg_no'    => $c['reg_no'],
			'tin'       => $c['tin'],
			'sst_no'    => $c['sst_no'],
			'address'   => self::address_lines( $c ),
			'email'     => $c['billing_email'] ? $c['billing_email'] : ( $owner ? $owner->user_email : '' ),
			'phone'     => $c['phone'],
			'code'      => $c['client_code'],
		);
	}

	/** Where invoices for the company are emailed. */
	public static function billing_email( $c ) {
		$to = self::bill_to( $c );
		return $to['email'];
	}

	/** @return int|WP_Error New company ID. */
	public static function create( array $data ) {
		global $wpdb;
		$row = self::clean( $data );
		if ( '' === $row['name'] ) {
			return new WP_Error( 'nhm_company', __( 'Enter the company name.', 'neurohr-members' ) );
		}
		if ( ! isset( $row['level_id'] ) ) {
			$row['level_id'] = NHM_Levels::base_id();
		}
		if ( ! empty( $row['client_code'] ) && self::by_code( $row['client_code'] ) ) {
			return new WP_Error( 'nhm_company', __( 'Another company already has this client code.', 'neurohr-members' ) );
		}
		$row['created_at'] = current_time( 'mysql' );
		$row['updated_at'] = $row['created_at'];
		$wpdb->insert( NHM_DB::table( 'companies' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'nhm_company', __( 'The company could not be saved.', 'neurohr-members' ) );
		}
		self::flush();
		NHM_Log::add( array( 'company_id' => $id, 'event' => 'company_new', 'level_to' => isset( $row['level_id'] ) ? $row['level_id'] : 0, 'expiry' => isset( $row['expiry_date'] ) ? $row['expiry_date'] : null, 'message' => $row['name'] ) );
		return $id;
	}

	private static function clean( array $data ) {
		$row = array();
		if ( array_key_exists( 'name', $data ) ) {
			$row['name'] = mb_substr( sanitize_text_field( $data['name'] ), 0, 190 );
		}
		if ( array_key_exists( 'level_id', $data ) ) {
			$row['level_id'] = NHM_Levels::get( $data['level_id'] ) ? (int) $data['level_id'] : NHM_Levels::base_id();
		}
		if ( array_key_exists( 'start_date', $data ) ) {
			$row['start_date'] = NHM_Members::clean_date( $data['start_date'] ) ? NHM_Members::clean_date( $data['start_date'] ) : null;
		}
		if ( array_key_exists( 'expiry_date', $data ) ) {
			$row['expiry_date'] = NHM_Members::clean_date( $data['expiry_date'] ) ? NHM_Members::clean_date( $data['expiry_date'] ) : null;
		}
		if ( array_key_exists( 'seats', $data ) ) {
			$row['seats'] = max( 1, min( 100000, absint( $data['seats'] ) ) );
		}
		if ( array_key_exists( 'owner_id', $data ) ) {
			$row['owner_id'] = absint( $data['owner_id'] );
		}
		foreach ( self::text_fields() as $key => $len ) {
			if ( array_key_exists( $key, $data ) ) {
				$value       = 'billing_email' === $key ? sanitize_email( $data[ $key ] ) : sanitize_text_field( $data[ $key ] );
				$row[ $key ] = mb_substr( $value, 0, $len );
			}
		}
		if ( array_key_exists( 'address', $data ) ) {
			$row['address'] = sanitize_textarea_field( $data['address'] );
		}
		if ( array_key_exists( 'employees', $data ) ) {
			$row['employees'] = absint( $data['employees'] );
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$row['notes'] = sanitize_textarea_field( $data['notes'] );
		}
		if ( array_key_exists( 'reminded', $data ) ) {
			$row['reminded'] = mb_substr( (string) $data['reminded'], 0, 190 );
		}
		return $row;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$before = self::get( $id );
		if ( ! $before ) {
			return false;
		}
		$row = self::clean( $data );
		if ( isset( $row['name'] ) && '' === $row['name'] ) {
			unset( $row['name'] );
		}
		if ( isset( $row['client_code'] ) ) {
			$other = self::by_code( $row['client_code'] );
			if ( '' !== $row['client_code'] && $other && (int) $other['id'] !== (int) $id ) {
				unset( $row['client_code'] ); // Taken by another company: keep the old code.
			}
		}
		// A new date or plan starts the reminders again.
		$new_expiry = array_key_exists( 'expiry_date', $row ) ? (string) $row['expiry_date'] : $before['expiry_date'];
		$new_level  = array_key_exists( 'level_id', $row ) ? (int) $row['level_id'] : $before['level_id'];
		if ( ( $new_expiry !== $before['expiry_date'] || $new_level !== $before['level_id'] ) && ! array_key_exists( 'reminded', $row ) ) {
			$row['reminded'] = '';
		}
		$row['updated_at'] = current_time( 'mysql' );
		$wpdb->update( NHM_DB::table( 'companies' ), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();

		$plan_changed = $new_level !== $before['level_id'] || $new_expiry !== $before['expiry_date']
			|| ( array_key_exists( 'start_date', $row ) && (string) $row['start_date'] !== $before['start_date'] );
		if ( $plan_changed ) {
			NHM_Log::add( array( 'company_id' => (int) $id, 'event' => 'company_set', 'level_from' => $before['level_id'], 'level_to' => $new_level, 'expiry' => $new_expiry ) );
			do_action( 'nhm_company_plan_changed', (int) $id, $before['level_id'], $new_level, $new_expiry );
		}
		return true;
	}

	/** Delete a company: its people keep their accounts and their own plans. */
	public static function delete( $id ) {
		global $wpdb;
		$company = self::get( $id );
		if ( ! $company ) {
			return;
		}
		foreach ( self::member_ids( $id ) as $user_id ) {
			delete_user_meta( $user_id, NHM_Members::META_COMPANY );
			delete_user_meta( $user_id, NHM_Members::META_ROLE );
		}
		$wpdb->delete( NHM_DB::table( 'invites' ), array( 'company_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( NHM_DB::table( 'companies' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		NHM_Log::add( array( 'company_id' => (int) $id, 'event' => 'company_delete', 'message' => $company['name'] ) );
		self::flush();
	}

	/** Used when a level is deleted. */
	public static function move_level( $from, $to ) {
		foreach ( self::query( array( 'level' => (int) $from, 'limit' => 100000 ) ) as $company ) {
			self::update( $company['id'], array( 'level_id' => (int) $to ) );
		}
	}

	/* ---------- People ---------- */

	public static function member_ids( $company_id ) {
		return array_map(
			'intval',
			get_users(
				array(
					'meta_key'   => NHM_Members::META_COMPANY, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => (int) $company_id,         // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'     => 'ID',
					'number'     => -1,
				)
			)
		);
	}

	/** @return WP_User[] Owner first, then by name. */
	public static function members( $company_id ) {
		$ids = self::member_ids( $company_id );
		if ( ! $ids ) {
			return array();
		}
		$users   = get_users( array( 'include' => $ids, 'orderby' => 'display_name', 'number' => -1 ) );
		$company = self::get( $company_id );
		usort(
			$users,
			function ( $a, $b ) use ( $company ) {
				$ao = $company && (int) $company['owner_id'] === (int) $a->ID ? 0 : 1;
				$bo = $company && (int) $company['owner_id'] === (int) $b->ID ? 0 : 1;
				return $ao === $bo ? strcasecmp( $a->display_name, $b->display_name ) : $ao - $bo;
			}
		);
		return $users;
	}

	public static function seats_used( $company_id ) {
		return count( self::member_ids( $company_id ) ) + count( self::invites( $company_id ) );
	}

	public static function seats_free( $company_id ) {
		$company = self::get( $company_id );
		return $company ? max( 0, $company['seats'] - self::seats_used( $company_id ) ) : 0;
	}

	/**
	 * Put a user into a company.
	 *
	 * @param bool $use_invite_seat The seat was already reserved by an invitation.
	 * @return true|WP_Error
	 */
	public static function add_member( $company_id, $user_id, $role = 'member', $use_invite_seat = false ) {
		$company = self::get( $company_id );
		$user    = get_userdata( $user_id );
		if ( ! $company || ! $user ) {
			return new WP_Error( 'nhm_company', __( 'Company or user not found.', 'neurohr-members' ) );
		}
		$current = (int) get_user_meta( $user_id, NHM_Members::META_COMPANY, true );
		if ( $current === (int) $company_id ) {
			if ( 'owner' === $role ) {
				self::set_owner( $company_id, $user_id );
			}
			return true;
		}
		if ( $current && self::get( $current ) ) {
			$other = self::get( $current );
			/* translators: %s: company name */
			return new WP_Error( 'nhm_company', sprintf( __( 'This account already belongs to %s. It must leave that company first.', 'neurohr-members' ), $other['name'] ) );
		}
		if ( ! $use_invite_seat && self::seats_free( $company_id ) < 1 ) {
			return new WP_Error( 'nhm_seats', __( 'All seats of this company are in use. Remove someone or add seats first.', 'neurohr-members' ) );
		}
		update_user_meta( $user_id, NHM_Members::META_COMPANY, (int) $company_id );
		update_user_meta( $user_id, NHM_Members::META_ROLE, 'member' );
		self::flush();
		NHM_Log::add( array( 'user_id' => $user_id, 'company_id' => (int) $company_id, 'event' => 'joined', 'level_to' => $company['level_id'], 'expiry' => $company['expiry_date'] ) );
		if ( 'owner' === $role || ! $company['owner_id'] ) {
			self::set_owner( $company_id, $user_id );
		}
		do_action( 'nhm_company_joined', (int) $company_id, (int) $user_id );
		return true;
	}

	public static function remove_member( $company_id, $user_id, $event = 'removed' ) {
		$company = self::get( $company_id );
		if ( (int) get_user_meta( $user_id, NHM_Members::META_COMPANY, true ) !== (int) $company_id ) {
			return;
		}
		delete_user_meta( $user_id, NHM_Members::META_COMPANY );
		delete_user_meta( $user_id, NHM_Members::META_ROLE );
		if ( $company && (int) $company['owner_id'] === (int) $user_id ) {
			self::update( $company_id, array( 'owner_id' => 0 ) );
		}
		self::flush();
		NHM_Log::add( array( 'user_id' => (int) $user_id, 'company_id' => (int) $company_id, 'event' => $event, 'level_from' => $company ? $company['level_id'] : 0 ) );
		do_action( 'nhm_company_left', (int) $company_id, (int) $user_id );
	}

	/** The owner manages staff accounts from My Account. Must already be in the company. */
	public static function set_owner( $company_id, $user_id ) {
		$company = self::get( $company_id );
		if ( ! $company ) {
			return;
		}
		$old = (int) $company['owner_id'];
		if ( $old && $old !== (int) $user_id ) {
			update_user_meta( $old, NHM_Members::META_ROLE, 'member' );
		}
		if ( $user_id ) {
			update_user_meta( $user_id, NHM_Members::META_ROLE, 'owner' );
		}
		if ( $old !== (int) $user_id ) {
			self::update( $company_id, array( 'owner_id' => (int) $user_id ) );
			NHM_Log::add( array( 'user_id' => (int) $user_id, 'company_id' => (int) $company_id, 'event' => 'owner' ) );
		}
		self::flush();
	}

	/* ---------- Invitations ---------- */

	public static function invites( $company_id ) {
		global $wpdb;
		$table = NHM_DB::table( 'invites' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE company_id = %d AND expires_at >= %s ORDER BY id ASC", (int) $company_id, current_time( 'mysql' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function invite_url( $token ) {
		$page = NHM_Settings::page_url( 'invite' );
		if ( ! $page ) {
			$page = NHM_Settings::page_url( 'register' );
		}
		if ( ! $page ) {
			$page = NHM_Settings::page_url( 'account' );
		}
		if ( ! $page ) {
			$page = home_url( '/' );
		}
		return add_query_arg( 'nhm_invite', rawurlencode( $token ), $page );
	}

	/**
	 * Invite an email address. Sends the invitation email.
	 *
	 * @return true|WP_Error
	 */
	public static function invite( $company_id, $email, $role = 'member', $inviter_id = 0 ) {
		global $wpdb;
		$company = self::get( $company_id );
		$email   = strtolower( sanitize_email( $email ) );
		if ( ! $company ) {
			return new WP_Error( 'nhm_company', __( 'Company not found.', 'neurohr-members' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'nhm_email', __( 'Enter a valid email address.', 'neurohr-members' ) );
		}
		$user = get_user_by( 'email', $email );
		if ( $user && (int) get_user_meta( $user->ID, NHM_Members::META_COMPANY, true ) === (int) $company_id ) {
			return new WP_Error( 'nhm_email', __( 'This person is already in the company.', 'neurohr-members' ) );
		}
		foreach ( self::invites( $company_id ) as $invite ) {
			if ( strtolower( $invite['email'] ) === $email ) {
				return self::resend( $invite['id'], $inviter_id );
			}
		}
		if ( self::seats_free( $company_id ) < 1 ) {
			return new WP_Error( 'nhm_seats', __( 'All seats are in use. Remove someone or cancel an invitation first.', 'neurohr-members' ) );
		}
		$token = wp_generate_password( 32, false, false );
		$days  = max( 1, (int) NHM_Settings::get( 'invite_days' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			NHM_DB::table( 'invites' ),
			array(
				'company_id' => (int) $company_id,
				'email'      => $email,
				'role'       => 'owner' === $role ? 'owner' : 'member',
				'token_hash' => hash( 'sha256', $token ),
				'invited_by' => (int) $inviter_id,
				'created_at' => current_time( 'mysql' ),
				'expires_at' => wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
			)
		);
		NHM_Log::add( array( 'company_id' => (int) $company_id, 'event' => 'invited', 'message' => $email ) );
		NHM_Emails::invite( $company, $email, $token, $inviter_id );
		return true;
	}

	/** New token, new expiry, new email. */
	public static function resend( $invite_id, $inviter_id = 0 ) {
		global $wpdb;
		$invite = self::invite_row( $invite_id );
		if ( ! $invite ) {
			return new WP_Error( 'nhm_invite', __( 'Invitation not found.', 'neurohr-members' ) );
		}
		$token = wp_generate_password( 32, false, false );
		$days  = max( 1, (int) NHM_Settings::get( 'invite_days' ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			NHM_DB::table( 'invites' ),
			array(
				'token_hash' => hash( 'sha256', $token ),
				'created_at' => current_time( 'mysql' ),
				'expires_at' => wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
			),
			array( 'id' => (int) $invite_id )
		);
		NHM_Emails::invite( self::get( $invite['company_id'] ), $invite['email'], $token, $inviter_id ? $inviter_id : (int) $invite['invited_by'] );
		return true;
	}

	public static function invite_row( $invite_id ) {
		global $wpdb;
		$table = NHM_DB::table( 'invites' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $invite_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function cancel_invite( $invite_id ) {
		global $wpdb;
		$wpdb->delete( NHM_DB::table( 'invites' ), array( 'id' => (int) $invite_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Valid (not expired) invitation for a token, or null. */
	public static function find_invite( $token ) {
		global $wpdb;
		$token = (string) $token;
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$table = NHM_DB::table( 'invites' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token_hash = %s AND expires_at >= %s", hash( 'sha256', $token ), current_time( 'mysql' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row && self::get( $row['company_id'] ) ? $row : null;
	}

	/**
	 * The logged-in user accepts an invitation. Their email must be the invited address.
	 *
	 * @return true|WP_Error
	 */
	public static function accept( $token, $user_id ) {
		$invite = self::find_invite( $token );
		$user   = get_userdata( $user_id );
		if ( ! $invite || ! $user ) {
			return new WP_Error( 'nhm_invite', __( 'This invitation link is not valid any more. Ask your company to send a new one.', 'neurohr-members' ) );
		}
		if ( strtolower( $user->user_email ) !== strtolower( $invite['email'] ) ) {
			/* translators: %s: email address */
			return new WP_Error( 'nhm_invite', sprintf( __( 'This invitation was sent to %s. Log in with that email address to accept it.', 'neurohr-members' ), $invite['email'] ) );
		}
		$result = self::add_member( $invite['company_id'], $user_id, $invite['role'], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::cancel_invite( $invite['id'] );
		return true;
	}

	public static function cleanup_invites() {
		global $wpdb;
		$table = NHM_DB::table( 'invites' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires_at < %s", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
	}
}
