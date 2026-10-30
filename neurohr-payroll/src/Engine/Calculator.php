<?php
/**
 * One-employee, one-month payroll calculation (payroll.my style).
 * Pure PHP: no WordPress functions, so the future HRMS can reuse it.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Calculator {

	const MAX_WAGES = 100000000; // RM1,000,000 per field — sanity limit

	/**
	 * @param array $input raw form values (strings are fine)
	 * @return array result with amounts in sen plus formatted strings
	 */
	public static function calculate( array $input ) {
		$in    = self::normalise( $input );
		$rules = Rates::forPeriod( $in['period'] );
		$notes = array();

		if ( $rules['pcb_year_fallback'] ) {
			$notes[] = sprintf( 'The %1$s PCB table has not been added yet, so the %2$s table is used.', substr( $rules['period'], 0, 4 ), $rules['pcb_year'] );
		}

		// ---- Earnings ----------------------------------------------------
		$basic = 'parttime' === $in['type']
			? Money::truncDiv( $in['rate'] * $in['units'], 100 )
			: $in['salary'];

		$statutoryWages = $basic + $in['allowance'];          // EPF, SOCSO, EIS
		$epfWages       = $statutoryWages + $in['bonus'];      // bonus is subject to EPF
		$socsoWages     = $statutoryWages;                     // annual bonus is not SOCSO/EIS wages
		$gross          = $statutoryWages + $in['allowance_tax_only'] + $in['bonus'];

		// ---- EPF ---------------------------------------------------------
		list( $autoEe, $autoEr, $autoLabel ) = Epf::autoRates( $in['citizenship'], $in['age'], $statutoryWages, $rules );
		$eeBp = 'auto' === $in['epf_ee'] ? $autoEe : (int) $in['epf_ee'];
		$erBp = 'auto' === $in['epf_er'] ? $autoEr : (int) $in['epf_er'];

		$epf       = Epf::calculate( $epfWages, $eeBp, $erBp );
		$epfNormal = Epf::calculate( $statutoryWages, $eeBp, $erBp );

		if ( $in['bonus'] > 0 && 'auto' === $in['epf_er'] && 1300 === $erBp && $epfWages > 500000 ) {
			$notes[] = 'Employer EPF stays at 13% because the bonus, not the salary, takes wages above RM5,000.';
		}
		if ( 'foreign' === $in['citizenship'] && 'auto' === $in['epf_ee'] && ! $rules['epf_foreign_mandatory'] ) {
			$notes[] = 'EPF for foreign workers is mandatory only from October 2025 wages.';
		}

		// ---- SOCSO / LINDUNG 24 / EIS --------------------------------------
		$socsoCat = 'auto' === $in['socso'] ? Socso::autoCategory( $in['citizenship'], $in['age'] ) : $in['socso'];
		$socso    = Socso::calculate( $socsoWages, $socsoCat, $rules );
		if ( in_array( $socsoCat, array( Socso::CAT_INJURY_INVALIDITY_L24, Socso::CAT_INJURY_L24 ), true ) && ! $rules['socso_l24_active'] ) {
			$notes[] = 'LINDUNG 24 Jam is not deducted for this pay month (it starts with June 2026 wages).';
		}

		$eisOn = 'auto' === $in['eis'] ? Socso::autoEis( $in['citizenship'], $in['age'], $rules ) : ( 'yes' === $in['eis'] );
		$eis   = $eisOn ? Socso::eis( $socsoWages, $rules ) : 0;

		// ---- PCB ---------------------------------------------------------
		$month = (int) substr( $rules['period'], 5, 2 );
		$pcb = Pcb::calculate(
			array(
				'Y1'              => $statutoryWages + $in['allowance_tax_only'],
				'K1'              => $epfNormal['employee'],
				'Yt'              => $in['bonus'],
				'Kt'              => max( 0, $epf['employee'] - $epfNormal['employee'] ),
				'category'        => $in['tax_category'],
				'children'        => $in['children'],
				'disabled'        => $in['disabled'],
				'disabled_spouse' => $in['disabled_spouse'],
				'LP1'             => $in['tp1'],
				'socso_month'     => $socso['employee'] + $eis,
				'l24_month'       => $socso['l24'],
				'zakat'           => $in['zakat'],
				'resident'        => $in['resident'],
				// Year-to-date mode uses the real month and the amounts already paid this year;
				// otherwise the same pay is assumed for every month of the year.
				'month'           => $in['ytd'] ? $month : 1,
				'Y'               => $in['ytd'] ? $in['ytd_Y'] : 0,
				'K'               => $in['ytd'] ? $in['ytd_K'] : 0,
				'X'               => $in['ytd'] ? $in['ytd_X'] : 0,
				'Z'               => $in['ytd'] ? $in['ytd_Z'] : 0,
				'LP'              => $in['ytd'] ? $in['ytd_LP'] : 0,
				'socso_ytd'       => $in['ytd'] ? $in['ytd_socso'] : 0,
				'l24_ytd'         => $in['ytd'] ? $in['ytd_l24'] : 0,
			),
			$rules
		);
		if ( $in['zakat'] > $pcb['zakat'] ) {
			$notes[] = 'Zakat above the PCB amount does not reduce PCB further.';
		}

		// ---- HRD Corp levy (employer) ---------------------------------------
		// Basic salary + fixed allowances; Malaysian full-time employees only.
		$hrd = 0;
		if ( $in['hrd'] > 0 ) {
			if ( 'citizen' !== $in['citizenship'] || 'parttime' === $in['type'] ) {
				$notes[] = 'HRD Corp levy applies only to Malaysian full-time employees, so none is charged.';
			} else {
				$hrd = intdiv( $statutoryWages * $in['hrd'] + 5000, 10000 ); // rounded to the sen
			}
		}

		// ---- Totals ------------------------------------------------------
		// Zakat is paid in full through payroll; PCB is reduced by it but never below zero.
		$employeeDeductions = $epf['employee'] + $socso['employee'] + $socso['l24'] + $eis + $pcb['total'] + $in['zakat'] + $in['other_deductions'];
		$net                = $gross - $employeeDeductions;
		$employerTotal      = $epf['employer'] + $socso['employer'] + $eis + $hrd;

		$socsoLabel = Socso::categories();

		$result = array(
			'period'    => $rules['period'],
			'earnings'  => array(
				'basic'              => $basic,
				'allowance'          => $in['allowance'],
				'allowance_tax_only' => $in['allowance_tax_only'],
				'bonus'              => $in['bonus'],
				'gross'              => $gross,
			),
			'employee'  => array(
				'epf'        => $epf['employee'],
				'socso'      => $socso['employee'],
				'l24'        => $socso['l24'],
				'eis'        => $eis,
				'pcb'        => $pcb['total'],
				'pcb_normal' => $pcb['normal'] - $pcb['zakat'],
				'pcb_bonus'  => $pcb['bonus'],
				'pcb_before_zakat' => $pcb['normal'] + $pcb['bonus'],
				'zakat'      => $in['zakat'],
				'other'      => $in['other_deductions'],
				'total'      => $employeeDeductions,
			),
			'employer'  => array(
				'epf'   => $epf['employer'],
				'socso' => $socso['employer'],
				'eis'   => $eis,
				'hrd'   => $hrd,
				'total' => $employerTotal,
			),
			'net'       => $net,
			'cost'      => $gross + $employerTotal,
			'settings'  => array(
				'epf_employee_rate' => self::pct( $eeBp ),
				'epf_employer_rate' => self::pct( $erBp ),
				'epf_auto_label'    => $autoLabel,
				'epf_band'          => $epf['band'],
				'socso_category'    => $socsoCat,
				'socso_label'       => isset( $socsoLabel[ $socsoCat ] ) ? $socsoLabel[ $socsoCat ] : '',
				'socso_band'        => $socso['band'],
				'eis'               => $eisOn,
				'pcb_steps'         => $pcb['steps'],
				'resident'          => $in['resident'],
			),
			'notes'     => $notes,
			'carry'     => self::carry( $in, $rules, $month, $statutoryWages + $in['allowance_tax_only'] + $in['bonus'], $epf['employee'], $pcb['total'], $socso['employee'] + $eis, $socso['l24'] ),
		);

		$result['formatted'] = self::formatTree( $result );
		return $result;
	}

	/**
	 * Year-to-date figures for the next pay month ("Next month" button).
	 * After December a new year starts from zero.
	 */
	private static function carry( array $in, array $rules, $month, $taxable, $epfEe, $pcbPaid, $socsoEis, $l24 ) {
		$year = (int) substr( $rules['period'], 0, 4 );
		if ( 12 === $month ) {
			$next = array( 'Y' => 0, 'K' => 0, 'X' => 0, 'Z' => 0, 'LP' => 0, 'socso' => 0, 'l24' => 0 );
			$p    = sprintf( '%04d-01', $year + 1 );
		} else {
			$base  = $in['ytd'] ? $in : array( 'ytd_Y' => 0, 'ytd_K' => 0, 'ytd_X' => 0, 'ytd_Z' => 0, 'ytd_LP' => 0, 'ytd_socso' => 0, 'ytd_l24' => 0 );
			$l24Limit = $rules['pcb']['l24_relief_limit'];
			$limit = $rules['pcb']['socso_relief_limit'];
			$next  = array(
				'Y'     => $base['ytd_Y'] + $taxable,
				'K'     => $base['ytd_K'] + $epfEe,
				'X'     => $base['ytd_X'] + $pcbPaid,
				'Z'     => $base['ytd_Z'] + $in['zakat'],
				'LP'    => $base['ytd_LP'] + $in['tp1'],
				'socso' => $base['ytd_socso'] + min( $socsoEis, max( 0, $limit - $base['ytd_socso'] ) ),
				'l24'   => $base['ytd_l24'] + min( $l24, max( 0, $l24Limit - $base['ytd_l24'] ) ),
			);
			$p = sprintf( '%04d-%02d', $year, $month + 1 );
		}
		$out = array( 'period' => $p );
		foreach ( $next as $k => $v ) {
			$out[ $k ] = Money::format( $v );
		}
		return $out;
	}

	/** Clean and limit user input. */
	public static function normalise( array $x ) {
		$get = function ( $k, $d = '' ) use ( $x ) {
			return isset( $x[ $k ] ) && is_scalar( $x[ $k ] ) ? trim( (string) $x[ $k ] ) : $d;
		};
		$money = function ( $k ) use ( $get ) {
			return min( self::MAX_WAGES, Money::toSen( $get( $k, '0' ) ) );
		};
		$rate = function ( $k, array $allowed ) use ( $get ) {
			$v = $get( $k, 'auto' );
			if ( 'auto' === $v ) {
				return 'auto';
			}
			if ( 'none' === $v || '0' === $v ) {
				return 0;
			}
			$bp = (int) round( (float) $v * 100 );
			return in_array( $bp, $allowed, true ) ? $bp : 'auto';
		};
		$oneOf = function ( $k, array $allowed, $d ) use ( $get ) {
			$v = $get( $k, $d );
			return in_array( $v, $allowed, true ) ? $v : $d;
		};

		$cat = (int) $get( 'tax_category', '1' );

		return array(
			'period'             => Rates::normalisePeriod( $get( 'period', '' ) ),
			'type'               => $oneOf( 'type', array( 'monthly', 'parttime' ), 'monthly' ),
			'salary'             => $money( 'salary' ),
			'rate'               => $money( 'rate' ),
			'units'              => min( 74400, Money::toSen( $get( 'units', '0' ) ) ), // hours/days, 2 decimals
			'allowance'          => $money( 'allowance' ),
			'allowance_tax_only' => $money( 'allowance_tax_only' ),
			'bonus'              => $money( 'bonus' ),
			'other_deductions'   => $money( 'other_deductions' ),
			'citizenship'        => $oneOf( 'citizenship', array( 'citizen', 'pr', 'foreign' ), 'citizen' ),
			'age'                => max( 0, min( 120, (int) $get( 'age', '30' ) ) ),
			'epf_ee'             => $rate( 'epf_ee', Epf::employeeRateOptions() ),
			'epf_er'             => $rate( 'epf_er', Epf::employerRateOptions() ),
			'socso'              => $oneOf( 'socso', array_merge( array( 'auto' ), array_keys( Socso::categories() ) ), 'auto' ),
			'eis'                => $oneOf( 'eis', array( 'auto', 'yes', 'no' ), 'auto' ),
			'resident'           => '0' !== $get( 'resident', '1' ),
			'tax_category'       => in_array( $cat, array( 1, 2, 3 ), true ) ? $cat : 1,
			'children'           => max( 0, min( 30, (int) $get( 'children', '0' ) ) ),
			'disabled'           => '1' === $get( 'disabled', '0' ),
			'disabled_spouse'    => '1' === $get( 'disabled_spouse', '0' ),
			'zakat'              => $money( 'zakat' ),
			'tp1'                => $money( 'tp1' ),
			'hrd'                => in_array( $get( 'hrd', '0' ), array( '0.5', '1' ), true ) ? (int) round( (float) $get( 'hrd', '0' ) * 100 ) : 0,
			'ytd'                => '1' === $get( 'ytd', '0' ),
			'ytd_Y'              => $money( 'ytd_Y' ),
			'ytd_K'              => $money( 'ytd_K' ),
			'ytd_X'              => $money( 'ytd_X' ),
			'ytd_Z'              => $money( 'ytd_Z' ),
			'ytd_LP'             => $money( 'ytd_LP' ),
			'ytd_socso'          => $money( 'ytd_socso' ),
			'ytd_l24'            => $money( 'ytd_l24' ),
		);
	}

	private static function pct( $bp ) {
		$s = rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' );
		return $s . '%';
	}

	private static function formatTree( array $r ) {
		$out = array();
		foreach ( array( 'earnings', 'employee', 'employer' ) as $group ) {
			foreach ( $r[ $group ] as $k => $v ) {
				$out[ $group ][ $k ] = Money::format( $v );
			}
		}
		$out['net']  = Money::format( $r['net'] );
		$out['cost'] = Money::format( $r['cost'] );
		return $out;
	}
}
