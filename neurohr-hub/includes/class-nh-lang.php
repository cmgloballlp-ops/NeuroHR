<?php
/**
 * Content languages (the interface stays English).
 *
 * - Each resource has a language: 中文 (zh), English (en); Bahasa Melayu (ms) is reserved and
 *   can be switched on in Settings → General → Languages.
 * - Translations of the same content are linked in a group (_nh_tr = the group's first ID).
 *   Lists, topic cards, search and suggestions show each content ONCE, in the reader's
 *   language; content without that translation shows in the language it has.
 * - Reader's language: ?lang= (the "Language" switch, remembered in a cookie), else the
 *   cookie, else the member's language in NeuroHR Members → My account, else the default
 *   (Settings, 中文 out of the box).
 * - A resource with translations shows "Also available in …" and tells search engines
 *   about the other versions (hreflang).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Lang {

	const META   = '_nh_lang';
	const GROUP  = '_nh_tr';
	const COOKIE = 'nh_lang';
	const NONCE  = 'nh_hub_lang_nonce';

	private static $preferred = null;
	private static $hidden    = array();
	private static $groups    = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'remember_choice' ), 1 );
		add_action( 'send_headers', array( __CLASS__, 'vary_header' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'one_per_content' ), 20 );
		add_filter( 'the_posts', array( __CLASS__, 'search_results' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 2 );
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'flush' ), 30 );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 8, 2 );
			add_action( 'admin_post_nh_hub_new_translation', array( __CLASS__, 'new_translation' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_filter( 'manage_' . NH_Post_Types::CPT . '_posts_columns', array( __CLASS__, 'add_column' ), 30 );
			add_action( 'manage_' . NH_Post_Types::CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
			add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ), 20, 2 );
			add_action( 'pre_get_posts', array( __CLASS__, 'admin_filter' ) );
		}
	}

	/* ---------- Languages ---------- */

	/** Every known language: code => array( name, short, hreflang ). */
	public static function all() {
		return array(
			'zh' => array( 'name' => '中文', 'short' => '中文', 'hreflang' => 'zh-Hans' ),
			'en' => array( 'name' => 'English', 'short' => 'EN', 'hreflang' => 'en' ),
			'ms' => array( 'name' => 'Bahasa Melayu', 'short' => 'BM', 'hreflang' => 'ms' ),
		);
	}

	/** Languages switched on in Settings: code => info. */
	public static function enabled() {
		$on  = array_filter( array_map( 'trim', explode( ',', (string) NH_Display::get( 'lang_enabled' ) ) ) );
		$out = array();
		foreach ( self::all() as $code => $info ) {
			if ( in_array( $code, $on, true ) ) {
				$out[ $code ] = $info;
			}
		}
		return $out ? $out : array( 'en' => self::all()['en'] );
	}

	public static function valid( $code ) {
		$code = sanitize_key( (string) $code );
		return isset( self::all()[ $code ] ) ? $code : '';
	}

	public static function name( $code ) {
		$all = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ]['name'] : $code;
	}

	public static function short( $code ) {
		$all = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ]['short'] : strtoupper( $code );
	}

	/** Language for new resources (Settings). */
	public static function default_new() {
		$code = self::valid( NH_Display::get( 'lang_default_new' ) );
		return $code && isset( self::enabled()[ $code ] ) ? $code : key( self::enabled() );
	}

	/** Reader's language when nothing else is known (Settings). */
	public static function default_view() {
		$code = self::valid( NH_Display::get( 'lang_default_view' ) );
		return $code && isset( self::enabled()[ $code ] ) ? $code : key( self::enabled() );
	}

	/* ---------- Resources ---------- */

	public static function of_post( $post ) {
		$id   = is_object( $post ) ? $post->ID : (int) $post;
		$code = self::valid( get_post_meta( $id, self::META, true ) );
		return $code ? $code : 'en'; // Content from before languages existed is English.
	}

	public static function group_of( $post ) {
		$id    = is_object( $post ) ? $post->ID : (int) $post;
		$group = (int) get_post_meta( $id, self::GROUP, true );
		return $group ? $group : $id;
	}

	/** Group key => array( post ID => language ), published resources only, for groups of 2+. */
	private static function groups() {
		if ( null !== self::$groups ) {
			return self::$groups;
		}
		$cached = get_transient( 'nh_lang_groups' );
		if ( is_array( $cached ) ) {
			self::$groups = $cached;
			return $cached;
		}
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, g.meta_value AS grp, l.meta_value AS lang
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				self::GROUP,
				self::META,
				NH_Post_Types::CPT
			)
		);
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$lang                                 = self::valid( $row->lang );
			$groups[ (int) $row->grp ][ (int) $row->ID ] = $lang ? $lang : 'en';
		}
		$groups = array_filter( $groups, function ( $g ) { return count( $g ) > 1; } );
		set_transient( 'nh_lang_groups', $groups, DAY_IN_SECONDS );
		self::$groups = $groups;
		return $groups;
	}

	/** Published versions of a resource's content: language => ID (itself included). */
	public static function versions( $post ) {
		$id     = is_object( $post ) ? $post->ID : (int) $post;
		$groups = self::groups();
		$group  = self::group_of( $id );
		$out    = array( self::of_post( $id ) => $id );
		if ( isset( $groups[ $group ] ) ) {
			foreach ( $groups[ $group ] as $pid => $lang ) {
				if ( ! isset( $out[ $lang ] ) || $pid === $id ) {
					$out[ $lang ] = $pid;
				}
			}
		}
		$order = array_keys( self::all() );
		uksort( $out, function ( $a, $b ) use ( $order ) { return array_search( $a, $order, true ) - array_search( $b, $order, true ); } );
		return $out;
	}

	/** Other published versions (siblings) of a resource. */
	public static function siblings( $post ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return array_values( array_diff( self::versions( $id ), array( $id ) ) );
	}

	/* ---------- The reader ---------- */

	/** A click on the language switch (?lang=zh) is remembered for a year. */
	public static function remember_choice() {
		if ( is_admin() || ! isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display preference only.
			return;
		}
		$code = self::valid( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( $code && isset( self::enabled()[ $code ] ) && ! headers_sent() ) {
			setcookie( self::COOKIE, $code, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE ] = $code;
		}
	}

	public static function preferred() {
		if ( null !== self::$preferred ) {
			return self::$preferred;
		}
		$enabled = self::enabled();
		$code    = '';
		if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$code = self::valid( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
		if ( ( ! $code || ! isset( $enabled[ $code ] ) ) && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$code = self::valid( wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( ( ! $code || ! isset( $enabled[ $code ] ) ) && is_user_logged_in() ) {
			// NeuroHR Members → My account → Language (e.g. "zh", "zh_CN", "en_US", "ms").
			$member = strtolower( (string) get_user_meta( get_current_user_id(), 'nhm_lang', true ) );
			$code   = self::valid( substr( $member, 0, 2 ) );
		}
		if ( ! $code || ! isset( $enabled[ $code ] ) ) {
			$code = self::default_view();
		}
		self::$preferred = $code;
		return $code;
	}

	/**
	 * Lists can differ per language, so LiteSpeed Cache must keep one copy of a page per
	 * language cookie (matters for visitors on public pages; members are not cached).
	 */
	public static function vary_header() {
		if ( count( self::enabled() ) > 1 && ! headers_sent() ) {
			header( 'X-LiteSpeed-Vary: cookie=' . self::COOKIE, false );
		}
	}

	/** Which version to show for each group, given a language: IDs to leave out of lists. */
	public static function hidden_ids( $lang = null ) {
		$lang = $lang ? $lang : self::preferred();
		if ( isset( self::$hidden[ $lang ] ) ) {
			return self::$hidden[ $lang ];
		}
		$order  = array_unique( array_merge( array( $lang, self::default_view() ), array_keys( self::all() ) ) );
		$hidden = array();
		foreach ( self::groups() as $members ) {
			$show = 0;
			foreach ( $order as $code ) {
				$found = array_search( $code, $members, true );
				if ( false !== $found ) {
					$show = $found;
					break;
				}
			}
			foreach ( array_keys( $members ) as $pid ) {
				if ( $pid !== $show ) {
					$hidden[] = $pid;
				}
			}
		}
		self::$hidden[ $lang ] = $hidden;
		return $hidden;
	}

	/**
	 * Lists, topic cards, search, live search and suggestions: one version per content.
	 * Not for single pages, the admin screens, feeds, sitemaps or hand-picked IDs.
	 */
	public static function one_per_content( $query ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || $query->get( 'nh_all_langs' ) || $query->is_feed() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( NH_Post_Types::CPT !== $type && ! ( is_array( $type ) && in_array( NH_Post_Types::CPT, $type, true ) ) ) {
			return;
		}
		if ( $query->get( 'p' ) || $query->get( 'name' ) || $query->get( NH_Post_Types::CPT ) || $query->get( 'post__in' ) || $query->is_singular() ) {
			return;
		}
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 0 !== strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'nh_hub_live' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return; // Editor searches in the admin list everything.
		}
		// Searches look in every version (a word may only be in the English one);
		// search_results() then shows each match in the reader's language.
		if ( '' !== (string) $query->get( 's' ) ) {
			$query->set( 'nh_lang_map', true );
			return;
		}
		$hidden = self::hidden_ids();
		if ( $hidden ) {
			$query->set( 'post__not_in', array_unique( array_merge( (array) $query->get( 'post__not_in' ), $hidden ) ) );
		}
	}

	/** The version of a content to show to this reader. */
	public static function shown_id( $post_id ) {
		$versions = self::versions( $post_id );
		foreach ( array_unique( array_merge( array( self::preferred(), self::default_view() ), array_keys( self::all() ) ) ) as $code ) {
			if ( isset( $versions[ $code ] ) ) {
				return (int) $versions[ $code ];
			}
		}
		return (int) $post_id;
	}

	/** Search matches: each content once, in the reader's language. */
	public static function search_results( $posts, $query ) {
		if ( ! $query->get( 'nh_lang_map' ) || ! $posts ) {
			return $posts;
		}
		$fields = $query->get( 'fields' );
		$hidden = array_flip( self::hidden_ids() );
		$out    = array();
		$seen   = array();
		foreach ( $posts as $p ) {
			$id   = is_object( $p ) ? (int) $p->ID : (int) $p;
			$show = isset( $hidden[ $id ] ) ? self::shown_id( $id ) : $id;
			if ( isset( $seen[ $show ] ) ) {
				continue;
			}
			$seen[ $show ] = true;
			$out[]         = ( 'ids' === $fields ) ? $show : ( $show === $id ? $p : get_post( $show ) );
		}
		return $out;
	}

	public static function flush() {
		delete_transient( 'nh_lang_groups' );
		self::$groups = null;
		self::$hidden = array();
	}

	/* ---------- Front end ---------- */

	/** URL of the current page with ?lang= set. */
	public static function switch_url( $code, $url = '' ) {
		if ( '' === $url ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$url = home_url( $uri );
		}
		return add_query_arg( 'lang', $code, remove_query_arg( array( 'lang', 'paged' ), $url ) );
	}

	/**
	 * Small language label(s) for lists: "中文 · EN" when the content exists in several
	 * languages; the language alone when it is not the reader's.
	 */
	public static function badge( $post ) {
		if ( count( self::enabled() ) < 2 ) {
			return '';
		}
		$versions = self::versions( $post );
		if ( count( $versions ) < 2 && self::of_post( $post ) === self::preferred() ) {
			return '';
		}
		$labels = array_map( array( __CLASS__, 'short' ), array_keys( $versions ) );
		return '<span class="nh-langtag" title="' . esc_attr__( 'Languages', 'neurohr-hub' ) . '">' . esc_html( implode( ' · ', $labels ) ) . '</span>';
	}

	/** Plain text version of the badge (live search). */
	public static function badge_text( $post ) {
		return trim( wp_strip_all_tags( self::badge( $post ) ) );
	}

	/** "Also available in: 中文 | English" on a resource with translations. */
	public static function versions_bar( $post ) {
		$versions = self::versions( $post );
		if ( count( $versions ) < 2 ) {
			return '';
		}
		$current = self::of_post( $post );
		$html    = '<nav class="nh-versions" aria-label="' . esc_attr__( 'Languages', 'neurohr-hub' ) . '"><span class="nh-versions__label">' . esc_html__( 'Also available in', 'neurohr-hub' ) . '</span>';
		foreach ( $versions as $code => $id ) {
			if ( $code === $current ) {
				$html .= '<span class="nh-versions__item is-current" aria-current="true">' . esc_html( self::name( $code ) ) . '</span>';
			} else {
				$html .= '<a class="nh-versions__item" lang="' . esc_attr( self::all()[ $code ]['hreflang'] ) . '" href="' . esc_url( self::switch_url( $code, get_permalink( $id ) ) ) . '">' . esc_html( self::name( $code ) ) . '</a>';
			}
		}
		return $html . '</nav>';
	}

	/** Languages that have content in a section (cached with the topic lists). */
	public static function section_languages( $section ) {
		$key   = 'nh_lang_section_' . $section;
		$langs = get_transient( $key );
		if ( ! is_array( $langs ) ) {
			$ids   = get_posts(
				array(
					'post_type'      => NH_Post_Types::CPT,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'nh_all_langs'   => true,
					'tax_query'      => 'all' === $section ? array() : array( NH_Sections::type_clause( $section ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- cached.
				)
			);
			$langs = array();
			foreach ( $ids as $id ) {
				$langs[ self::of_post( $id ) ] = true;
			}
			$langs = array_keys( $langs );
			set_transient( $key, $langs, DAY_IN_SECONDS );
			NH_Frontend::remember_cache_key( $key );
		}
		return $langs;
	}

	/** "Language: 中文 | English" on section pages — only where the section has 2+ languages. */
	public static function switcher( $section ) {
		$langs = array_intersect( array_keys( self::enabled() ), self::section_languages( $section ) );
		if ( count( $langs ) < 2 ) {
			return '';
		}
		$current = self::preferred();
		$html    = '<nav class="nh-langswitch" aria-label="' . esc_attr__( 'Language', 'neurohr-hub' ) . '"><span class="nh-langswitch__label">' . esc_html__( 'Language', 'neurohr-hub' ) . '</span>';
		foreach ( $langs as $code ) {
			$html .= '<a class="nh-langswitch__item' . ( $code === $current ? ' is-active' : '' ) . '" href="' . esc_url( self::switch_url( $code ) ) . '"' . ( $code === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( self::name( $code ) ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** <link rel="alternate" hreflang> for resources with translations. */
	public static function hreflang() {
		if ( ! is_singular( NH_Post_Types::CPT ) ) {
			return;
		}
		$versions = self::versions( get_queried_object_id() );
		if ( count( $versions ) < 2 ) {
			return;
		}
		foreach ( $versions as $code => $id ) {
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n", esc_attr( self::all()[ $code ]['hreflang'] ), esc_url( get_permalink( $id ) ) );
		}
	}

	/* ---------- Editor ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_lang', __( 'Language & translations', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'side', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_lang', self::NONCE );
		$is_new  = 'auto-draft' === $post->post_status && ! get_post_meta( $post->ID, self::META, true );
		$current = $is_new ? self::default_new() : self::of_post( $post );
		$group   = self::group_of( $post );
		$members = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 10,
				'post__not_in'   => array( $post->ID ),
				'meta_key'       => self::GROUP, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $group, // phpcs:ignore WordPress.DB.SlowDBQuery
				'nh_all_langs'   => true,
			)
		);
		?>
		<p><label for="nh-lang"><strong><?php esc_html_e( 'Language of this resource', 'neurohr-hub' ); ?></strong></label><br>
			<select id="nh-lang" name="nh_lang">
				<?php foreach ( self::enabled() as $code => $info ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $info['name'] ); ?></option>
				<?php endforeach; ?>
			</select></p>

		<?php if ( $members ) : ?>
			<p><strong><?php esc_html_e( 'Translations', 'neurohr-hub' ); ?></strong></p>
			<ul style="margin:0 0 8px">
				<?php foreach ( $members as $m ) : ?>
					<li><?php echo esc_html( self::name( self::of_post( $m ) ) ); ?>: <a href="<?php echo esc_url( get_edit_post_link( $m->ID ) ); ?>"><?php echo esc_html( get_the_title( $m ) ); ?></a>
						<?php echo 'publish' !== $m->post_status ? '<span class="description">(' . esc_html( $m->post_status ) . ')</span>' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<p><label><input type="checkbox" name="nh_tr_unlink" value="1"> <?php esc_html_e( 'Unlink this resource from its translations', 'neurohr-hub' ); ?></label></p>
		<?php else : ?>
			<p><label for="nh-tr-of"><?php esc_html_e( 'This is a translation of (search a title):', 'neurohr-hub' ); ?></label>
				<input type="search" id="nh-tr-search" class="widefat" autocomplete="off" placeholder="<?php esc_attr_e( 'Type 2+ letters…', 'neurohr-hub' ); ?>">
				<input type="hidden" name="nh_tr_of" id="nh-tr-of" value="">
				<span id="nh-tr-picked" class="description"></span></p>
			<ul id="nh-tr-results" style="margin:0;max-height:160px;overflow:auto"></ul>
		<?php endif; ?>

		<?php if ( 'auto-draft' !== $post->post_status ) : ?>
			<?php
			$have = array_map( array( __CLASS__, 'of_post' ), $members );
			$have[] = $current;
			?>
			<p>
				<?php foreach ( self::enabled() as $code => $info ) : ?>
					<?php if ( ! in_array( $code, $have, true ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nh_hub_new_translation&post=' . $post->ID . '&lang=' . $code ), 'nh_hub_new_translation_' . $post->ID ) ); ?>">
							<?php
							/* translators: %s: language */
							echo esc_html( sprintf( __( '+ Create %s version', 'neurohr-hub' ), $info['name'] ) );
							?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Creates a draft copy (same type, topics, tags, level and picture) linked as a translation, for you to translate.', 'neurohr-hub' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $members ) : ?>
		<script>
		( function () {
			var input = document.getElementById( 'nh-tr-search' ), list = document.getElementById( 'nh-tr-results' ), timer;
			if ( ! input ) { return; }
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'nh_hub_search' ) ); ?>;
			input.addEventListener( 'input', function () {
				clearTimeout( timer );
				var term = input.value.trim();
				if ( term.length < 2 ) { list.innerHTML = ''; return; }
				timer = setTimeout( function () {
					fetch( ajaxurl + '?action=nh_hub_search&nonce=' + encodeURIComponent( nonce ) + '&exclude=<?php echo (int) $post->ID; ?>&q=' + encodeURIComponent( term ), { credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } ).then( function ( res ) {
							list.innerHTML = '';
							( res.data || [] ).forEach( function ( item ) {
								var li = document.createElement( 'li' ), a = document.createElement( 'a' );
								a.href = '#'; a.textContent = item.title + ' (' + item.type + ')';
								a.addEventListener( 'click', function ( e ) {
									e.preventDefault();
									document.getElementById( 'nh-tr-of' ).value = item.id;
									document.getElementById( 'nh-tr-picked' ).textContent = '✓ ' + item.title + ' — <?php echo esc_js( __( 'linked when you save', 'neurohr-hub' ) ); ?>';
									list.innerHTML = '';
								} );
								li.appendChild( a ); list.appendChild( li );
							} );
						} );
				}, 250 );
			} );
		} )();
		</script>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_save_lang' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$lang = isset( $_POST['nh_lang'] ) ? self::valid( wp_unslash( $_POST['nh_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $lang ) {
			update_post_meta( $post_id, self::META, $lang );
		}
		if ( ! empty( $_POST['nh_tr_unlink'] ) ) {
			delete_post_meta( $post_id, self::GROUP );
		}
		$of = isset( $_POST['nh_tr_of'] ) ? absint( $_POST['nh_tr_of'] ) : 0;
		if ( $of && $of !== (int) $post_id && NH_Post_Types::CPT === get_post_type( $of ) ) {
			$error = self::link( $post_id, $of );
			if ( $error ) {
				set_transient( 'nh_lang_error_' . get_current_user_id(), $error, 120 );
			}
		}
		self::flush();
	}

	/** Put $post_id in $other's translation group. Returns an error message or ''. */
	public static function link( $post_id, $other ) {
		$group = self::group_of( $other );
		$lang  = self::of_post( $post_id );
		$taken = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => self::GROUP, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $group, // phpcs:ignore WordPress.DB.SlowDBQuery
				'nh_all_langs'   => true,
			)
		);
		$taken[] = $other;
		foreach ( array_unique( $taken ) as $id ) {
			if ( (int) $id !== (int) $post_id && self::of_post( $id ) === $lang ) {
				/* translators: 1: language, 2: title */
				return sprintf( __( 'Not linked: "%2$s" already has a %1$s version. Change this resource\'s language first.', 'neurohr-hub' ), self::name( $lang ), get_the_title( $other ) );
			}
		}
		update_post_meta( $other, self::GROUP, $group );
		update_post_meta( $post_id, self::GROUP, $group );
		self::flush();
		return '';
	}

	public static function notice() {
		$error = get_transient( 'nh_lang_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'nh_lang_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/** "+ Create 中文 version": a linked draft copy to translate. */
	public static function new_translation() {
		$source = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang   = isset( $_GET['lang'] ) ? self::valid( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		check_admin_referer( 'nh_hub_new_translation_' . $source );
		$post = get_post( $source );
		if ( ! $post || NH_Post_Types::CPT !== $post->post_type || ! $lang || ! current_user_can( 'edit_post', $source ) ) {
			wp_die( esc_html__( 'Cannot create this translation.', 'neurohr-hub' ) );
		}
		if ( isset( self::versions( $source )[ $lang ] ) ) {
			wp_safe_redirect( get_edit_post_link( self::versions( $source )[ $lang ], 'raw' ) );
			exit;
		}
		$new = wp_insert_post(
			array(
				'post_type'    => NH_Post_Types::CPT,
				'post_status'  => 'draft',
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'menu_order'   => $post->menu_order,
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		foreach ( NH_Post_Types::taxonomies() as $tax ) {
			$terms = wp_get_object_terms( $source, $tax, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new, array_map( 'intval', $terms ), $tax );
			}
		}
		foreach ( array( NH_Access::META, '_thumbnail_id', NH_Faq::GROUP_META, NH_Faq::IDS_META, NH_Related::META ) as $key ) {
			$value = get_post_meta( $source, $key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $new, $key, $value );
			}
		}
		update_post_meta( $new, self::META, $lang );
		self::link( $new, $source );
		wp_safe_redirect( get_edit_post_link( $new, 'raw' ) );
		exit;
	}

	/* ---------- Admin list ---------- */

	public static function add_column( $columns ) {
		if ( count( self::enabled() ) > 1 ) {
			$columns['nh_lang'] = __( 'Language', 'neurohr-hub' );
		}
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'nh_lang' !== $column ) {
			return;
		}
		echo esc_html( self::short( self::of_post( $post_id ) ) );
		$group = (int) get_post_meta( $post_id, self::GROUP, true );
		if ( $group ) {
			$others = get_posts( array( 'post_type' => NH_Post_Types::CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 5, 'post__not_in' => array( $post_id ), 'meta_key' => self::GROUP, 'meta_value' => (string) $group, 'nh_all_langs' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $others as $o ) {
				echo ' <a href="' . esc_url( get_edit_post_link( $o ) ) . '" title="' . esc_attr( get_the_title( $o ) ) . '">↔ ' . esc_html( self::short( self::of_post( $o ) ) ) . '</a>';
			}
		}
	}

	public static function list_filter( $post_type, $which = 'top' ) {
		if ( NH_Post_Types::CPT !== $post_type || 'top' !== $which || count( self::enabled() ) < 2 ) {
			return;
		}
		$current = isset( $_GET['nh_lang_f'] ) ? self::valid( wp_unslash( $_GET['nh_lang_f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		echo '<select name="nh_lang_f"><option value="">' . esc_html__( 'All languages', 'neurohr-hub' ) . '</option>';
		foreach ( self::enabled() as $code => $info ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $info['name'] ) . '</option>';
		}
		echo '</select>';
	}

	public static function admin_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || NH_Post_Types::CPT !== $query->get( 'post_type' ) || empty( $_GET['nh_lang_f'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = self::valid( wp_unslash( $_GET['nh_lang_f'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( $code ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array( 'key' => self::META, 'value' => $code );
			$query->set( 'meta_query', $meta );
		}
	}
}
