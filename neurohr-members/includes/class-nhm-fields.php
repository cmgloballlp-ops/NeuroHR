<?php
/**
 * Extra registration / profile fields, edited in Settings → Registration.
 *
 * Built-in fields (company, phone, job title) can be hidden but not deleted; you can add
 * any number of your own. Values are stored in user meta (built-in: nhm_company_name,
 * nhm_phone, nhm_job_title; own fields: nhm_f_{key}). Several-choice values are arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Fields {

	const OPTION = 'nhm_fields';

	private static $cache = null;

	public static function types() {
		return array(
			'text'       => __( 'Text', 'neurohr-members' ),
			'textarea'   => __( 'Long text', 'neurohr-members' ),
			'number'     => __( 'Number', 'neurohr-members' ),
			'email'      => __( 'Email', 'neurohr-members' ),
			'tel'        => __( 'Phone', 'neurohr-members' ),
			'url'        => __( 'Website', 'neurohr-members' ),
			'date'       => __( 'Date', 'neurohr-members' ),
			'select'     => __( 'Drop-down', 'neurohr-members' ),
			'radio'      => __( 'Choose one (buttons)', 'neurohr-members' ),
			'checkboxes' => __( 'Choose several', 'neurohr-members' ),
			'checkbox'   => __( 'Single tick box', 'neurohr-members' ),
		);
	}

	public static function builtins() {
		return array(
			'company' => array( 'label' => __( 'Company', 'neurohr-members' ), 'type' => 'text', 'meta' => 'nhm_company_name', 'autocomplete' => 'organization' ),
			'phone'   => array( 'label' => __( 'Phone', 'neurohr-members' ), 'type' => 'tel', 'meta' => 'nhm_phone', 'autocomplete' => 'tel' ),
			'job'      => array( 'label' => __( 'Job title', 'neurohr-members' ), 'type' => 'text', 'meta' => 'nhm_job_title', 'autocomplete' => 'organization-title' ),
			'location' => array( 'label' => __( 'Location', 'neurohr-members' ), 'type' => 'select', 'meta' => 'nhm_location', 'autocomplete' => 'address-level1' ),
		);
	}

	/** The fixed choices of the Location field: Malaysian states and federal territories. */
	public static function locations() {
		return array( 'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya', __( 'Outside Malaysia', 'neurohr-members' ) );
	}

	/* ---------- Phone numbers (stored as +60123456789) ---------- */

	public static function country_codes() {
		return apply_filters(
			'nhm_country_codes',
			array(
				'+60'  => 'MY +60',
				'+65'  => 'SG +65',
				'+673' => 'BN +673',
				'+62'  => 'ID +62',
				'+66'  => 'TH +66',
				'+63'  => 'PH +63',
				'+84'  => 'VN +84',
				'+86'  => 'CN +86',
				'+852' => 'HK +852',
				'+886' => 'TW +886',
				'+81'  => 'JP +81',
				'+82'  => 'KR +82',
				'+91'  => 'IN +91',
				'+971' => 'AE +971',
				'+61'  => 'AU +61',
				'+64'  => 'NZ +64',
				'+44'  => 'UK +44',
				'+1'   => 'US/CA +1',
			)
		);
	}

	/** "+60123456789" → array( '+60', '123456789' ). Numbers saved before 0.3 (e.g. "012-345 6789") count as Malaysian. */
	public static function split_phone( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array( '+60', '' );
		}
		if ( '+' === $value[0] ) {
			$digits = '+' . preg_replace( '/\D/', '', $value );
			$codes  = array_keys( self::country_codes() );
			usort( $codes, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			foreach ( $codes as $cc ) {
				if ( 0 === strpos( $digits, $cc ) ) {
					return array( $cc, substr( $digits, strlen( $cc ) ) );
				}
			}
			return array( '', $value );
		}
		return array( '+60', ltrim( preg_replace( '/\D/', '', $value ), '0' ) );
	}

	/** Country code + number as typed → "+60123456789" ('' when no number). */
	public static function join_phone( $cc, $number ) {
		$number = trim( (string) $number );
		if ( '' === $number ) {
			return '';
		}
		if ( '+' === $number[0] ) {
			return '+' . preg_replace( '/\D/', '', $number ); // Typed with its own code.
		}
		$cc = isset( self::country_codes()[ $cc ] ) ? $cc : '+60';
		return $cc . ltrim( preg_replace( '/\D/', '', $number ), '0' );
	}

	/** "+60123456789" → "+60 12-345 6789" for display. */
	public static function format_phone( $value ) {
		list( $cc, $n ) = self::split_phone( $value );
		if ( '' === $n ) {
			return '';
		}
		if ( '+60' === $cc && strlen( $n ) >= 9 ) {
			return '+60 ' . substr( $n, 0, 2 ) . '-' . substr( $n, 2, strlen( $n ) - 6 ) . ' ' . substr( $n, -4 );
		}
		return trim( $cc . ' ' . $n );
	}

	private static function blank( $key ) {
		return array(
			'key'          => $key,
			'label'        => '',
			'type'         => 'text',
			'options'      => array(),
			'required'     => 0,
			'placeholder'  => '',
			'help'         => '',
			'register'     => 1,
			'account'      => 1,
			'builtin'      => 0,
			'meta'         => 'nhm_f_' . $key,
			'autocomplete' => '',
		);
	}

	/** All fields in order. Built from the 0.1 settings the first time. */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			$stored = array();
			foreach ( self::builtins() as $key => $b ) {
				$mode     = NHM_Settings::stored( 'field_' . $key );
				$mode     = $mode ? $mode : 'optional';
				$stored[] = array(
					'key'      => $key,
					'label'    => $b['label'],
					'type'     => $b['type'],
					'required' => 'required' === $mode ? 1 : 0,
					'register' => 'hidden' === $mode ? 0 : 1,
					'account'  => 'hidden' === $mode ? 0 : 1,
				);
			}
			update_option( self::OPTION, $stored );
		}
		$out      = array();
		$builtins = self::builtins();
		foreach ( $stored as $f ) {
			if ( empty( $f['key'] ) ) {
				continue;
			}
			$f = array_merge( self::blank( $f['key'] ), $f );
			if ( isset( $builtins[ $f['key'] ] ) ) {
				$f['builtin']      = 1;
				$f['meta']         = $builtins[ $f['key'] ]['meta'];
				$f['autocomplete'] = $builtins[ $f['key'] ]['autocomplete'];
				if ( '' === $f['label'] ) {
					$f['label'] = $builtins[ $f['key'] ]['label'];
				}
			}
			$f['options']      = array_values( array_filter( array_map( 'trim', (array) $f['options'] ), 'strlen' ) );
			if ( 'location' === $f['key'] ) {
				$f['options'] = self::locations();
			}
			$out[ $f['key'] ] = $f;
		}
		// A built-in field missing from the option is added at the end (hidden).
		foreach ( $builtins as $key => $b ) {
			if ( ! isset( $out[ $key ] ) ) {
				$new = 'location' === $key; // Added in 0.3: on by default (optional) for lists saved before 0.3.
				$out[ $key ] = array_merge( self::blank( $key ), array( 'label' => $b['label'], 'type' => $b['type'], 'builtin' => 1, 'meta' => $b['meta'], 'autocomplete' => $b['autocomplete'], 'register' => $new ? 1 : 0, 'account' => $new ? 1 : 0, 'options' => $new ? self::locations() : array() ) );
			}
		}
		self::$cache = $out;
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function for_register() {
		return array_filter( self::all(), function ( $f ) { return ! empty( $f['register'] ); } );
	}

	public static function for_account() {
		return array_filter( self::all(), function ( $f ) { return ! empty( $f['account'] ); } );
	}

	/** Save the list from the settings screen ($rows in the new order). */
	public static function save( array $rows ) {
		$builtins = self::builtins();
		$out      = array();
		$seen     = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$key   = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $key ) {
				$key = substr( str_replace( '-', '_', sanitize_title( $label ) ), 0, 40 );
			}
			if ( '' === $key || '' === $label && ! isset( $builtins[ $key ] ) ) {
				continue;
			}
			if ( ! empty( $row['delete'] ) && ! isset( $builtins[ $key ] ) ) {
				continue;
			}
			// Names the form already uses.
			if ( ! isset( $builtins[ $key ] ) && in_array( $key, array( 'name', 'email', 'password', 'plan', 'invite', 'terms', 'newsletter', 'website', 't', 'action', 'current', 'new', 'level', 'start', 'expiry', 'note', 'owner', 'user', 'invite_email', 'invite_id', 'bill_name', 'bill_company', 'bill_reg', 'bill_tin', 'bill_email', 'bill_address' ), true ) ) {
				$key .= '_field';
			}
			// Keys are permanent (they name the stored data); a clash gets a number.
			$base = $key;
			$n    = 2;
			while ( isset( $seen[ $key ] ) ) {
				$key = $base . '_' . $n++;
			}
			$seen[ $key ] = true;
			$type         = isset( $row['type'] ) && isset( self::types()[ $row['type'] ] ) ? $row['type'] : 'text';
			$out[]        = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => isset( $builtins[ $key ] ) ? $builtins[ $key ]['type'] : $type,
				'options'     => isset( $row['options'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $row['options'] ) ), 'strlen' ) ) : array(),
				'required'    => empty( $row['required'] ) ? 0 : 1,
				'placeholder' => isset( $row['placeholder'] ) ? sanitize_text_field( $row['placeholder'] ) : '',
				'help'        => isset( $row['help'] ) ? sanitize_text_field( $row['help'] ) : '',
				'register'    => empty( $row['register'] ) ? 0 : 1,
				'account'     => empty( $row['account'] ) ? 0 : 1,
			);
		}
		// Built-in fields left out of the list are stored as hidden (so "missing" means "saved before it existed").
		foreach ( $builtins as $key => $b ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$out[] = array( 'key' => $key, 'label' => $b['label'], 'type' => $b['type'], 'options' => array(), 'required' => 0, 'placeholder' => '', 'help' => '', 'register' => 0, 'account' => 0 );
			}
		}
		update_option( self::OPTION, $out );
		self::flush();
	}

	/* ---------- Values ---------- */

	public static function value( $user_id, array $f ) {
		$v = get_user_meta( $user_id, $f['meta'], true );
		if ( 'checkboxes' === $f['type'] ) {
			return is_array( $v ) ? $v : ( '' === $v ? array() : array( (string) $v ) );
		}
		return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
	}

	/** Value as text for lists, CSV and invoices. */
	public static function text( $user_id, array $f ) {
		$v = self::value( $user_id, $f );
		if ( 'tel' === $f['type'] ) {
			return self::format_phone( $v );
		}
		if ( 'checkbox' === $f['type'] ) {
			return $v ? __( 'Yes', 'neurohr-members' ) : '';
		}
		return is_array( $v ) ? implode( ', ', $v ) : $v;
	}

	public static function store( $user_id, array $f, $value ) {
		if ( ( is_array( $value ) && ! $value ) || '' === $value || null === $value ) {
			delete_user_meta( $user_id, $f['meta'] );
		} else {
			update_user_meta( $user_id, $f['meta'], $value );
		}
	}

	/**
	 * Read a field from $source (e.g. $_POST, already unslashed) and check it.
	 *
	 * @return array{0:mixed,1:string} [ clean value, error message or '' ]
	 */
	public static function read( array $f, array $source, $prefix = 'nhm_', $check_required = true ) {
		$name = $prefix . $f['key'];
		$raw  = isset( $source[ $name ] ) ? $source[ $name ] : '';
		$err  = '';
		switch ( $f['type'] ) {
			case 'checkboxes':
				$value = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $raw ), $f['options'] ) );
				break;
			case 'checkbox':
				$value = empty( $raw ) ? '' : '1';
				break;
			case 'textarea':
				$value = sanitize_textarea_field( (string) $raw );
				break;
			case 'email':
				$value = sanitize_email( (string) $raw );
				if ( '' !== trim( (string) $raw ) && ! is_email( $value ) ) {
					/* translators: %s: field name */
					$err = sprintf( __( '%s: enter a valid email address.', 'neurohr-members' ), $f['label'] );
				}
				break;
			case 'number':
				$value = trim( sanitize_text_field( (string) $raw ) );
				if ( '' !== $value && ! is_numeric( $value ) ) {
					/* translators: %s: field name */
					$err = sprintf( __( '%s: enter a number.', 'neurohr-members' ), $f['label'] );
				}
				break;
			case 'date':
				$value = NHM_Members::clean_date( sanitize_text_field( (string) $raw ) );
				break;
			case 'url':
				$value = esc_url_raw( trim( (string) $raw ) );
				break;
			case 'tel':
				$cc    = isset( $source[ $name . '_cc' ] ) ? sanitize_text_field( (string) $source[ $name . '_cc' ] ) : '+60';
				$value = self::join_phone( $cc, sanitize_text_field( (string) $raw ) );
				$len   = strlen( preg_replace( '/\D/', '', $value ) );
				if ( '' !== $value && ( $len < 8 || $len > 15 ) ) {
					/* translators: %s: field name */
					$err = sprintf( __( '%s: enter a valid phone number.', 'neurohr-members' ), $f['label'] );
				}
				break;
			case 'select':
			case 'radio':
				$value = sanitize_text_field( (string) $raw );
				if ( '' !== $value && ! in_array( $value, $f['options'], true ) ) {
					$value = '';
				}
				break;
			default:
				$value = sanitize_text_field( (string) $raw );
		}
		if ( ! $err && $check_required && $f['required'] && ( '' === $value || array() === $value ) ) {
			/* translators: %s: field name */
			$err = 'checkbox' === $f['type'] ? sprintf( __( 'Please tick "%s".', 'neurohr-members' ), $f['label'] ) : sprintf( __( 'Please fill in: %s.', 'neurohr-members' ), $f['label'] );
		}
		return array( $value, $err );
	}

	/** Form markup for a field (front end). */
	public static function render( array $f, $value, $prefix = 'nhm_' ) {
		return NHM_Forms::field(
			array(
				'name'         => $prefix . $f['key'],
				'label'        => $f['label'],
				'type'         => $f['type'],
				'value'        => $value,
				'options'      => $f['options'],
				'required'     => (bool) $f['required'],
				'autocomplete' => $f['autocomplete'],
				'placeholder'  => $f['placeholder'],
				'help'         => $f['help'],
			)
		);
	}
}
