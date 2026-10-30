<?php
require __DIR__ . '/stubs-front.php';
class Back extends Exception {}
function check_admin_referer() { return true; }
function wp_die( $m = '' ) { throw new Exception( 'die: ' . $m ); }
function disabled() {}
$W = $argv[1];
require $W . '/neurohr-members/neurohr-members.php';
foreach ( array( 'class-nhm-admin', 'class-nhm-admin-levels', 'class-nhm-admin-members', 'class-nhm-admin-companies', 'class-nhm-admin-settings', 'class-nhm-admin-invoices', 'class-nhm-admin-dashboard', 'class-nhm-admin-tools' ) as $f ) { require_once $W . '/neurohr-members/admin/' . $f . '.php'; }
NHM_Levels::ensure();
add_user( 1, 'admin@n.my', 'Admin', array( 'list_users', 'edit_others_posts', 'edit_posts' ) );
wp_set_current_user( 1 );
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
function save( $post ) { $_POST = $post; try { NHM_Admin_Settings::save(); } catch ( Redirect $r ) { return $r->getMessage(); } }

// Emails tab: default text not stored, edited text stored, off saved.
$reg  = NHM_Emails::registry();
$post = array( 'tab' => 'emails', 'nhm' => array( 'reminder_days' => '30, 7, 1', 'mail_from_name' => 'NeuroHR', 'mail_from_email' => 'admin@neurohr.com.my', 'admin_emails' => 'a@n.my' ) );
foreach ( $reg as $k => $e ) {
	$post['nhm'][ 'email_' . $k . '_on' ]   = 'invite' === $k ? '0' : '1';
	$post['nhm'][ 'email_' . $k . '_subj' ] = 'welcome' === $k ? 'Hi there' : $e['subj'];
	$post['nhm'][ 'email_' . $k . '_body' ] = str_replace( "\n", "\r\n", $e['body'] );
}
save( $post );
ok( null === NHM_Settings::stored( 'email_reminder_body' ) && 'Hi there' === NHM_Settings::stored( 'email_welcome_subj' ), 'only edited email texts stored' );
ok( false === NHM_Emails::template( 'invite' )['on'] && array( 30, 7, 1 ) === NHM_Settings::reminder_days(), 'email on/off + reminder days' );

// Registration tab: field rows + other settings kept.
save( array( 'tab' => 'registration', 'nhm' => array( 'registration_open' => '1', 'min_password' => '10' ), 'nhm_fields' => array( array( 'key' => 'company', 'label' => 'Organisation', 'register' => '1', 'account' => '1', 'required' => '1' ), array( 'key' => '', 'label' => 'HR team size', 'type' => 'number', 'register' => '1' ) ) ) );
ok( 'Organisation' === NHM_Fields::get( 'company' )['label'] && NHM_Fields::get( 'hr_team_size' ) && 10 === NHM_Settings::get( 'min_password' ), 'fields + registration settings saved' );
ok( 'Hi there' === NHM_Settings::stored( 'email_welcome_subj' ), 'other tab kept (merge)' );
ok( NHM_Fields::get( 'phone' ) && ! NHM_Fields::get( 'phone' )['register'], 'built-in left out = hidden, not deleted' );

// Business tab
save( array( 'tab' => 'business', 'nhm' => array( 'biz_name' => 'NeuroHR Sdn. Bhd.', 'invoice_format' => 'NHR', 'sst_rate' => '8', 'sst_enabled' => '0', 'biz_website' => 'www.neurohr.com.my' ) ) );
ok( 'NHR-{####}' === NHM_Settings::get( 'invoice_format' ) && 'www.neurohr.com.my' === NHM_Settings::get( 'biz_website' ), 'number formats get a counter' );

// Import companies
$csv = sys_get_temp_dir() . '/c.csv';
file_put_contents( $csv, "\xEF\xBB\xBFname,client_code,reg_no,address,city,plan,start,last_day,seats,owner_email\nAlpha Sdn Bhd,CL-0001,111,No 1 | Jalan 2,KL,corporate,01/01/2026,,10,boss@alpha.my\nBeta,,222,,,unknownplan,,,,\n" );
$rows = NHM_Admin_Tools::read_csv( $csv );
ok( 2 === count( $rows ) && 'Alpha Sdn Bhd' === $rows[0]['name'], 'csv read (BOM)' );
$dry = NHM_Admin_Tools::import_companies( $rows, true, false );
ok( false !== strpos( $dry['summary'], '1 new' ) && ! NHM_Companies::query(), 'dry run changes nothing' );
$rep = NHM_Admin_Tools::import_companies( $rows, false, false );
$al  = NHM_Companies::query( array( 'search' => 'Alpha' ) );
ok( 1 === count( $al ) && 4 === $al[0]['level_id'] && '2026-01-01' === $al[0]['start_date'] && '2027-01-01' === $al[0]['expiry_date'] && "No 1\nJalan 2" === $al[0]['address'] && 'CL-0001' === $al[0]['client_code'], 'company imported with term + code' );
// update by code
file_put_contents( $csv, "client_code,city\nCL-0001,Petaling Jaya\n" );
NHM_Admin_Tools::import_companies( NHM_Admin_Tools::read_csv( $csv ), false, false );
ok( 'Petaling Jaya' === NHM_Companies::by_code( 'CL-0001' )['city'] && 'Alpha Sdn Bhd' === NHM_Companies::by_code( 'CL-0001' )['name'], 'update by client code keeps other fields' );

// Import people
file_put_contents( $csv, "email,name,plan,start,last_day,client_code,role,organisation,hr_team_size\nboss@alpha.my,Boss,,,,CL-0001,owner,Alpha,5\nnew@x.my,New Person,pro,2026-10-01,2026-12-31,,,NewCo,\nbad,,,,,,,,\n" );
$GLOBALS['mails'] = array();
$rep = NHM_Admin_Tools::import_members( NHM_Admin_Tools::read_csv( $csv ), false, true, true );
$boss = get_user_by( 'email', 'boss@alpha.my' );
$newp = get_user_by( 'email', 'new@x.my' );
ok( $boss && 'owner' === NHM_Members::membership( $boss->ID )['role'] && 4 === NHM_Members::level( $boss->ID ), 'owner imported into company' );
ok( $newp && 3 === NHM_Members::personal( $newp->ID )['level'] && '2026-12-31' === NHM_Members::personal( $newp->ID )['expiry'], 'person imported with plan' );
ok( 'NewCo' === get_user_meta( $newp->ID, 'nhm_company_name' ), 'field matched by label' );
ok( false !== strpos( $rep['summary'], '1 skipped' ), 'bad email skipped' );
ok( (bool) preg_grep( '/account is ready/', array_column( $GLOBALS['mails'], 's' ) ), 'account created email' );
echo "$n settings/import tests, $fails failed\n";
