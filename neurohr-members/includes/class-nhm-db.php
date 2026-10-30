<?php
/**
 * Custom tables: companies, invitations and the change history.
 * Per-user membership data is kept in user meta (see NHM_Members).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_DB {

	const DB_VERSION = 5;
	const OPTION     = 'nhm_db_version';

	public static function init() {
		// Also runs after an update by file upload (no activation hook then).
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nhm_' . $name;
	}

	public static function activate() {
		self::install();
		NHM_Levels::ensure();
		NHM_Cron::schedule();
	}

	public static function maybe_upgrade() {
		$from = (int) get_option( self::OPTION );
		if ( $from < self::DB_VERSION ) {
			self::install();
			if ( $from && $from < 2 ) {
				self::migrate_v2();
			}
		}
		NHM_Levels::ensure();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$companies = self::table( 'companies' );
		$invites   = self::table( 'invites' );
		$log       = self::table( 'log' );
		$invoices  = self::table( 'invoices' );
		$payments  = self::table( 'payments' );
		$refunds   = self::table( 'refunds' );

		dbDelta(
			"CREATE TABLE $companies (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				client_code varchar(40) NOT NULL DEFAULT '',
				reg_no varchar(60) NOT NULL DEFAULT '',
				tin varchar(40) NOT NULL DEFAULT '',
				sst_no varchar(40) NOT NULL DEFAULT '',
				address text NULL,
				postcode varchar(20) NOT NULL DEFAULT '',
				city varchar(80) NOT NULL DEFAULT '',
				state varchar(80) NOT NULL DEFAULT '',
				country varchar(80) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				billing_email varchar(190) NOT NULL DEFAULT '',
				contact_person varchar(120) NOT NULL DEFAULT '',
				industry varchar(120) NOT NULL DEFAULT '',
				employees int(11) unsigned NOT NULL DEFAULT 0,
				level_id int(11) unsigned NOT NULL DEFAULT 0,
				start_date date NULL DEFAULT NULL,
				expiry_date date NULL DEFAULT NULL,
				seats int(11) unsigned NOT NULL DEFAULT 1,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				notes text NULL,
				reminded varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY level_id (level_id),
				KEY expiry_date (expiry_date),
				KEY client_code (client_code)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $invites (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				role varchar(20) NOT NULL DEFAULT 'member',
				token_hash char(64) NOT NULL DEFAULT '',
				invited_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY company_id (company_id),
				KEY token_hash (token_hash),
				KEY email (email)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(40) NOT NULL DEFAULT '',
				level_from int(11) unsigned NOT NULL DEFAULT 0,
				level_to int(11) unsigned NOT NULL DEFAULT 0,
				expiry date NULL DEFAULT NULL,
				message varchar(255) NOT NULL DEFAULT '',
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY company_id (company_id),
				KEY created_at (created_at)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $invoices (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				number varchar(60) NOT NULL DEFAULT '',
				kind varchar(20) NOT NULL DEFAULT 'manual',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(10) NOT NULL DEFAULT 'unpaid',
				issue_date date NULL DEFAULT NULL,
				due_date date NULL DEFAULT NULL,
				paid_date date NULL DEFAULT NULL,
				paid_at datetime NULL DEFAULT NULL,
				refunded decimal(12,2) NOT NULL DEFAULT 0,
				payment_method varchar(60) NOT NULL DEFAULT '',
				payment_ref varchar(120) NOT NULL DEFAULT '',
				currency varchar(8) NOT NULL DEFAULT 'RM',
				subtotal decimal(12,2) NOT NULL DEFAULT 0,
				discount decimal(12,2) NOT NULL DEFAULT 0,
				tax_rate decimal(5,2) NOT NULL DEFAULT 0,
				tax decimal(12,2) NOT NULL DEFAULT 0,
				total decimal(12,2) NOT NULL DEFAULT 0,
				items longtext NULL,
				bill_to longtext NULL,
				level_id int(11) unsigned NOT NULL DEFAULT 0,
				terms tinyint(3) unsigned NOT NULL DEFAULT 1,
				tax_inclusive tinyint(1) NOT NULL DEFAULT 0,
				period_start date NULL DEFAULT NULL,
				period_end date NULL DEFAULT NULL,
				grant_to varchar(10) NOT NULL DEFAULT '',
				granted tinyint(1) NOT NULL DEFAULT 0,
				notes text NULL,
				token char(32) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY number (number),
				KEY user_id (user_id),
				KEY company_id (company_id),
				KEY status (status)
			) $charset;"
		);

		// One row per payment try: Stripe checkout, or a bank-transfer / DuitNow QR proof.
		dbDelta(
			"CREATE TABLE $payments (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				method varchar(20) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				amount decimal(12,2) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT 'MYR',
				mode varchar(4) NOT NULL DEFAULT '',
				stripe_session varchar(255) NOT NULL DEFAULT '',
				stripe_intent varchar(255) NOT NULL DEFAULT '',
				stripe_method varchar(30) NOT NULL DEFAULT '',
				payer_name varchar(190) NOT NULL DEFAULT '',
				paid_at datetime NULL DEFAULT NULL,
				reference varchar(120) NOT NULL DEFAULT '',
				note text NULL,
				slip varchar(190) NOT NULL DEFAULT '',
				admin_note text NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY invoice_id (invoice_id),
				KEY status (status),
				KEY stripe_session (stripe_session(64))
			) $charset;"
		);

		// Refund requests and refunds (with their Refund Note number once paid back).
		dbDelta(
			"CREATE TABLE $refunds (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				number varchar(60) NOT NULL DEFAULT '',
				invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'requested',
				source varchar(20) NOT NULL DEFAULT 'member',
				reason varchar(190) NOT NULL DEFAULT '',
				details text NULL,
				usage_info longtext NULL,
				paid_amount decimal(12,2) NOT NULL DEFAULT 0,
				fee decimal(12,2) NULL DEFAULT NULL,
				amount decimal(12,2) NOT NULL DEFAULT 0,
				method varchar(20) NOT NULL DEFAULT '',
				stripe_refund varchar(255) NOT NULL DEFAULT '',
				reference varchar(120) NOT NULL DEFAULT '',
				refund_date date NULL DEFAULT NULL,
				reject_reason text NULL,
				admin_note text NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL DEFAULT NULL,
				token char(32) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY invoice_id (invoice_id),
				KEY user_id (user_id),
				KEY status (status),
				KEY stripe_refund (stripe_refund(64))
			) $charset;"
		);

		update_option( self::OPTION, self::DB_VERSION );
	}

	/** 0.1 → 0.2: settings that moved. */
	public static function migrate_v2() {
		$s = get_option( NHM_Settings::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		if ( ! empty( $s['admin_email'] ) && empty( $s['admin_emails'] ) ) {
			$s['admin_emails'] = $s['admin_email'];
		}
		if ( isset( $s['notify_admin'] ) && ! isset( $s['email_admin_signup_on'] ) ) {
			$s['email_admin_signup_on'] = (int) $s['notify_admin'];
		}
		if ( isset( $s['admin_copy'] ) ) {
			$s['email_admin_reminder_on'] = (int) $s['admin_copy'];
			$s['email_admin_expired_on']  = (int) $s['admin_copy'];
		}
		NHM_Settings::update( $s );
		// Form fields: built from the 0.1 "required / optional / hidden" settings.
		NHM_Fields::all();
	}
}
