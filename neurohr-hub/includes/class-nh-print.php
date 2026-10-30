<?php
/**
 * Print / Save as PDF for resources.
 *
 * A "Print / PDF" button on resources the member may read. The printout keeps only the
 * article (no menus, sidebar, related, search) and carries a copyright notice:
 * at the end of the article in every browser, and at the foot of every page in
 * Chrome / Edge (CSS page margin boxes).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Print {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'page_footer_css' ), 30 );
	}

	public static function default_notice() {
		return '© {year} {site}. All rights reserved. Licensed to {name} ({email}) for internal use only. Do not copy, share or distribute without written permission.';
	}

	/** Should this request get the print button and notice? */
	public static function applies( $post = null ) {
		$post = get_post( $post );
		return $post && NH_Post_Types::CPT === $post->post_type && is_user_logged_in() && NH_Access::can_view( $post );
	}

	/** Notice with placeholders filled in for the current member. */
	public static function notice( $post = null ) {
		$post = get_post( $post );
		$text = trim( (string) NH_Display::get( 'print_notice' ) );
		$text = '' === $text ? self::default_notice() : $text;
		$user = wp_get_current_user();
		return strtr(
			$text,
			array(
				'{year}'  => wp_date( 'Y' ),
				'{site}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{name}'  => $user->exists() ? $user->display_name : '',
				'{email}' => $user->exists() ? $user->user_email : '',
				'{date}'  => wp_date( get_option( 'date_format' ) ),
				'{url}'   => $post ? get_permalink( $post ) : home_url( '/' ),
			)
		);
	}

	public static function button() {
		return '<button type="button" class="nh-print-btn" onclick="window.print()">'
			. '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/></svg>'
			. esc_html__( 'Print / PDF', 'neurohr-hub' ) . '</button>';
	}

	/** Shown only on paper: site and address at the top. */
	public static function head_html( $post = null ) {
		$post = get_post( $post );
		return '<div class="nh-print-only nh-print-head"><strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
			. '<span>' . esc_html( get_permalink( $post ) ) . '</span></div>';
	}

	/** Shown only on paper: the notice at the end of the article. */
	public static function notice_html( $post = null ) {
		return '<div class="nh-print-only nh-print-notice">' . esc_html( self::notice( $post ) ) . '</div>';
	}

	/** Same notice at the foot of every printed page (Chrome / Edge). */
	public static function page_footer_css() {
		if ( ! is_singular( NH_Post_Types::CPT ) || ! self::applies( get_queried_object_id() ) ) {
			return;
		}
		$text = preg_replace( '/\s+/', ' ', self::notice( get_queried_object_id() ) );
		$text = str_replace( array( '\\', '"', '<' ), array( '\\\\', '\\"', '\\3C ' ), $text );
		wp_add_inline_style( 'nh-hub', '@media print{@page{margin:16mm 14mm 20mm;@bottom-center{content:"' . $text . '";font:8pt/1.3 sans-serif;color:#666;}@bottom-right{content:counter(page);font:8pt sans-serif;color:#666;}}}' );
	}
}
