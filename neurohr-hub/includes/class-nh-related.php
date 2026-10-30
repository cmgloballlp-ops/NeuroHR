<?php
/**
 * Related resources: hand-picked in the editor, topped up automatically
 * with resources sharing a tag, then a topic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Related {

	const META        = '_nh_related';
	const ATTACH_META = '_nh_attachments';
	const NONCE       = 'nh_hub_related_nonce';
	const MAX         = 12;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 10, 2 );
			add_action( 'wp_ajax_nh_hub_search', array( __CLASS__, 'ajax_search' ) );
		}
	}

	/* ---------- Front end ---------- */

	public static function get_manual( $post_id ) {
		$ids = get_post_meta( $post_id, self::META, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Up to $limit published related resource IDs: manual picks first, then same tag, then same topic.
	 */
	public static function get_ids( $post_id, $limit = 4 ) {
		$ids = array();
		foreach ( self::get_manual( $post_id ) as $id ) {
			if ( $id !== $post_id && 'publish' === get_post_status( $id ) && NH_Post_Types::CPT === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}
		if ( count( $ids ) >= $limit ) {
			return array_slice( $ids, 0, $limit );
		}

		return array_slice( array_merge( $ids, self::get_auto( $post_id, $limit - count( $ids ), $ids ) ), 0, $limit );
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
				'post__not_in'           => array_merge( array( $post_id ), $exclude ),
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
		$own = NH_Sections::of_post( $post_id );
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
		$items = get_post_meta( $post_id, self::ATTACH_META, true );
		return is_array( $items ) ? $items : array();
	}

	/* ---------- Editor ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_related', __( 'Related Resources', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'normal', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_related', self::NONCE );
		$selected = self::get_manual( $post->ID );
		?>
		<p class="description"><?php esc_html_e( 'Shown at the bottom of this resource. If you pick fewer than 4, the rest are filled with resources sharing a tag or topic.', 'neurohr-hub' ); ?></p>
		<ul id="nh-related-list" style="margin:8px 0">
			<?php foreach ( $selected as $id ) : ?>
				<?php if ( get_post( $id ) ) : ?>
					<?php self::render_item( $id ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
		<input type="search" id="nh-related-search" class="widefat" placeholder="<?php esc_attr_e( 'Search articles, case studies, videos, downloads…', 'neurohr-hub' ); ?>" autocomplete="off">
		<ul id="nh-related-results" style="margin:4px 0 0;max-height:220px;overflow:auto"></ul>

		<?php $attachments = self::get_attachments( $post->ID ); ?>
		<?php if ( $attachments ) : ?>
			<p style="margin-top:16px"><strong><?php esc_html_e( 'Attachments (from BetterDocs)', 'neurohr-hub' ); ?></strong></p>
			<ul>
				<?php foreach ( $attachments as $item ) : ?>
					<li><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['title'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'These will move to protected Downloads in the next version.', 'neurohr-hub' ); ?></p>
		<?php endif; ?>

		<script>
		( function () {
			var input = document.getElementById( 'nh-related-search' );
			var results = document.getElementById( 'nh-related-results' );
			var list = document.getElementById( 'nh-related-list' );
			var timer;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'nh_hub_search' ) ); ?>;
			var current = <?php echo (int) $post->ID; ?>;
			var max = <?php echo (int) self::MAX; ?>;

			function chosen() {
				return Array.prototype.map.call( list.querySelectorAll( 'input[name="nh_related[]"]' ), function ( el ) { return el.value; } );
			}
			function addItem( item ) {
				if ( chosen().indexOf( String( item.id ) ) !== -1 || chosen().length >= max ) { return; }
				var li = document.createElement( 'li' );
				li.style.cssText = 'display:flex;gap:8px;align-items:center;padding:4px 0';
				var label = document.createElement( 'span' );
				label.textContent = item.title + ' (' + item.type + ')';
				var hidden = document.createElement( 'input' );
				hidden.type = 'hidden'; hidden.name = 'nh_related[]'; hidden.value = item.id;
				var remove = document.createElement( 'button' );
				remove.type = 'button'; remove.className = 'button-link nh-related-remove'; remove.textContent = '<?php echo esc_js( __( 'Remove', 'neurohr-hub' ) ); ?>';
				li.appendChild( label ); li.appendChild( remove ); li.appendChild( hidden );
				list.appendChild( li );
			}
			list.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'nh-related-remove' ) ) { e.target.closest( 'li' ).remove(); }
			} );
			input.addEventListener( 'input', function () {
				clearTimeout( timer );
				var term = input.value.trim();
				if ( term.length < 2 ) { results.innerHTML = ''; return; }
				timer = setTimeout( function () {
					var url = ajaxurl + '?action=nh_hub_search&nonce=' + encodeURIComponent( nonce ) + '&exclude=' + current + '&q=' + encodeURIComponent( term );
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
			} );
		} )();
		</script>
		<?php
	}

	private static function render_item( $id ) {
		$type = nh_hub_get_type( $id );
		?>
		<li style="display:flex;gap:8px;align-items:center;padding:4px 0">
			<span><?php echo esc_html( get_the_title( $id ) . ( $type ? ' (' . $type->name . ')' : '' ) ); ?></span>
			<button type="button" class="button-link nh-related-remove"><?php esc_html_e( 'Remove', 'neurohr-hub' ); ?></button>
			<input type="hidden" name="nh_related[]" value="<?php echo (int) $id; ?>">
		</li>
		<?php
	}

	public static function ajax_search() {
		check_ajax_referer( 'nh_hub_search', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array(), 403 );
		}
		$term    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$exclude = isset( $_GET['exclude'] ) ? absint( $_GET['exclude'] ) : 0;

		$posts = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => array( 'publish', 'draft', 'future' ),
				's'              => $term,
				'posts_per_page' => 10,
				'post__not_in'   => $exclude ? array( $exclude ) : array(),
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$type  = nh_hub_get_type( $p );
			$out[] = array(
				'id'    => $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'type'  => $type ? $type->name : '—',
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
	}
}
