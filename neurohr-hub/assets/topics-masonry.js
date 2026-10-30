/**
 * Topic cards: left-to-right order, but each card sits right under the one above it
 * (no empty space when cards have different heights). Without JavaScript the cards
 * fall back to a normal grid.
 */
( function () {
	var UNIT = 2; // px per grid row
	function layout( grid ) {
		var gap = parseFloat( getComputedStyle( grid ).columnGap ) || 24;
		Array.prototype.forEach.call( grid.children, function ( card ) {
			card.style.gridRowEnd = 'auto';
			var h = card.getBoundingClientRect().height;
			card.style.gridRowEnd = 'span ' + Math.ceil( ( h + gap ) / UNIT );
		} );
	}
	function init() {
		document.querySelectorAll( '.nh-topics-grid' ).forEach( function ( grid ) {
			grid.classList.add( 'is-masonry' );
			layout( grid );
			if ( window.ResizeObserver ) {
				var busy = false;
				new ResizeObserver( function () {
					if ( busy ) { return; }
					busy = true;
					requestAnimationFrame( function () { layout( grid ); busy = false; } );
				} ).observe( grid );
			} else {
				window.addEventListener( 'resize', function () { layout( grid ); } );
			}
			window.addEventListener( 'load', function () { layout( grid ); } );
		} );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
