<?php
/**
 * Access control: which level a resource needs and whether a user has it.
 *
 * Levels come from NeuroHR Members when it is active (nhm_levels()): each level has a fixed
 * ID (stored in _nh_min_level and the type defaults) and a rank (its place on the ladder).
 * Without NeuroHR Members the old fixed ladder is used, read from MemberPress:
 *   1 = Free (any logged-in user), 2 = Lite, 3 = Pro, 4 = Corporate / Consulting.
 * 0 = Public (anyone, including guests) in both cases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Access {

	const META          = '_nh_min_level';
	const OPTION_MAP    = 'nh_hub_level_map';
	const OPTION_TYPES  = 'nh_hub_type_levels';
	const OPTION_PRICE  = 'nh_hub_pricing_url';

	/** Old fixed ladder (only used without NeuroHR Members). */
	const MAX_LEVEL     = 4;

	/** Old "fail closed" level. Use default_level(): the top of the current ladder. */
	const DEFAULT_LEVEL = 4;

	private static $user_levels = array();
	private static $levels      = null;
	private static $in_gate     = false;

	public static function init() {
		// Late priority so we replace the fully rendered content (incl. shortcodes/builders).
		// the_content also runs for REST "content.rendered" and RSS feeds, so those are gated too.
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_action( 'nhm_level_deleted', array( __CLASS__, 'level_deleted' ), 10, 2 );
		add_action( 'nhm_levels_saved', array( __CLASS__, 'flush' ) );
	}

	/* ---------- The ladder ---------- */

	/** True when NeuroHR Members supplies the levels. */
	public static function members_active() {
		return function_exists( 'nhm_levels' ) && function_exists( 'nhm_user_level' );
	}

	public static function flush() {
		self::$levels      = null;
		self::$user_levels = array();
	}

	/**
	 * Ordered levels (without Public): ID => array( 'name' => …, 'rank' => … ).
	 */
	public static function levels() {
		if ( null !== self::$levels ) {
			return self::$levels;
		}
		$out = array();
		if ( self::members_active() ) {
			foreach ( nhm_levels() as $id => $level ) {
				$out[ (int) $id ] = array( 'name' => $level['name'], 'rank' => (int) $level['rank'] );
			}
		}
		if ( ! $out ) {
			$out = array(
				1 => array( 'name' => __( 'Free', 'neurohr-hub' ), 'rank' => 1 ),
				2 => array( 'name' => __( 'Lite', 'neurohr-hub' ), 'rank' => 2 ),
				3 => array( 'name' => __( 'Pro', 'neurohr-hub' ), 'rank' => 3 ),
				4 => array( 'name' => __( 'Corporate', 'neurohr-hub' ), 'rank' => 4 ),
			);
		}
		self::$levels = $out;
		return $out;
	}

	/** Rank of a level ID. 0 = Public. Unknown IDs are treated as the very top (fail closed). */
	public static function rank( $level ) {
		$level = (int) $level;
		if ( 0 === $level ) {
			return 0;
		}
		$levels = self::levels();
		return isset( $levels[ $level ] ) ? $levels[ $level ]['rank'] : PHP_INT_MAX;
	}

	public static function max_rank() {
		$max = 0;
		foreach ( self::levels() as $level ) {
			$max = max( $max, $level['rank'] );
		}
		return $max;
	}

	/** ID of the base level (every logged-in user). */
	public static function base_level() {
		$levels = self::levels();
		reset( $levels );
		return (int) key( $levels );
	}

	/** Fail-closed level: the first level at the top of the ladder. */
	public static function default_level() {
		$max = self::max_rank();
		foreach ( self::levels() as $id => $level ) {
			if ( $level['rank'] === $max ) {
				return (int) $id;
			}
		}
		return self::base_level();
	}

	/** A stored value as a level ID that exists (0 = Public). Unknown → top level. */
	public static function valid_level( $level ) {
		$level = absint( $level );
		if ( 0 === $level ) {
			return 0;
		}
		$levels = self::levels();
		return isset( $levels[ $level ] ) ? $level : self::default_level();
	}

	/* ---------- MemberPress fallback ---------- */

	/**
	 * MemberPress membership ID => level. Used until the settings page is saved.
	 */
	public static function default_map() {
		return array(
			6366 => 1, // NeuroHR Free
			6373 => 2, // NeuroHR Lite
			6376 => 3, // NeuroHR Pro
			6379 => 4, // NeuroHR Corporate
			6382 => 4, // NeuroHR Consulting
		);
	}

	public static function get_map() {
		$map = get_option( self::OPTION_MAP, null );
		if ( ! is_array( $map ) ) {
			$map = self::default_map();
		}
		$clean = array();
		foreach ( $map as $membership_id => $level ) {
			$clean[ absint( $membership_id ) ] = min( self::MAX_LEVEL, absint( $level ) );
		}
		return $clean;
	}

	public static function memberpress_active() {
		return class_exists( 'MeprUser' );
	}

	/* ---------- Users ---------- */

	/**
	 * Level ID of the user's plan (0 = guest). Editors and admins get the top level.
	 * Result is cached for the request.
	 */
	public static function get_user_level( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return 0;
		}
		if ( isset( self::$user_levels[ $user_id ] ) ) {
			return self::$user_levels[ $user_id ];
		}

		// Any logged-in user has at least the base level (Free).
		$level = self::base_level();

		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			// Editors and admins see everything so they can check content.
			$level = self::default_level();
		} elseif ( self::members_active() ) {
			$level = (int) nhm_user_level( $user_id );
		} elseif ( self::memberpress_active() ) {
			$mepr_user = new MeprUser( $user_id );
			$ids       = $mepr_user->active_product_subscriptions( 'ids' );
			$map       = self::get_map();

			foreach ( (array) $ids as $membership_id ) {
				$membership_id = (int) $membership_id;
				if ( isset( $map[ $membership_id ] ) && self::rank( $map[ $membership_id ] ) > self::rank( $level ) ) {
					$level = $map[ $membership_id ];
				}
			}
		}

		$level  = (int) apply_filters( 'nh_hub_user_level', $level, $user_id );
		$levels = self::levels();
		if ( ! isset( $levels[ $level ] ) ) {
			$level = self::base_level();
		}

		self::$user_levels[ $user_id ] = $level;
		return $level;
	}

	/** Rank of the user's plan (0 = guest). */
	public static function get_user_rank( $user_id = null ) {
		$level = self::get_user_level( $user_id );
		return $level ? self::rank( $level ) : 0;
	}

	/* ---------- Resources ---------- */

	/**
	 * Resource type slug => default level, used when a resource has no own level.
	 */
	public static function default_type_levels() {
		return array(
			'article'    => 2,
			'case-study' => 2,
			'faq'        => 2,
			'video'      => 3,
			'download'   => 3,
		);
	}

	public static function get_type_levels() {
		$levels = get_option( self::OPTION_TYPES, null );
		if ( ! is_array( $levels ) ) {
			$levels = array();
		}
		// Saved values win; types missing from the option fall back to the built-in default.
		$levels = array_merge( self::default_type_levels(), $levels );
		return array_map( array( __CLASS__, 'valid_level' ), $levels );
	}

	/**
	 * True when the resource has its own level (not inherited from its type).
	 */
	public static function has_own_level( $post ) {
		$post = get_post( $post );
		return $post && '' !== get_post_meta( $post->ID, self::META, true );
	}

	/**
	 * Level inherited from the resource's type. Several types: the strictest wins.
	 */
	public static function get_type_level( $post ) {
		$terms = get_the_terms( $post, NH_Post_Types::TAX_TYPE );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return self::default_level();
		}
		$levels = self::get_type_levels();
		$level  = null;
		foreach ( $terms as $term ) {
			$type_level = isset( $levels[ $term->slug ] ) ? $levels[ $term->slug ] : self::default_level();
			if ( null === $level || self::rank( $type_level ) > self::rank( $level ) ) {
				$level = $type_level;
			}
		}
		return $level;
	}

	/** Level ID a resource needs (0 = Public). */
	public static function get_post_level( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return self::default_level();
		}
		$raw   = get_post_meta( $post->ID, self::META, true );
		$level = '' === $raw ? self::get_type_level( $post ) : self::valid_level( $raw );
		// With login required for the whole hub, "Public" (0) is the same as Free.
		return ( 0 === $level && NH_Login::required() ) ? self::base_level() : $level;
	}

	public static function can_view( $post, $user_id = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( NH_Post_Types::CPT !== $post->post_type ) {
			return true;
		}
		return self::get_user_rank( $user_id ) >= self::rank( self::get_post_level( $post ) );
	}

	/**
	 * Sign-up / upgrade page for a level: NeuroHR Members' upgrade link, or the first
	 * MemberPress membership mapped to it. Falls back to the pricing URL on the settings page.
	 */
	public static function upgrade_url( $level ) {
		$url = '';
		if ( self::members_active() && function_exists( 'nhm_upgrade_url' ) ) {
			// The free level is reached by registering, not by upgrading.
			$url = ( (int) $level === self::base_level() && ! is_user_logged_in() && function_exists( 'nhm_register_url' ) )
				? nhm_register_url()
				: nhm_upgrade_url( (int) $level ? (int) $level : null );
		} else {
			foreach ( self::get_map() as $membership_id => $mapped_level ) {
				if ( (int) $mapped_level === (int) $level ) {
					$link = get_permalink( $membership_id );
					if ( $link ) {
						$url = $link;
						break;
					}
				}
			}
		}
		if ( ! $url ) {
			$url = get_option( self::OPTION_PRICE, '' );
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		return apply_filters( 'nh_hub_upgrade_url', $url, $level );
	}

	/**
	 * A level was deleted in NeuroHR Members: resources and type defaults that needed it
	 * now need the next level up.
	 */
	public static function level_deleted( $deleted, $content_to ) {
		global $wpdb;
		self::flush();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::META, (string) (int) $deleted ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META, (int) $content_to );
		}
		$types = get_option( self::OPTION_TYPES, null );
		if ( is_array( $types ) ) {
			foreach ( $types as $slug => $level ) {
				if ( (int) $level === (int) $deleted ) {
					$types[ $slug ] = (int) $content_to;
				}
			}
			update_option( self::OPTION_TYPES, $types );
		} else {
			$types = self::default_type_levels();
			if ( in_array( (int) $deleted, $types, true ) ) {
				foreach ( $types as $slug => $level ) {
					if ( (int) $level === (int) $deleted ) {
						$types[ $slug ] = (int) $content_to;
					}
				}
				update_option( self::OPTION_TYPES, $types );
			}
		}
	}

	public static function filter_content( $content ) {
		if ( self::$in_gate ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || NH_Post_Types::CPT !== $post->post_type ) {
			return $content;
		}
		if ( self::can_view( $post ) ) {
			return $content;
		}

		self::$in_gate = true;
		$html          = self::locked_html( $post );
		self::$in_gate = false;

		return $html;
	}

	/**
	 * Teaser + call to action shown instead of locked content.
	 * Builds the excerpt directly (not via get_the_excerpt) to avoid filter recursion.
	 */
	public static function locked_html( $post ) {
		$required = self::get_post_level( $post );
		$teaser   = nh_hub_excerpt( $post, 40 );
		$label    = nh_hub_level_label( $required );

		ob_start();
		?>
		<div class="nh-teaser"><p><?php echo esc_html( $teaser ); ?></p></div>
		<div class="nh-locked" id="nh-locked">
			<p class="nh-locked__title">
				<?php
				/* translators: %s: membership level name */
				echo esc_html( sprintf( __( 'This resource is available to %s members and above.', 'neurohr-hub' ), $label ) );
				?>
			</p>
			<div class="nh-locked__actions">
				<?php if ( ! is_user_logged_in() ) : ?>
					<a class="nh-btn nh-btn--primary" href="<?php echo esc_url( NH_Login::login_url( get_permalink( $post ) ) ); ?>"><?php esc_html_e( 'Log in', 'neurohr-hub' ); ?></a>
					<a class="nh-btn" data-nh-upgrade href="<?php echo esc_url( self::upgrade_url( $required ? $required : self::base_level() ) ); ?>">
						<?php
						echo self::rank( $required ) <= self::rank( self::base_level() )
							? esc_html__( 'Create a free account', 'neurohr-hub' )
							/* translators: %s: membership level name */
							: esc_html( sprintf( __( 'Join %s', 'neurohr-hub' ), $label ) );
						?>
					</a>
				<?php else : ?>
					<a class="nh-btn nh-btn--primary" data-nh-upgrade href="<?php echo esc_url( self::upgrade_url( $required ) ); ?>">
						<?php
						/* translators: %s: membership level name */
						echo esc_html( sprintf( __( 'Upgrade to %s', 'neurohr-hub' ), $label ) );
						?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
