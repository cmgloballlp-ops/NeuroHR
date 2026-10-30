/* NeuroHR Knowledge Hub — live search. Results appear under the box while typing. */
( function () {
	'use strict';
	if ( ! window.nhLiveSearch ) {
		return;
	}
	var cfg = window.nhLiveSearch;

	function escapeRegExp( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	// Title with the typed words wrapped in <mark>, built with DOM nodes (no innerHTML).
	function highlighted( title, q ) {
		var frag = document.createDocumentFragment();
		var words = q.trim().split( /\s+/ ).filter( function ( w ) { return w.length > 1; } ).map( escapeRegExp );
		if ( ! words.length ) {
			frag.appendChild( document.createTextNode( title ) );
			return frag;
		}
		var re = new RegExp( '(' + words.join( '|' ) + ')', 'ig' );
		title.split( re ).forEach( function ( part, i ) {
			if ( ! part ) { return; }
			if ( i % 2 === 1 ) {
				var m = document.createElement( 'mark' );
				m.textContent = part;
				frag.appendChild( m );
			} else {
				frag.appendChild( document.createTextNode( part ) );
			}
		} );
		return frag;
	}

	function setup( form ) {
		var input = form.querySelector( 'input[name="q"]' );
		var box = form.querySelector( '.nh-live' );
		if ( ! input || ! box ) { return; }
		var timer, controller, active = -1, lastQuery = '';

		function close() {
			box.hidden = true;
			box.textContent = '';
			input.setAttribute( 'aria-expanded', 'false' );
			active = -1;
		}

		function options() {
			return box.querySelectorAll( '.nh-live__item' );
		}

		function setActive( i ) {
			var opts = options();
			if ( ! opts.length ) { return; }
			active = ( i + opts.length ) % opts.length;
			opts.forEach( function ( o, n ) { o.classList.toggle( 'is-active', n === active ); o.setAttribute( 'aria-selected', n === active ? 'true' : 'false' ); } );
			opts[ active ].scrollIntoView( { block: 'nearest' } );
		}

		function render( data, q ) {
			box.textContent = '';
			if ( ! data.items.length ) {
				var none = document.createElement( 'p' );
				none.className = 'nh-live__empty';
				none.textContent = cfg.strings.none;
				box.appendChild( none );
			}
			data.items.forEach( function ( item ) {
				var a = document.createElement( 'a' );
				a.className = 'nh-live__item';
				a.href = item.url;
				a.setAttribute( 'role', 'option' );
				var t = document.createElement( 'span' );
				t.className = 'nh-live__title';
				t.appendChild( highlighted( item.title, q ) );
				a.appendChild( t );
				var meta = document.createElement( 'span' );
				meta.className = 'nh-live__meta';
				meta.textContent = ( item.locked ? '🔒 ' + item.locked + ( item.meta ? ' · ' : '' ) : '' ) + ( item.meta || '' );
				a.appendChild( meta );
				box.appendChild( a );
			} );
			if ( data.total > data.items.length && data.all ) {
				var all = document.createElement( 'a' );
				all.className = 'nh-live__all';
				all.href = data.all;
				all.textContent = cfg.strings.all.replace( '%d', data.total ) + ' →';
				box.appendChild( all );
			}
			box.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			active = -1;
		}

		function search() {
			var q = input.value.trim();
			if ( q.length < cfg.min ) { close(); return; }
			if ( q === lastQuery && ! box.hidden ) { return; }
			lastQuery = q;
			if ( controller ) { controller.abort(); }
			controller = 'AbortController' in window ? new AbortController() : null;
			var url = cfg.url + '?action=' + encodeURIComponent( cfg.action ) +
				'&q=' + encodeURIComponent( q ) +
				'&section=' + encodeURIComponent( form.dataset.section || 'knowledge' ) +
				'&topic=' + encodeURIComponent( form.dataset.topic || '0' );
			fetch( url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( input.value.trim() !== q ) { return; } // A newer query is on its way.
					if ( res && res.success ) { render( res.data, q ); } else { close(); }
				} )
				.catch( function () {} );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( search, 200 );
		} );
		input.addEventListener( 'focus', function () { if ( box.childNodes.length ) { box.hidden = false; } } );
		input.addEventListener( 'keydown', function ( e ) {
			if ( box.hidden ) { return; }
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); setActive( active + 1 ); }
			else if ( 'ArrowUp' === e.key ) { e.preventDefault(); setActive( active - 1 ); }
			else if ( 'Escape' === e.key ) { close(); }
			else if ( 'Enter' === e.key && active > -1 ) { e.preventDefault(); window.location.href = options()[ active ].href; }
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! form.contains( e.target ) ) { box.hidden = true; input.setAttribute( 'aria-expanded', 'false' ); }
		} );
	}

	function init() {
		document.querySelectorAll( '.nh-searchbox, [data-nh-live]' ).forEach( setup ); // [data-nh-live]: the NeuroHR Members top bar.
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
