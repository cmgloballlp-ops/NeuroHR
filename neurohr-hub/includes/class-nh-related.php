<?php
/**
 * References between resources.
 *
 * - Picked in the editor ("References" box), any section, as many as needed. Shown under the
 *   resource grouped by kind: further reading, legal cases, videos, templates, questions.
 * - Inline cards: [nh_ref id="123"] inside the text shows a small card for that resource
 *   (e.g. "Download: Termination letter template") right where it is mentioned.
 * - Backlinks: a resource lists where it is referenced ("Referenced in"), from picks and
 *   inline cards alike. Kept in _nh_ref (one row per referenced resource) for fast lookup.
 * - "You may also like": automatic suggestions sharing a topic or tag; can be switched off
 *   per resource.
 * Every item checks the member's level: locked items show a lock, never their content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Related {

	const META        = '_nh_related';           // Ordered IDs picked in the editor.
	const REF_META    = '_nh_ref';               // One row per referenced ID (picks + inline cards).
	const AUTO_OFF    = '_nh_related_auto_off';  // '1' = no automatic suggestions.
	const ATTACH_META = '_nh_attachments';
	const NONCE       = 'nh_hub_related_nonce';
	const MAX         = 40;

	public static function init() {
		add_shortcode( 'nh_ref', array( __CLASS__, 'sc_ref' ) );
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'sync_refs' ), 20, 2 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 10, 2 );
			add_action( 'wp_ajax_nh_hub_search', array( __CLASS__, 'ajax_search' ) );
		}
	}

	/* ---------- Data ---------- */

	public static function get_manual( $post_id ) {
		$ids = get_post_meta( $post_id, self::META, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	private static function is_live( $id, $exclude = 0 ) {
		return $id && $id !== (int) $exclude && NH_Post_Types::CPT === get_post_type( $id ) && 'publish' === get_post_status( $id );
	}

	/** Picked references that are published, in the editor's order. */
	public static function get_picked( $post_id ) {
		$out = array();
		foreach ( self::get_manual( $post_id ) as $id ) {
			if ( self::is_live( $id, $post_id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Picked references grouped for display: section key => array( 'title' => …, 'ids' => … ),
	 * in a fixed order (reading first, then cases, videos, templates, questions).
	 */
	public static function grouped( $post_id ) {
		$titles = array(
			'article'    => __( 'Further Reading', 'neurohr-hub' ),
			'case-study' => __( 'Related Legal Cases', 'neurohr-hub' ),
			'video'      => __( 'Related Videos', 'neurohr-hub' ),
			'download'   => __( 'Templates to Download', 'neurohr-hub' ),
			'faq'        => __( 'Related Questions', 'neurohr-hub' ),
		);
		$groups = array();
		foreach ( $titles as $kind => $title ) {
			$groups[ $kind ] = array( 'title' => $title, 'ids' => array() );
		}
		foreach ( self::get_picked( $post_id ) as $id ) {
			$groups[ NH_Types::of_post( $id ) ]['ids'][] = $id;
		}
		return array_filter( $groups, function ( $g ) { return (bool) $g['ids']; } );
	}

	/** Automatic suggestions, not repeating picked ones. Empty when switched off. */
	public static function suggestions( $post_id, $limit = 4 ) {
		if ( get_post_meta( $post_id, self::AUTO_OFF, true ) ) {
			return array();
		}
		return self::get_auto( $post_id, $limit, self::get_picked( $post_id ) );
	}

	/** Old API (shortcodes, other plugins): picks first, then suggestions, up to $limit. */
	public static function get_ids( $post_id, $limit = 4 ) {
		$ids = array_slice( self::get_picked( $post_id ), 0, $limit );
		if ( count( $ids ) < $limit ) {
			$ids = array_merge( $ids, self::get_auto( $post_id, $limit - count( $ids ), $ids ) );
		}
		return $ids;
	}

	/** Published resources that reference this one (picked or inline card). */
	public static function backlinks( $post_id, $limit = 12 ) {
		$ids = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__not_in'   => array( (int) $post_id ),
				'meta_key'       => self::REF_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) (int) $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/** IDs used in [nh_ref id="…"] inside a text. */
	public static function inline_ids( $content ) {
		$ids = array();
		if ( preg_match_all( '/\[nh_ref\s[^\]]*\bid=["\']?(\d+)/', (string) $content, $m ) ) {
			$ids = array_map( 'absint', $m[1] );
		}
		return $ids;
	}

	/** Rebuild the _nh_ref rows of one resource (picks + inline cards). */
	public static function sync_refs( $post_id, $post = null ) {
		$post = get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post_id ) || NH_Post_Types::CPT !== $post->post_type ) {
			return;
		}
		$ids = array_unique( array_merge( self::get_manual( $post_id ), self::inline_ids( $post->post_content ) ) );
		delete_post_meta( $post_id, self::REF_META );
		foreach ( $ids as $id ) {
			if ( $id && $id !== (int) $post_id ) {
				add_post_meta( $post_id, self::REF_META, (string) $id );
			}
		}
	}

	/**
	 * Automatic suggestions ranked by overlap: a shared topic counts 3, each shared tag 1.
	 * Ties go to the newer resource.
	 */
	private static function get_auto( $post_id, $limit, $exclude ) {
		// Related resources may come from any section: a case can suggest an article and back.
		$hr_topics   = wp_get_object_terms( $post_id, NH_Post_Types::TAX_TOPIC, array( 'fields' => 'ids' ) );
		$case_topics = wp_get_object_terms( $post_id, NH_Post_Types::TAX_CASE_TOPIC, array( 'fields' => 'ids' ) );
		$tags        = wp_get_object_terms( $post_id, NH_Post_Types::TAX_TAG, array( 'fields' => 'ids' ) );
		$topics      = array_merge( is_wp_error( $hr_topics ) ? array() : $hr_topics, is_wp_error( $case_topics ) ? array() : $case_topics );
		$tags        = is_wp_error( $tags ) ? array() : $tags;
		if ( $limit < 1 || ( ! $topics && ! $tags ) ) {
			return array();
		}

		$tax_query = array( 'relation' => 'OR' );
		if ( $hr_topics && ! is_wp_error( $hr_topics ) ) {
			$tax_query[] = array( 'taxonomy' => NH_Post_Types::TAX_TOPIC, 'terms' => $hr_topics );
		}
		if ( $case_topics && ! is_wp_error( $case_topics ) ) {
			$tax_query[] = array( 'taxonomy' => NH_Post_Types::TAX_CASE_TOPIC, 'terms' => $case_topics );
		}
		if ( $tags ) {
			$tax_query[] = array( 'taxonomy' => NH_Post_Types::TAX_TAG, 'terms' => $tags );
		}

		$candidates = get_posts(
			array(
				'post_type'              => NH_Post_Types::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => 60,
				'post__not_in'           => array_merge( array( $post_id ), $exclude, NH_Lang::siblings( $post_id ) ),
				'no_found_rows'          => true,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_meta_cache' => false,
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- capped at 60.
			)
		);

		$scores = array();
		foreach ( $candidates as $rank => $candidate ) {
			$c_topics = array_merge( self::term_ids( $candidate, NH_Post_Types::TAX_TOPIC ), self::term_ids( $candidate, NH_Post_Types::TAX_CASE_TOPIC ) );
			$c_tags   = self::term_ids( $candidate, NH_Post_Types::TAX_TAG );
			$score    = 3 * count( array_intersect( $topics, $c_topics ) ) + count( array_intersect( $tags, $c_tags ) );
			// Newer first on equal score: candidates are already sorted by date.
			$scores[ $candidate->ID ] = $score * 1000 - $rank;
		}
		arsort( $scores );
		$picked = array_slice( array_keys( $scores ), 0, $limit );

		// Keep one slot for the best match from another section (e.g. an article on a case page),
		// since sections no longer share topics and same-section items would otherwise fill every slot.
		$own       = NH_Sections::of_post( $post_id );
		$has_other = false;
		foreach ( $picked as $id ) {
			if ( NH_Sections::of_post( $id ) !== $own ) {
				$has_other = true;
				break;
			}
		}
		if ( ! $has_other && $limit > 1 && count( $picked ) === $limit ) {
			foreach ( array_keys( $scores ) as $id ) {
				if ( $scores[ $id ] >= 1000 && NH_Sections::of_post( $id ) !== $own ) { // Shares at least a tag.
					$picked[ $limit - 1 ] = $id;
					break;
				}
			}
		}
		return $picked;
	}

	/** Term IDs of a post (empty array when it has none). Uses the term cache. */
	private static function term_ids( $post, $taxonomy ) {
		$terms = get_the_terms( $post, $taxonomy );
		return ( empty( $terms ) || is_wp_error( $terms ) ) ? array() : wp_list_pluck( $terms, 'term_id' );
	}

	/**
	 * Attachments carried over from BetterDocs: list of [title, url].
	 * Shown only to users who can read the resource (see single template).
	 */
	public static function get_attachments( $post_id ) {
		return NH_Attachments::items( $post_id ); // Kept for older code; see NH_Attachments.
	}

	/* ---------- Front end ---------- */

	/** Everything under a resource: grouped picks, where it is referenced, suggestions. */
	public static function render_sections( $post_id ) {
		ob_start();
		foreach ( self::grouped( $post_id ) as $key => $group ) {
			?>
			<section class="nh-related nh-related--<?php echo esc_attr( $key ); ?>">
				<h2 class="nh-section__title"><?php echo esc_html( $group['title'] ); ?></h2>
				<div class="nh-grid nh-grid--related">
					<?php foreach ( $group['ids'] as $id ) : ?>
						<?php nh_hub_render_card( $id ); ?>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}

		// "Referenced in" is not shown to readers (it repeated picked items); editors see it in the References box.

		$auto = self::suggestions( $post_id, 4 );
		if ( $auto ) {
			?>
			<section class="nh-related nh-related--auto">
				<h2 class="nh-section__title"><?php esc_html_e( 'You May Also Like', 'neurohr-hub' ); ?></h2>
				<div class="nh-grid nh-grid--related">
					<?php foreach ( $auto as $id ) : ?>
						<?php nh_hub_render_card( $id ); ?>
					<?php endforeach; ?>
				</div>
			</section>
			<?php
		}
		return ob_get_clean();
	}

	/** What the card's button says for each kind of resource. */
	private static function action_label( $id ) {
		switch ( NH_Types::of_post( $id ) ) {
			case 'video':
				return __( 'Watch', 'neurohr-hub' );
			case 'download':
				return __( 'Download', 'neurohr-hub' );
			case 'case-study':
				return __( 'Read the case', 'neurohr-hub' );
			case 'faq':
				return __( 'See the answer', 'neurohr-hub' );
		}
		return __( 'Read', 'neurohr-hub' );
	}

	/**
	 * [nh_ref id="123"] — inline card for another resource, inside any text.
	 * Optional: label="Download the letter" (button text).
	 */
	public static function sc_ref( $atts ) {
		static $depth = 0;
		$atts = shortcode_atts( array( 'id' => 0, 'label' => '' ), $atts, 'nh_ref' );
		$id   = absint( $atts['id'] );
		if ( ! self::is_live( $id ) || $depth > 0 ) {
			return '';
		}
		$depth++;
		NH_Display::ensure_assets();
		$can     = NH_Access::can_view( $id );
		$level   = NH_Access::get_post_level( $id );
		$section = NH_Sections::get( NH_Sections::of_post( $id ) );
		$label   = '' !== $atts['label'] ? $atts['label'] : self::action_label( $id );
		if ( ! $can ) {
			$label = is_user_logged_in()
				/* translators: %s: level */
				? sprintf( __( 'For %s members', 'neurohr-hub' ), nh_hub_level_label( $level ) )
				: __( 'Members only — log in', 'neurohr-hub' );
		}
		ob_start();
		?>
		<span class="nh-hub nh-refcard-wrap"><a class="nh-refcard nh-refcard--<?php echo esc_attr( NH_Sections::of_post( $id ) ); ?><?php echo $can ? '' : ' is-locked'; ?>" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
			<span class="nh-refcard__kind"><?php echo esc_html( $section ? $section['label'] : '' ); ?></span>
			<span class="nh-refcard__title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
			<span class="nh-refcard__excerpt"><?php echo esc_html( nh_hub_excerpt( $id, 18 ) ); ?></span>
			<span class="nh-refcard__action"><?php echo $can ? '' : '&#128274; '; ?><?php echo esc_html( $label ); ?> &rarr;</span>
		</a></span>
		<?php
		$depth--;
		return trim( ob_get_clean() );
	}

	/* ---------- Editor ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_related', __( 'References', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'normal', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_related', self::NONCE );
		$selected = self::get_manual( $post->ID );
		$back     = self::backlinks( $post->ID, 50 );
		?>
		<p class="description"><?php esc_html_e( 'Resources to show under this one, grouped as Further Reading, Related Legal Cases, Related Videos, Templates to Download and Related Questions. "Copy card" gives a code to paste into the text, which shows that resource as a small card right there.', 'neurohr-hub' ); ?></p>
		<ul id="nh-related-list" class="nh-ref-picks">
			<?php foreach ( $selected as $id ) : ?>
				<?php if ( get_post( $id ) ) : ?>
					<?php self::render_item( $id ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
		<div class="nh-ref-search">
			<select id="nh-related-type" aria-label="<?php esc_attr_e( 'Kind', 'neurohr-hub' ); ?>">
				<option value=""><?php esc_html_e( 'All kinds', 'neurohr-hub' ); ?></option>
				<?php foreach ( NH_Post_Types::default_types() as $slug => $name ) : ?>
					<?php $term = get_term_by( 'slug', $slug, NH_Post_Types::TAX_TYPE ); ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $term ? $term->name : $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" id="nh-related-search" class="widefat" placeholder="<?php esc_attr_e( 'Search to add: articles, cases, videos, templates, FAQs…', 'neurohr-hub' ); ?>" autocomplete="off">
		</div>
		<ul id="nh-related-results" class="nh-ref-results"></ul>

		<p><label><input type="checkbox" name="nh_related_auto_off" value="1" <?php checked( (bool) get_post_meta( $post->ID, self::AUTO_OFF, true ) ); ?>>
			<?php esc_html_e( 'Do not show automatic "You May Also Like" suggestions on this resource', 'neurohr-hub' ); ?></label></p>

		<?php if ( $back ) : ?>
			<p><strong><?php esc_html_e( 'Referenced in', 'neurohr-hub' ); ?></strong> <span class="description"><?php esc_html_e( '(for you only: resources that picked this one)', 'neurohr-hub' ); ?></span></p>
			<ul class="nh-ref-back">
				<?php foreach ( $back as $id ) : ?>
					<li><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>


		<style>
			.nh-ref-picks{margin:10px 0}.nh-ref-picks li{display:flex;gap:10px;align-items:center;padding:6px 8px;margin:0 0 4px;background:#f6f7f7;border-radius:6px}
			.nh-ref-picks .nh-ref-title{flex:1 1 auto}.nh-ref-picks .nh-ref-kind{color:#646970;font-size:12px}
			.nh-ref-search{display:flex;gap:8px}.nh-ref-search select{flex:0 0 auto}
			.nh-ref-results{margin:4px 0 0;max-height:220px;overflow:auto}.nh-ref-results a{text-decoration:none}
			.nh-ref-back{margin:4px 0 0 18px;list-style:disc}
		</style>
		<script>
		( function () {
			var input = document.getElementById( 'nh-related-search' );
			var type = document.getElementById( 'nh-related-type' );
			var results = document.getElementById( 'nh-related-results' );
			var list = document.getElementById( 'nh-related-list' );
			var timer;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'nh_hub_search' ) ); ?>;
			var current = <?php echo (int) $post->ID; ?>;
			var max = <?php echo (int) self::MAX; ?>;
			var t = <?php echo wp_json_encode( array( 'remove' => __( 'Remove', 'neurohr-hub' ), 'copy' => __( 'Copy card', 'neurohr-hub' ), 'copied' => __( 'Copied — paste it into the text', 'neurohr-hub' ) ) ); ?>;

			function chosen() {
				return Array.prototype.map.call( list.querySelectorAll( 'input[name="nh_related[]"]' ), function ( el ) { return el.value; } );
			}
			function button( cls, text ) {
				var b = document.createElement( 'button' ); b.type = 'button'; b.className = 'button-link ' + cls; b.textContent = text; return b;
			}
			function addItem( item ) {
				if ( chosen().indexOf( String( item.id ) ) !== -1 || chosen().length >= max ) { return; }
				var li = document.createElement( 'li' );
				li.setAttribute( 'data-id', item.id );
				var title = document.createElement( 'span' ); title.className = 'nh-ref-title'; title.textContent = item.title + ' ';
				var kind = document.createElement( 'span' ); kind.className = 'nh-ref-kind'; kind.textContent = item.type;
				title.appendChild( kind );
				var hidden = document.createElement( 'input' ); hidden.type = 'hidden'; hidden.name = 'nh_related[]'; hidden.value = item.id;
				li.appendChild( title ); li.appendChild( button( 'nh-ref-copy', t.copy ) ); li.appendChild( button( 'nh-related-remove', t.remove ) ); li.appendChild( hidden );
				list.appendChild( li );
			}
			list.addEventListener( 'click', function ( e ) {
				var li = e.target.closest( 'li' );
				if ( e.target.classList.contains( 'nh-related-remove' ) ) { li.remove(); }
				if ( e.target.classList.contains( 'nh-ref-copy' ) ) {
					var code = '[nh_ref id="' + li.getAttribute( 'data-id' ) + '"]';
					var done = function () { var old = e.target.textContent; e.target.textContent = t.copied; setTimeout( function () { e.target.textContent = old; }, 2000 ); };
					if ( navigator.clipboard ) { navigator.clipboard.writeText( code ).then( done, function () { window.prompt( '', code ); } ); } else { window.prompt( '', code ); }
				}
			} );
			function search() {
				clearTimeout( timer );
				var term = input.value.trim();
				if ( term.length < 2 && ! type.value ) { results.innerHTML = ''; return; }
				timer = setTimeout( function () {
					var url = ajaxurl + '?action=nh_hub_search&nonce=' + encodeURIComponent( nonce ) + '&exclude=' + current + '&type=' + encodeURIComponent( type.value ) + '&q=' + encodeURIComponent( term );
					fetch( url, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
						results.innerHTML = '';
						( res.data || [] ).forEach( function ( item ) {
							var li = document.createElement( 'li' );
							var a = document.createElement( 'a' );
							a.href = '#'; a.textContent = '+ ' + item.title + ' (' + item.type + ')';
							a.addEventListener( 'click', function ( e ) { e.preventDefault(); addItem( item ); } );
							li.appendChild( a ); results.appendChild( li );
						} );
					} );
				}, 250 );
			}
			input.addEventListener( 'input', search );
			type.addEventListener( 'change', search );
		} )();
		</script>
		<?php
	}

	private static function render_item( $id ) {
		$type = nh_hub_get_type( $id );
		?>
		<li data-id="<?php echo (int) $id; ?>">
			<span class="nh-ref-title"><?php echo esc_html( get_the_title( $id ) ); ?> <span class="nh-ref-kind"><?php echo esc_html( $type ? $type->name : '' ); ?></span></span>
			<button type="button" class="button-link nh-ref-copy"><?php esc_html_e( 'Copy card', 'neurohr-hub' ); ?></button>
			<button type="button" class="button-link nh-related-remove"><?php esc_html_e( 'Remove', 'neurohr-hub' ); ?></button>
			<input type="hidden" name="nh_related[]" value="<?php echo (int) $id; ?>">
		</li>
		<?php
	}

	/** Editor search: by words and/or kind (type slug). */
	public static function ajax_search() {
		check_ajax_referer( 'nh_hub_search', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array(), 403 );
		}
		$term    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$exclude = isset( $_GET['exclude'] ) ? absint( $_GET['exclude'] ) : 0;

		$args = array(
			'post_type'      => NH_Post_Types::CPT,
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => 15,
			'post__not_in'   => $exclude ? array( $exclude ) : array(),
			'no_found_rows'  => true,
			'orderby'        => '' === $term ? 'title' : 'relevance',
			'order'          => 'ASC',
		);
		if ( '' !== $term ) {
			$args['s'] = $term;
		}
		if ( $type ) {
			$args['tax_query'] = array( array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'field' => 'slug', 'terms' => $type ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$t     = nh_hub_get_type( $p );
			$out[] = array(
				'id'    => $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'type'  => $t ? $t->name : '—',
			);
		}
		wp_send_json_success( $out );
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_save_related' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['nh_related'] ) ? (array) wp_unslash( $_POST['nh_related'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- absint below.
		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id && $id !== $post_id && NH_Post_Types::CPT === get_post_type( $id ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$ids = array_slice( $ids, 0, self::MAX );
		if ( $ids ) {
			update_post_meta( $post_id, self::META, $ids );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		if ( ! empty( $_POST['nh_related_auto_off'] ) ) {
			update_post_meta( $post_id, self::AUTO_OFF, '1' );
		} else {
			delete_post_meta( $post_id, self::AUTO_OFF );
		}
	}
}
