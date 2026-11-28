<?php
/**
 * Faster page changes (Chrome and Android; other browsers simply do not use this):
 * - "prefetch": a page starts loading the moment a finger touches (or a mouse rests on) its link.
 * - "ahead": after a page has opened, the bottom-bar pages are loaded quietly in the background.
 * Only plain page addresses are loaded early: never log out, downloads, videos, admin,
 * addresses with ?… (forms, payment links, account tabs) or links marked no-prefetch.
 * Loading early runs no JavaScript, so it never counts as a view or a download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHA_Speed {

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'rules' ), 50 );
	}

	/** Address patterns never loaded early. */
	public static function never() {
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$list = array( '/wp-*.php', '/wp-admin/*', '/wp-content/*', '/wp-json/*', '/logout*', '/nhm-*', '/feed/*', '/*/feed/*', '/*\\?(.+)' );
		$list = array_map(
			function ( $p ) use ( $base ) {
				return $base . $p;
			},
			$list
		);
		return array_values( apply_filters( 'nha_prefetch_never', $list ) );
	}

	public static function on() {
		return NHA_App::on() && (bool) NHA_Settings::get( 'prefetch' );
	}

	public static function rules() {
		if ( ! self::on() ) {
			return;
		}
		$base  = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$rules = array(
			'prefetch' => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => $base . '/*' ),
							array( 'not' => array( 'href_matches' => self::never() ) ),
							array( 'not' => array( 'selector_matches' => '.no-prefetch, .no-prefetch a, [download], [target=_blank], [rel~=nofollow], [data-nhm-more-open]' ) ),
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);
		echo '<script type="speculationrules">' . wp_json_encode( $rules, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
	}

	/** Bottom-bar pages to load in the background (not the page that is open). */
	public static function ahead_urls() {
		if ( ! self::on() || ! NHA_Settings::get( 'ahead' ) ) {
			return array();
		}
		$links = NHA_App::links();
		$cur   = NHA_App::current_tab( $links );
		$open  = '' !== $cur && ! empty( $links[ $cur ] ) ? $links[ $cur ] : '';
		$out   = array();
		foreach ( array( 'home', 'hub', 'templates' ) as $key ) {
			if ( $key !== $cur && ! empty( $links[ $key ] ) && $links[ $key ] !== $open && false === strpos( $links[ $key ], '?' ) ) {
				$out[] = $links[ $key ];
			}
		}
		return array_values( array_unique( $out ) );
	}
}
