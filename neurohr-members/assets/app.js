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
