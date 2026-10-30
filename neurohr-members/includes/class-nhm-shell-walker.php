<?php
/**
 * Sidebar menu markup for the member area (loaded only when a menu is shown).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sidebar links with icons; sub-items open under their parent. */
class NHM_Shell_Walker extends Walker_Nav_Menu {

	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '<ul class="nhs-nav__sub">';
	}

	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '</ul>';
	}

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
		$item    = $data_object;
		$classes = array_filter( (array) $item->classes );
		$on      = array_intersect( $classes, array( 'current-menu-item', 'current_page_item', 'current-menu-ancestor', 'current-menu-parent' ) );
		$icon    = (string) get_post_meta( $item->ID, NHM_Visibility::META_ICON, true );
		$has_sub = in_array( 'menu-item-has-children', $classes, true );
		$output .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . ( $on ? ' is-on' : '' ) . '">';
		$attrs   = ' href="' . esc_url( $item->url ? $item->url : '#' ) . '"';
		if ( in_array( 'current-menu-item', $classes, true ) ) {
			$attrs .= ' aria-current="page"';
		}
		if ( ! empty( $item->target ) ) {
			$attrs .= ' target="' . esc_attr( $item->target ) . '"' . ( '_blank' === $item->target ? ' rel="noopener"' : '' );
		}
		$title = apply_filters( 'the_title', $item->title, $item->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core hook.
		$is_user = in_array( 'nhm-menu-user', $classes, true );
		$output .= '<a' . $attrs . '>' . ( $depth || $is_user ? '' : NHM_Shell::icon( $icon ? $icon : 'dot' ) ) . '<span>' . $title . '</span>';
		if ( $has_sub && 0 === $depth ) {
			$output .= '<span class="nhs-nav__caret">' . NHM_Shell::icon( 'chevron' ) . '</span>';
		}
		$output .= '</a>';
	}

	public function end_el( &$output, $data_object, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}
