<?php
/**
 * PCB / MTD — LHDN Computerised Calculation Method (2026 specification).
 *
 *   P   = [Σ(Y − K) + (Y1 − K1) + (Y2 − K2) n + (Yt − Kt)] − [D + S + DU + SU + QC + (ΣLP + LP1)]
 *   MTD = [ (P − M) R + B − (Z + X) ] / (n + 1)
 *   Net MTD = MTD − zakat for the current month
 *
 * Additional remuneration (bonus) follows the five steps of the specification.
 * Results are kept to 2 decimals by truncation; MTD is rounded up to the next 5 sen;
 * MTD below RM10 is not deducted.
 *
 * The engine supports year-to-date history (for a future payroll module).
 * The simple calculator uses month = 1 with no history, i.e. "same pay all year".
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Pcb {

	const CAT_SINGLE         = 1; // single
	const CAT_SPOUSE_NOT_WRK = 2; // married, spouse not working
	const CAT_SPOUSE_WORKING = 3; // married & spouse working, divorced/widowed, single with adopted child

	/**
	 * @param array $in {
	 *   int  Y1          gross normal remuneration this month (sen)
	 *   int  K1          EPF on Y1 (sen)
	 *   int  Yt          gross additional remuneration this month (sen)
	 *   int  Kt          EPF on Yt (sen)
	 *   int  category    1|2|3
	 *   int  children    qualifying children (cat 2 and 3)
	 *   bool disabled, disabled_spouse
	 *   int  LP1         allowable deductions claimed this month via TP1 (sen)
	 *   int  socso_month SOCSO + EIS deducted this month; claimed automatically up to the yearly limit
	 *   int  socso_ytd   SOCSO + EIS already claimed this year (part of ΣLP)
	 *   int  l24_month, l24_ytd  same for LINDUNG 24 Jam (only where the year has a relief for it)
	 *   int  zakat       zakat deducted this month (sen)
	 *   bool resident
	 *   int  month       1..12
	 *   int  Y, K, LP, Z, X   year-to-date before this month (sen)
	 * }
	 */
	public static function calculate( array $in, array $rules ) {
		$in = array_merge(
			array(
				'Y1' => 0, 'K1' => 0, 'Yt' => 0, 'Kt' => 0,
				'category' => self::CAT_SINGLE, 'children' => 0,
				'disabled' => false, 'disabled_spouse' => false,
				'LP1' => 0, 'zakat' => 0, 'resident' => true, 'month' => 1,
				'socso_month' => 0, 'socso_ytd' => 0, 'l24_month' => 0, 'l24_ytd' => 0,
				'Y' => 0, 'K' => 0, 'LP' => 0, 'Z' => 0, 'X' => 0,
			),
			$in
		);
		$r = $rules['pcb'];

		if ( ! $in['resident'] ) {
			$gross = $in['Y1'] + $in['Yt'];
			$mtd   = Money::ceilNickel( Money::truncDiv( $gross * $r['non_resident_bp'], 10000 ) );
			$net   = max( 0, $mtd - $in['zakat'] );
			return array(
				'normal'  => $mtd,
				'bonus'   => 0,
				'zakat'   => min( $in['zakat'], $mtd ),
				'total'   => $net,
				'steps'   => array( 'Non-resident: 30% of RM' . Money::format( $gross ) ),
				'P'       => null,
			);
		}

		$n        = 12 - max( 1, min( 12, (int) $in['month'] ) );
		$category = (int) $in['category'];
		$children = self::CAT_SINGLE === $category ? 0 : max( 0, (int) $in['children'] );

		$reliefs = $r['relief_self']
			+ ( self::CAT_SPOUSE_NOT_WRK === $category ? $r['relief_spouse'] : 0 )
			+ ( $in['disabled'] ? $r['relief_disabled'] : 0 )
			+ ( self::CAT_SPOUSE_NOT_WRK === $category && $in['disabled_spouse'] ? $r['relief_dis_spouse'] : 0 )
			+ $children * $r['relief_child']
			+ $in['LP'] + $in['LP1']
			// SOCSO + EIS (and LINDUNG 24 where allowed): already claimed this year + this month, up to the limit.
			+ min( $r['socso_relief_limit'], $in['socso_ytd'] + $in['socso_month'] )
			+ min( $r['l24_relief_limit'], $in['l24_ytd'] + $in['l24_month'] );

		$steps = array();

		// Step 1: normal remuneration only.
		$normal = self::monthlyMtd( $in, $n, $category, $reliefs, 0, 0, $r, $steps, 'Normal' );
		$c      = $normal['mtd'];
		if ( $c < $r['min_mtd'] ) {
			if ( $c > 0 ) {
				$steps[] = 'MTD below RM10 is not deducted';
			}
			$c = 0;
		}
		$netNormal = max( 0, $c - $in['zakat'] );
		$zakatUsed = $c - $netNormal;

		$bonusMtd = 0;
		$P        = $normal['P'];

		// Steps 2-4: additional remuneration.
		if ( $in['Yt'] > 0 ) {
			$yearMtd = $in['X'] + $c * ( $n + 1 );                                 // [E]
			$withBonus = self::chargeable( $in, $n, $reliefs, $in['Yt'], $in['Kt'], $r );
			$tax       = self::tax( $withBonus['P'], $category, $r );
			$bonusMtd  = $tax['tax'] - ( $yearMtd + $in['Z'] );
			$bonusMtd  = Money::ceilNickel( $bonusMtd );
			$steps[]   = sprintf(
				'Bonus: P = RM%s, yearly tax = (P − %s) × %s%% + %s = RM%s; minus MTD for the year RM%s',
				Money::format( $withBonus['P'] ),
				Money::format( $tax['M'] ),
				self::pct( $tax['R'] ),
				Money::format( $tax['B'] ),
				Money::format( $tax['tax'] ),
				Money::format( $yearMtd )
			);
			if ( $bonusMtd > 0 && $bonusMtd < $r['min_mtd'] ) {
				$steps[]  = 'MTD on bonus below RM10 is not deducted';
				$bonusMtd = 0;
			}
			$P = $withBonus['P'];
		}

		return array(
			'normal' => $c,
			'bonus'  => $bonusMtd,
			'zakat'  => $zakatUsed,
			'total'  => $netNormal + $bonusMtd,
			'steps'  => $steps,
			'P'      => $P,
		);
	}

	/** Chargeable income P for the year. */
	private static function chargeable( array $in, $n, $reliefs, $Yt, $Kt, array $r ) {
		$limit = $r['epf_relief_limit'];
		$K     = min( $in['K'], $limit );
		$K1    = min( $in['K1'], max( 0, $limit - $K ) );
		$Ktc   = min( $Kt, max( 0, $limit - $K - $K1 ) );
		$K2    = 0;
		if ( $n > 0 ) {
			$K2 = min( $K1, max( 0, Money::truncDiv( $limit - ( $K + $K1 + $Ktc ), $n ) ) );
		}
		$Y1 = $in['Y1'];
		$P  = ( $in['Y'] - $K ) + ( $Y1 - $K1 ) + ( $Y1 - $K2 ) * $n + ( $Yt - $Ktc ) - $reliefs;

		return array( 'P' => $P, 'K2' => $K2, 'K1' => $K1 );
	}

	/** Tax on P using Table 1. */
	public static function tax( $P, $category, array $r ) {
		foreach ( array_reverse( $r['table'] ) as $row ) {
			list( $M, $R, $B13, $B2 ) = $row;
			if ( $P > $M ) {
				$B   = self::CAT_SPOUSE_NOT_WRK === $category ? $B2 : $B13;
				$tax = Money::truncDiv( ( $P - $M ) * $R, 10000 ) + $B;
				return array( 'tax' => max( 0, $tax ), 'raw' => $tax, 'M' => $M, 'R' => $R, 'B' => $B );
			}
		}
		return array( 'tax' => 0, 'raw' => 0, 'M' => 0, 'R' => 0, 'B' => 0 );
	}

	private static function monthlyMtd( array $in, $n, $category, $reliefs, $Yt, $Kt, array $r, array &$steps, $label ) {
		$c   = self::chargeable( $in, $n, $reliefs, $Yt, $Kt, $r );
		$tax = self::tax( $c['P'], $category, $r );
		$mtd = Money::truncDiv( $tax['raw'] - ( $in['Z'] + $in['X'] ), $n + 1 );
		$mtd = Money::ceilNickel( $mtd );

		$steps[] = sprintf(
			'%s: K2 = RM%s, P = RM%s, MTD = [(P − %s) × %s%% + %s − %s] ÷ %d = RM%s',
			$label,
			Money::format( $c['K2'] ),
			Money::format( $c['P'] ),
			Money::format( $tax['M'] ),
			self::pct( $tax['R'] ),
			Money::format( $tax['B'] ),
			Money::format( $in['Z'] + $in['X'] ),
			$n + 1,
			Money::format( $mtd )
		);

		return array( 'mtd' => $mtd, 'P' => $c['P'] );
	}

	private static function pct( $bp ) {
		return rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' );
	}
}
