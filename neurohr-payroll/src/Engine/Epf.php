<?php
/**
 * EPF (KWSP) contribution using the Third Schedule band method.
 *
 *  - Wages up to RM10: no contribution.
 *  - RM10.01 to RM5,000: RM20 bands; RM5,000.01 to RM20,000: RM100 bands.
 *    Each share = rate x upper limit of the band, rounded up to the next ringgit.
 *  - Above RM20,000: rate x actual wages, rounded up to the next ringgit.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Epf {

	/** Selectable rates (basis points) — same list as payroll.my. */
	public static function employeeRateOptions() {
		$r = array( 200, 400, 550 );
		for ( $p = 7; $p <= 30; $p++ ) {
			$r[] = $p * 100;
		}
		return $r;
	}

	public static function employerRateOptions() {
		$r = array( 200, 400, 600, 650 );
		for ( $p = 12; $p <= 30; $p++ ) {
			$r[] = $p * 100;
		}
		return $r;
	}

	/**
	 * Default rates from citizenship and age.
	 *
	 * @param string $citizenship citizen|pr|foreign
	 * @param int    $age
	 * @param int    $ordinaryWages wages excluding bonus, in sen (decides 13% vs 12%)
	 * @param array  $rules        Rates::forPeriod()
	 * @return array [employee_bp, employer_bp, label]
	 */
	public static function autoRates( $citizenship, $age, $ordinaryWages, array $rules ) {
		$upTo5k = $ordinaryWages <= 500000;

		if ( $age >= $rules['epf_max_age'] ) {
			return array( 0, 0, 'Age 75 and above: no contribution' );
		}
		if ( 'foreign' === $citizenship ) {
			if ( ! $rules['epf_foreign_mandatory'] ) {
				return array( 0, 0, 'Foreign worker: not mandatory before Oct 2025' );
			}
			return array( 200, 200, 'Foreign worker: 2% + 2%' );
		}
		if ( $age >= 60 ) {
			if ( 'pr' === $citizenship ) {
				return array( 550, $upTo5k ? 650 : 600, 'Permanent resident aged 60+' );
			}
			return array( 0, 400, 'Malaysian aged 60+' );
		}
		return array( 1100, $upTo5k ? 1300 : 1200, 'Below 60' );
	}

	/**
	 * Contribution for one rate.
	 *
	 * @return array [amount_sen, basis_label]
	 */
	public static function share( $wages, $bp ) {
		if ( $bp <= 0 || $wages <= 1000 ) {
			return array( 0, $wages <= 1000 && $wages > 0 ? 'wages RM10 and below' : '' );
		}
		if ( $wages > 2000000 ) {
			$amount = Money::ceilDiv( $wages * $bp, 1000000 ) * 100;
			return array( $amount, 'exact % of wages above RM20,000' );
		}
		$step  = $wages <= 500000 ? 2000 : 10000;
		$upper = Money::ceilDiv( $wages, $step ) * $step;
		$lower = $upper - $step;
		$amount = Money::ceilDiv( $upper * $bp, 1000000 ) * 100;
		return array( $amount, sprintf( 'band RM%s – RM%s', Money::format( $lower + 1 ), Money::format( $upper ) ) );
	}

	/**
	 * @return array{employee:int,employer:int,band:string}
	 */
	public static function calculate( $wages, $employeeBp, $employerBp ) {
		list( $ee, $bandA ) = self::share( $wages, $employeeBp );
		list( $er, $bandB ) = self::share( $wages, $employerBp );
		return array(
			'employee' => $ee,
			'employer' => $er,
			'band'     => $bandA ? $bandA : $bandB,
		);
	}
}
