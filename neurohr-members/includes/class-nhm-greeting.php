<?php
/**
 * [nhm_greeting]: "Good morning, Marcus!" by the member's own time of day, and
 * [nhm_greeting part="line"]: one of the rotating lines, never the same twice in a row.
 * Texts and lines: Settings → Member Area → Greeting. Also used by the NeuroHR App plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Greeting {

	const LAST_LINE = 'nhm_greet_last';

	public static function init() {
		add_shortcode( 'nhm_greeting', array( __CLASS__, 'shortcode' ) );
	}

	public static function default_lines() {
		return array(
			"Here's what's new in Malaysian HR today.",
			'Ready to make HR a little easier today?',
			'Your next great HR decision starts here.',
			'Good HR is built one small step at a time.',
			"Let's turn today's HR questions into answers.",
			"Great teams start with great HR. You've got this.",
			'A quick read today can save a big headache tomorrow.',
			'Stay ahead of the latest employment law updates.',
			'Templates, guides and tools, all ready when you are.',
			'Every policy you get right makes your team stronger.',
			'Take a moment to explore something new today.',
			'HR made simpler, one resource at a time.',
			"Your people are your business. We're here to help.",
			'Clear policies, happy teams, fewer surprises.',
			'Small improvements in HR add up to big results.',
			'Have a question? The answer may be just a search away.',
			'Thanks for building a better workplace.',
			'Smart HR starts with the right knowledge.',
			"Let's make today a productive one.",
			"Good to see you again. Let's get started.",
		);
	}

	public static function lines() {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) NHM_Settings::get( 'greet_lines' ) ) ) ) );
		return $lines ? $lines : self::default_lines();
	}

	/** The member's time zone (My account), else the site's. */
	public static function timezone( $user_id ) {
		$tz = $user_id ? (string) get_user_meta( $user_id, 'nhm_tz', true ) : '';
		if ( '' !== $tz ) {
			try {
				return new DateTimeZone( $tz );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
		return wp_timezone();
	}

	public static function now( $user_id ) {
		return new DateTimeImmutable( 'now', self::timezone( $user_id ) );
	}

	/** morning 5–11 · afternoon 12–17 · evening 18–20 · late 21–4 */
	public static function part_of_day( $hour ) {
		$hour = (int) $hour;
		if ( $hour >= 5 && $hour < 12 ) {
			return 'morning';
		}
		if ( $hour >= 12 && $hour < 18 ) {
			return 'afternoon';
		}
		if ( $hour >= 18 && $hour < 21 ) {
			return 'evening';
		}
		return 'late';
	}

	public static function first_name( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$first = trim( (string) $user->first_name );
		if ( '' === $first ) {
			// "Marcus Lim" → Marcus; a single word (often the username) is not used.
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
			$first = count( $parts ) > 1 && false === strpos( $parts[0], '@' ) ? $parts[0] : '';
		}
		return $first;
	}

	public static function greeting( $user_id, $hour = null ) {
		$hour = null === $hour ? (int) self::now( $user_id )->format( 'G' ) : (int) $hour;
		$part = self::part_of_day( $hour );
		$fmt  = (string) NHM_Settings::get( 'greet_' . $part );
		$name = self::first_name( $user_id );
		if ( '' === $name ) {
			$fmt = preg_replace( '/,?\s*\{name\}/', '', $fmt );
		}
		return trim( str_replace( '{name}', $name, $fmt ) );
	}

	/** A random line, never the one this member saw last time. */
	public static function line( $user_id ) {
		$lines = self::lines();
		$last  = $user_id ? (int) get_user_meta( $user_id, self::LAST_LINE, true ) : -1;
		$pick  = array_keys( $lines );
		if ( count( $pick ) > 1 ) {
			$pick = array_values( array_diff( $pick, array( $last ) ) );
		}
		$i = $pick[ wp_rand( 0, count( $pick ) - 1 ) ];
		if ( $user_id ) {
			update_user_meta( $user_id, self::LAST_LINE, $i );
		}
		return $lines[ $i ];
	}

	/** [nhm_greeting] or [nhm_greeting part="line"] */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'part' => 'greeting', 'guest' => 'Welcome!' ), $atts, 'nhm_greeting' );
		$uid  = get_current_user_id();
		if ( 'line' === $atts['part'] ) {
			return '<span class="nhm-greeting-line">' . esc_html( self::line( $uid ) ) . '</span>';
		}
		return '<span class="nhm-greeting">' . esc_html( $uid ? self::greeting( $uid ) : $atts['guest'] ) . '</span>';
	}

}
