/* NeuroHR Members — member area: mobile drawer, account menu, sub-menus. */
( function () {
	var root = document.querySelector( '[data-nhs]' );
	if ( ! root ) {
		return;
	}
	var side   = document.getElementById( 'nhs-side' );
	var scrim  = root.querySelector( '.nhs-scrim' );
	var burger = root.querySelector( '[data-nhs-open]' );

	function open() {
		root.classList.add( 'is-drawer' );
		if ( scrim ) { scrim.hidden = false; }
		if ( burger ) { burger.setAttribute( 'aria-expanded', 'true' ); }
		document.documentElement.style.overflow = 'hidden';
		var first = side && side.querySelector( 'a, button, input' );
		if ( first ) { first.focus(); }
	}
	function close() {
		root.classList.remove( 'is-drawer' );
		if ( scrim ) { scrim.hidden = true; }
		if ( burger ) { burger.setAttribute( 'aria-expanded', 'false' ); }
		document.documentElement.style.overflow = '';
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-nhs-open]' ) ) { e.preventDefault(); open(); return; }
		if ( e.target.closest( '[data-nhs-close]' ) ) { e.preventDefault(); close(); burger && burger.focus(); return; }
		// Parent item with sub-items: first click opens the list.
		var caret = e.target.closest( '.nhs-nav__caret' );
		if ( caret ) {
			e.preventDefault();
			caret.closest( 'li' ).classList.toggle( 'is-open' );
			return;
		}
		// Account menu closes when clicking elsewhere.
		document.querySelectorAll( '[data-nhs-user][open]' ).forEach( function ( d ) {
			if ( ! d.contains( e.target ) ) { d.removeAttribute( 'open' ); }
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) { return; }
		if ( root.classList.contains( 'is-drawer' ) ) { close(); burger && burger.focus(); }
		document.querySelectorAll( '[data-nhs-user][open]' ).forEach( function ( d ) {
			d.removeAttribute( 'open' );
			d.querySelector( 'summary' ).focus();
		} );
	} );

	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 900 && root.classList.contains( 'is-drawer' ) ) { close(); }
	} );
} )();
