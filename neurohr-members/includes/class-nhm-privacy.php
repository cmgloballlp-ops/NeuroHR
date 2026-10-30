<?php
/**
 * PDPA / WordPress privacy tools (Tools → Export Personal Data / Erase Personal Data).
 * Erasing removes profile answers and billing details. Invoices are kept for accounting
 * (they are business records); the eraser says so in its report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $list ) {
		$list['neurohr-members'] = array( 'exporter_friendly_name' => __( 'NeuroHR Members', 'neurohr-members' ), 'callback' => array( __CLASS__, 'export' ) );
		return $list;
	}

	public static function register_eraser( $list ) {
		$list['neurohr-members'] = array( 'eraser_friendly_name' => __( 'NeuroHR Members', 'neurohr-members' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $list;
	}

	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$out  = array();
		if ( $user ) {
			$m    = NHM_Members::membership( $user->ID );
			$data = array(
				array( 'name' => __( 'Plan', 'neurohr-members' ), 'value' => $m['name'] ),
				array( 'name' => __( 'Last day', 'neurohr-members' ), 'value' => $m['expiry'] ),
			);
			if ( $m['company_id'] ) {
				$c      = NHM_Companies::get( $m['company_id'] );
				$data[] = array( 'name' => __( 'Company account', 'neurohr-members' ), 'value' => $c ? $c['name'] : '' );
			}
			foreach ( NHM_Fields::all() as $f ) {
				$v = NHM_Fields::text( $user->ID, $f );
				if ( '' !== $v ) {
					$data[] = array( 'name' => $f['label'], 'value' => $v );
				}
			}
			foreach ( NHM_Account::billing_fields() as $key => $f ) {
				$v = (string) get_user_meta( $user->ID, $key, true );
				if ( '' !== $v ) {
					$data[] = array( 'name' => $f[0], 'value' => $v );
				}
			}
			$out[] = array( 'group_id' => 'nhm-membership', 'group_label' => __( 'Membership', 'neurohr-members' ), 'item_id' => 'nhm-' . $user->ID, 'data' => $data );
			foreach ( NHM_Invoices::query( array( 'user_id' => $user->ID, 'limit' => 500 ) ) as $inv ) {
				$out[] = array(
					'group_id'    => 'nhm-invoices',
					'group_label' => __( 'Invoices', 'neurohr-members' ),
					'item_id'     => 'nhm-invoice-' . $inv['id'],
					'data'        => array(
						array( 'name' => __( 'Number', 'neurohr-members' ), 'value' => $inv['number'] ),
						array( 'name' => __( 'Date', 'neurohr-members' ), 'value' => $inv['issue_date'] ),
						array( 'name' => __( 'Total', 'neurohr-members' ), 'value' => NHM_Settings::money( $inv['total'] ) ),
						array( 'name' => __( 'Status', 'neurohr-members' ), 'value' => $inv['status'] ),
					),
				);
			}
		}
		return array( 'data' => $out, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		$kept    = false;
		$msgs    = array();
		if ( $user ) {
			foreach ( NHM_Fields::all() as $f ) {
				if ( '' !== NHM_Fields::text( $user->ID, $f ) ) {
					delete_user_meta( $user->ID, $f['meta'] );
					$removed = true;
				}
			}
			foreach ( array_keys( NHM_Account::billing_fields() ) as $key ) {
				if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
					delete_user_meta( $user->ID, $key );
					$removed = true;
				}
			}
			delete_user_meta( $user->ID, 'nhm_registered_ip' );
			if ( NHM_Invoices::query( array( 'user_id' => $user->ID, 'limit' => 1 ) ) ) {
				$kept   = true;
				$msgs[] = __( 'Invoices were kept: they are accounting records that must be retained.', 'neurohr-members' );
			}
		}
		return array( 'items_removed' => $removed, 'items_retained' => $kept, 'messages' => $msgs, 'done' => true );
	}

	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'NeuroHR Members',
			wp_kses_post( wpautop( __( "When you create an account we store your name, email address and the details you enter in the registration form (for example company, phone and job title). We use them to run your account and plan, to send emails about your plan and invoices, and to issue invoices. Invoices and payment records are kept as long as accounting rules require. If you tick the newsletter box, your name and email are sent to MailerLite.", 'neurohr-members' ) ) )
		);
	}
}
