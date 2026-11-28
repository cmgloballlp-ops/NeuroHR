/* NeuroHR Members: phone app (bottom bar, search screen, More sheet, plan cards, install). */
( function () {
	'use strict';
	var cfg = window.nhmApp || {};
	var S = cfg.strings || {};
	var RECENT = 'nhm_recent_search';

	function store( key, val ) {
		try {
			if ( undefined === val ) { return JSON.parse( localStorage.getItem( key ) || 'null' ); }
			localStorage.setItem( key, JSON.stringify( val ) );
		} catch ( e ) { return null; }
	}
	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}
	function mark( text, q ) {
		var t = esc( text );
		var words = q.trim().split( /\s+/ ).filter( function ( w ) { return w.length > 1; } ).map( function ( w ) { return esc( w ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ); } );
		return words.length ? t.replace( new RegExp( '(' + words.join( '|' ) + ')', 'gi' ), '<mark>$1</mark>' ) : t;
	}

	var lastFocus = null;
	function lock( on ) { document.documentElement.style.overflow = on ? 'hidden' : ''; }

	/* ---------- Search screen ---------- */
	var screen = document.getElementById( 'nhm-searchscreen' );
	var input = screen && screen.querySelector( 'input[type=search]' );
	var results = screen && screen.querySelector( '[data-nhm-results]' );
	var recentBox = screen && screen.querySelector( '[data-nhm-recent]' );
	var timer = null, ctrl = null;

	function showRecent() {
		if ( ! recentBox ) { return; }
		var list = store( RECENT ) || [];
		var chips = recentBox.querySelector( '.nhm-chips' );
		chips.innerHTML = list.map( function ( q ) { return '<button type="button" data-q="' + esc( q ) + '">' + esc( q ) + '</button>'; } ).join( '' );
		recentBox.hidden = ! list.length;
	}
	function remember( q ) {
		q = q.trim();
		if ( q.length < 2 ) { return; }
		var list = ( store( RECENT ) || [] ).filter( function ( x ) { return x.toLowerCase() !== q.toLowerCase(); } );
		list.unshift( q );
		store( RECENT, list.slice( 0, 6 ) );
	}
	function openSearch() {
		if ( ! screen ) { return; }
		closeMore();
		lastFocus = document.activeElement;
		screen.hidden = false;
		lock( true );
		showRecent();
		setTimeout( function () { input && input.focus(); }, 30 );
	}
	function closeSearch() {
		if ( ! screen || screen.hidden ) { return; }
		screen.hidden = true;
		lock( false );
		if ( lastFocus ) { lastFocus.focus(); }
	}
	function live( q ) {
		if ( ! cfg.live ) { return; }
		if ( ctrl ) { ctrl.abort(); }
		if ( q.trim().length < 2 ) { results.innerHTML = ''; recentBox && ( recentBox.hidden = ! ( store( RECENT ) || [] ).length ); return; }
		if ( recentBox ) { recentBox.hidden = true; }
		results.innerHTML = '<p class="nhm-res__msg">' + esc( S.loading || '…' ) + '</p>';
		ctrl = window.AbortController ? new AbortController() : null;
		var url = cfg.live.url + '?action=' + encodeURIComponent( cfg.live.action ) + '&section=all&q=' + encodeURIComponent( q );
		fetch( url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				var d = r && r.success ? r.data : null;
				if ( ! d || ! d.items || ! d.items.length ) {
					results.innerHTML = '<p class="nhm-res__msg">' + esc( S.none || '' ) + '</p>';
					return;
				}
				var h = '<div class="nhm-res">';
				d.items.forEach( function ( it ) {
					var ab = ( it.meta || '' ).replace( /[^A-Za-z ]/g, '' ).trim().split( /\s+/ ).map( function ( w ) { return w.charAt( 0 ); } ).join( '' ).slice( 0, 3 ) || '•';
					h += '<a href="' + esc( it.url ) + '" data-q="' + esc( q ) + '"><i aria-hidden="true">' + esc( ab ) + '</i><span><b>' + mark( it.title, q ) + '</b><small>' + esc( it.meta || '' ) + '</small></span>' + ( it.locked ? '<span class="is-lock" title="' + esc( it.locked ) + '">🔒</span>' : '<span></span>' ) + '</a>';
				} );
				if ( d.all && d.total > d.items.length ) {
					h += '<a class="nhm-res__all" href="' + esc( d.all ) + '">' + esc( S.all || 'See All' ) + ' (' + d.total + ')</a>';
				}
				results.innerHTML = h + '</div>';
			} )
			.catch( function () {} );
	}
	if ( input ) {
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = input.value;
			timer = setTimeout( function () { live( q ); }, 220 );
		} );
		input.form.addEventListener( 'submit', function () { remember( input.value ); } );
	}

	/* ---------- More sheet ---------- */
	var more = document.getElementById( 'nhm-moresheet' );
	var back = document.querySelector( '.nhm-sheet-back' );
	function openMore() {
		if ( ! more ) { return; }
		lastFocus = document.activeElement;
		more.hidden = false;
		if ( back ) { back.hidden = false; }
		lock( true );
		var f = more.querySelector( 'a, button' );
		if ( f ) { f.focus( { preventScroll: true } ); }
	}
	function closeMore() {
		if ( ! more || more.hidden ) { return; }
		more.hidden = true;
		if ( back ) { back.hidden = true; }
		lock( false );
		if ( lastFocus ) { lastFocus.focus(); }
	}
	// Swipe the sheet down to close it.
	if ( more ) {
		var y0 = null;
		more.addEventListener( 'touchstart', function ( e ) { y0 = more.scrollTop <= 0 ? e.touches[ 0 ].clientY : null; }, { passive: true } );
		more.addEventListener( 'touchmove', function ( e ) {
			if ( null === y0 ) { return; }
			var dy = e.touches[ 0 ].clientY - y0;
			more.style.transform = dy > 0 ? 'translateY(' + dy + 'px)' : '';
		}, { passive: true } );
		more.addEventListener( 'touchend', function ( e ) {
			if ( null === y0 ) { return; }
			var dy = e.changedTouches[ 0 ].clientY - y0;
			more.style.transform = '';
			y0 = null;
			if ( dy > 90 ) { closeMore(); }
		} );
	}

	/* ---------- Install ---------- */
	var deferred = null;
	var ios = /iphone|ipad|ipod/i.test( navigator.userAgent ) && ! window.MSStream;
	var standalone = window.matchMedia( '(display-mode: standalone)' ).matches || true === navigator.standalone;
	var installBtn = document.querySelector( '[data-nhm-install]' );
	var iosSheet = document.getElementById( 'nhm-iosinstall' );
	if ( standalone ) { document.documentElement.classList.add( 'nhm-standalone' ); }
	if ( installBtn && ! standalone && ios && iosSheet ) { installBtn.hidden = false; }
	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault();
		deferred = e;
		if ( installBtn ) { installBtn.hidden = false; }
	} );
	window.addEventListener( 'appinstalled', function () { deferred = null; if ( installBtn ) { installBtn.hidden = true; } } );
	if ( cfg.sw && 'serviceWorker' in navigator ) {
		window.addEventListener( 'load', function () { navigator.serviceWorker.register( cfg.sw ).catch( function () {} ); } );
	}

	/* ---------- Clicks and keys ---------- */
	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.closest( '[data-nhm-search-open]' ) ) { e.preventDefault(); openSearch(); return; }
		if ( t.closest( '[data-nhm-search-close]' ) ) { e.preventDefault(); closeSearch(); return; }
		if ( t.closest( '[data-nhm-more-open]' ) ) { e.preventDefault(); openMore(); return; }
		if ( t.closest( '[data-nhm-more-close]' ) ) { e.preventDefault(); closeMore(); return; }
		if ( t.closest( '[data-nhm-install]' ) ) {
			e.preventDefault();
			if ( deferred ) {
				deferred.prompt();
				deferred.userChoice.finally( function () { deferred = null; } );
			} else if ( iosSheet ) {
				closeMore();
				iosSheet.hidden = false;
				lock( true );
			}
			return;
		}
		if ( t.closest( '[data-nhm-ios-close]' ) || t === iosSheet ) { iosSheet.hidden = true; lock( false ); return; }
		var chip = t.closest( '.nhm-chips [data-q]' );
		if ( chip && input ) { input.value = chip.getAttribute( 'data-q' ); input.dispatchEvent( new Event( 'input' ) ); input.focus(); return; }
		var res = t.closest( '.nhm-res a[data-q]' );
		if ( res ) { remember( res.getAttribute( 'data-q' ) ); }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) { return; }
		closeSearch();
		closeMore();
		if ( iosSheet && ! iosSheet.hidden ) { iosSheet.hidden = true; lock( false ); }
	} );

	/* ---------- Plan cards: dots follow the swipe ---------- */
	var cards = document.querySelector( '[data-nhm-cards]' );
	if ( cards ) {
		var dots = document.querySelectorAll( '.nhm-cards__dots i' );
		var n = cards.children.length;
		dots.forEach( function ( d, i ) { if ( i >= n ) { d.remove(); } } );
		dots = document.querySelectorAll( '.nhm-cards__dots i' );
		cards.addEventListener( 'scroll', function () {
			var w = cards.firstElementChild ? cards.firstElementChild.offsetWidth + 14 : 1;
			var i = Math.round( cards.scrollLeft / w );
			dots.forEach( function ( d, j ) { d.classList.toggle( 'is-on', i === j ); } );
		}, { passive: true } );
	}
} )();

/* NeuroHR App 0.1: answer taps at once, load pages early, app header, tables. */
( function () {
	'use strict';
	var cfg = window.nhmApp || {};
	var phone = window.matchMedia( '(max-width: 768px)' );
	var root = document.documentElement;
	var here = location.origin;
	var skelTimer = null;

	function plainLink( a ) {
		if ( ! a || ! a.href || a.target === '_blank' || a.hasAttribute( 'download' ) || a.closest( '.no-prefetch' ) ) { return false; }
		var u;
		try { u = new URL( a.href, location.href ); } catch ( e ) { return false; }
		if ( u.origin !== here || ( u.pathname === location.pathname && u.search === location.search ) ) { return false; }
		return u;
	}
	function earlyOk( u ) {
		if ( ! u || u.search ) { return false; }
		return ! ( cfg.never || [] ).some( function ( p ) { return u.pathname.indexOf( p ) !== -1; } );
	}

	/* ---------- Load early (Chrome / Android) ---------- */
	var canSpec = window.HTMLScriptElement && HTMLScriptElement.supports && HTMLScriptElement.supports( 'speculationrules' );
	var asked = {};
	function prefetch( urls ) {
		urls = urls.filter( function ( u ) { return ! asked[ u ]; } );
		if ( ! canSpec || ! urls.length ) { return; }
		urls.forEach( function ( u ) { asked[ u ] = 1; } );
		var s = document.createElement( 'script' );
		s.type = 'speculationrules';
		s.textContent = JSON.stringify( { prefetch: [ { source: 'list', urls: urls, eagerness: 'immediate' } ] } );
		document.head.appendChild( s );
	}
	if ( cfg.prefetch ) {
		document.addEventListener( 'touchstart', function ( e ) {
			var a = e.target.closest && e.target.closest( 'a[href]' );
			var u = plainLink( a );
			if ( earlyOk( u ) ) { prefetch( [ u.href ] ); }
		}, { passive: true, capture: true } );
		var slow = navigator.connection && ( navigator.connection.saveData || /2g/.test( navigator.connection.effectiveType || '' ) );
		if ( cfg.ahead && cfg.ahead.length && ! slow ) {
			window.addEventListener( 'load', function () {
				var go = function () { prefetch( cfg.ahead ); };
				if ( window.requestIdleCallback ) { requestIdleCallback( go, { timeout: 2500 } ); } else { setTimeout( go, 1200 ); }
			} );
		}
	}

	/* ---------- Answer every tap at once ---------- */
	function going() {
		root.classList.add( 'nha-going' );
		clearTimeout( skelTimer );
		skelTimer = setTimeout( function () { root.classList.add( 'nha-skeleton' ); }, 180 );
		setTimeout( arrived, 12000 ); // Never stay stuck (e.g. a link that downloads a file).
	}
	function arrived() {
		clearTimeout( skelTimer );
		root.classList.remove( 'nha-going', 'nha-skeleton' );
	}
	window.addEventListener( 'pageshow', arrived );
	window.addEventListener( 'pagehide', function () { setTimeout( arrived, 0 ); } );

	document.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) { return; }
		var a = e.target.closest && e.target.closest( 'a[href]' );
		if ( ! a ) { return; }
		// Back: go back in history when we came from this site (the old page often shows at once).
		if ( a.hasAttribute( 'data-nha-back' ) && document.referrer.indexOf( here ) === 0 && history.length > 1 ) {
			e.preventDefault();
			if ( cfg.feedback && phone.matches ) { going(); }
			history.back();
			return;
		}
		var u = plainLink( a );
		if ( ! u || ! cfg.feedback || ! phone.matches || u.hash && u.pathname === location.pathname ) { return; }
		if ( /\/logout|nh_download|nh_video|\/wp-content\/|\.(pdf|docx?|xlsx?|pptx?|zip|csv|png|jpe?g)$/i.test( u.href ) ) { return; }
		if ( a.hasAttribute( 'data-nha-tab' ) ) {
			document.querySelectorAll( '.nha-tab.is-on' ).forEach( function ( t ) { t.classList.remove( 'is-on' ); } );
			a.classList.add( 'is-on' );
		}
		going();
	} );

	/* ---------- App header: show the title once the page title has scrolled away ---------- */
	var head = document.querySelector( '[data-nha-head]' );
	var prog = document.querySelector( '[data-nha-prog]' );
	var article = document.querySelector( '.nh-article__content' );
	if ( prog && article ) {
		var pt = false;
		var paint = function () {
			pt = false;
			var b = article.getBoundingClientRect();
			prog.style.transform = 'scaleX(' + Math.max( 0, Math.min( 1, -b.top / Math.max( 1, b.height - window.innerHeight * 0.6 ) ) ).toFixed( 3 ) + ')';
		};
		window.addEventListener( 'scroll', function () { if ( ! pt ) { pt = true; requestAnimationFrame( paint ); } }, { passive: true } );
		paint();
	}
	if ( head ) {
		var h1 = document.querySelector( '.nh-article__title, .nhs-main h1:not(.nha-head__title)' );
		if ( h1 && window.IntersectionObserver ) {
			new IntersectionObserver( function ( en ) {
				head.classList.toggle( 'is-scrolled', ! en[ 0 ].isIntersecting && en[ 0 ].boundingClientRect.top < 0 );
			}, { rootMargin: '-60px 0px 0px 0px' } ).observe( h1 );
		}
	}

	/* ---------- Tables: only the table moves sideways ---------- */
	function tables() {
		document.querySelectorAll( '.nh-article__content table, .nhs-content table, .nhs-main .entry-content table' ).forEach( function ( t ) {
			if ( t.closest( '.nha-table, form, .nhm, .nhm-account, .nh-hub-admin' ) || t.closest( 'table' ) !== t ) { return; }
			var box = document.createElement( 'div' );
			box.className = 'nha-table';
			var sc = document.createElement( 'div' );
			sc.className = 'nha-table__scroll';
			sc.setAttribute( 'tabindex', '0' );
			t.parentNode.insertBefore( box, t );
			sc.appendChild( t );
			box.appendChild( sc );
			var fade = document.createElement( 'span' );
			fade.className = 'nha-table__fade';
			box.appendChild( fade );
			var hint = document.createElement( 'p' );
			hint.className = 'nha-table__hint';
			hint.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14M15 8l4 4-4 4"/></svg>';
			hint.appendChild( document.createTextNode( ( cfg.strings && cfg.strings.more ) || 'Swipe the table to see more' ) );
			box.appendChild( hint );
			var check = function () {
				var fit = sc.scrollWidth <= sc.clientWidth + 2;
				box.classList.toggle( 'is-fit', fit );
				box.classList.toggle( 'is-end', fit || sc.scrollLeft + sc.clientWidth >= sc.scrollWidth - 4 );
			};
			sc.addEventListener( 'scroll', check, { passive: true } );
			window.addEventListener( 'resize', check );
			check();
		} );
	}
	if ( phone.matches ) {
		if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', tables ); } else { tables(); }
	}
} )();
