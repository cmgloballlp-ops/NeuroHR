<?php
/**
 * Resource Types and what they behave like.
 *
 * A Resource Type (Knowledge Hub → Resource Types) can be renamed — name and slug — freely:
 * the hub knows types by their ID. What a type DOES is its behaviour ("kind"), chosen in
 * Settings → Type defaults:
 *   article     reading (reading time, table of contents)
 *   case-study  legal case (reading, legal-case references)
 *   faq         question & answer (open/close lists, FAQ anywhere)
 *   video       video (video box and player)
 *   download    template download (protected template file)
 * Stored in term meta nh_kind. Types made before 0.12 get their kind from their old slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Types {

	const META = 'nh_kind';

	/** Kind => label. The kind codes are the original type slugs (CSS classes use them). */
	public static function kinds() {
		return array(
			'article'    => __( 'Article (reading)', 'neurohr-hub' ),
			'case-study' => __( 'Legal case', 'neurohr-hub' ),
			'faq'        => __( 'FAQ (question & answer)', 'neurohr-hub' ),
			'video'      => __( 'Video', 'neurohr-hub' ),
			'download'   => __( 'Template download', 'neurohr-hub' ),
		);
	}

	/** Slugs and names a type of each kind had before 0.12 (also the owner's renames of Nov 2026). */
	private static function known() {
		return array(
			'article'    => array( 'article', 'hr-insights', 'Article', 'HR Insights' ),
			'case-study' => array( 'case-study', 'legal-case-studies', 'Case Study', 'Legal Case Studies' ),
			'faq'        => array( 'faq', 'hr-faqs', 'FAQ', 'HR FAQs' ),
			'video'      => array( 'video', 'video-learning', 'Video', 'Video Learning' ),
			'download'   => array( 'download', 'hr-templates', 'Download', 'HR Templates' ),
		);
	}

	/** Every Resource Type term, in kind order then name. */
	public static function terms() {
		$terms = get_terms( array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'hide_empty' => false ) );
		$terms = is_array( $terms ) ? $terms : array();
		$order = array_flip( array_keys( self::kinds() ) );
		usort(
			$terms,
			function ( $a, $b ) use ( $order ) {
				$x = $order[ self::kind( $a ) ];
				$y = $order[ self::kind( $b ) ];
				return $x === $y ? strcasecmp( $a->name, $b->name ) : $x - $y;
			}
		);
		return $terms;
	}

	/** Kind of a type term (object or ID). */
	public static function kind( $term ) {
		$term = is_object( $term ) ? $term : get_term( (int) $term, NH_Post_Types::TAX_TYPE );
		if ( ! $term || is_wp_error( $term ) ) {
			return 'article';
		}
		$kind = (string) get_term_meta( $term->term_id, self::META, true );
		if ( isset( self::kinds()[ $kind ] ) ) {
			return $kind;
		}
		foreach ( self::known() as $k => $names ) {
			if ( in_array( $term->slug, $names, true ) || in_array( $term->name, $names, true ) ) {
				return $k;
			}
		}
		return 'article';
	}

	public static function set_kind( $term_id, $kind ) {
		if ( isset( self::kinds()[ $kind ] ) ) {
			update_term_meta( (int) $term_id, self::META, $kind );
		}
	}

	/** Kind of a resource (its first type). */
	public static function of_post( $post ) {
		$type = nh_hub_get_type( $post );
		return $type ? self::kind( $type ) : 'article';
	}

	/** Type term IDs of a kind. */
	public static function ids_of_kind( $kind ) {
		$out = array();
		foreach ( self::terms() as $term ) {
			if ( self::kind( $term ) === $kind ) {
				$out[] = (int) $term->term_id;
			}
		}
		return $out;
	}

	/** tax_query clause: resources whose type has this kind. */
	public static function kind_clause( $kind ) {
		$ids = self::ids_of_kind( $kind );
		return array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'field' => 'term_id', 'terms' => $ids ? $ids : array( 0 ) );
	}

	/**
	 * The first type of a kind; finds a renamed one before creating a new one,
	 * so renaming a type never brings back an empty copy of the old one.
	 */
	public static function find_or_create( $kind ) {
		$ids = self::ids_of_kind( $kind );
		if ( $ids ) {
			self::set_kind( $ids[0], $kind ); // Remember it, so a later rename cannot lose it.
			return $ids[0];
		}
		$names = self::known()[ $kind ];
		$made  = wp_insert_term( $names[2], NH_Post_Types::TAX_TYPE, array( 'slug' => $names[0] ) );
		if ( is_wp_error( $made ) ) {
			$existing = get_term_by( 'slug', $names[0], NH_Post_Types::TAX_TYPE );
			return $existing ? (int) $existing->term_id : 0;
		}
		self::set_kind( $made['term_id'], $kind );
		return (int) $made['term_id'];
	}
}
