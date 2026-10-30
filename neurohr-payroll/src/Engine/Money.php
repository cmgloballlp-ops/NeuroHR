<?php
/**
 * Money helpers. All engine amounts are integers in sen (RM 1.00 = 100).
 * No floats are used anywhere in the calculations.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class Money {

	/** Parse user input ("1,234.5", "1234", 1234.56) into sen. Negative becomes 0. */
	public static function toSen( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value * 100 );
		}
		$s = trim( str_replace( array( ',', 'RM', 'rm', ' ' ), '', (string) $value ) );
		if ( '' === $s || ! preg_match( '/^\d*(\.\d*)?$/', $s ) ) {
			return 0;
		}
		$parts = explode( '.', $s, 2 );
		$rm    = '' === $parts[0] ? 0 : (int) $parts[0];
		$cents = isset( $parts[1] ) ? substr( str_pad( $parts[1], 2, '0' ), 0, 2 ) : '00';
		return $rm * 100 + (int) $cents;
	}

	/** Format sen as "1,234.50". */
	public static function format( $sen ) {
		$neg = $sen < 0;
		$sen = abs( (int) $sen );
		$out = number_format( intdiv( $sen, 100 ) ) . '.' . str_pad( (string) ( $sen % 100 ), 2, '0', STR_PAD_LEFT );
		return $neg ? '-' . $out : $out;
	}

	/** Integer division that rounds up (for non-negative values). */
	public static function ceilDiv( $a, $b ) {
		return intdiv( $a + $b - 1, $b );
	}

	/** Truncating division toward zero (drops further decimals, as LHDN requires). */
	public static function truncDiv( $a, $b ) {
		return intdiv( $a, $b );
	}

	/**
	 * Round an amount given in tenths of a sen to the nearest 5 sen.
	 * PERKESO tables always land exactly halfway; $tie decides which way:
	 *  'ten'  -> to the multiple of 10 sen (Act 4 first/second category, verified against the table)
	 *  'five' -> to the amount ending in 5 sen (LINDUNG 24 Jam column, verified against the table)
	 */
	public static function roundNickel( $tenths, $tie = 'ten' ) {
		$q = intdiv( $tenths, 50 );
		$r = $tenths % 50;
		if ( $r > 25 ) {
			$q++;
		} elseif ( 25 === $r ) {
			$isEven = ( 0 === $q % 2 );
			if ( 'ten' === $tie && ! $isEven ) {
				$q++;
			} elseif ( 'five' === $tie && $isEven ) {
				$q++;
			}
		}
		return $q * 5;
	}

	/** LHDN rule: round up to the next 5 sen (287.02 -> 287.05, 152.06 -> 152.10). */
	public static function ceilNickel( $sen ) {
		return self::ceilDiv( max( 0, $sen ), 5 ) * 5;
	}
}
