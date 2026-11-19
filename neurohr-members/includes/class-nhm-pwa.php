<?php
/**
 * Install the member area as an app (PWA, 0.5).
 *
 * - /nhm-manifest.webmanifest : name, icons, colours, opens on the Dashboard.
 * - /nhm-sw.js                : service worker at the site root (so it covers every page).
 * - /nhm-offline/             : the page shown when there is no internet.
 *
 * Members-only pages are NEVER stored on the phone: pages always come from the internet,
 * and only CSS, JS, fonts and pictures are kept so the app opens fast.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_PWA {

	const CACHE_V = 1;

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'login_head', array( __CLASS__, 'head' ), 2 );
	}

	public static function on() {
		return (bool) NHM_Settings::get( 'pwa_on' ) && (bool) self::icon_url( 512 );
	}

	/** Which of our files was asked for: manifest | sw | offline | ''. */
	public static function route( $path = null ) {
		if ( null === $path ) {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$map  = array(
			$base . '/nhm-manifest.webmanifest' => 'manifest',
			$base . '/nhm-sw.js'                => 'sw',
			$base . '/nhm-offline'              => 'offline',
			$base . '/nhm-offline/'             => 'offline',
		);
		return isset( $map[ $path ] ) ? $map[ $path ] : '';
	}

	public static function serve() {
		$what = self::route();
		if ( '' === $what || ! NHM_Settings::get( 'pwa_on' ) ) {
			return;
		}
		nocache_headers();
		if ( 'manifest' === $what ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		} elseif ( 'sw' === $what ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
			echo self::service_worker(); // phpcs:ignore WordPress.Security.EscapeOutput -- our own script.
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo self::offline_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in method.
		}
		exit;
	}

	/* ---------- Icons ---------- */

	/** Icon URL at about $size px: the App Icon setting, else the Site Icon. */
	public static function icon_url( $size = 512 ) {
		$own = trim( (string) NHM_Settings::get( 'pwa_icon' ) );
		if ( '' !== $own ) {
			$id = attachment_url_to_postid( $own );
			if ( $id ) {
				$src = wp_get_attachment_image_src( $id, array( $size, $size ) );
				if ( $src && (int) $src[1] >= min( $size, 192 ) ) {
					return $src[0];
				}
			}
			return $own;
		}
		return function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( $size ) : '';
	}

	/** Real pixel size of an icon URL ("512x512"), so phones accept it. */
	private static function icon_sizes( $url, $fallback ) {
		$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url ) );
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['width'] ) ) {
				$base = wp_basename( (string) $meta['file'] );
				if ( wp_basename( $url ) !== $base && ! empty( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $s ) {
						if ( wp_basename( $url ) === $s['file'] ) {
							return $s['width'] . 'x' . $s['height'];
						}
					}
				}
				return $meta['width'] . 'x' . $meta['height'];
			}
		}
		return $fallback;
	}

	public static function manifest() {
		$start = NHM_Settings::url( 'home_redirect', home_url( '/' ) );
		$icons = array();
		$seen  = array();
		foreach ( array( 192, 512 ) as $size ) {
			$url = self::icon_url( $size );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$type         = wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) );
			$icons[]      = array_filter(
				array(
					'src'     => $url,
					'sizes'   => self::icon_sizes( $url, $size . 'x' . $size ),
					'type'    => $type['type'] ? $type['type'] : null,
					'purpose' => 'any',
				)
			);
		}
		$manifest = array(
			'id'               => wp_parse_url( $start, PHP_URL_PATH ),
			'name'             => (string) NHM_Settings::get( 'pwa_name' ),
			'short_name'       => (string) NHM_Settings::get( 'pwa_short' ),
			'description'      => get_bloginfo( 'description' ),
			'start_url'        => add_query_arg( 'source', 'app', $start ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'theme_color'      => (string) NHM_Settings::get( 'pwa_color' ),
			'background_color' => (string) NHM_Settings::get( 'pwa_bg' ),
			'lang'             => get_bloginfo( 'language' ),
			'icons'            => $icons,
		);
		return apply_filters( 'nhm_pwa_manifest', $manifest );
	}

	/* ---------- <head> ---------- */

	public static function head() {
		if ( ! self::on() ) {
			return;
		}
		$name = (string) NHM_Settings::get( 'pwa_short' );
		echo '<link rel="manifest" href="' . esc_url( home_url( '/nhm-manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( NHM_Settings::get( 'pwa_color' ) ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::icon_url( 180 ) ) . '">' . "\n";
	}

	/* ---------- Service worker ---------- */

	public static function service_worker() {
		$offline = home_url( '/nhm-offline/' );
		$cache   = 'nhm-v' . self::CACHE_V . '-' . NHM_VERSION;
		$skip    = array( '/wp-admin', '/wp-login.php', '/wp-json', 'admin-ajax.php', 'nhm_download', 'nh_file', 'nh-file', 'stripe' );
		$skip    = apply_filters( 'nhm_pwa_never_cache', $skip );
		$js      = <<<'JS'
/* NeuroHR app: service worker. Pages are never stored; only CSS, JS, fonts and pictures. */
var CACHE = __CACHE__;
var OFFLINE = __OFFLINE__;
var SKIP = __SKIP__;

self.addEventListener( 'install', function ( e ) {
	e.waitUntil( caches.open( CACHE ).then( function ( c ) {
		return c.add( new Request( OFFLINE, { cache: 'reload' } ) );
	} ).then( function () { return self.skipWaiting(); } ) );
} );

self.addEventListener( 'activate', function ( e ) {
	e.waitUntil( caches.keys().then( function ( keys ) {
		return Promise.all( keys.filter( function ( k ) { return k.indexOf( 'nhm-' ) === 0 && k !== CACHE; } ).map( function ( k ) { return caches.delete( k ); } ) );
	} ).then( function () { return self.clients.claim(); } ) );
} );

function skipped( url ) {
	for ( var i = 0; i < SKIP.length; i++ ) {
		if ( url.indexOf( SKIP[ i ] ) !== -1 ) { return true; }
	}
	return false;
}

self.addEventListener( 'fetch', function ( e ) {
	var req = e.request;
	if ( req.method !== 'GET' ) { return; }
	// Pages: always from the internet (members-only content is never stored). No internet: the offline page.
	if ( req.mode === 'navigate' ) {
		e.respondWith( fetch( req ).catch( function () { return caches.match( OFFLINE ); } ) );
		return;
	}
	var dest = req.destination;
	if ( [ 'style', 'script', 'font', 'image' ].indexOf( dest ) === -1 || skipped( req.url ) ) { return; }
	var url = new URL( req.url );
	if ( url.origin !== self.location.origin && url.hostname.indexOf( 'fonts.g' ) !== 0 ) { return; }
	// Look: show the stored copy at once, refresh it in the background.
	e.respondWith( caches.open( CACHE ).then( function ( c ) {
		return c.match( req ).then( function ( hit ) {
			var net = fetch( req ).then( function ( res ) {
				if ( res && res.ok && ( res.type === 'basic' || res.type === 'cors' ) ) { c.put( req, res.clone() ); }
				return res;
			} ).catch( function () { return hit; } );
			return hit || net;
		} );
	} ) );
} );
JS;
		return strtr(
			$js,
			array(
				'__CACHE__'   => wp_json_encode( $cache ),
				'__OFFLINE__' => wp_json_encode( $offline, JSON_UNESCAPED_SLASHES ),
				'__SKIP__'    => wp_json_encode( array_values( $skip ), JSON_UNESCAPED_SLASHES ),
			)
		);
	}

	public static function offline_html() {
		$name  = (string) NHM_Settings::get( 'pwa_name' );
		$brand = (string) NHM_Settings::get( 'pwa_color' );
		$icon  = self::icon_url( 192 );
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>' . esc_html( $name ) . '</title>'
			. '<style>html,body{margin:0;height:100%;background:' . esc_attr( NHM_Settings::get( 'pwa_bg' ) ) . ';font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d2330}main{min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:32px 28px;box-sizing:border-box}img{width:72px;height:72px;border-radius:20px;box-shadow:0 10px 30px rgba(29,35,48,.12);margin-bottom:22px}h1{font-size:22px;margin:0 0 8px}p{margin:0 0 26px;color:#6b7280;line-height:1.55;max-width:300px}button{border:0;border-radius:999px;background:' . esc_attr( $brand ) . ';color:#fff;font:inherit;font-weight:700;padding:14px 28px;font-size:15px}</style></head>'
			. '<body><main>' . ( $icon ? '<img src="' . esc_url( $icon ) . '" alt="">' : '' ) . '<h1>' . esc_html__( "You're offline", 'neurohr-members' ) . '</h1><p>' . esc_html__( 'No internet connection right now. Check your Wi-Fi or data, then try again.', 'neurohr-members' ) . '</p><button type="button" onclick="location.reload()">' . esc_html__( 'Try Again', 'neurohr-members' ) . '</button></main></body></html>';
	}

	/* ---------- iPhone: how to install ---------- */

	public static function ios_sheet() {
		$share = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M16 6l-4-4-4 4M12 2v13"/></svg>';
		$plus  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';
		$h     = '<div class="nhm-iosinstall" id="nhm-iosinstall" role="dialog" aria-modal="true" aria-labelledby="nhm-iosinstall-t" hidden><div class="nhm-iosinstall__card">';
		$h    .= '<img src="' . esc_url( self::icon_url( 192 ) ) . '" alt="" width="64" height="64">';
		/* translators: %s: app name */
		$h .= '<h2 id="nhm-iosinstall-t">' . esc_html( sprintf( __( 'Add %s to your Home Screen', 'neurohr-members' ), NHM_Settings::get( 'pwa_short' ) ) ) . '</h2>';
		$h .= '<ol><li><span>' . $share . '</span>' . esc_html__( 'Tap the Share button in Safari.', 'neurohr-members' ) . '</li><li><span>' . $plus . '</span>' . esc_html__( 'Choose "Add to Home Screen".', 'neurohr-members' ) . '</li><li><span>✓</span>' . esc_html__( 'Tap "Add". The app is on your Home Screen.', 'neurohr-members' ) . '</li></ol>';
		$h .= '<button type="button" class="nhm-btn" data-nhm-ios-close>' . esc_html__( 'Got It', 'neurohr-members' ) . '</button></div></div>';
		return $h;
	}
}
