<?php
/**
 * SOCSO (Act 4), LINDUNG 24 Jam (SKBBK) and EIS (Act 800) from the PERKESO band tables.
 *
 * Bands up to RM300 are irregular and copied from the official table.
 * From RM300.01 the bands are RM100 wide and every amount is the rate x the
 * band's mid-point, rounded to 5 sen (tie rules verified row by row against the
 * published table). Wages above the RM6,000 ceiling use the last band.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Socso {

	const CAT_INJURY_INVALIDITY_L24 = 'ei_inv_l24';
	const CAT_INJURY_L24            = 'ei_l24';
	const CAT_INJURY_INVALIDITY     = 'ei_inv';
	const CAT_INJURY                = 'ei';
	const CAT_NONE                  = 'none';

	/**
	 * Low bands: upper limit (sen) => [cat1 employer, cat1 employee, cat2 employer, L24 employee, EIS each]
	 */
	private static $low = array(
		3000  => array( 40, 10, 30, 20, 5 ),
		5000  => array( 70, 20, 50, 30, 10 ),
		7000  => array( 110, 30, 80, 50, 15 ),
		10000 => array( 150, 40, 110, 65, 20 ),
		14000 => array( 210, 60, 150, 90, 25 ),
		20000 => array( 295, 85, 210, 125, 35 ),
		30000 => array( 435, 125, 310, 185, 50 ),
	);

	public static function categories() {
		return array(
			self::CAT_INJURY_INVALIDITY_L24 => 'Employment Injury & Invalidity & LINDUNG 24',
			self::CAT_INJURY_L24            => 'Employment Injury & LINDUNG 24',
			self::CAT_INJURY_INVALIDITY     => 'Employment Injury & Invalidity',
			self::CAT_INJURY                => 'Employment Injury only',
			self::CAT_NONE                  => 'No contribution',
		);
	}

	/** Default category from citizenship and age. */
	public static function autoCategory( $citizenship, $age ) {
		if ( 'foreign' === $citizenship || $age >= 60 ) {
			return self::CAT_INJURY_L24;
		}
		return self::CAT_INJURY_INVALIDITY_L24;
	}

	/** Default EIS from citizenship and age. */
	public static function autoEis( $citizenship, $age, array $rules ) {
		if ( 'foreign' === $citizenship ) {
			return false;
		}
		return $age >= $rules['eis_min_age'] && $age < $rules['eis_max_age'];
	}

	/**
	 * Row of the table for these wages.
	 *
	 * @return array [cat1_er, cat1_ee, cat2_er, l24_ee, eis_each, band_label]
	 */
	public static function row( $wages, $ceiling = 600000, $l24Bp = 75 ) {
		if ( $wages <= 0 ) {
			return array( 0, 0, 0, 0, 0, '' );
		}
		$lower = 0;
		foreach ( self::$low as $upper => $r ) {
			if ( $wages <= $upper ) {
				if ( 75 !== $l24Bp ) {
					// Only the 0.75% column is published so far; other rates use the band mid-point.
					$mid  = intdiv( $lower + $upper, 2 );
					$r[3] = Money::roundNickel( intdiv( $mid * $l24Bp, 1000 ), 'five' );
				}
				$r[] = sprintf( 'band RM%s – RM%s', Money::format( $lower ? $lower + 1 : 0 ), Money::format( $upper ) );
				return $r;
			}
			$lower = $upper;
		}

		$capped = min( $wages, $ceiling );
		$upper  = Money::ceilDiv( $capped, 10000 ) * 10000;
		$mid    = $upper - 5000; // sen

		$cat1Total = Money::roundNickel( intdiv( $mid * 9, 40 ), 'ten' ); // 2.25%
		$cat1Ee    = intdiv( $mid, 200 );                                // 0.50%
		$cat2Er    = Money::roundNickel( intdiv( $mid, 8 ), 'ten' );      // 1.25%
		$l24       = Money::roundNickel( intdiv( $mid * $l24Bp, 1000 ), 'five' ); // LINDUNG 24 (0.75% in phase 1)
		$eis       = intdiv( $mid, 500 );                                // 0.20%

		$label = $wages > $ceiling
			? sprintf( 'ceiling RM%s', Money::format( $ceiling ) )
			: sprintf( 'band RM%s – RM%s', Money::format( $upper - 9999 ), Money::format( $upper ) );

		return array( $cat1Total - $cat1Ee, $cat1Ee, $cat2Er, $l24, $eis, $label );
	}

	/**
	 * @return array{employer:int,employee:int,l24:int,band:string,l24_applied:bool}
	 */
	public static function calculate( $wages, $category, array $rules ) {
		$out = array( 'employer' => 0, 'employee' => 0, 'l24' => 0, 'band' => '', 'l24_applied' => false );
		if ( self::CAT_NONE === $category || $wages <= 0 ) {
			return $out;
		}
		list( $c1Er, $c1Ee, $c2Er, $l24, , $band ) = self::row( $wages, $rules['socso_ceiling'], max( 0, (int) $rules['socso_l24_rate_bp'] ) );
		$out['band'] = $band;

		if ( self::CAT_INJURY_INVALIDITY_L24 === $category || self::CAT_INJURY_INVALIDITY === $category ) {
			$out['employer'] = $c1Er;
			$out['employee'] = $c1Ee;
		} else {
			$out['employer'] = $c2Er;
		}

		$wantsL24 = ( self::CAT_INJURY_INVALIDITY_L24 === $category || self::CAT_INJURY_L24 === $category );
		if ( $wantsL24 && $rules['socso_l24_active'] ) {
			$out['l24']         = $l24;
			$out['l24_applied'] = true;
		}
		return $out;
	}

	/** EIS amount for each side (employer = employee). */
	public static function eis( $wages, array $rules ) {
		if ( $wages <= 0 ) {
			return 0;
		}
		$r = self::row( $wages, $rules['socso_ceiling'] );
		return $r[4];
	}
}
