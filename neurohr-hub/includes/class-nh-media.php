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
 * - Or a video file kept on this site (uploads/nh-protected/videos/). It plays through
 *   ?nh_video={id}: every request checks login and level, so a copied link is useless to
 *   anyone else. Served in small pieces (HTTP ranges) so seeking works and no PHP worker
 *   is held for long on shared hosting. Large files can be put in nh-protected/incoming/
 *   with cPanel or FTP and picked in the editor.
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
	const VIDEO_FILE   = '_nh_video_file'; // array( path, name, size, mime ) — like FILE_META.
	const VIDEO_EXTS   = array( 'mp4', 'm4v', 'webm' );
	const IMAGE_EXTS   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
	const INLINE_EXTS  = array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp' ); // Opened in a new tab, not downloaded.
	const INCOMING     = 'incoming';       // nh-protected/incoming/: files uploaded with cPanel / FTP.
	const CHUNK        = 2097152;          // 2 MB per range response.

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_download' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_video' ), 2 );
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
		wp_mkdir_p( $dir . '/' . self::INCOMING );
		return true;
	}

	/**
	 * Copy a local file into the protected folder under a random name.
	 * Returns the stored file info, or WP_Error.
	 */
	public static function store_file( $source, $original_name, $kind = 'file', $move = false ) {
		if ( ! self::ensure_dir() ) {
			return new WP_Error( 'nh_dir', __( 'Could not create the protected folder.', 'neurohr-hub' ) );
		}
		$allowed = 'video' === $kind ? self::VIDEO_EXTS : ( 'attach' === $kind ? array_merge( self::ALLOWED_EXTS, self::IMAGE_EXTS ) : self::ALLOWED_EXTS );
		$ext     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'nh_type', sprintf( /* translators: %s: extensions */ __( 'This file type is not allowed. Allowed: %s', 'neurohr-hub' ), implode( ', ', $allowed ) ) );
		}
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'nh_missing', __( 'The file could not be found on this site.', 'neurohr-hub' ) );
		}
		$base   = sanitize_file_name( pathinfo( $original_name, PATHINFO_FILENAME ) );
		$sub    = array( 'video' => 'videos/', 'attach' => 'attachments/' );
		$stored = ( isset( $sub[ $kind ] ) ? $sub[ $kind ] : '' ) . gmdate( 'Y' ) . '/' . $base . '-' . wp_generate_password( 16, false, false ) . '.' . $ext;
		$target = self::dir() . '/' . $stored;
		wp_mkdir_p( dirname( $target ) );
		// Moving (same disk) avoids a second copy of a large video; copy is the fallback.
		if ( ! ( $move && @rename( $source, $target ) ) && ! copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
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

	public static function get_file( $post_id, $meta = self::FILE_META ) {
		$file = get_post_meta( $post_id, $meta, true );
		return ( is_array( $file ) && ! empty( $file['path'] ) ) ? $file : null;
	}

	public static function get_video_file( $post_id ) {
		return self::get_file( $post_id, self::VIDEO_FILE );
	}

	/** PDF and pictures open in the browser (new tab); other files download. */
	public static function opens_inline( $name ) {
		return in_array( strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) ), self::INLINE_EXTS, true );
	}

	/** Send a stored file and stop. */
	public static function send_file( $file ) {
		$path = self::file_path( $file );
		if ( ! $path ) {
			status_header( 404 );
			wp_die( esc_html__( 'The file is missing. Please tell the NeuroHR team.', 'neurohr-hub' ), '', array( 'response' => 404 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$name = str_replace( array( '"', "\r", "\n" ), '', $file['name'] );
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Disposition: ' . ( self::opens_inline( $name ) ? 'inline' : 'attachment' ) . '; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Absolute path of a stored file, refusing anything outside the protected folder. */
	public static function file_path( $file ) {
		$root = realpath( self::dir() );
		$path = realpath( self::dir() . '/' . $file['path'] );
		return ( $root && $path && 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ) && is_file( $path ) ) ? $path : '';
	}

	private static function delete_stored( $post_id, $meta = self::FILE_META ) {
		$file = self::get_file( $post_id, $meta );
		if ( $file ) {
			$path = self::file_path( $file );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
		delete_post_meta( $post_id, $meta );
	}

	public static function delete_file_with_post( $post_id ) {
		if ( NH_Post_Types::CPT === get_post_type( $post_id ) ) {
			self::delete_stored( $post_id );
			self::delete_stored( $post_id, self::VIDEO_FILE );
		}
	}

	/** Video files waiting in nh-protected/incoming/ (uploaded with cPanel / FTP): name => size. */
	public static function incoming_videos() {
		$dir   = self::dir() . '/' . self::INCOMING;
		$files = array();
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
			if ( is_file( $path ) && in_array( $ext, self::VIDEO_EXTS, true ) ) {
				$files[ wp_basename( $path ) ] = (int) filesize( $path );
			}
		}
		ksort( $files );
		return $files;
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
		if ( ! is_user_logged_in() && ! NH_Access::is_public( $post ) ) {
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
		self::send_file( $file );
	}

	/* ---------- Video file endpoint ---------- */

	public static function video_url( $post_id ) {
		$file = self::get_video_file( $post_id );
		// The version (file size) makes the browser fetch a replaced file instead of its cached copy.
		return add_query_arg( array( 'nh_video' => (int) $post_id, 'v' => $file ? (int) $file['size'] : 0 ), home_url( '/' ) );
	}

	private static function video_refuse( $code, $message ) {
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}

	/**
	 * Stream a protected video. Every request (every 2 MB piece) checks login and level.
	 * Opening the address directly in a browser tab is refused: it only plays inside the
	 * hub's own player on this site.
	 */
	public static function serve_video() {
		if ( ! isset( $_GET['nh_video'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- access is checked below.
			return;
		}
		$post_id = absint( $_GET['nh_video'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$post    = get_post( $post_id );
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$file = ( $post && NH_Post_Types::CPT === $post->post_type ) ? self::get_video_file( $post_id ) : null;
		$path = $file ? self::file_path( $file ) : '';
		if ( ! $path || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) ) {
			self::video_refuse( 404, __( 'This video does not exist.', 'neurohr-hub' ) );
		}
		if ( ! NH_Access::can_view( $post ) ) { // Guests only for free samples ("Everyone").
			self::video_refuse( 403, __( 'Please log in with a membership that includes this video.', 'neurohr-hub' ) );
		}
		// Browsers say what a request is for. Only the video player on this site may load it,
		// not a tab where the address was pasted, and not another site.
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) ) : '';
		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) ) : '';
		if ( in_array( $dest, array( 'document', 'iframe', 'embed', 'object' ), true ) && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect( get_permalink( $post ) );
			exit;
		}
		if ( $site && ! in_array( $site, array( 'same-origin', 'none' ), true ) ) {
			self::video_refuse( 403, __( 'This video plays only on NeuroHR.', 'neurohr-hub' ) );
		}

		$size  = (int) filesize( $path );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$part  = false;
		if ( preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $m ) ) {
			$part = true;
			if ( '' === $m[1] && '' !== $m[2] ) { // Last N bytes.
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
		}
		// Short responses keep shared-hosting PHP workers free; the player asks for the next piece.
		if ( $part ) {
			$end = min( $end, $start + self::CHUNK - 1 );
		}
		$length = $end - $start + 1;

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( $part ? 206 : 200 );
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . $length );
		if ( $part ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Disposition: inline' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			exit;
		}
		fseek( $handle, $start );
		$left = $length;
		while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$buffer = fread( $handle, min( 262144, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $buffer || '' === $buffer ) {
				break;
			}
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput -- binary video data.
			flush();
			$left -= strlen( $buffer );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
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
				<?php $inline = ! $file || self::opens_inline( $file['name'] ); // PDF, pictures and links open in a new tab. ?>
				<a class="nh-btn nh-btn--primary" href="<?php echo esc_url( self::download_url( $post->ID ) ); ?>" rel="nofollow noopener"<?php echo $inline ? ' target="_blank"' : ''; ?>>
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
					<?php echo $inline ? esc_html__( 'Open', 'neurohr-hub' ) : esc_html__( 'Download', 'neurohr-hub' ); ?>
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
		$own   = self::get_video_file( $post->ID );
		$embed = $own ? '' : self::embed_url( get_post_meta( $post->ID, self::VIDEO_META, true ) );
		if ( ! $own && ! $embed ) {
			return '';
		}
		$length = get_post_meta( $post->ID, self::LENGTH_META, true );
		ob_start();
		if ( $can && $own ) {
			$poster = has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'large' ) : '';
			?>
			<div class="nh-video nh-video--own">
				<video controls playsinline preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture
					oncontextmenu="return false;" <?php echo $poster ? 'poster="' . esc_url( $poster ) . '"' : ''; ?>
					src="<?php echo esc_url( self::video_url( $post->ID ) ); ?>">
					<?php esc_html_e( 'Your browser cannot play this video.', 'neurohr-hub' ); ?>
				</video>
			</div>
			<?php
		} elseif ( $can ) {
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
		add_meta_box( 'nh_download', __( 'Template file (types that behave as Template download)', 'neurohr-hub' ), array( __CLASS__, 'render_download_box' ), NH_Post_Types::CPT, 'normal', 'high' );
		add_meta_box( 'nh_video', __( 'Video (types that behave as Video)', 'neurohr-hub' ), array( __CLASS__, 'render_video_box' ), NH_Post_Types::CPT, 'normal', 'high' );
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
			<button type="button" class="button nh-media-pick" id="nh-file-pick" data-input="nh-file-attachment" data-picked="nh-file-picked"><?php echo $file ? esc_html__( 'Replace file…', 'neurohr-hub' ) : esc_html__( 'Choose or upload file…', 'neurohr-hub' ); ?></button>
			<span id="nh-file-picked" class="description"></span>
		</p>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: extensions */ __( 'Allowed: %s. When you save, the file is moved out of the public media library into a protected folder; members download it through a checked link.', 'neurohr-hub' ), implode( ', ', self::ALLOWED_EXTS ) ) ); ?></p>
		<p><label for="nh-file-url"><?php esc_html_e( 'Or a link to a file on another site (e.g. a government PDF):', 'neurohr-hub' ); ?></label><br>
			<input type="url" class="widefat" id="nh-file-url" name="nh_file_url" value="<?php echo esc_attr( $link ); ?>" placeholder="https://"></p>
		<?php self::picker_script(); ?>
		<?php
	}

	/**
	 * One click handler for every "choose file" button (data-input: hidden field for the
	 * attachment ID, data-picked: where to show the name, data-library: e.g. "video").
	 * The block editor prints meta boxes before the media library script, so look for
	 * wp.media when the button is clicked, not when the box is printed.
	 */
	private static function picker_script() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<script>
		( function () {
			if ( window.nhFilePickBound ) { return; }
			window.nhFilePickBound = true;
			var frames = {};
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '.nh-media-pick' ) : null;
				if ( ! btn ) { return; }
				e.preventDefault();
				if ( ! window.wp || ! wp.media ) {
					window.alert( <?php echo wp_json_encode( __( 'The media library is still loading. Please try again in a moment.', 'neurohr-hub' ) ); ?> );
					return;
				}
				var key = btn.getAttribute( 'data-input' ), frame = frames[ key ];
				if ( ! frame ) {
					var opts = { title: btn.textContent, multiple: false };
					if ( btn.getAttribute( 'data-library' ) ) { opts.library = { type: btn.getAttribute( 'data-library' ) }; }
					frame = frames[ key ] = wp.media( opts );
					frame.on( 'select', function () {
						var a = frame.state().get( 'selection' ).first().toJSON();
						document.getElementById( key ).value = a.id;
						document.getElementById( btn.getAttribute( 'data-picked' ) ).textContent = a.filename + ' — ' + <?php echo wp_json_encode( __( 'will be moved to the protected folder when you save', 'neurohr-hub' ) ); ?>;
					} );
				}
				frame.open();
			} );
		} )();
		</script>
		<?php
	}

	public static function render_video_box( $post ) {
		$url      = get_post_meta( $post->ID, self::VIDEO_META, true );
		$length   = get_post_meta( $post->ID, self::LENGTH_META, true );
		$own      = self::get_video_file( $post->ID );
		$incoming = self::incoming_videos();
		$error    = get_transient( 'nh_video_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'nh_video_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		?>
		<p><strong><?php esc_html_e( 'Video file on this site (protected)', 'neurohr-hub' ); ?></strong></p>
		<?php if ( $own ) : ?>
			<p><?php echo esc_html( $own['name'] ); ?> · <?php echo esc_html( self::format_size( $own['size'] ) ); ?> — <?php esc_html_e( 'plays only for logged-in members with the right level', 'neurohr-hub' ); ?></p>
			<p><label><input type="checkbox" name="nh_video_file_remove" value="1"> <?php esc_html_e( 'Remove this video file', 'neurohr-hub' ); ?></label></p>
		<?php endif; ?>
		<p>
			<input type="hidden" name="nh_video_attachment" id="nh-video-attachment" value="">
			<button type="button" class="button nh-media-pick" data-input="nh-video-attachment" data-picked="nh-video-picked" data-library="video"><?php echo $own ? esc_html__( 'Replace video…', 'neurohr-hub' ) : esc_html__( 'Choose or upload video…', 'neurohr-hub' ); ?></button>
			<span id="nh-video-picked" class="description"></span>
		</p>
		<?php if ( $incoming ) : ?>
			<p><label for="nh-video-incoming"><?php esc_html_e( 'Or a file you put in the incoming folder (cPanel / FTP):', 'neurohr-hub' ); ?></label><br>
				<select id="nh-video-incoming" name="nh_video_incoming">
					<option value=""><?php esc_html_e( '— none —', 'neurohr-hub' ); ?></option>
					<?php foreach ( $incoming as $name => $bytes ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name . ' (' . self::format_size( $bytes ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select></p>
		<?php endif; ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: max upload size, 2: folder */
					__( 'MP4 (H.264) recommended, 720p is enough. This site accepts uploads up to %1$s. Bigger files: upload them with cPanel File Manager or FTP into %2$s, then pick them above.', 'neurohr-hub' ),
					size_format( wp_max_upload_size() ),
					'wp-content/uploads/' . self::DIR . '/' . self::INCOMING . '/'
				)
			);
			?>
		</p>
		<?php self::picker_script(); ?>
		<hr>
		<p><label for="nh-video-url"><?php esc_html_e( 'Or a YouTube or Vimeo link (used when there is no video file)', 'neurohr-hub' ); ?></label><br>
			<input type="url" class="widefat" id="nh-video-url" name="nh_video_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://vimeo.com/123456789"></p>
		<?php if ( $url && ! self::embed_url( $url ) ) : ?>
			<p class="notice notice-warning inline" style="padding:6px 10px"><?php esc_html_e( 'This link is not recognised as YouTube or Vimeo.', 'neurohr-hub' ); ?></p>
		<?php endif; ?>
		<p><label for="nh-video-length"><?php esc_html_e( 'Length (optional)', 'neurohr-hub' ); ?></label>
			<input type="text" id="nh-video-length" name="nh_video_length" value="<?php echo esc_attr( $length ); ?>" placeholder="12:30" style="width:100px"></p>
		<p class="description"><?php esc_html_e( 'For paid videos use a video file on this site (or Vimeo with "embed only on specific domains"). Unlisted YouTube links can be shared by anyone who has them. Set a featured image: it is the cover of the player, and locked members see it instead of the player.', 'neurohr-hub' ); ?></p>
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

		// Video file on this site.
		if ( ! empty( $_POST['nh_video_file_remove'] ) ) {
			self::delete_stored( $post_id, self::VIDEO_FILE );
		}
		$video_att = isset( $_POST['nh_video_attachment'] ) ? absint( $_POST['nh_video_attachment'] ) : 0;
		$incoming  = isset( $_POST['nh_video_incoming'] ) ? wp_basename( sanitize_text_field( wp_unslash( $_POST['nh_video_incoming'] ) ) ) : ''; // Must match a file listed in the folder (checked below).
		$stored    = null;
		if ( $video_att && 'attachment' === get_post_type( $video_att ) && current_user_can( 'delete_post', $video_att ) ) {
			$source = get_attached_file( $video_att );
			$stored = self::store_file( $source, wp_basename( $source ), 'video', true );
			if ( ! is_wp_error( $stored ) ) {
				wp_delete_attachment( $video_att, true ); // Gone from the public uploads folder.
			}
		} elseif ( $incoming && isset( self::incoming_videos()[ $incoming ] ) ) {
			$stored = self::store_file( self::dir() . '/' . self::INCOMING . '/' . $incoming, $incoming, 'video', true );
		}
		if ( is_wp_error( $stored ) ) {
			set_transient( 'nh_video_error_' . get_current_user_id(), $stored->get_error_message(), 120 );
		} elseif ( $stored ) {
			self::delete_stored( $post_id, self::VIDEO_FILE );
			update_post_meta( $post_id, self::VIDEO_FILE, $stored );
		}

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

	/* ---------- BetterDocs attachments => protected folder ---------- */

	/**
	 * Attachment files that are still in the public media library move into the protected
	 * folder. They stay attachments of their resource (same name, same place on the page);
	 * links to other sites stay as they are. Returns a report.
	 */
	public static function convert_attachments() {
		return NH_Attachments::protect_all();
	}
}
