<?php
/**
 * Table of contents and reading time for single resources.
 *
 * The same function adds anchors to the rendered content AND builds the list,
 * so the links always match. For locked resources the list is built from the
 * full text (headings only, no answers) and links go to the upgrade box.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Toc {

	/** Fewer headings than this: no table of contents. */
	const MIN_HEADINGS = 2;

	/**
	 * Add id="" anchors to H2/H3 and return array( 'html' => ..., 'headings' => [ [level, id, text], … ] ).
	 */
	public static function process( $html ) {
		$headings = array();
		$used     = array();

		$html = preg_replace_callback(
			'#<h([23])([^>]*)>(.*?)</h\1>#is',
			function ( $m ) use ( &$headings, &$used ) {
				$level = (int) $m[1];
				$attrs = $m[2];
				$text  = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $text ) {
					return $m[0];
				}

				if ( preg_match( '#\sid=(["\'])([^"\']+)\1#i', $attrs, $id_match ) ) {
					$id = $id_match[2];
				} else {
					$base = sanitize_title( $text );
					$base = '' !== $base ? $base : 'section';
					$id   = $base;
					$n    = 2;
					while ( isset( $used[ $id ] ) ) {
						$id = $base . '-' . $n++;
					}
					$attrs .= ' id="' . esc_attr( $id ) . '"';
				}
				$used[ $id ] = true;
				$headings[]  = array( $level, $id, $text );

				return '<h' . $level . $attrs . '>' . $m[3] . '</h' . $level . '>';
			},
			$html
		);

		return array( 'html' => $html, 'headings' => $headings );
	}

	/** Headings of the full (unrendered) text — used for locked resources. */
	public static function headings_from_post( $post ) {
		$post   = get_post( $post );
		$result = self::process( do_blocks( (string) $post->post_content ) );
		return $result['headings'];
	}

	public static function reading_minutes( $post ) {
		$post  = get_post( $post );
		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		return max( 1, (int) ceil( $words / 200 ) );
	}

	/**
	 * Render the list. $locked: items link to the upgrade box instead of the sections.
	 */
	public static function render( $headings, $locked = false ) {
		if ( count( $headings ) < self::MIN_HEADINGS ) {
			return '';
		}
		ob_start();
		?>
		<ol class="nh-toc__list">
			<?php foreach ( $headings as $h ) : ?>
				<li class="nh-toc__item nh-toc__item--h<?php echo (int) $h[0]; ?>">
					<a href="#<?php echo esc_attr( $locked ? 'nh-locked' : $h[1] ); ?>" data-target="<?php echo esc_attr( $h[1] ); ?>">
						<?php echo esc_html( $h[2] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		return ob_get_clean();
	}

	/** Highlights the section being read (desktop sidebar). */
	public static function print_script() {
		?>
		<script>
		( function () {
			var links = document.querySelectorAll( '.nh-toc--side .nh-toc__item a' );
			if ( ! links.length || ! ( 'IntersectionObserver' in window ) ) { return; }
			var map = {};
			links.forEach( function ( a ) { map[ a.getAttribute( 'data-target' ) ] = a; } );
			var current;
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( e ) {
					if ( e.isIntersecting && map[ e.target.id ] ) {
						if ( current ) { current.classList.remove( 'is-active' ); }
						current = map[ e.target.id ];
						current.classList.add( 'is-active' );
					}
				} );
			}, { rootMargin: '0px 0px -70% 0px' } );
			Object.keys( map ).forEach( function ( id ) {
				var el = document.getElementById( id );
				if ( el ) { io.observe( el ); }
			} );
		} )();
		</script>
		<?php
	}
}
