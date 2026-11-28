<?php
/**
 * Settings of NeuroHR App (option nha_settings). The first time it runs it takes over the
 * values that NeuroHR Members 0.5 kept (phone layout, app home, install as an app).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHA_Settings {

	const OPTION = 'nha_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'app_on'    => 1,          // Bottom bar, search screen and More sheet on phones.
			'app_home'  => 1,          // App home on the Dashboard page.
			'feedback'  => 1,          // Progress bar and grey placeholders the moment a link is tapped.
			'prefetch'  => 1,          // Load a page when the finger touches its link (Chrome / Android).
			'ahead'     => 1,          // Also load the bottom-bar pages in the background after a page has opened.
			'pwa_on'    => 1,
			'pwa_name'  => 'NeuroHR',
			'pwa_short' => 'NeuroHR',
			'pwa_icon'  => '',
			'pwa_color' => '#f47b20',
			'pwa_bg'    => '#f7f3ee',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, null );
			if ( ! is_array( $saved ) ) {
				$saved = self::from_members();
				update_option( self::OPTION, $saved, true );
			}
			self::$cache = array_merge( self::defaults(), $saved );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_intersect_key( array_merge( self::all(), $values ), self::defaults() );
		update_option( self::OPTION, $all, true );
		self::$cache = null;
	}

	/** Values set in NeuroHR Members 0.5 (Settings → Member Area), so nothing has to be set again. */
	public static function from_members() {
		$out = array();
		if ( ! class_exists( 'NHM_Settings' ) ) {
			return $out;
		}
		$stored = get_option( 'nhm_settings', array() );
		foreach ( array( 'app_on', 'app_home', 'pwa_on', 'pwa_name', 'pwa_short', 'pwa_icon', 'pwa_color', 'pwa_bg' ) as $key ) {
			if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}
}
