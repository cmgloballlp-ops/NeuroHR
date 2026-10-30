<?php
/**
 * Templates (protected downloads) and Videos.
 *
 * Downloads
 * - The file is chosen or uploaded in the media library; on save it is moved into
 *   uploads/nh-protected/ (blocked by .htaccess, random file name) and removed from the
 *   public media library.
 * - Members download through ?nh_download={id}: login and level are checked, the
 *   download is counted, then the file is streamed. A link to another site is allowed too.
 *
 * Videos
 * - A YouTube or Vimeo link. The player (and so the video address) is only printed for
 *   members who may watch it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Media {

	const DIR          = 'nh-protected';
	const FILE_META    = '_nh_file';      // array( path, name, size, mime ) — path relative to the protected folder.
	const LINK_META    = '_nh_file_url';  // External download link instead of a file.
	const COUNT_META   = '_nh_downloads';
	const VIDEO_META   = '_nh_video_url';
	const LENGTH_META  = '_nh_video_length';
	const NONCE        = 'nh_media_nonce';
	const ALLOWED_EXTS = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'odt', 'ods' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_download' ), 2 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_boxes' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 20, 2 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
			add_action( 'before_delete_post', array( __CLASS__, 'delete_file_with_post' ) );
		}
	}

	/* ---------- Protected folder ---------- */

	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/** Create the folder with files that block direct access (Apache and LiteSpeed read .htaccess). */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "# NeuroHR Knowledge Hub: files are served through WordPress only.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/**
	 * Copy a local file into the protected folder under a random name.
	 * Returns the stored file info, or WP_Error.
	 */
	public static function store_file( $source, $original_name ) {
		if ( ! self::ensure_dir() ) {
			return new WP_Error( 'nh_dir', __( 'Could not create the protected folder.', 'neurohr-hub' ) );
		}
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
			return new WP_Error( 'nh_type', sprintf( /* translators: %s: extensions */ __( 'This file type is not allowed. Allowed: %s', 'neurohr-hub' ), implode( ', ', self::ALLOWED_EXTS ) ) );
		}
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'nh_missing', __( 'The file could not be found on this site.', 'neurohr-hub' ) );
		}
		$base   = sanitize_file_name( pathinfo( $original_name, PATHINFO_FILENAME ) );
		$stored = gmdate( 'Y' ) . '/' . $base . '-' . wp_generate_password( 16, false, false ) . '.' . $ext;
		$target = self::dir() . '/' . $stored;
		wp_mkdir_p( dirname( $target ) );
		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'nh_copy', __( 'Could not copy the file.', 'neurohr-hub' ) );
		}
		$check = wp_check_filetype( $original_name );
		return array(
			'path' => $stored,
			'name' => sanitize_file_name( $original_name ),
			'size' => (int) filesize( $target ),
			'mime' => $check['type'] ? $check['type'] : 'application/octet-stream',
		);
	}

	public static function get_file( $post_id ) {
		$file = get_post_meta( $post_id, self::FILE_META, true );
		return ( is_array( $file ) && ! empty( $file['path'] ) ) ? $file : null;
	}

	/** Absolute path of a stored file, refusing anything outside the protected folder. */
	private static function file_path( $file ) {
		$root = realpath( self::dir() );
		$path = realpath( self::dir() . '/' . $file['path'] );
		return ( $root && $path && 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ) && is_file( $path ) ) ? $path : '';
	}

	private static function delete_stored( $post_id ) {
		$file = self::get_file( $post_id );
		if ( $file ) {
			$path = self::file_path( $file );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
		delete_post_meta( $post_id, self::FILE_META );
	}

	public static function delete_file_with_post( $post_id ) {
		if ( NH_Post_Types::CPT === get_post_type( $post_id ) ) {
			self::delete_stored( $post_id );
		}
	}

	/* ---------- Download endpoint ---------- */

	public static function download_url( $post_id ) {
		return add_query_arg( 'nh_download', (int) $post_id, home_url( '/' ) );
	}

	public static function has_download( $post_id ) {
		return self::get_file( $post_id ) || get_post_meta( $post_id, self::LINK_META, true );
	}

	public static function serve_download() {
		if ( ! isset( $_GET['nh_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- access is checked below.
			return;
		}
		$post_id = absint( $_GET['nh_download'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$post    = get_post( $post_id );
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( ! $post || NH_Post_Types::CPT !== $post->post_type || 'publish' !== $post->post_status || ! self::has_download( $post_id ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This download does not exist.', 'neurohr-hub' ), '', array( 'response' => 404 ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( NH_Login::login_url( get_permalink( $post ) ) );
			exit;
		}
		if ( ! NH_Access::can_view( $post ) ) {
			// The resource page explains the level needed and shows the upgrade button.
			wp_safe_redirect( get_permalink( $post ) );
			exit;
		}

		update_post_meta( $post_id, self::COUNT_META, (int) get_post_meta( $post_id, self::COUNT_META, true ) + 1 );
		NH_Usage::log( $post_id, 'download' );

		$link = get_post_meta( $post_id, self::LINK_META, true );
		$file = self::get_file( $post_id );
		if ( ! $file && $link ) {
			wp_redirect( esc_url_raw( $link ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- the editor set this link on purpose.
			exit;
		}
		$path = $file ? self::file_path( $file ) : '';
		if ( ! $path ) {
			status_header( 404 );
			wp_die( esc_html__( 'The file is missing. Please tell the NeuroHR team.', 'neurohr-hub' ), '', array( 'response' => 404 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $file['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------- Video ---------- */

	/** Embed address for a YouTube or Vimeo link, or '' when not recognised. */
	public static function embed_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
		}
		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)(?:/([0-9a-f]+))?~', $url, $m ) ) {
			$hash = ! empty( $m[2] ) ? $m[2] : '';
			if ( ! $hash ) {
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( $query, $args );
				$hash = isset( $args['h'] ) ? preg_replace( '/[^0-9a-f]/', '', $args['h'] ) : '';
			}
			return 'https://player.vimeo.com/video/' . $m[1] . ( $hash ? '?h=' . $hash . '&' : '?' ) . 'dnt=1';
		}
		return '';
	}

	/* ---------- Front end blocks (single resource page) ---------- */

	public static function format_size( $bytes ) {
		return $bytes ? size_format( $bytes, $bytes > 1048576 ? 1 : 0 ) : '';
	}

	/** Download card. $can: may the current user download it. */
	public static function render_download( $post, $can ) {
		$file = self::get_file( $post->ID );
		$link = get_post_meta( $post->ID, self::LINK_META, true );
		if ( ! $file && ! $link ) {
			return '';
		}
		$ext   = $file ? strtoupper( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) : __( 'LINK', 'neurohr-hub' );
		$name  = $file ? $file['name'] : wp_parse_url( $link, PHP_URL_HOST );
		$size  = $file ? self::format_size( $file['size'] ) : '';
		$count = (int) get_post_meta( $post->ID, self::COUNT_META, true );
		ob_start();
		?>
		<div class="nh-download">
			<span class="nh-download__ext"><?php echo esc_html( $ext ); ?></span>
			<span class="nh-download__info">
				<span class="nh-download__name"><?php echo esc_html( $name ); ?></span>
				<span class="nh-download__meta">
					<?php echo esc_html( trim( $size . ( $count ? ' · ' . sprintf( /* translators: %d: count */ _n( '%d download', '%d downloads', $count, 'neurohr-hub' ), $count ) : '' ), ' ·' ) ); ?>
				</span>
			</span>
			<?php if ( $can ) : ?>
				<a class="nh-btn nh-btn--primary" href="<?php echo esc_url( self::download_url( $post->ID ) ); ?>" rel="nofollow">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
					<?php echo $file ? esc_html__( 'Download', 'neurohr-hub' ) : esc_html__( 'Open', 'neurohr-hub' ); ?>
				</a>
			<?php else : ?>
				<a class="nh-btn" href="#nh-locked">&#128274; <?php echo esc_html( nh_hub_level_label( NH_Access::get_post_level( $post ) ) ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Video player for members who may watch; a locked placeholder (no video address) otherwise. */
	public static function render_video( $post, $can ) {
		$embed = self::embed_url( get_post_meta( $post->ID, self::VIDEO_META, true ) );
		if ( ! $embed ) {
			return '';
		}
		$length = get_post_meta( $post->ID, self::LENGTH_META, true );
		ob_start();
		if ( $can ) {
			?>
			<div class="nh-video">
				<iframe src="<?php echo esc_url( $embed ); ?>" title="<?php echo esc_attr( get_the_title( $post ) ); ?>" loading="lazy"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
			</div>
			<?php
		} else {
			$thumb = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'large' ) : '';
			?>
			<a class="nh-video nh-video--locked" href="#nh-locked" <?php echo $thumb ? 'style="background-image:url(' . esc_url( $thumb ) . ')"' : ''; ?>>
				<span class="nh-video__lock">&#128274; <?php echo esc_html( sprintf( /* translators: %s: level */ __( 'Available to %s members', 'neurohr-hub' ), nh_hub_level_label( NH_Access::get_post_level( $post ) ) ) ); ?></span>
			</a>
			<?php
		}
		if ( $length ) {
			echo '<p class="nh-video__length">' . esc_html( sprintf( /* translators: %s: length */ __( 'Length: %s', 'neurohr-hub' ), $length ) ) . '</p>';
		}
		return ob_get_clean();
	}

	/* ---------- Editor ---------- */

	public static function enqueue_admin( $hook ) {
		$screen = get_current_screen();
		if ( $screen && NH_Post_Types::CPT === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'nh_download', __( 'Template file (Resource Type: Download)', 'neurohr-hub' ), array( __CLASS__, 'render_download_box' ), NH_Post_Types::CPT, 'normal', 'high' );
		add_meta_box( 'nh_video', __( 'Video (Resource Type: Video)', 'neurohr-hub' ), array( __CLASS__, 'render_video_box' ), NH_Post_Types::CPT, 'normal', 'high' );
	}

	public static function render_download_box( $post ) {
		wp_nonce_field( 'nh_media_save', self::NONCE );
		$error = get_transient( 'nh_media_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'nh_media_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		$file  = self::get_file( $post->ID );
		$link  = get_post_meta( $post->ID, self::LINK_META, true );
		$count = (int) get_post_meta( $post->ID, self::COUNT_META, true );
		?>
		<?php if ( $file ) : ?>
			<p><strong><?php echo esc_html( $file['name'] ); ?></strong> · <?php echo esc_html( self::format_size( $file['size'] ) ); ?> · <?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d download', '%d downloads', $count, 'neurohr-hub' ), $count ) ); ?>
				— <?php esc_html_e( 'stored in the protected folder', 'neurohr-hub' ); ?></p>
			<p><label><input type="checkbox" name="nh_file_remove" value="1"> <?php esc_html_e( 'Remove this file', 'neurohr-hub' ); ?></label></p>
		<?php endif; ?>
		<p>
			<input type="hidden" name="nh_file_attachment" id="nh-file-attachment" value="">
			<button type="button" class="button" id="nh-file-pick"><?php echo $file ? esc_html__( 'Replace file…', 'neurohr-hub' ) : esc_html__( 'Choose or upload file…', 'neurohr-hub' ); ?></button>
			<span id="nh-file-picked" class="description"></span>
		</p>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: extensions */ __( 'Allowed: %s. When you save, the file is moved out of the public media library into a protected folder; members download it through a checked link.', 'neurohr-hub' ), implode( ', ', self::ALLOWED_EXTS ) ) ); ?></p>
		<p><label for="nh-file-url"><?php esc_html_e( 'Or a link to a file on another site (e.g. a government PDF):', 'neurohr-hub' ); ?></label><br>
			<input type="url" class="widefat" id="nh-file-url" name="nh_file_url" value="<?php echo esc_attr( $link ); ?>" placeholder="https://"></p>
		<script>
		/*
		 * The block editor prints meta boxes before the media library script, so look for
		 * wp.media when the button is clicked, not when this box is printed.
		 */
		( function () {
			if ( window.nhFilePickBound ) { return; }
			window.nhFilePickBound = true;
			var frame;
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '#nh-file-pick' ) : null;
				if ( ! btn ) { return; }
				e.preventDefault();
				if ( ! window.wp || ! wp.media ) {
					window.alert( <?php echo wp_json_encode( __( 'The media library is still loading. Please try again in a moment.', 'neurohr-hub' ) ); ?> );
					return;
				}
				frame = frame || wp.media( { title: btn.textContent, multiple: false } );
				frame.off( 'select' ).on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					document.getElementById( 'nh-file-attachment' ).value = a.id;
					document.getElementById( 'nh-file-picked' ).textContent = a.filename + ' — ' + <?php echo wp_json_encode( __( 'will be moved to the protected folder when you save', 'neurohr-hub' ) ); ?>;
				} );
				frame.open();
			} );
		} )();
		</script>
		<?php
	}

	public static function render_video_box( $post ) {
		$url    = get_post_meta( $post->ID, self::VIDEO_META, true );
		$length = get_post_meta( $post->ID, self::LENGTH_META, true );
		?>
		<p><label for="nh-video-url"><?php esc_html_e( 'YouTube or Vimeo link', 'neurohr-hub' ); ?></label><br>
			<input type="url" class="widefat" id="nh-video-url" name="nh_video_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://vimeo.com/123456789"></p>
		<?php if ( $url && ! self::embed_url( $url ) ) : ?>
			<p class="notice notice-warning inline" style="padding:6px 10px"><?php esc_html_e( 'This link is not recognised as YouTube or Vimeo.', 'neurohr-hub' ); ?></p>
		<?php endif; ?>
		<p><label for="nh-video-length"><?php esc_html_e( 'Length (optional)', 'neurohr-hub' ); ?></label>
			<input type="text" id="nh-video-length" name="nh_video_length" value="<?php echo esc_attr( $length ); ?>" placeholder="12:30" style="width:100px"></p>
		<p class="description"><?php esc_html_e( 'For paid videos use Vimeo with "embed only on specific domains" (your site). Unlisted YouTube links can be shared by anyone who has them. Set a featured image: locked members see it instead of the player.', 'neurohr-hub' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_media_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Video.
		$video = isset( $_POST['nh_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['nh_video_url'] ) ) : '';
		$video ? update_post_meta( $post_id, self::VIDEO_META, $video ) : delete_post_meta( $post_id, self::VIDEO_META );
		$length = isset( $_POST['nh_video_length'] ) ? sanitize_text_field( wp_unslash( $_POST['nh_video_length'] ) ) : '';
		$length ? update_post_meta( $post_id, self::LENGTH_META, $length ) : delete_post_meta( $post_id, self::LENGTH_META );

		// External link.
		$link = isset( $_POST['nh_file_url'] ) ? esc_url_raw( wp_unslash( $_POST['nh_file_url'] ) ) : '';
		$link ? update_post_meta( $post_id, self::LINK_META, $link ) : delete_post_meta( $post_id, self::LINK_META );

		if ( ! empty( $_POST['nh_file_remove'] ) ) {
			self::delete_stored( $post_id );
		}

		// New file from the media library: move it into the protected folder.
		$attachment = isset( $_POST['nh_file_attachment'] ) ? absint( $_POST['nh_file_attachment'] ) : 0;
		if ( $attachment && 'attachment' === get_post_type( $attachment ) && current_user_can( 'delete_post', $attachment ) ) {
			$source = get_attached_file( $attachment );
			$stored = self::store_file( $source, wp_basename( $source ) );
			if ( is_wp_error( $stored ) ) {
				set_transient( 'nh_media_error_' . get_current_user_id(), $stored->get_error_message(), 120 );
				return;
			}
			self::delete_stored( $post_id );
			update_post_meta( $post_id, self::FILE_META, $stored );
			wp_delete_attachment( $attachment, true ); // Gone from the public uploads folder.
		}
	}

	/* ---------- BetterDocs attachments => protected downloads ---------- */

	/**
	 * For every resource with BetterDocs attachments: files stored on this site become
	 * Download resources (same access level as the resource, linked as related);
	 * links to other sites stay as plain links. Returns a report.
	 */
	public static function convert_attachments() {
		$report  = array( 'moved' => array(), 'kept' => 0, 'missing' => array() );
		$uploads = wp_upload_dir( null, false );
		$type    = get_term_by( 'slug', 'download', NH_Post_Types::TAX_TYPE );
		$parents = get_posts(
			array(
				'post_type'      => NH_Post_Types::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => NH_Related::ATTACH_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time tool.
			)
		);
		foreach ( $parents as $parent ) {
			$keep = array();
			foreach ( NH_Related::get_attachments( $parent->ID ) as $item ) {
				$url = $item['url'];
				if ( 0 !== strpos( $url, $uploads['baseurl'] . '/' ) ) {
					$keep[] = $item; // Another site's file (e.g. a government PDF): public anyway.
					$report['kept']++;
					continue;
				}
				$source = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
				$stored = self::store_file( $source, wp_basename( $source ) );
				if ( is_wp_error( $stored ) ) {
					$keep[]              = $item;
					$report['missing'][] = $item['title'] . ' (' . $stored->get_error_message() . ')';
					continue;
				}
				$download = wp_insert_post(
					array(
						'post_type'    => NH_Post_Types::CPT,
						'post_status'  => 'publish',
						'post_title'   => $item['title'],
						/* translators: %s: resource title */
						'post_excerpt' => sprintf( __( 'Attachment of: %s', 'neurohr-hub' ), $parent->post_title ),
					),
					true
				);
				if ( is_wp_error( $download ) ) {
					$keep[] = $item;
					continue;
				}
				if ( $type ) {
					wp_set_object_terms( $download, array( (int) $type->term_id ), NH_Post_Types::TAX_TYPE );
				}
				$topics = wp_get_object_terms( $parent->ID, NH_Post_Types::TAX_TOPIC, array( 'fields' => 'ids' ) );
				if ( $topics && ! is_wp_error( $topics ) ) {
					wp_set_object_terms( $download, $topics, NH_Post_Types::TAX_TOPIC );
				}
				update_post_meta( $download, self::FILE_META, $stored );
				// Same readers as the resource it belonged to (not the stricter Download default).
				update_post_meta( $download, NH_Access::META, NH_Access::get_post_level( $parent ) );
				$related = NH_Related::get_manual( $parent->ID );
				array_unshift( $related, $download );
				update_post_meta( $parent->ID, NH_Related::META, array_slice( array_values( array_unique( $related ) ), 0, NH_Related::MAX ) );
				$report['moved'][] = array( 'title' => $item['title'], 'url' => $url );
			}
			$keep ? update_post_meta( $parent->ID, NH_Related::ATTACH_META, $keep ) : delete_post_meta( $parent->ID, NH_Related::ATTACH_META );
		}
		NH_Frontend::flush_topic_cache();
		return $report;
	}
}
