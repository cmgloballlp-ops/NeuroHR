/* NeuroHR Knowledge Hub — Save and Share buttons. */
( function () {
	'use strict';
	var cfg = window.nhApp || {};

	function toast( text ) {
		var t = document.createElement( 'div' );
		t.className = 'nh-toast';
		t.setAttribute( 'role', 'status' );
		t.textContent = text;
		document.body.appendChild( t );
		setTimeout( function () { t.classList.add( 'is-in' ); }, 10 );
		setTimeout( function () { t.classList.remove( 'is-in' ); setTimeout( function () { t.remove(); }, 300 ); }, 1800 );
	}

	document.addEventListener( 'click', function ( e ) {
		var save = e.target.closest( '[data-nh-save]' );
		if ( save && cfg.nonce ) {
			e.preventDefault();
			var on = ! save.classList.contains( 'is-on' );
			save.classList.toggle( 'is-on', on );
			save.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			var body = new FormData();
			body.append( 'action', cfg.action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'post', save.getAttribute( 'data-nh-save' ) );
			fetch( cfg.url, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( r && r.success ) {
						save.classList.toggle( 'is-on', r.data.saved );
						save.setAttribute( 'aria-pressed', r.data.saved ? 'true' : 'false' );
						toast( r.data.saved ? cfg.strings.saved : cfg.strings.removed );
						var row = save.closest( '[data-nh-saved-row]' );
						if ( row && ! r.data.saved ) { row.remove(); }
					} else {
						save.classList.toggle( 'is-on', ! on );
					}
				} )
				.catch( function () { save.classList.toggle( 'is-on', ! on ); } );
			return;
		}
		var share = e.target.closest( '[data-nh-share]' );
		if ( share ) {
			e.preventDefault();
			var data = { title: share.getAttribute( 'data-title' ), url: share.getAttribute( 'data-url' ) };
			if ( navigator.share ) {
				navigator.share( data ).catch( function () {} );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( data.url ).then( function () { toast( cfg.strings.copied ); } );
			} else {
				window.prompt( '', data.url );
			}
		}
	} );
} )();

/* Reading progress bar along the top and a "back to top" button (articles). */
( function () {
	'use strict';
	var read = document.querySelector( '[data-nh-read]' );
	var up = document.querySelector( '[data-nh-totop]' );
	var body = document.querySelector( '.nh-article__content' );
	if ( ! read && ! up ) { return; }
	var ticking = false;
	function update() {
		ticking = false;
		if ( read && body ) {
			var b = body.getBoundingClientRect();
			var done = Math.max( 0, Math.min( 1, -b.top / Math.max( 1, b.height - window.innerHeight * 0.6 ) ) );
			read.style.transform = 'scaleX(' + done.toFixed( 3 ) + ')';
		}
		if ( up ) { up.hidden = window.scrollY < 1200; }
	}
	window.addEventListener( 'scroll', function () { if ( ! ticking ) { ticking = true; requestAnimationFrame( update ); } }, { passive: true } );
	if ( up ) {
		up.addEventListener( 'click', function () {
			var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			window.scrollTo( { top: 0, behavior: reduce ? 'auto' : 'smooth' } );
		} );
	}
	update();
} )();
