<?php
/**
 * Runs the worked examples stored with each PCB year (copied from LHDN's
 * yearly specification) through the engine, so a new year's figures can be
 * checked before members use them.
 */

namespace NeuroHR\Payroll\Engine;

if ( ! defined( 'ABSPATH' ) && ! defined( 'NHP_ENGINE_STANDALONE' ) ) {
	exit;
}

final class SelfTest {

	/** Fields of a test case, amounts in RM as typed from the specification. */
	public static function fields() {
		return array(
			'month'    => 'Month (1–12)',
			'category' => 'Category (1, 2, 3)',
			'children' => 'Children',
			'Y1'       => 'Y1 salary',
			'K1'       => 'K1 EPF',
			'Yt'       => 'Yt bonus',
			'Kt'       => 'Kt EPF on bonus',
			'Y'        => 'ΣY before',
			'K'        => 'ΣK before',
			'X'        => 'X PCB paid',
			'Z'        => 'Z zakat paid',
			'LP'       => 'ΣLP before',
			'LP1'      => 'LP1 this month',
			'expect'   => 'Expected MTD',
		);
	}

	/**
	 * @return array{got:int,expect:int,pass:bool}
	 */
	public static function run( array $test, array $pcbRules ) {
		$rm = function ( $k ) use ( $test ) {
			return isset( $test[ $k ] ) ? Money::toSen( $test[ $k ] ) : 0;
		};
		$in = array(
			'month'    => isset( $test['month'] ) ? (int) $test['month'] : 1,
			'category' => isset( $test['category'] ) ? (int) $test['category'] : 1,
			'children' => isset( $test['children'] ) ? (int) $test['children'] : 0,
		);
		foreach ( array( 'Y1', 'K1', 'Yt', 'Kt', 'Y', 'K', 'X', 'Z', 'LP', 'LP1' ) as $k ) {
			$in[ $k ] = $rm( $k );
		}
		$r      = Pcb::calculate( $in, array( 'pcb' => $pcbRules ) );
		$expect = $rm( 'expect' );
		return array(
			'got'    => $r['total'],
			'expect' => $expect,
			'pass'   => $r['total'] === $expect,
		);
	}

	/** Run every test of every year. year => list of results with labels. */
	public static function runAll() {
		$out = array();
		foreach ( Rates::pcbYears() as $year => $rules ) {
			foreach ( $rules['tests'] as $i => $t ) {
				$res               = self::run( $t, $rules );
				$res['label']      = isset( $t['label'] ) && '' !== $t['label'] ? $t['label'] : 'Test ' . ( $i + 1 );
				$out[ $year ][]    = $res;
			}
			if ( ! isset( $out[ $year ] ) ) {
				$out[ $year ] = array();
			}
		}
		return $out;
	}
}
