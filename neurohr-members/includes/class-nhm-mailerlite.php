<?php
/**
 * Adds people who tick "send me updates" at registration to a MailerLite group
 * (MailerLite API, connect.mailerlite.com). Errors are kept for the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_MailerLite {

	const ERROR_OPTION = 'nhm_ml_last_error';
	const ENDPOINT     = 'https://connect.mailerlite.com/api/subscribers';

	public static function enabled() {
		return NHM_Settings::get( 'ml_enabled' ) && '' !== trim( (string) NHM_Settings::get( 'ml_api_key' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function subscribe( $email, $name = '', array $fields = array() ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'nhm_ml', 'MailerLite is not set up.' );
		}
		$body = array(
			'email'  => $email,
			'fields' => array_filter( array_merge( array( 'name' => $name ), $fields ) ),
		);
		$group = preg_replace( '/[^0-9]/', '', (string) NHM_Settings::get( 'ml_group' ) );
		if ( $group ) {
			$body['groups'] = array( $group );
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( (string) NHM_Settings::get( 'ml_api_key' ) ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::remember_error( $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			self::remember_error( $message );
			return new WP_Error( 'nhm_ml', $message );
		}
		delete_option( self::ERROR_OPTION );
		return true;
	}

	private static function request( $method, $url, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 8,
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( (string) NHM_Settings::get( 'ml_api_key' ) ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			self::remember_error( $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code > 299 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			self::remember_error( $message );
			return new WP_Error( 'nhm_ml', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Add to or remove from one group (My account → Notifications). Works without the
	 * registration tick box being switched on; only the API token is needed.
	 *
	 * @return true|WP_Error
	 */
	public static function set_group( $email, $name, $group, $on ) {
		if ( '' === trim( (string) NHM_Settings::get( 'ml_api_key' ) ) || '' === (string) $group ) {
			return new WP_Error( 'nhm_ml', 'MailerLite is not set up.' );
		}
		if ( $on ) {
			$r = self::request( 'POST', self::ENDPOINT, array( 'email' => $email, 'fields' => array_filter( array( 'name' => $name ) ), 'groups' => array( (string) $group ), 'status' => 'active' ) );
			return is_wp_error( $r ) ? $r : true;
		}
		$sub = self::request( 'GET', self::ENDPOINT . '/' . rawurlencode( $email ) );
		if ( is_wp_error( $sub ) ) {
			$data = $sub->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'] ? true : $sub; // Not in MailerLite: nothing to remove.
		}
		$id = isset( $sub['data']['id'] ) ? $sub['data']['id'] : '';
		if ( ! $id ) {
			return true;
		}
		$r = self::request( 'DELETE', self::ENDPOINT . '/' . rawurlencode( $id ) . '/groups/' . rawurlencode( (string) $group ) );
		if ( is_wp_error( $r ) ) {
			$data = $r->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'] ? true : $r;
		}
		return true;
	}

	private static function remember_error( $message ) {
		update_option( self::ERROR_OPTION, array( 'time' => current_time( 'mysql' ), 'message' => mb_substr( (string) $message, 0, 300 ) ), false );
	}
}
