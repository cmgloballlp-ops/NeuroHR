<?php
/**
 * Statutory rate data.
 *
 * - PCB / MTD figures are kept per year of assessment, exactly as printed in
 *   LHDN's yearly "Specification for MTD Calculations Using Computerised
 *   Calculation". Built-in years are below; the admin screen can add or
 *   correct a year without touching code (see configure()).
 * - LINDUNG 24 Jam rates are kept as a list of "from pay month" changes.
 * - EPF and SOCSO/EIS rules rarely change and are dated below.
 *
 * Sources (checked Oct 2026): KWSP Third Schedule; PERKESO Act 4 / Act 800
 * tables (ceiling RM6,000 from Oct 2024); PERKESO LINDUNG 24 Jam FAQ;
 * LHDN MTD computerised calculation specification 2026.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Rates {

	/** Earliest supported pay period (EPF foreign-worker and SOCSO ceiling rules start before this). */
	const FIRST_PERIOD = '2025-01';

	/** @var array|null year => PCB rules supplied by the admin screen */
	private static $pcbOverrides = null;

	/** @var array|null list of ['from' => 'YYYY-MM', 'bp' => int] supplied by the admin screen */
	private static $l24Override = null;

	/**
	 * Inject admin-edited data. Pass null to keep the built-in values.
	 */
	public static function configure( $pcbYears = null, $l24Schedule = null ) {
		self::$pcbOverrides = is_array( $pcbYears ) && $pcbYears ? $pcbYears : null;
		self::$l24Override  = is_array( $l24Schedule ) && $l24Schedule ? $l24Schedule : null;
	}

	/**
	 * Rules for a pay period. Amounts in sen, rates in basis points (1% = 100).
	 */
	public static function forPeriod( $period ) {
		$period = self::normalisePeriod( $period );
		$year   = (int) substr( $period, 0, 4 );

		$pcbYear = self::pcbYearFor( $year );
		$l24     = self::l24RateFor( $period );

		return array(
			'period'                => $period,

			// EPF
			'epf_foreign_mandatory' => $period >= '2025-10',
			'epf_max_age'           => 75,

			// SOCSO / EIS
			'socso_ceiling'         => 600000,   // RM6,000 from Oct 2024
			'socso_l24_active'      => $l24 > 0,
			'socso_l24_rate_bp'     => $l24,
			'eis_min_age'           => 18,
			'eis_max_age'           => 60,

			// PCB / MTD
			'pcb'                   => self::pcbYears()[ $pcbYear ],
			'pcb_year'              => $pcbYear,
			'pcb_year_fallback'     => $pcbYear !== $year,
		);
	}

	public static function normalisePeriod( $period ) {
		$period = (string) $period;
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			$period = date( 'Y-m' );
		}
		if ( $period < self::FIRST_PERIOD ) {
			$period = self::FIRST_PERIOD;
		}
		if ( $period > '2099-12' ) {
			$period = '2099-12';
		}
		return $period;
	}

	/* ------------------------------------------------------------------ */
	/* PCB years                                                           */
	/* ------------------------------------------------------------------ */

	/** All PCB years: built-in, then admin overrides on top. */
	public static function pcbYears() {
		$years = self::builtInPcbYears();
		if ( self::$pcbOverrides ) {
			foreach ( self::$pcbOverrides as $y => $rules ) {
				$years[ (int) $y ] = self::cleanPcbRules( $rules, (int) $y );
			}
		}
		ksort( $years );
		return $years;
	}

	/** The year whose table applies: the same year, else the latest earlier one, else the earliest. */
	public static function pcbYearFor( $year ) {
		$years = array_keys( self::pcbYears() );
		$pick  = $years[0];
		foreach ( $years as $y ) {
			if ( $y <= $year ) {
				$pick = $y;
			}
		}
		return $pick;
	}

	public static function builtInPcbYears() {
		$y2026 = array(
			'source'             => 'LHDN Specification for MTD Calculations Using Computerised Calculation for 2026',
			// Table 1 rows: [M (sen), R (basis points, 1% = 100), B category 1 & 3 (sen), B category 2 (sen)]; the row applies when P > M.
			// B already includes the RM400 individual / spouse rebates where P <= RM35,000.
			'table'              => array(
				array( 500000, 100, -40000, -80000 ),
				array( 2000000, 300, -25000, -65000 ),
				array( 3500000, 600, 60000, 60000 ),
				array( 5000000, 1100, 150000, 150000 ),
				array( 7000000, 1900, 370000, 370000 ),
				array( 10000000, 2500, 940000, 940000 ),
				array( 40000000, 2600, 8440000, 8440000 ),
				array( 60000000, 2800, 13640000, 13640000 ),
				array( 200000000, 3000, 52840000, 52840000 ),
			),
			'relief_self'        => 900000,  // D
			'relief_spouse'      => 400000,  // S
			'relief_disabled'    => 700000,  // DU
			'relief_dis_spouse'  => 600000,  // SU
			'relief_child'       => 200000,  // Q per child
			'epf_relief_limit'   => 400000,  // yearly cap on K
			'socso_relief_limit' => 35000,   // SOCSO + EIS deducted from salary
			'l24_relief_limit'   => 0,       // LINDUNG 24 Jam (proposed RM150 from YA 2027)
			'min_mtd'            => 1000,    // MTD below RM10 is not deducted
			'non_resident_bp'    => 3000,    // 30%
			// Worked examples printed in the specification (amounts in RM).
			'tests'              => array(
				array( 'label' => 'Example: January', 'month' => 1, 'category' => 3, 'children' => 3, 'Y1' => '5500', 'K1' => '605', 'expect' => '110.00' ),
				array( 'label' => 'Example: February', 'month' => 2, 'category' => 3, 'children' => 3, 'Y1' => '5500', 'K1' => '605', 'Y' => '5500', 'K' => '605', 'X' => '110', 'expect' => '110.00' ),
				array( 'label' => 'Example: March', 'month' => 3, 'category' => 3, 'children' => 3, 'Y1' => '5500', 'K1' => '605', 'Y' => '11000', 'K' => '1210', 'X' => '220', 'LP1' => '300', 'expect' => '108.20' ),
				array( 'label' => 'Example: April with bonus', 'month' => 4, 'category' => 3, 'children' => 3, 'Y1' => '5500', 'K1' => '605', 'Y' => '16500', 'K' => '1815', 'X' => '328.20', 'LP' => '300', 'LP1' => '300', 'Yt' => '8250', 'Kt' => '908', 'expect' => '833.70' ),
			),
		);

		// YA 2025 used the same table and the same personal reliefs.
		$y2025           = $y2026;
		$y2025['source'] = 'LHDN Specification for MTD Calculations Using Computerised Calculation for 2025 (same figures as 2026)';
		$y2025['tests']  = array();

		return array(
			2025 => $y2025,
			2026 => $y2026,
		);
	}

	/** Make sure an admin-supplied year has every key with a sane type. */
	public static function cleanPcbRules( $rules, $year ) {
		$base  = self::builtInPcbYears();
		$ref   = isset( $base[ $year ] ) ? $base[ $year ] : end( $base );
		$rules = is_array( $rules ) ? $rules : array();
		$out   = array();
		foreach ( $ref as $k => $v ) {
			if ( 'table' === $k ) {
				$rows = array();
				if ( isset( $rules['table'] ) && is_array( $rules['table'] ) ) {
					foreach ( $rules['table'] as $row ) {
						if ( is_array( $row ) && 4 === count( $row ) ) {
							$rows[] = array_map( 'intval', array_values( $row ) );
						}
					}
				}
				usort(
					$rows,
					function ( $a, $b ) {
						return $a[0] - $b[0];
					}
				);
				$out['table'] = $rows ? $rows : $v;
			} elseif ( 'tests' === $k ) {
				$out['tests'] = isset( $rules['tests'] ) && is_array( $rules['tests'] ) ? array_values( $rules['tests'] ) : array();
			} elseif ( 'source' === $k ) {
				$out['source'] = isset( $rules['source'] ) ? (string) $rules['source'] : '';
			} else {
				$out[ $k ] = isset( $rules[ $k ] ) && is_numeric( $rules[ $k ] ) ? (int) $rules[ $k ] : $v;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* LINDUNG 24 Jam                                                      */
	/* ------------------------------------------------------------------ */

	public static function builtInL24Schedule() {
		// Phase 1. PERKESO will announce phase 2 (1.00%) and phase 3 (1.25%) dates.
		return array(
			array( 'from' => '2026-06', 'bp' => 75 ),
		);
	}

	public static function l24Schedule() {
		$s = self::$l24Override ? self::$l24Override : self::builtInL24Schedule();
		usort(
			$s,
			function ( $a, $b ) {
				return strcmp( $a['from'], $b['from'] );
			}
		);
		return $s;
	}

	public static function l24RateFor( $period ) {
		$bp = 0;
		foreach ( self::l24Schedule() as $row ) {
			if ( $period >= $row['from'] ) {
				$bp = (int) $row['bp'];
			}
		}
		return $bp;
	}
}
