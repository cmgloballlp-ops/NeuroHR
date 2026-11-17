<?php
/**
 * Attachments of a resource (documents that are not Templates of their own).
 *
 * - Editor → "Attachments" box: upload / choose files, or add links; rename, reorder, remove.
 * - Uploaded files go to uploads/nh-protected/attachments/ and are served through
 *   ?nh_file={resource}&k={key}: only readers of the resource get them (visitors too, on a
 *   free sample). PDF and pictures open in a new tab, other files download.
 * - Readers below the level see the names with a lock (the upgrade box is on the page).
 * - Items from BetterDocs (title + address of a file in the public media library) can be moved
 *   into the protected folder with one tick.
 * Stored in _nh_attachments: list of array( k, title, file|url ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Attachments {

	const META  = '_nh_attachments';
	const NONCE = 'nh_hub_attach_nonce';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_files_with_post' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 10, 2 );
		}
	}

	/* ---------- Data ---------- */

	/** Items with a key each (old BetterDocs items get one). */
	public static function items( $post_id ) {
		$raw = get_post_meta( $post_id, self::META, true );
		$out = array();
		foreach ( (array) $raw as $i => $item ) {
			if ( ! is_array( $item ) || ( empty( $item['file'] ) && empty( $item['url'] ) ) ) {
				continue;
			}
			$item['k']     = ! empty( $item['k'] ) ? sanitize_key( $item['k'] ) : 'old' . $i;
			$item['title'] = isset( $item['title'] ) && '' !== $item['title'] ? $item['title'] : ( ! empty( $item['file'] ) ? $item['file']['name'] : wp_basename( (string) $item['url'] ) );
			$out[]         = $item;
		}
		return $out;
	}

	public static function url( $post_id, $item ) {
		return ! empty( $item['file'] )
			? add_query_arg( array( 'nh_file' => (int) $post_id, 'k' => $item['k'] ), home_url( '/' ) )
			: $item['url'];
	}

	/** Media library attachment ID behind an old item's address (a public file on this site), or 0. */
	private static function public_media_id( $item ) {
		if ( ! empty( $item['file'] ) || empty( $item['url'] ) ) {
			return 0;
		}
		$uploads = wp_get_upload_dir();
		if ( 0 !== strpos( $item['url'], $uploads['baseurl'] ) ) {
			return 0;
		}
		return (int) attachment_url_to_postid( $item['url'] );
	}

	/** "PDF · 1.2 MB", "Link", … */
	private static function describe( $item ) {
		if ( ! empty( $item['file'] ) ) {
			return strtoupper( pathinfo( $item['file']['name'], PATHINFO_EXTENSION ) ) . ' · ' . NH_Media::format_size( $item['file']['size'] );
		}
		$ext = strtolower( pathinfo( (string) wp_parse_url( $item['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$docs = array_merge( NH_Media::ALLOWED_EXTS, NH_Media::IMAGE_EXTS );
		return in_array( $ext, $docs, true ) ? strtoupper( $ext ) . ' · ' . __( 'link', 'neurohr-hub' ) : __( 'Link', 'neurohr-hub' );
	}

	/** Resources whose attachments still include public media-library files. */
	public static function with_public_files() {
		$out = array();
		$ids = get_posts( array( 'post_type' => NH_Post_Types::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'nh_all_langs' => true, 'meta_key' => self::META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- admin tool.
		foreach ( $ids as $id ) {
			foreach ( self::items( $id ) as $item ) {
				if ( self::public_media_id( $item ) ) {
					$out[] = $id;
					break;
				}
			}
		}
		return $out;
	}

	/** Move every public attachment file into the protected folder. */
	public static function protect_all() {
		$report = array( 'moved' => array(), 'kept' => 0, 'missing' => array() );
		foreach ( self::with_public_files() as $post_id ) {
			$items = self::items( $post_id );
			foreach ( $items as $i => $item ) {
				$att = self::public_media_id( $item );
				if ( ! $att ) {
					$report['kept']++;
					continue;
				}
				$source = get_attached_file( $att );
				$stored = NH_Media::store_file( $source, wp_basename( (string) $source ), 'attach', true );
				if ( is_wp_error( $stored ) ) {
					$report['missing'][] = $item['title'] . ' (' . $stored->get_error_message() . ')';
					continue;
				}
				$report['moved'][] = array( 'title' => $item['title'], 'url' => $item['url'] );
				wp_delete_attachment( $att, true );
				unset( $items[ $i ]['url'] );
				$items[ $i ]['file'] = $stored;
			}
			update_post_meta( $post_id, self::META, array_values( $items ) );
		}
		return $report;
	}

	/* ---------- Serving ---------- */

	public static function serve() {
		if ( ! isset( $_GET['nh_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- access is checked below.
			return;
		}
		$post_id = absint( $_GET['nh_file'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$key     = isset( $_GET['k'] ) ? sanitize_key( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = get_post( $post_id );
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$item = null;
		if ( $post && NH_Post_Types::CPT === $post->post_type && 'publish' === $post->post_status ) {
			foreach ( self::items( $post_id ) as $candidate ) {
				if ( $candidate['k'] === $key && ! empty( $candidate['file'] ) ) {
					$item = $candidate;
					break;
				}
			}
		}
		if ( ! $item ) {
			status_header( 404 );
			wp_die( esc_html__( 'This file does not exist.', 'neurohr-hub' ), '', array( 'response' => 404 ) );
		}
		if ( ! is_user_logged_in() && ! NH_Access::is_public( $post ) ) {
			wp_safe_redirect( NH_Login::login_url( get_permalink( $post ) ) );
			exit;
		}
		if ( ! NH_Access::can_view( $post ) ) {
			wp_safe_redirect( get_permalink( $post ) . '#nh-locked' );
			exit;
		}
		NH_Media::send_file( $item['file'] );
	}

	/* ---------- Front end ---------- */

	public static function render( $post_id, $can ) {
		$items = self::items( $post_id );
		if ( ! $items ) {
			return '';
		}
		ob_start();
		?>
		<div class="nh-attachments">
			<h2 class="nh-attachments__title"><?php esc_html_e( 'Attachments', 'neurohr-hub' ); ?></h2>
			<ul class="nh-attachments__list">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$name   = ! empty( $item['file'] ) ? $item['file']['name'] : (string) wp_parse_url( $item['url'], PHP_URL_PATH );
					$inline = empty( $item['file'] ) || NH_Media::opens_inline( $name );
					?>
					<li>
						<?php if ( $can ) : ?>
							<a href="<?php echo esc_url( self::url( $post_id, $item ) ); ?>" rel="nofollow noopener"<?php echo $inline ? ' target="_blank"' : ''; ?>>
								<span class="nh-attachments__name"><?php echo esc_html( $item['title'] ); ?></span>
							</a>
						<?php else : ?>
							<a href="#nh-locked" class="is-locked"><span class="nh-attachments__name">&#128274; <?php echo esc_html( $item['title'] ); ?></span></a>
						<?php endif; ?>
						<span class="nh-attachments__meta"><?php echo esc_html( self::describe( $item ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $can ) : ?>
				<p class="nh-attachments__note">
					<?php
					/* translators: %s: level */
					echo esc_html( sprintf( __( 'Available to %s members and above.', 'neurohr-hub' ), nh_hub_level_label( NH_Access::get_post_level( $post_id ) ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- Editor ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_attachments', __( 'Attachments', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'normal', 'default' );
	}

	private static function row( $item ) {
		$file   = ! empty( $item['file'] );
		$public = self::public_media_id( $item );
		?>
		<li class="nh-att-row">
			<input type="hidden" name="nh_att_k[]" value="<?php echo esc_attr( isset( $item['k'] ) ? $item['k'] : '' ); ?>">
			<input type="hidden" name="nh_att_media[]" value="<?php echo isset( $item['media'] ) ? (int) $item['media'] : 0; ?>">
			<input type="text" name="nh_att_title[]" value="<?php echo esc_attr( $item['title'] ); ?>" class="nh-att-title" placeholder="<?php esc_attr_e( 'Name shown to members', 'neurohr-hub' ); ?>">
			<?php if ( $file ) : ?>
				<input type="hidden" name="nh_att_url[]" value="">
				<span class="nh-att-what"><?php echo esc_html( $item['file']['name'] . ' · ' . NH_Media::format_size( $item['file']['size'] ) ); ?> · <?php esc_html_e( 'protected', 'neurohr-hub' ); ?></span>
			<?php elseif ( ! empty( $item['media'] ) ) : ?>
				<input type="hidden" name="nh_att_url[]" value="">
				<span class="nh-att-what"><?php echo esc_html( $item['note'] ); ?></span>
			<?php else : ?>
				<input type="url" name="nh_att_url[]" value="<?php echo esc_attr( $item['url'] ); ?>" class="nh-att-url" placeholder="https://">
			<?php endif; ?>
			<span class="nh-att-tools">
				<?php if ( $public ) : ?>
					<label class="nh-att-protect" title="<?php esc_attr_e( 'This file is in the public media library: anyone with the address can open it.', 'neurohr-hub' ); ?>"><input type="checkbox" name="nh_att_protect[]" value="<?php echo esc_attr( $item['k'] ); ?>"> <?php esc_html_e( 'Move to protected folder', 'neurohr-hub' ); ?></label>
				<?php endif; ?>
				<button type="button" class="button-link nh-att-up" aria-label="<?php esc_attr_e( 'Move up', 'neurohr-hub' ); ?>">↑</button>
				<button type="button" class="button-link nh-att-down" aria-label="<?php esc_attr_e( 'Move down', 'neurohr-hub' ); ?>">↓</button>
				<button type="button" class="button-link nh-att-remove"><?php esc_html_e( 'Remove', 'neurohr-hub' ); ?></button>
			</span>
		</li>
		<?php
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_save_attach', self::NONCE );
		$error = get_transient( 'nh_attach_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'nh_attach_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		?>
		<p class="description"><?php esc_html_e( 'Documents shown under this resource. Uploaded files are protected: only readers of this resource can open them. PDF and pictures open in a new tab, other files download. Members below the level see the names with a lock.', 'neurohr-hub' ); ?></p>
		<ul id="nh-att-list" class="nh-att-list">
			<?php foreach ( self::items( $post->ID ) as $item ) : ?>
				<?php self::row( $item ); ?>
			<?php endforeach; ?>
		</ul>
		<p class="nh-att-add">
			<button type="button" class="button" id="nh-att-upload"><?php esc_html_e( 'Upload or choose files…', 'neurohr-hub' ); ?></button>
			<button type="button" class="button" id="nh-att-link"><?php esc_html_e( 'Add a link', 'neurohr-hub' ); ?></button>
		</p>
		<template id="nh-att-tpl">
			<?php self::row( array( 'k' => '', 'title' => '', 'url' => '' ) ); ?>
		</template>
		<style>
			.nh-att-list{margin:10px 0}.nh-att-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:6px 8px;margin:0 0 4px;background:#f6f7f7;border-radius:6px}
			.nh-att-title{flex:1 1 240px}.nh-att-url{flex:1 1 260px}.nh-att-what{color:#50575e;font-size:12px;flex:1 1 220px}
			.nh-att-tools{display:flex;gap:10px;align-items:center}.nh-att-protect{color:#b32d2e;font-size:12px}
		</style>
		<script>
		( function () {
			var list = document.getElementById( 'nh-att-list' ), tpl = document.getElementById( 'nh-att-tpl' ), frame;
			var t = <?php echo wp_json_encode( array( 'note' => __( 'will be uploaded to the protected folder when you save', 'neurohr-hub' ), 'loading' => __( 'The media library is still loading. Please try again in a moment.', 'neurohr-hub' ), 'title' => __( 'Choose attachments', 'neurohr-hub' ) ) ); ?>;
			function newRow() {
				var li = tpl.content.firstElementChild.cloneNode( true );
				list.appendChild( li );
				return li;
			}
			document.getElementById( 'nh-att-link' ).addEventListener( 'click', function () {
				newRow().querySelector( '.nh-att-title' ).focus();
			} );
			document.getElementById( 'nh-att-upload' ).addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) { window.alert( t.loading ); return; }
				frame = frame || wp.media( { title: t.title, multiple: true } );
				frame.off( 'select' ).on( 'select', function () {
					frame.state().get( 'selection' ).each( function ( att ) {
						var a = att.toJSON(), li = newRow(), url = li.querySelector( '.nh-att-url' );
						li.querySelector( 'input[name="nh_att_media[]"]' ).value = a.id;
						li.querySelector( '.nh-att-title' ).value = a.title || a.filename;
						var span = document.createElement( 'span' ); span.className = 'nh-att-what'; span.textContent = a.filename + ' — ' + t.note;
						url.type = 'hidden'; url.value = ''; url.parentNode.insertBefore( span, url );
					} );
				} );
				frame.open();
			} );
			list.addEventListener( 'click', function ( e ) {
				var li = e.target.closest( '.nh-att-row' );
				if ( ! li ) { return; }
				if ( e.target.classList.contains( 'nh-att-remove' ) ) { li.remove(); }
				if ( e.target.classList.contains( 'nh-att-up' ) && li.previousElementSibling ) { list.insertBefore( li, li.previousElementSibling ); }
				if ( e.target.classList.contains( 'nh-att-down' ) && li.nextElementSibling ) { list.insertBefore( li.nextElementSibling, li ); }
			} );
		} )();
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_save_attach' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each value is sanitized below.
		$keys    = isset( $_POST['nh_att_k'] ) ? (array) wp_unslash( $_POST['nh_att_k'] ) : array();
		$titles  = isset( $_POST['nh_att_title'] ) ? (array) wp_unslash( $_POST['nh_att_title'] ) : array();
		$urls    = isset( $_POST['nh_att_url'] ) ? (array) wp_unslash( $_POST['nh_att_url'] ) : array();
		$media   = isset( $_POST['nh_att_media'] ) ? (array) wp_unslash( $_POST['nh_att_media'] ) : array();
		$protect = isset( $_POST['nh_att_protect'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['nh_att_protect'] ) ) : array();
		// phpcs:enable

		$old = array();
		foreach ( self::items( $post_id ) as $item ) {
			$old[ $item['k'] ] = $item;
		}
		$new    = array();
		$errors = array();
		foreach ( $titles as $i => $title ) {
			$title = sanitize_text_field( $title );
			$k     = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';
			$url   = isset( $urls[ $i ] ) ? esc_url_raw( trim( $urls[ $i ] ) ) : '';
			$mid   = isset( $media[ $i ] ) ? absint( $media[ $i ] ) : 0;

			if ( $k && isset( $old[ $k ] ) ) {
				// Existing item: new name; a link may get a new address; an old public file may move in.
				$item          = $old[ $k ];
				$item['title'] = '' !== $title ? $title : $item['title'];
				if ( empty( $item['file'] ) && $url ) {
					$item['url'] = $url;
				}
				if ( in_array( $k, $protect, true ) ) {
					$att = self::public_media_id( $item );
					if ( $att && current_user_can( 'delete_post', $att ) ) {
						$source = get_attached_file( $att );
						$stored = NH_Media::store_file( $source, wp_basename( $source ), 'attach', true );
						if ( is_wp_error( $stored ) ) {
							$errors[] = $item['title'] . ': ' . $stored->get_error_message();
						} else {
							wp_delete_attachment( $att, true ); // No longer in the public uploads folder.
							unset( $item['url'] );
							$item['file'] = $stored;
						}
					}
				}
				$new[] = $item;
				unset( $old[ $k ] );
			} elseif ( $mid && 'attachment' === get_post_type( $mid ) && current_user_can( 'delete_post', $mid ) ) {
				// Newly uploaded / chosen file: move it into the protected folder.
				$source = get_attached_file( $mid );
				$stored = NH_Media::store_file( $source, wp_basename( $source ), 'attach', true );
				if ( is_wp_error( $stored ) ) {
					$errors[] = wp_basename( (string) $source ) . ': ' . $stored->get_error_message();
					continue;
				}
				wp_delete_attachment( $mid, true );
				$new[] = array( 'k' => strtolower( wp_generate_password( 10, false, false ) ), 'title' => '' !== $title ? $title : $stored['name'], 'file' => $stored );
			} elseif ( $url ) {
				$new[] = array( 'k' => strtolower( wp_generate_password( 10, false, false ) ), 'title' => '' !== $title ? $title : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), 'url' => $url );
			}
		}
		// Removed items: delete their protected files.
		foreach ( $old as $item ) {
			self::delete_file( $item );
		}
		if ( $new ) {
			update_post_meta( $post_id, self::META, $new );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		if ( $errors ) {
			set_transient( 'nh_attach_error_' . get_current_user_id(), implode( ' — ', $errors ), 120 );
		}
	}

	private static function delete_file( $item ) {
		if ( ! empty( $item['file'] ) ) {
			$path = NH_Media::file_path( $item['file'] );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
	}

	public static function delete_files_with_post( $post_id ) {
		if ( NH_Post_Types::CPT === get_post_type( $post_id ) ) {
			foreach ( self::items( $post_id ) as $item ) {
				self::delete_file( $item );
			}
		}
	}
}
