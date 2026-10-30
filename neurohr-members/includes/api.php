<?php
/**
 * Public API for other NeuroHR plugins (Knowledge Hub, Simple Payroll, later the HRMS).
 *
 * Check for it with function_exists( 'nhm_levels' ). Level IDs never change and are never
 * reused, so store the ID; compare with the rank (higher rank = more access, ties allowed).
 * Guests have rank 0. NHM_API_VERSION goes up if anything here changes.
 *
 * Hooks:
 *   nhm_level_deleted( $deleted_id, $content_to_id, $members_to_id ) — move stored level IDs.
 *   nhm_levels_saved()                                               — names or order changed.
 *   nhm_plan_changed( $user_id, $from_id, $to_id, $expiry )
 *   nhm_company_joined( $company_id, $user_id ) / nhm_company_left( $company_id, $user_id )
 *   nhm_user_registered( $user_id, $invite|null )
 *   filter nhm_membership( $membership, $user_id )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordered levels: ID => array( id, slug, name, rank, is_base, upgrade_url, … ).
 */
function nhm_levels() {
	$out = array();
	foreach ( NHM_Levels::all() as $id => $level ) {
		$level['upgrade_url'] = NHM_Levels::upgrade_url( $id );
		$out[ $id ]           = $level;
	}
	return $out;
}

/** One level by ID, slug or name, or null. */
function nhm_level( $ref ) {
	return NHM_Levels::resolve( $ref );
}

/** Rank of a level ID (null when unknown). */
function nhm_level_rank( $level_id ) {
	return NHM_Levels::rank( $level_id );
}

function nhm_max_rank() {
	return NHM_Levels::max_rank();
}

function nhm_base_level_id() {
	return NHM_Levels::base_id();
}

/** Level ID of the user's current plan (0 = guest). Staff get their real plan here. */
function nhm_user_level( $user_id = null ) {
	return NHM_Members::level( $user_id );
}

/** Rank of the user's current plan (0 = guest). */
function nhm_user_rank( $user_id = null ) {
	return NHM_Members::rank( $user_id );
}

/** Full details: level, rank, name, source, expiry, days_left, company_id, role. */
function nhm_user_membership( $user_id = null ) {
	return NHM_Members::membership( $user_id );
}

/** May the user open content that needs $level (ID, slug or name)? Editors and admins: always. */
function nhm_user_can_level( $level, $user_id = null ) {
	return NHM_Members::can( $level, $user_id );
}

function nhm_login_url( $redirect = '' ) {
	return NHM_Auth::login_url( $redirect );
}

function nhm_register_url() {
	$page = NHM_Settings::page_url( 'register' );
	return $page ? $page : wp_registration_url();
}

function nhm_lost_password_url() {
	$page = NHM_Auth::own_lost_url();
	if ( $page ) {
		return $page;
	}
	remove_filter( 'lostpassword_url', array( 'NHM_Auth', 'filter_lost_url' ), 20 );
	$url = wp_lostpassword_url();
	add_filter( 'lostpassword_url', array( 'NHM_Auth', 'filter_lost_url' ), 20, 2 );
	return $url;
}

function nhm_account_url() {
	return NHM_Settings::page_url( 'account' );
}

function nhm_pricing_url() {
	return NHM_Levels::upgrade_url();
}

/** Where to send someone who needs $level_id (the level's own button link, else the pricing page). */
function nhm_upgrade_url( $level_id = null ) {
	return NHM_Levels::upgrade_url( $level_id );
}
