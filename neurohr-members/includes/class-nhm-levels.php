<?php
/**
 * Membership levels: an ordered ladder that the owner can edit.
 *
 * Each level has a fixed numeric ID that never changes and is never reused, so other
 * plugins can store it (the Knowledge Hub keeps it per resource). The rank is worked out
 * from the order: the first level (the base level, "Free") has rank 1, every next level
 * one more — unless it is marked "same access as the level above", then it shares that rank
 * (Consulting = Corporate). Guests have rank 0.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Levels {

	const OPTION = 'nhm_levels';

	private static $cache = null;

	/** Levels created on first activation. IDs 1–4 match the Knowledge Hub's old level numbers. */
	public static function seed() {
		return array(
			'next_id' => 6,
			'items'   => array(
				self::blank( 1, 'Free', 'free', array( 'access' => 'lifetime', 'description' => 'Create an account and read the free articles and FAQs.', 'button_label' => 'Sign up free' ) ),
				self::blank( 2, 'Lite', 'lite' ),
				self::blank( 3, 'Pro', 'pro' ),
				self::blank( 4, 'Corporate', 'corporate' ),
				self::blank( 5, 'Consulting', 'consulting', array( 'same_as_prev' => 1, 'in_pricing' => 0 ) ),
			),
		);
	}

	public static function blank( $id, $name, $slug, $extra = array() ) {
		return array_merge(
			array(
				'id'           => (int) $id,
				'name'         => $name,
				'slug'         => $slug,
				'same_as_prev' => 0,
				'price'        => '',  // Display text for the pricing table; empty = from amount + term.
				'period'       => '',  // Display text after the price; empty = from the term.
				'amount'       => '0', // Price in RM for invoices. 0 = free (no invoice).
				'billing'      => 'one_time', // one_time | recurring (renewal invoice before the end).
				'access'       => 'expire',   // lifetime | expire | fixed
				'expire_n'     => 1,
				'expire_unit'  => 'year',     // day | week | month | year
				'fixed_date'   => '',
				'early_renew'  => 1,
				'tagline'      => '',  // One line under the name on the pricing table.
				'price_note'   => '',  // Small text under the price, e.g. "≈ RM50 a month".
				'badge'        => '',  // Label above the card, e.g. "Most popular".
				'description'  => '',
				'features'     => '',  // One per line; a line starting with "-" shows as not included (grey).
				'button_label' => '',
				'button_url'   => '',
				'highlight'    => 0,
				'in_pricing'   => 1,
			),
			$extra
		);
	}

	/** Create the seed levels if nothing is stored yet. */
	public static function ensure() {
		$data = get_option( self::OPTION, null );
		if ( ! is_array( $data ) || empty( $data['items'] ) ) {
			update_option( self::OPTION, self::seed() );
			self::$cache = null;
		}
	}

	private static function data() {
		$data = get_option( self::OPTION, null );
		if ( ! is_array( $data ) || empty( $data['items'] ) ) {
			$data = self::seed();
		}
		return $data;
	}

	/**
	 * Ordered levels, ID => level array with 'rank' and 'is_base' added.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out  = array();
		$rank = 0;
		foreach ( array_values( self::data()['items'] ) as $i => $item ) {
			if ( ! isset( $item['autorenew'] ) ) {
				// Company plans are invoiced; card auto-renew is for personal plans.
				$item['autorenew'] = in_array( isset( $item['slug'] ) ? $item['slug'] : '', array( 'corporate', 'consulting' ), true ) ? 0 : 1;
			}
			$item = array_merge( self::blank( 0, '', '' ), $item );
			if ( 0 === $i || empty( $item['same_as_prev'] ) ) {
				$rank++;
			}
			$item['id']      = (int) $item['id'];
			$item['rank']    = $rank;
			$item['is_base'] = 0 === $i;
			if ( 0 === $i ) {
				$item['same_as_prev'] = 0;
			}
			$out[ $item['id'] ] = $item;
		}
		self::$cache = $out;
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/** Accepts an ID, a slug or a name (case-insensitive). */
	public static function resolve( $ref ) {
		if ( is_array( $ref ) ) {
			return isset( $ref['id'] ) ? self::get( $ref['id'] ) : null;
		}
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			return self::get( (int) $ref );
		}
		$needle = strtolower( $ref );
		foreach ( self::all() as $level ) {
			if ( strtolower( $level['slug'] ) === $needle || strtolower( $level['name'] ) === $needle ) {
				return $level;
			}
		}
		return null;
	}

	public static function base() {
		$all = self::all();
		return reset( $all );
	}

	public static function base_id() {
		$base = self::base();
		return (int) $base['id'];
	}

	public static function rank( $id ) {
		$level = self::get( $id );
		return $level ? (int) $level['rank'] : null;
	}

	public static function max_rank() {
		$max = 0;
		foreach ( self::all() as $level ) {
			$max = max( $max, (int) $level['rank'] );
		}
		return $max;
	}

	/** The first (lowest-ordered) level with the highest rank. */
	public static function top() {
		$max = self::max_rank();
		foreach ( self::all() as $level ) {
			if ( (int) $level['rank'] === $max ) {
				return $level;
			}
		}
		return self::base();
	}

	public static function name( $id ) {
		$level = self::get( $id );
		return $level ? $level['name'] : '';
	}

	/** Where the "Upgrade" button for a level goes. */
	public static function upgrade_url( $id = null ) {
		$level = null === $id ? null : self::get( $id );
		$url   = $level && $level['button_url'] ? $level['button_url'] : '';
		if ( ! $url && $level && ! $level['is_base'] && (float) $level['amount'] > 0 && NHM_Settings::page_url( 'register' ) ) {
			$url = self::register_url( $level['id'] );
		}
		if ( ! $url ) {
			$url = NHM_Settings::page_url( 'pricing' );
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}
		return apply_filters( 'nhm_upgrade_url', $url, $id );
	}

	/** The next plan up from a level: lowest rank above it, a paid one first. */
	public static function next_up( $id ) {
		$rank  = (int) self::rank( $id );
		$found = null;
		foreach ( self::all() as $level ) {
			if ( (int) $level['rank'] <= $rank ) {
				continue;
			}
			$better = ! $found || (int) $level['rank'] < (int) $found['rank'] || ( (int) $level['rank'] === (int) $found['rank'] && self::is_paid( $level['id'] ) && ! self::is_paid( $found['id'] ) );
			if ( $better ) {
				$found = $level;
			}
		}
		return $found ? (int) $found['id'] : 0;
	}

	/**
	 * Where an "Upgrade" button for someone on $id goes: the pricing page (to compare plans) when you
	 * set one in Settings → Pages & login; otherwise the next plan up (its own upgrade link, or its
	 * registration link /register/?plan=…, where a logged-in member gets "Get <plan>" and an invoice).
	 */
	public static function upgrade_from( $id ) {
		$pricing = NHM_Settings::page_url( 'pricing' );
		if ( $pricing ) {
			return apply_filters( 'nhm_upgrade_from_url', $pricing, $id );
		}
		$next = self::next_up( $id );
		return apply_filters( 'nhm_upgrade_from_url', $next ? self::upgrade_url( $next ) : home_url( '/' ), $id );
	}

	/**
	 * The pricing texts agreed for NeuroHR (Members → Levels → "Fill in the suggested pricing texts").
	 * slug => fields. Prices themselves come from each level's amount.
	 */
	public static function pricing_preset() {
		return array(
			'free'      => array( 'tagline' => 'Start smart with free HR tools', 'price' => 'Free', 'period' => '', 'price_note' => 'No card needed', 'badge' => '', 'highlight' => 0, 'button_label' => 'Create free account', 'description' => 'Free HR calculators and the latest HR news — ideal for individuals and micro businesses getting started.', 'features' => "HR News & Updates\nHR calculators: payroll (EPF, SOCSO, EIS, PCB), annual leave, termination benefits\n- HR Knowledge Hub & FAQ\n- Legal Case Insights\n- Special discounts on CM's training\n- HR templates & downloads\n- HR videos & training clips\n- Your company logo on payslips & termination statements\n- Free access to CM's Tea Talk\n- Yearly corporate gift\nStaff logins: **1**\n- Special discounts on CM's HR services\n- 1-hour HR consultation\n- Unlimited online support" ),
			'lite'      => array( 'tagline' => 'Know the rules, get it right', 'price' => '', 'period' => 'per year', 'price_note' => '≈ RM12.50 a month', 'badge' => '', 'highlight' => 0, 'button_label' => 'Get Lite', 'description' => 'The full HR Knowledge Hub, FAQ and Malaysian legal case insights to keep your HR practices compliant.', 'features' => "HR News & Updates\nHR calculators: payroll (EPF, SOCSO, EIS, PCB), annual leave, termination benefits\nHR Knowledge Hub & FAQ\nLegal Case Insights\nSpecial discounts on CM's training\n- HR templates & downloads\n- HR videos & training clips\n- Your company logo on payslips & termination statements\n- Free access to CM's Tea Talk\n- Yearly corporate gift\nStaff logins: **1**\n- Special discounts on CM's HR services\n- 1-hour HR consultation\n- Unlimited online support" ),
			'pro'       => array( 'tagline' => 'The complete HR kit for SMEs', 'price' => '', 'period' => 'per year', 'price_note' => '≈ RM50 a month', 'badge' => 'Most popular', 'highlight' => 1, 'button_label' => 'Get Pro', 'description' => 'Everything in Lite, plus ready-to-use templates, HR videos and your company logo on payslips and statements.', 'features' => "HR News & Updates\nHR calculators: payroll (EPF, SOCSO, EIS, PCB), annual leave, termination benefits\nHR Knowledge Hub & FAQ\nLegal Case Insights\nSpecial discounts on CM's training\nHR templates & downloads\nHR videos & training clips\nYour company logo on payslips & termination statements\nFree access to CM's Tea Talk\nYearly corporate gift\nStaff logins: **1**\n- Special discounts on CM's HR services\n- 1-hour HR consultation\n- Unlimited online support" ),
			'corporate' => array( 'tagline' => 'Strategic HR support for growing teams', 'price' => '', 'period' => 'per year', 'price_note' => 'for up to 5 staff', 'badge' => '', 'highlight' => 0, 'button_label' => 'Get Corporate', 'description' => 'Everything in Pro for your whole HR team, with a consultation hour, unlimited online support and member pricing on our services.', 'features' => "HR News & Updates\nHR calculators: payroll (EPF, SOCSO, EIS, PCB), annual leave, termination benefits\nHR Knowledge Hub & FAQ\nLegal Case Insights\nSpecial discounts on CM's training\nHR templates & downloads\nHR videos & training clips\nYour company logo on payslips & termination statements\nFree access to CM's Tea Talk\nYearly corporate gift\nStaff logins: **Up to 5**\nSpecial discounts on CM's HR services\n1-hour HR consultation\nUnlimited online support" ),
		);
	}

	/** Write the preset texts into the levels with those short names. @return int levels changed */
	public static function apply_pricing_preset() {
		$data    = get_option( self::OPTION );
		$preset  = self::pricing_preset();
		$changed = 0;
		if ( ! is_array( $data ) || empty( $data['items'] ) ) {
			return 0;
		}
		foreach ( $data['items'] as $i => $item ) {
			if ( isset( $preset[ $item['slug'] ] ) ) {
				$data['items'][ $i ] = array_merge( $item, $preset[ $item['slug'] ], array( 'in_pricing' => 1 ) );
				++$changed;
			}
		}
		update_option( self::OPTION, $data );
		self::flush();
		return $changed;
	}

	/* ---------- Changes ---------- */

	public static function unique_slug( $slug, $except_id = 0 ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			$slug = 'level';
		}
		$base = $slug;
		$n    = 2;
		while ( true ) {
			$taken = false;
			foreach ( self::data()['items'] as $item ) {
				if ( (int) $item['id'] !== (int) $except_id && $item['slug'] === $slug ) {
					$taken = true;
					break;
				}
			}
			if ( ! $taken ) {
				return $slug;
			}
			$slug = $base . '-' . $n++;
		}
	}

	/** Add a level at the top of the ladder. Returns the new ID. */
	public static function add( $name ) {
		$data = self::data();
		$id   = max( (int) $data['next_id'], 6 );
		foreach ( $data['items'] as $item ) {
			$id = max( $id, (int) $item['id'] + 1 );
		}
		$name            = sanitize_text_field( $name );
		$data['items'][] = self::blank( $id, $name ? $name : 'New level', self::unique_slug( $name ) );
		$data['next_id'] = $id + 1;
		update_option( self::OPTION, $data );
		self::flush();
		return $id;
	}

	/**
	 * Save edited levels. $rows is ID => fields, in the new order. Unknown IDs are ignored
	 * and levels missing from $rows keep their place at the end (nothing is deleted here).
	 */
	public static function save( array $rows ) {
		$data    = self::data();
		$current = array();
		foreach ( $data['items'] as $item ) {
			$current[ (int) $item['id'] ] = $item;
		}
		$base_id = (int) $data['items'][0]['id'];
		$items   = array( $current[ $base_id ] ); // The base level always stays first.
		$seen    = array( $base_id => true );

		$ordered = array_keys( $rows );
		foreach ( $ordered as $id ) {
			$id = (int) $id;
			if ( isset( $current[ $id ] ) && ! isset( $seen[ $id ] ) ) {
				$items[]     = $current[ $id ];
				$seen[ $id ] = true;
			}
		}
		foreach ( $current as $id => $item ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$items[] = $item;
			}
		}

		foreach ( $items as $i => $item ) {
			$id = (int) $item['id'];
			if ( ! isset( $rows[ $id ] ) || ! is_array( $rows[ $id ] ) ) {
				continue;
			}
			$in   = $rows[ $id ];
			$name = isset( $in['name'] ) ? sanitize_text_field( wp_unslash( $in['name'] ) ) : $item['name'];
			$item = array_merge(
				$item,
				array(
					'name'         => '' !== $name ? $name : $item['name'],
					'slug'         => isset( $in['slug'] ) && '' !== trim( $in['slug'] ) ? self::unique_slug( wp_unslash( $in['slug'] ), $id ) : $item['slug'],
					'same_as_prev' => 0 === $i ? 0 : ( empty( $in['same_as_prev'] ) ? 0 : 1 ),
					'price'        => isset( $in['price'] ) ? sanitize_text_field( wp_unslash( $in['price'] ) ) : '',
					'period'       => isset( $in['period'] ) ? sanitize_text_field( wp_unslash( $in['period'] ) ) : '',
					'tagline'      => isset( $in['tagline'] ) ? sanitize_text_field( wp_unslash( $in['tagline'] ) ) : '',
					'price_note'   => isset( $in['price_note'] ) ? sanitize_text_field( wp_unslash( $in['price_note'] ) ) : '',
					'badge'        => isset( $in['badge'] ) ? sanitize_text_field( wp_unslash( $in['badge'] ) ) : '',
					'description'  => isset( $in['description'] ) ? sanitize_textarea_field( wp_unslash( $in['description'] ) ) : '',
					'features'     => isset( $in['features'] ) ? sanitize_textarea_field( wp_unslash( $in['features'] ) ) : '',
					'button_label' => isset( $in['button_label'] ) ? sanitize_text_field( wp_unslash( $in['button_label'] ) ) : '',
					'button_url'   => isset( $in['button_url'] ) ? self::clean_url( wp_unslash( $in['button_url'] ) ) : '',
					'amount'       => isset( $in['amount'] ) ? self::clean_amount( wp_unslash( $in['amount'] ) ) : $item['amount'],
					'billing'      => isset( $in['billing'] ) && 'recurring' === $in['billing'] ? 'recurring' : 'one_time',
					'access'       => isset( $in['access'] ) && in_array( $in['access'], array( 'lifetime', 'expire', 'fixed' ), true ) ? $in['access'] : $item['access'],
					'expire_n'     => isset( $in['expire_n'] ) ? max( 1, min( 1000, absint( $in['expire_n'] ) ) ) : $item['expire_n'],
					'expire_unit'  => isset( $in['expire_unit'] ) && in_array( $in['expire_unit'], array( 'day', 'week', 'month', 'year' ), true ) ? $in['expire_unit'] : $item['expire_unit'],
					'fixed_date'   => isset( $in['fixed_date'] ) ? NHM_Members::clean_date( wp_unslash( $in['fixed_date'] ) ) : $item['fixed_date'],
					'early_renew'  => empty( $in['early_renew'] ) ? 0 : 1,
					'autorenew'    => empty( $in['autorenew'] ) ? 0 : 1,
					'highlight'    => empty( $in['highlight'] ) ? 0 : 1,
					'in_pricing'   => empty( $in['in_pricing'] ) ? 0 : 1,
				)
			);
			$items[ $i ] = $item;
		}

		$data['items'] = array_values( $items );
		update_option( self::OPTION, $data );
		self::flush();
		do_action( 'nhm_levels_saved' );
	}

	public static function clean_amount( $value ) {
		$value = preg_replace( '/[^0-9.]/', '', (string) $value );
		return number_format( (float) $value, 2, '.', '' );
	}

	/* ---------- Billing terms ---------- */

	public static function is_paid( $id ) {
		$level = self::get( $id );
		return $level && (float) $level['amount'] > 0;
	}

	/**
	 * Last day of a term that starts on $start (Y-m-d). '' = no end (lifetime).
	 * "1 year" from 23 Oct 2026 ends on 23 Oct 2027, like the "+1 year" button.
	 */
	public static function term_end( $id, $start = '' ) {
		$level = self::get( $id );
		if ( ! $level || $level['is_base'] || 'lifetime' === $level['access'] ) {
			return '';
		}
		if ( 'fixed' === $level['access'] ) {
			return (string) $level['fixed_date'];
		}
		$start = NHM_Members::clean_date( $start ) ? $start : NHM_Settings::today();
		$d     = DateTimeImmutable::createFromFormat( '!Y-m-d', $start );
		$n     = max( 1, (int) $level['expire_n'] );
		$unit  = in_array( $level['expire_unit'], array( 'day', 'week', 'month', 'year' ), true ) ? $level['expire_unit'] : 'year';
		if ( 'month' === $unit || 'year' === $unit ) {
			// 31 Jan + 1 month → 28/29 Feb, not 3 Mar.
			$months = 'year' === $unit ? 12 * $n : $n;
			$day    = (int) $d->format( 'j' );
			$first  = $d->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
			$last   = (int) $first->format( 't' );
			return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $last ) )->format( 'Y-m-d' );
		}
		return $d->modify( '+' . $n . ' ' . $unit . ( $n > 1 ? 's' : '' ) )->format( 'Y-m-d' );
	}

	/**
	 * [start, last day] when $id is bought by someone whose current plan is $current_level
	 * until $current_expiry. Same level still running + early renewal allowed: the new term
	 * is added to the current last day and start is '' (keep the current start). Otherwise
	 * the term starts today.
	 */
	public static function renewal_dates( $id, $current_level, $current_expiry ) {
		$level = self::get( $id );
		$today = NHM_Settings::today();
		if ( $level && (int) $current_level === (int) $id && $current_expiry && $current_expiry >= $today && ! empty( $level['early_renew'] ) && 'expire' === $level['access'] ) {
			return array( '', self::term_end( $id, $current_expiry ) );
		}
		return array( $today, self::term_end( $id, $today ) );
	}

	/** "per year", "for 6 months", "lifetime", "until 31 Dec 2026". */
	public static function term_label( $id ) {
		$level = self::get( $id );
		if ( ! $level ) {
			return '';
		}
		if ( 'lifetime' === $level['access'] || $level['is_base'] ) {
			return __( 'lifetime', 'neurohr-members' );
		}
		if ( 'fixed' === $level['access'] ) {
			/* translators: %s: date */
			return $level['fixed_date'] ? sprintf( __( 'until %s', 'neurohr-members' ), NHM_Emails::format_date( $level['fixed_date'] ) ) : '';
		}
		$n     = max( 1, (int) $level['expire_n'] );
		$units = array(
			'day'   => array( __( 'day', 'neurohr-members' ), __( 'days', 'neurohr-members' ) ),
			'week'  => array( __( 'week', 'neurohr-members' ), __( 'weeks', 'neurohr-members' ) ),
			'month' => array( __( 'month', 'neurohr-members' ), __( 'months', 'neurohr-members' ) ),
			'year'  => array( __( 'year', 'neurohr-members' ), __( 'years', 'neurohr-members' ) ),
		);
		$u = isset( $units[ $level['expire_unit'] ] ) ? $units[ $level['expire_unit'] ] : $units['year'];
		if ( 1 === $n ) {
			/* translators: %s: unit, e.g. year */
			return sprintf( __( 'per %s', 'neurohr-members' ), $u[0] );
		}
		/* translators: 1: number, 2: unit */
		return sprintf( __( 'for %1$d %2$s', 'neurohr-members' ), $n, $u[1] );
	}

	/** "1 year", "2 years", "6 months", "lifetime", "until 31 Dec 2026" for $terms terms. */
	public static function duration_label( $id, $terms = 1 ) {
		$level = self::get( $id );
		if ( ! $level ) {
			return '';
		}
		if ( 'lifetime' === $level['access'] || $level['is_base'] ) {
			return __( 'lifetime', 'neurohr-members' );
		}
		if ( 'fixed' === $level['access'] ) {
			return self::term_label( $id );
		}
		$n     = max( 1, (int) $level['expire_n'] ) * max( 1, (int) $terms );
		$units = array(
			'day'   => array( __( 'day', 'neurohr-members' ), __( 'days', 'neurohr-members' ) ),
			'week'  => array( __( 'week', 'neurohr-members' ), __( 'weeks', 'neurohr-members' ) ),
			'month' => array( __( 'month', 'neurohr-members' ), __( 'months', 'neurohr-members' ) ),
			'year'  => array( __( 'year', 'neurohr-members' ), __( 'years', 'neurohr-members' ) ),
		);
		$u = isset( $units[ $level['expire_unit'] ] ) ? $units[ $level['expire_unit'] ] : $units['year'];
		return $n . ' ' . ( 1 === $n ? $u[0] : $u[1] );
	}

	/** Price text for the pricing table: own text, or "RM 299" from the amount. */
	public static function price_text( $id ) {
		$level = self::get( $id );
		if ( ! $level ) {
			return '';
		}
		if ( '' !== $level['price'] ) {
			return $level['price'];
		}
		return (float) $level['amount'] > 0 ? NHM_Settings::get( 'currency' ) . ' ' . rtrim( rtrim( number_format( (float) $level['amount'], 2, '.', ',' ), '0' ), '.' ) : __( 'Free', 'neurohr-members' );
	}

	public static function period_text( $id ) {
		$level = self::get( $id );
		if ( ! $level ) {
			return '';
		}
		if ( '' !== $level['period'] ) {
			return $level['period'];
		}
		return (float) $level['amount'] > 0 ? self::term_label( $id ) : '';
	}

	/** Registration link for a level: /register/?plan=pro (also works for members: it becomes an upgrade request). */
	public static function register_url( $id ) {
		$level = self::get( $id );
		$page  = NHM_Settings::page_url( 'register' );
		if ( ! $page ) {
			$page = home_url( '/' );
		}
		return $level ? add_query_arg( 'plan', rawurlencode( $level['slug'] ), $page ) : $page;
	}

	/** Accepts https://… / mailto: / tel: / site paths. No DNS lookups (some hosts reject valid URLs). */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return '/' . ltrim( sanitize_text_field( $url ), '/' );
		}
		return esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * The level that takes over a deleted level's content: the next level up the ladder,
	 * or the new top level when the deleted one was the highest.
	 */
	public static function successor( $id ) {
		$all   = self::all();
		$found = false;
		foreach ( $all as $level_id => $level ) {
			if ( $found ) {
				return (int) $level_id;
			}
			if ( (int) $level_id === (int) $id ) {
				$found = true;
			}
		}
		// Deleted the last one: take the level before it.
		$ids = array_keys( $all );
		$pos = array_search( (int) $id, $ids, true );
		return $pos > 0 ? (int) $ids[ $pos - 1 ] : self::base_id();
	}

	/**
	 * Delete a level. Members (people and companies) move to $members_to; content that
	 * required the level moves to the next level up (other plugins listen to nhm_level_deleted).
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id, $members_to ) {
		$id    = (int) $id;
		$level = self::get( $id );
		if ( ! $level ) {
			return new WP_Error( 'nhm_level', __( 'That level does not exist.', 'neurohr-members' ) );
		}
		if ( $level['is_base'] ) {
			return new WP_Error( 'nhm_level', __( 'The base level cannot be deleted. You can rename it.', 'neurohr-members' ) );
		}
		$members_to = (int) $members_to;
		if ( $members_to === $id || ! self::get( $members_to ) ) {
			return new WP_Error( 'nhm_level', __( 'Choose another level for its members.', 'neurohr-members' ) );
		}
		$content_to = self::successor( $id );

		// Move people and companies first, while the level still exists for the log.
		NHM_Members::move_level( $id, $members_to );
		NHM_Companies::move_level( $id, $members_to );

		$data  = self::data();
		$items = array();
		$drop  = false;
		foreach ( $data['items'] as $item ) {
			if ( (int) $item['id'] === $id ) {
				// The level after it takes its place on the ladder, not the place of the one before.
				$drop = empty( $item['same_as_prev'] );
				continue;
			}
			if ( $drop ) {
				$item['same_as_prev'] = 0;
				$drop                 = false;
			}
			$items[] = $item;
		}
		$data['items'] = $items;
		update_option( self::OPTION, $data );
		self::flush();

		NHM_Log::add( array( 'event' => 'level_deleted', 'level_from' => $id, 'level_to' => $members_to, 'message' => sprintf( 'Level "%s" deleted', $level['name'] ) ) );
		do_action( 'nhm_level_deleted', $id, $content_to, $members_to );
		return true;
	}
}
