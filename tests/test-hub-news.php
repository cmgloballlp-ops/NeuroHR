<?php
// Knowledge Hub 0.16: News Categories suggestions (pure functions) on the real post titles.
define( 'ABSPATH', __DIR__ . '/' );
require $argv[1] . '/neurohr-hub/includes/class-nh-news-tidy.php';
$fails = 0; $n = 0;
function ok( $c, $l ) { global $fails, $n; $n++; if ( ! $c ) { $fails++; echo "FAIL: $l\n"; } }
$expect = array(
	'PERKESO Introduces LINDUNG 24 Jam Opt-Out Option: What Malaysian Employers and HR Need to Know' => 'payroll-statutory',
	'Flexible Work Arrangements (FWA) – From Economic Imperative to Psychosocial Risk Compliance' => 'workplace-safety-health',
	'Kedah Declares 23 March 2026 as Additional Public Holiday for Hari Raya Aidilfitri' => 'public-holidays',
	'Malaysia Announces Additional Hari Raya Aidilfitri Public Holiday' => 'public-holidays',
	"Bonus Is Not a Reward. It's a Defensive Cost." => 'hr-trends',
	"Employees' Social Security (Amendment) Bill 2025: What Malaysian Employers Must Prepare For Now" => 'payroll-statutory',
	'The Employment Insurance System (Amendment) Act 2025: What Employers in Malaysia Must Prepare For Now' => 'payroll-statutory',
	'MMC Bans MC Issuance via Teleconsultation: What HR Needs to Know Now' => 'employment-laws',
	'Budget 2026: What HR Leaders Must Prepare For Next Year' => 'budget-policy',
	'Mandatory EPF Contributions for Non-Malaysian Employees' => 'payroll-statutory',
	'Malaysia Introduces the Gig Worker Act 2025' => 'employment-laws',
	'Additional Public Holiday on 15 September 2025 to Celebrate Unity and Malaysia Day' => 'public-holidays',
	'Malaysia Takes a Firm Stand Against Workplace Bullying – Now a Criminal Offence' => 'employment-laws',
	'Malaysia Pushes Ahead on Addressing Workplace Discrimination' => 'employment-laws',
	'Effective 1 August 2025: Apprenticeship Contracts Now Recognized as Contracts of Service – Minimum Wage Applies' => 'employment-laws',
	'Mandatory EPF for Foreign Workers Now Starts October 2025' => 'payroll-statutory',
	'Malaysia Introduces Key Amendments to PDPA 2010: What Organizations Need to Know' => 'employment-laws',
	'Malaysia Employment Contract Stamping Guide 2025' => 'payroll-statutory',
	'Sabah & Sarawak Labour Ordinance Amendments Take Effect 1 May 2025' => 'employment-laws',
	"Malaysia's New Anti-Bullying Law: Reframing Workplace Behaviour as a Criminal Risk" => 'employment-laws',
	'Malaysia Introduces Mandatory EPF Contributions for Foreign Workers' => 'payroll-statutory',
	'New Minimum Wages Order 2024 Announced: Implementation from February 2025' => 'payroll-statutory',
	'Occupational Safety and Health (Amendment) Act 2022 will be enforced on 01 June 2024.' => 'workplace-safety-health',
);
foreach ( $expect as $title => $slug ) {
	$got = NH_News_Tidy::suggest_category( $title, array( 'hr-updates', 'employment-laws' ) );
	ok( $got === $slug, "category: $title → $got (want $slug)" );
}
ok( 'hr-trends' === NH_News_Tidy::suggest_category( 'What HR leaders think about 2027', array( 'hr-trends' ) ), 'current HR Trends post stays HR Trends' );
ok( 6 === count( NH_News_Tidy::targets() ), 'six categories' );
$t = NH_News_Tidy::suggest_tags( 'Mandatory EPF Contributions for Non-Malaysian Employees' );
ok( in_array( 'EPF', $t, true ) && in_array( 'Foreign Workers', $t, true ), 'tags: EPF + Foreign Workers' );
ok( in_array( 'Budget 2026', NH_News_Tidy::suggest_tags( 'Budget 2026: What HR Leaders Must Prepare' ), true ), 'tags: Budget 2026' );
ok( in_array( 'PERKESO', NH_News_Tidy::suggest_tags( 'PERKESO Introduces LINDUNG 24 Jam' ), true ), 'tags: PERKESO' );
ok( array() === NH_News_Tidy::suggest_tags( 'Hello world' ), 'no tags when nothing matches' );
$p = function ( $id, $date, $title ) { $o = new stdClass(); $o->ID = $id; $o->post_date = $date; $o->post_title = $title; return $o; };
$newer = NH_News_Tidy::suggest_newer( array(
	$p( 1, '2025-02-03 10:00:00', 'Malaysia Introduces Mandatory EPF Contributions for Foreign Workers' ),
	$p( 2, '2025-06-26 10:00:00', 'Mandatory EPF for Foreign Workers Now Starts October 2025' ),
	$p( 3, '2025-10-07 10:00:00', 'Mandatory EPF Contributions for Non-Malaysian Employees' ),
	$p( 4, '2025-04-11 10:00:00', "Malaysia's New Anti-Bullying Law: Reframing Workplace Behaviour as a Criminal Risk" ),
	$p( 5, '2025-07-11 10:00:00', 'Malaysia Takes a Firm Stand Against Workplace Bullying – Now a Criminal Offence' ),
	$p( 6, '2026-07-11 10:00:00', 'PERKESO Introduces LINDUNG 24 Jam' ),
) );
ok( 3 === $newer[1] && 3 === $newer[2] && ! isset( $newer[3] ), 'EPF foreign workers: older two point to the newest' );
ok( 5 === $newer[4] && ! isset( $newer[5] ) && ! isset( $newer[6] ), 'bullying: older points to newer; others untouched' );
echo "$n hub news tests, $fails failed\n";
exit( $fails ? 1 : 0 );
