<?php
/**
 * Profile photos uploaded by members (My account → Profile). They replace Gravatar everywhere
 * on the site (comments, admin bar, the member area). People without a photo get a circle
 * with their initials in the brand colour — no request goes to gravatar.com.
 *
 * Files: wp-content/uploads/nhm-avatars/{user id}-{random}.jpg, cropped square to 400 px.
 * User meta nhm_avatar holds the file name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_Avatar {

	const MAX_BYTES = 2097152; // 2 MB
	const SIZE      = 400;

	public static function init() {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar_data' ), 20, 2 );
		add_filter( 'nhm_form_handlers', array( __CLASS__, 'handlers' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_files' ) );
	}

	public static function handlers( $h ) {
		$h['avatar']        = array( __CLASS__, 'handle_upload' );
		$h['avatar_remove'] = array( __CLASS__, 'handle_remove' );
		return $h;
	}

	private static function dir() {
		$up = wp_upload_dir( null, false );
		return array( trailingslashit( $up['basedir'] ) . 'nhm-avatars/', trailingslashit( $up['baseurl'] ) . 'nhm-avatars/' );
	}

	/** Photo URL, or '' when the person has none. */
	public static function url( $user_id ) {
		$file = (string) get_user_meta( (int) $user_id, 'nhm_avatar', true );
		if ( '' === $file ) {
			return '';
		}
		list( $dir, $url ) = self::dir();
		return file_exists( $dir . $file ) ? $url . rawurlencode( $file ) : '';
	}

	public static function initials( $user ) {
		$name  = trim( ( isset( $user->first_name ) ? $user->first_name : '' ) . ' ' . ( isset( $user->last_name ) ? $user->last_name : '' ) );
		$name  = '' !== $name ? $name : ( $user->display_name ? $user->display_name : $user->user_login );
		$parts = preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$first = $parts ? mb_substr( $parts[0], 0, 1 ) : '?';
		$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}

	/**
	 * Initials in a coloured circle: a small SVG file in uploads/nhm-avatars/, written once per
	 * initials + colour. A real file (not a data: URL), because WordPress and Elementor remove
	 * data: addresses from avatar URLs.
	 */
	public static function initials_url( $user ) {
		$brand = NHM_Settings::brand();
		$text  = self::initials( $user );
		$svg   = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="' . esc_attr( $brand ) . '"/><text x="48" y="50" dy=".35em" text-anchor="middle" font-family="Plus Jakarta Sans,Arial,sans-serif" font-size="38" font-weight="700" fill="#fff">' . esc_html( $text ) . '</text></svg>';
		$name  = 'i-' . substr( md5( $text . '|' . $brand ), 0, 12 ) . '.svg';
		list( $dir, $url ) = self::dir();
		if ( ! file_exists( $dir . $name ) ) {
			if ( ! wp_mkdir_p( $dir ) || false === @file_put_contents( $dir . $name, $svg ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- only if uploads is not writable.
			}
		}
		return $url . $name;
	}

	private static function user_from( $id_or_email ) {
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return get_userdata( (int) $id_or_email->post_author );
		}
		if ( $id_or_email instanceof WP_Comment ) {
			return $id_or_email->user_id ? get_userdata( (int) $id_or_email->user_id ) : get_user_by( 'email', $id_or_email->comment_author_email );
		}
		if ( is_numeric( $id_or_email ) ) {
			return get_userdata( (int) $id_or_email );
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			return get_user_by( 'email', $id_or_email );
		}
		return false;
	}

	public static function avatar_data( $args, $id_or_email ) {
		$user = self::user_from( $id_or_email );
		if ( ! $user ) {
			// A guest commenter: initials of the name, still no Gravatar.
			$name = $id_or_email instanceof WP_Comment ? $id_or_email->comment_author : '';
			$fake = (object) array( 'first_name' => '', 'last_name' => '', 'display_name' => $name ? $name : '?', 'user_login' => '?' );
			$args['url']          = self::initials_url( $fake );
			$args['found_avatar'] = false;
			return $args;
		}
		$url = self::url( $user->ID );
		$args['url']          = $url ? $url : self::initials_url( $user );
		$args['found_avatar'] = true;
		return $args;
	}

	/* ---------- Upload / remove (My account → Profile) ---------- */

	public static function handle_upload() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- the dispatcher checked the nonce.
		$f = isset( $_FILES['nhm_photo'] ) && is_array( $_FILES['nhm_photo'] ) ? $_FILES['nhm_photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$r = self::save_upload( $user_id, $f );
		if ( is_wp_error( $r ) ) {
			NHM_Forms::error( 'avatar', $r->get_error_message() );
			return;
		}
		NHM_Forms::done( NHM_Forms::current_url( array( 'nhm_msg' ) ), 'photo_saved' );
	}

	/** @return true|WP_Error */
	public static function save_upload( $user_id, $f ) {
		if ( ! $f || empty( $f['tmp_name'] ) || ! empty( $f['error'] ) ) {
			$big = $f && in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new WP_Error( 'nhm_photo', $big ? __( 'The photo is larger than 2 MB.', 'neurohr-members' ) : __( 'Please choose a photo.', 'neurohr-members' ) );
		}
		if ( (int) $f['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'nhm_photo', __( 'The photo is larger than 2 MB.', 'neurohr-members' ) );
		}
		$info = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) ) {
			return new WP_Error( 'nhm_photo', __( 'Please use a JPG or PNG photo.', 'neurohr-members' ) );
		}
		$editor = wp_get_image_editor( $f['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'nhm_photo', __( 'This photo could not be read. Please try another one.', 'neurohr-members' ) );
		}
		// Square from the centre, then 400 × 400.
		$size = $editor->get_size();
		$side = min( $size['width'], $size['height'] );
		$editor->crop( (int) ( ( $size['width'] - $side ) / 2 ), (int) ( ( $size['height'] - $side ) / 2 ), $side, $side, min( self::SIZE, $side ), min( self::SIZE, $side ) );
		$editor->set_quality( 86 );

		list( $dir ) = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'nhm_photo', __( 'The photo could not be saved. Please tell us.', 'neurohr-members' ) );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name  = (int) $user_id . '-' . wp_generate_password( 10, false, false ) . '.jpg';
		$saved = $editor->save( $dir . $name, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'nhm_photo', __( 'The photo could not be saved. Please tell us.', 'neurohr-members' ) );
		}
		self::delete_files( $user_id );
		update_user_meta( $user_id, 'nhm_avatar', $name );
		do_action( 'nhm_avatar_changed', $user_id );
		return true;
	}

	public static function handle_remove() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			self::delete_files( $user_id );
			do_action( 'nhm_avatar_changed', $user_id );
		}
		NHM_Forms::done( NHM_Forms::current_url( array( 'nhm_msg' ) ), 'photo_removed' );
	}

	public static function delete_files( $user_id ) {
		$file = (string) get_user_meta( (int) $user_id, 'nhm_avatar', true );
		if ( '' !== $file ) {
			list( $dir ) = self::dir();
			$path = $dir . basename( $file );
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_user_meta( (int) $user_id, 'nhm_avatar' );
	}

	/** <img> or initials for the member area (always round via CSS). */
	public static function img( $user_id, $size = 40, $class = 'nhm-avatar' ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return '';
		}
		$url = self::url( $user->ID );
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $url ? $url : self::initials_url( $user ), array( 'http', 'https', 'data' ) ) . '" width="' . (int) $size . '" height="' . (int) $size . '" alt="" loading="lazy" decoding="async">';
	}
}
