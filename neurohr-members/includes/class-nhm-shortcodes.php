<?php
/**
 * Shortcodes for the owner's own Elementor / Blocksy pages.
 *
 * [nhm_login] [nhm_register] [nhm_lost_password] [nhm_account] [nhm_pricing]
 * [nhm_if level="pro"]…[nhm_else]…[/nhm_if]  [nhm_user field="name"]  [nhm_logout]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Shortcodes {

	public static function init() {
		add_shortcode( 'nhm_login', array( 'NHM_Auth', 'render_login' ) );
		add_shortcode( 'nhm_register', array( 'NHM_Auth', 'render_register' ) );
		add_shortcode( 'nhm_invite', array( 'NHM_Auth', 'render_invite' ) );
		add_shortcode( 'nhm_lost_password', array( 'NHM_Auth', 'render_lost' ) );
		add_shortcode( 'nhm_account', array( 'NHM_Account', 'render' ) );
		add_shortcode( 'nhm_pricing', array( __CLASS__, 'pricing' ) );
		add_shortcode( 'nhm_if', array( __CLASS__, 'if_level' ) );
		add_shortcode( 'nhm_user', array( __CLASS__, 'user' ) );
		add_shortcode( 'nhm_logout', array( __CLASS__, 'logout' ) );
		add_shortcode( 'nhm_register_url', array( __CLASS__, 'register_url' ) );
		add_shortcode( 'nhm_plan_card', array( __CLASS__, 'plan_card' ) );
		add_shortcode( 'nhm_pay', array( 'NHM_Payments', 'shortcode' ) );
	}

	/**
	 * Pricing cards from the level settings.
	 * levels="lite,pro,corporate" (slugs, names or IDs; default: levels marked "show on pricing").
	 */
	/**
	 * [nhm_pricing] — the plans side by side (Members → Levels: order, texts, price, features).
	 *   levels="free,lite,pro,corporate"  which plans, in this order (default: all ticked "Show on the pricing table")
	 *   columns="4"                      cards a row on wide screens
	 *   note="All plans are billed yearly."  small text under the table
	 * Each card: badge, name, tagline, price (currency small), period, small note, description,
	 * features (lines starting with "-" are grey = not included), button. Logged-in members see
	 * "Your plan" / "Included" on their own and lower plans; higher plans open the upgrade request.
	 */
	public static function pricing( $atts ) {
		$atts = shortcode_atts( array( 'levels' => '', 'columns' => '', 'note' => '' ), $atts, 'nhm_pricing' );
		$list = array();
		if ( '' !== trim( $atts['levels'] ) ) {
			foreach ( explode( ',', $atts['levels'] ) as $ref ) {
				$level = NHM_Levels::resolve( trim( $ref ) );
				if ( $level ) {
					$list[] = $level;
				}
			}
		} else {
			foreach ( NHM_Levels::all() as $level ) {
				if ( ! empty( $level['in_pricing'] ) ) {
					$list[] = $level;
				}
			}
		}
		if ( ! $list ) {
			return '';
		}
		$m       = is_user_logged_in() ? NHM_Members::membership() : null;
		$columns = absint( $atts['columns'] ) ? absint( $atts['columns'] ) : min( 4, count( $list ) );
		$badges  = false;
		foreach ( $list as $level ) {
			$badges = $badges || '' !== (string) ( isset( $level['badge'] ) ? $level['badge'] : '' );
		}

		$h = '<div class="nhm-pricing' . ( $badges ? ' has-badges' : '' ) . '" style="--nhm-columns:' . (int) $columns . '">';
		foreach ( $list as $level ) {
			$level   = array_merge( array( 'tagline' => '', 'price_note' => '', 'badge' => '' ), $level );
			$classes = 'nhm-price-card nhm-price-card--' . sanitize_html_class( $level['slug'] );
			if ( $level['highlight'] ) {
				$classes .= ' is-highlight';
			}
			$current = $m && (int) $m['level'] === (int) $level['id'];
			if ( $current ) {
				$classes .= ' is-current';
			}
			$h .= '<div class="' . esc_attr( $classes ) . '">';
			$h .= '<div class="nhm-price-card__badge"' . ( '' === $level['badge'] ? ' aria-hidden="true"' : '' ) . '>' . esc_html( $level['badge'] ) . '</div>';
			$h .= '<div class="nhm-price-card__head"><h3 class="nhm-price-card__name">' . esc_html( $level['name'] ) . '</h3>';
			if ( '' !== $level['tagline'] ) {
				$h .= '<p class="nhm-price-card__tagline">' . esc_html( $level['tagline'] ) . '</p>';
			}
			$h .= '</div><div class="nhm-price-card__pricebox">';
			$price  = NHM_Levels::price_text( $level['id'] );
			$period = NHM_Levels::period_text( $level['id'] );
			if ( '' !== $price ) {
				// "RM 600" → small "RM" + big "600".
				if ( preg_match( '/^(\D*?)\s*([\d][\d,.]*)\s*(.*)$/u', $price, $pm ) && '' !== trim( $pm[1] ) ) {
					$amount = '<span class="nhm-price-card__currency">' . esc_html( trim( $pm[1] ) ) . '</span>' . esc_html( $pm[2] ) . ( '' !== trim( $pm[3] ) ? ' <span class="nhm-price-card__currency">' . esc_html( trim( $pm[3] ) ) . '</span>' : '' );
				} else {
					$amount = esc_html( $price );
				}
				$h .= '<p class="nhm-price-card__price"><span class="nhm-price-card__amount">' . $amount . '</span>';
				if ( '' !== $period ) {
					$h .= '<span class="nhm-price-card__period">' . esc_html( $period ) . '</span>';
				}
				$h .= '</p>';
			}
			if ( '' !== $level['price_note'] ) {
				$h .= '<p class="nhm-price-card__note">' . esc_html( $level['price_note'] ) . '</p>';
			}
			if ( NHM_Renew::level_ok( $level['id'] ) && NHM_Stripe::ready() ) {
				/* translators: %s: "every year" */
				$h .= '<p class="nhm-price-card__renew">' . esc_html( sprintf( __( 'Renews automatically %s. Cancel any time.', 'neurohr-members' ), NHM_Renew::every( $level['id'] ) ) ) . '</p>';
			}
			$h .= '</div>';
			// Every card has the same six parts, so the rows line up across the cards.
			$h       .= '<p class="nhm-price-card__desc">' . esc_html( $level['description'] ) . '</p>';
			$features = array_filter( array_map( 'trim', explode( "\n", (string) $level['features'] ) ), 'strlen' );
			$h       .= '<ul class="nhm-price-card__features">';
			if ( $features ) {
				foreach ( $features as $feature ) {
					$off  = 0 === strpos( $feature, '-' ) || 0 === strpos( $feature, '–' );
					$text = $off ? trim( ltrim( $feature, '-–' ) ) : ltrim( $feature, '+ ' );
					$text = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', esc_html( $text ) );
					$h   .= '<li class="' . ( $off ? 'is-off' : 'is-on' ) . '"><span class="screen-reader-text">' . ( $off ? esc_html__( 'Not included:', 'neurohr-members' ) : esc_html__( 'Included:', 'neurohr-members' ) ) . ' </span>' . $text . '</li>';
				}
			}
			$h .= '</ul>';
			$h .= '<p class="nhm-price-card__action">';
			if ( $current ) {
				$h .= '<span class="nhm-btn is-disabled">' . esc_html__( 'Your plan', 'neurohr-members' ) . '</span>';
			} elseif ( $m && $m['rank'] >= $level['rank'] ) {
				$h .= '<span class="nhm-btn is-disabled">' . esc_html__( 'Included', 'neurohr-members' ) . '</span>';
			} else {
				$url = $level['button_url'] ? $level['button_url'] : '';
				if ( ! $url && $level['is_base'] ) {
					$url = nhm_register_url();
				} elseif ( ! $url ) {
					// Registration for this level (members: an upgrade request with invoice).
					$url = NHM_Levels::register_url( $level['id'] );
				}
				if ( 0 === strpos( (string) $url, '/' ) && 0 !== strpos( (string) $url, '//' ) ) {
					$url = home_url( $url );
				}
				$label = $level['button_label'] ? $level['button_label'] : ( $level['is_base'] ? __( 'Sign up free', 'neurohr-members' ) : __( 'Choose plan', 'neurohr-members' ) );
				if ( $url ) {
					$h .= '<a class="nhm-btn' . ( $level['highlight'] ? ' nhm-btn--primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				}
			}
			$h .= '</p></div>';
		}
		$h .= '</div>';
		if ( '' !== trim( $atts['note'] ) ) {
			$h .= '<p class="nhm-pricing__note">' . esc_html( $atts['note'] ) . '</p>';
		}
		return NHM_Forms::wrap( $h, 'nhm-pricing-wrap' );
	}

	/**
	 * Show content only to some people.
	 *   level="pro"           Pro and above (by rank)
	 *   levels="lite,pro"     exactly these levels
	 *   guest="yes"           logged-out visitors only
	 *   logged_in="yes"       any logged-in person
	 *   not="yes"             the opposite
	 * [nhm_else] inside the shortcode separates the content for everyone else.
	 */
	public static function if_level( $atts, $content = '' ) {
		$atts  = shortcode_atts( array( 'level' => '', 'levels' => '', 'guest' => '', 'logged_in' => '', 'not' => '' ), $atts, 'nhm_if' );
		$yes   = function ( $v ) {
			return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true' ), true );
		};
		$match = true;
		if ( $yes( $atts['guest'] ) ) {
			$match = ! is_user_logged_in();
		} elseif ( $yes( $atts['logged_in'] ) ) {
			$match = is_user_logged_in();
		} elseif ( '' !== trim( $atts['levels'] ) ) {
			$current = NHM_Members::level();
			$match   = false;
			foreach ( explode( ',', $atts['levels'] ) as $ref ) {
				$level = NHM_Levels::resolve( $ref );
				if ( $level && is_user_logged_in() && (int) $level['id'] === $current ) {
					$match = true;
				}
			}
		} elseif ( '' !== trim( $atts['level'] ) ) {
			$match = is_user_logged_in() && NHM_Members::can( $atts['level'] );
		}
		if ( $yes( $atts['not'] ) ) {
			$match = ! $match;
		}
		$parts = preg_split( '/\[nhm_else\s*\/?\]/i', (string) $content, 2 );
		$out   = $match ? $parts[0] : ( isset( $parts[1] ) ? $parts[1] : '' );
		return do_shortcode( shortcode_unautop( trim( $out ) ) );
	}

	/** [nhm_user field="name|first_name|email|level|expiry|days_left|company"] */
	public static function user( $atts ) {
		$atts = shortcode_atts( array( 'field' => 'name', 'default' => '' ), $atts, 'nhm_user' );
		if ( ! is_user_logged_in() ) {
			return esc_html( $atts['default'] );
		}
		$user = wp_get_current_user();
		$m    = NHM_Members::membership();
		switch ( strtolower( $atts['field'] ) ) {
			case 'first_name':
				$parts = explode( ' ', trim( $user->display_name ) );
				$value = $parts[0];
				break;
			case 'email':
				$value = $user->user_email;
				break;
			case 'level':
				$value = $m['name'];
				break;
			case 'expiry':
				$value = $m['expiry'] ? NHM_Emails::format_date( $m['expiry'] ) : '';
				break;
			case 'days_left':
				$value = null === $m['days_left'] ? '' : (string) $m['days_left'];
				break;
			case 'status':
				$value = NHM_Account::status_text( $m );
				break;
			case 'company':
				$company = $m['company_id'] ? NHM_Companies::get( $m['company_id'] ) : null;
				$value   = $company ? $company['name'] : (string) get_user_meta( $user->ID, 'nhm_company_name', true );
				break;
			default:
				$value = $user->display_name;
		}
		return esc_html( '' !== (string) $value ? $value : $atts['default'] );
	}

	/**
	 * [nhm_plan_card] — the member's plan for a dashboard: plan, company, last day, days left,
	 * Renew (30 days before the end or after it) and Upgrade (below the top plan) buttons,
	 * unpaid-invoice notice. style="light" for a white card, title="" to hide the small title.
	 * Visitors who are not logged in see nothing (or text="…" with a login link).
	 */
	public static function plan_card( $atts ) {
		$atts = shortcode_atts( array( 'style' => 'dark', 'title' => __( 'Your plan', 'neurohr-members' ), 'text' => '' ), $atts, 'nhm_plan_card' );
		if ( ! is_user_logged_in() ) {
			return '' === $atts['text'] ? '' : NHM_Forms::wrap( '<p><a href="' . esc_url( nhm_login_url( NHM_Forms::current_url() ) ) . '">' . esc_html( $atts['text'] ) . '</a></p>', 'nhm-plan-card' );
		}
		NHM_Forms::no_cache();
		return NHM_Forms::wrap( NHM_Account::plan_card( array( 'style' => $atts['style'], 'title' => $atts['title'] ) ), 'nhm-plan-card' );
	}

	/** [nhm_register_url level="pro"] — the link only, for an Elementor button's link field. */
	public static function register_url( $atts ) {
		$atts  = shortcode_atts( array( 'level' => '' ), $atts, 'nhm_register_url' );
		$level = NHM_Levels::resolve( $atts['level'] );
		return esc_url( $level && ! $level['is_base'] ? NHM_Levels::register_url( $level['id'] ) : nhm_register_url() );
	}

	/** [nhm_logout text="Log out" redirect="/"] */
	public static function logout( $atts ) {
		$atts = shortcode_atts( array( 'text' => __( 'Log out', 'neurohr-members' ), 'redirect' => '', 'class' => 'nhm-logout-link' ), $atts, 'nhm_logout' );
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$redirect = $atts['redirect'] ? NHM_Levels::clean_url( $atts['redirect'] ) : '';
		if ( $redirect && 0 === strpos( $redirect, '/' ) ) {
			$redirect = home_url( $redirect );
		}
		return '<a class="' . esc_attr( $atts['class'] ) . '" href="' . esc_url( wp_logout_url( $redirect ) ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}
}
