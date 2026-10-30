<?php
require __DIR__ . '/wp-stub.php';
$W = $argv[1];
// A site that ran 0.1.
$GLOBALS['options']['nhm_db_version'] = 1;
$GLOBALS['options']['nhm_settings'] = array( 'field_phone' => 'hidden', 'field_company' => 'required', 'admin_email' => 'boss@n.my', 'admin_copy' => 0, 'notify_admin' => 0, 'email_welcome_subj' => 'My own welcome' );
$GLOBALS['options']['nhm_levels'] = array( 'next_id' => 6, 'items' => array( array( 'id' => 1, 'name' => 'Free', 'slug' => 'free', 'price' => 'RM 0', 'period' => 'per year' ), array( 'id' => 2, 'name' => 'Lite', 'slug' => 'lite', 'period' => 'per year' ) ) );
require $W . '/neurohr-members/neurohr-members.php';
$GLOBALS['wpdb']->insert( 'wp_nhm_companies', array( 'name' => 'Old Co', 'level_id' => 2 ) );
NHM_DB::migrate_v2();
$f = NHM_Fields::all(); $n = 0; $fails = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
ok( $f['company']['required'] && ! $f['phone']['register'] && $f['job']['register'], 'fields migrated from 0.1 modes' );
ok( 'boss@n.my' === NHM_Settings::get( 'admin_emails' ), 'admin email moved' );
ok( false === NHM_Emails::template( 'admin_signup' )['on'] && false === NHM_Emails::template( 'admin_reminder' )['on'], 'admin notice switches migrated' );
ok( 'My own welcome' === NHM_Emails::template( 'welcome' )['subj'], 'edited 0.1 email kept' );
ok( '' === (string) NHM_Companies::get( 1 )['client_code'], 'old company: no automatic client code (typed by hand since 0.3)' );
ok( NHM_Levels::get( 2 )['access'] === 'expire' && NHM_Levels::term_end( 2, '2026-10-23' ) === '2027-10-23' && NHM_Levels::period_text( 2 ) === 'per year', 'old levels get default term' );
echo "$n migration tests, $fails failed\n";
