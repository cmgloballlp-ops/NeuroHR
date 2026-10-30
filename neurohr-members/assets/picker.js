/* NeuroHR Members — date (dd/mm/yyyy, weeks start on Sunday) and time (24-hour HH:mm) pickers.
 * Enhances <input type="date"> and <input type="time"> inside .nhm. The original input becomes a
 * hidden field with the same name and the ISO value (yyyy-mm-dd / HH:mm), so the server sees no
 * difference. Without JavaScript the browser's own picker is used. */
( function () {
	'use strict';
	var MONTHS = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];
	var DOW    = [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' ];
	var open   = null; // { pop, close }

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function iso( d ) { return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ); }
	function fromIso( s ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( s || '' );
		return m ? new Date( +m[1], +m[2] - 1, +m[3] ) : null;
	}
	function show( d ) { return pad( d.getDate() ) + '/' + pad( d.getMonth() + 1 ) + '/' + d.getFullYear(); }
	function today() { var t = new Date(); return new Date( t.getFullYear(), t.getMonth(), t.getDate() ); }
	function same( a, b ) { return a && b && a.getTime() === b.getTime(); }
	function phone() { return window.matchMedia && window.matchMedia( '(max-width: 640px)' ).matches; }

	/* "29/10/2026", "29-10-26", "29102026" → Date (or null). */
	function parseDate( s ) {
		s = ( s || '' ).trim();
		var m = /^(\d{1,2})[\/.\-\s](\d{1,2})[\/.\-\s](\d{2}|\d{4})$/.exec( s ) || /^(\d{2})(\d{2})(\d{4})$/.exec( s );
		if ( ! m ) { return null; }
		var y = +m[3] < 100 ? 2000 + +m[3] : +m[3];
		var d = new Date( y, +m[2] - 1, +m[1] );
		return d.getDate() === +m[1] && d.getMonth() === +m[2] - 1 ? d : null;
	}
	/* "14:35", "1435", "9", "9.5" → "HH:mm" (or ''). */
	function parseTime( s ) {
		s = ( s || '' ).trim().replace( /[.\s]/g, ':' );
		var m = /^(\d{1,2})(?::?(\d{2}))?$/.exec( s );
		if ( ! m ) { return ''; }
		var h = +m[1], i = m[2] ? +m[2] : 0;
		return h < 24 && i < 60 ? pad( h ) + ':' + pad( i ) : '';
	}

	function refocus( f ) { if ( ! phone() ) { f.text.focus(); } }

	function closeOpen() {
		if ( open ) { open.close(); }
	}

	function popup( field, cls, title ) {
		closeOpen();
		var pop = document.createElement( 'div' );
		pop.className = 'nhm-pick ' + cls + ( phone() ? ' is-sheet' : '' );
		pop.setAttribute( 'role', 'dialog' );
		pop.setAttribute( 'aria-label', title );
		var back = null;
		if ( phone() ) {
			back = document.createElement( 'div' );
			back.className = 'nhm-pick-back';
			document.body.appendChild( back );
			var head = document.createElement( 'div' );
			head.className = 'nhm-pick__sheethead';
			head.innerHTML = '<span class="nhm-pick__grab"></span><strong></strong><button type="button" class="nhm-pick__x">Close</button>';
			head.querySelector( 'strong' ).textContent = title;
			pop.appendChild( head );
			document.body.appendChild( pop );
		} else {
			field.wrap.appendChild( pop );
		}
		var state = {
			pop: pop,
			close: function () {
				pop.remove();
				if ( back ) { back.remove(); }
				field.text.setAttribute( 'aria-expanded', 'false' );
				open = null;
			}
		};
		if ( back ) { back.addEventListener( 'click', state.close ); }
		var x = pop.querySelector( '.nhm-pick__x' );
		if ( x ) { x.addEventListener( 'click', function () { state.close(); refocus( field ); } ); }
		field.text.setAttribute( 'aria-expanded', 'true' );
		open = state;
		return pop;
	}

	/* ---------- Date ---------- */

	function dateField( input ) {
		var f = wrapField( input, 'date' );
		f.min = fromIso( input.getAttribute( 'min' ) );
		f.max = fromIso( input.getAttribute( 'max' ) );
		f.text.placeholder = 'dd/mm/yyyy';
		f.text.setAttribute( 'inputmode', 'numeric' );
		f.text.setAttribute( 'maxlength', '10' );
		var cur = fromIso( input.value );
		f.text.value = cur ? show( cur ) : '';

		function allowed( d ) { return ( ! f.min || d >= f.min ) && ( ! f.max || d <= f.max ); }
		function set( d ) {
			input.value = d ? iso( d ) : '';
			f.text.value = d ? show( d ) : '';
			f.text.setCustomValidity( '' );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		function check() {
			var v = f.text.value.trim();
			if ( '' === v ) { set( null ); return true; }
			var d = parseDate( v );
			if ( ! d ) { f.text.setCustomValidity( 'Please enter the date as dd/mm/yyyy.' ); return false; }
			if ( ! allowed( d ) ) { f.text.setCustomValidity( f.max && d > f.max ? 'This date cannot be in the future.' : 'This date is too early.' ); return false; }
			set( d );
			return true;
		}
		f.text.addEventListener( 'input', function ( e ) {
			// Add the slash while typing: "29" → "29/", "29/10" → "29/10/".
			if ( e.inputType && 0 === e.inputType.indexOf( 'insert' ) && /^(\d{2}|\d{2}\/\d{2})$/.test( f.text.value ) ) {
				f.text.value += '/';
			}
			f.text.setCustomValidity( '' );
		} );
		f.text.addEventListener( 'change', check );
		f.text.addEventListener( 'blur', check );

		function render( pop, view, focus ) {
			var t = today(), sel = fromIso( input.value );
			var first = new Date( view.getFullYear(), view.getMonth(), 1 );
			var start = new Date( first ); start.setDate( 1 - first.getDay() );
			var prevOk = ! f.min || new Date( view.getFullYear(), view.getMonth(), 0 ) >= f.min;
			var nextOk = ! f.max || new Date( view.getFullYear(), view.getMonth() + 1, 1 ) <= f.max;
			var h = '<div class="nhm-pick__cal"><div class="nhm-pick__head">'
				+ '<button type="button" class="nhm-pick__nav" data-m="-1" aria-label="Previous month"' + ( prevOk ? '' : ' disabled' ) + '>‹</button>'
				+ '<strong aria-live="polite">' + MONTHS[ view.getMonth() ] + ' ' + view.getFullYear() + '</strong>'
				+ '<button type="button" class="nhm-pick__nav" data-m="1" aria-label="Next month"' + ( nextOk ? '' : ' disabled' ) + '>›</button></div>'
				+ '<div class="nhm-pick__grid" role="grid">';
			DOW.forEach( function ( d ) { h += '<span class="nhm-pick__dow" role="columnheader">' + d + '</span>'; } );
			for ( var i = 0; i < 42; i++ ) {
				var d = new Date( start ); d.setDate( start.getDate() + i );
				if ( i >= 35 && d.getMonth() !== view.getMonth() ) { break; }
				var cls = 'nhm-pick__day';
				if ( d.getMonth() !== view.getMonth() ) { cls += ' is-out'; }
				if ( same( d, t ) ) { cls += ' is-today'; }
				if ( same( d, sel ) ) { cls += ' is-on'; }
				var ok = allowed( d );
				h += '<button type="button" role="gridcell" class="' + cls + '" data-d="' + iso( d ) + '" tabindex="' + ( same( d, focus ) ? '0' : '-1' ) + '"'
					+ ( ok ? '' : ' disabled' ) + ' aria-label="' + d.getDate() + ' ' + MONTHS[ d.getMonth() ] + ' ' + d.getFullYear() + '"' + ( same( d, sel ) ? ' aria-selected="true"' : '' ) + '>' + d.getDate() + '</button>';
			}
			h += '</div><div class="nhm-pick__quick">';
			var y = new Date( t ); y.setDate( t.getDate() - 1 );
			if ( allowed( t ) ) { h += '<button type="button" class="nhm-pick__chip' + ( same( sel, t ) ? ' is-on' : '' ) + '" data-d="' + iso( t ) + '">Today</button>'; }
			if ( allowed( y ) ) { h += '<button type="button" class="nhm-pick__chip' + ( same( sel, y ) ? ' is-on' : '' ) + '" data-d="' + iso( y ) + '">Yesterday</button>'; }
			h += '</div></div>';
			var body = pop.querySelector( '.nhm-pick__body' );
			body.innerHTML = h;
			var fb = body.querySelector( '.nhm-pick__day[tabindex="0"]' );
			return fb;
		}

		function openCal() {
			var sel = fromIso( input.value ) || ( f.max && today() > f.max ? f.max : today() );
			var view = new Date( sel.getFullYear(), sel.getMonth(), 1 );
			var focus = sel;
			var pop = popup( f, 'nhm-pick--date', f.label );
			var body = document.createElement( 'div' );
			body.className = 'nhm-pick__body';
			pop.appendChild( body );
			function draw( moveFocus ) {
				var b = render( pop, view, focus );
				if ( moveFocus && b ) { b.focus(); }
			}
			draw( false );
			pop.addEventListener( 'click', function ( e ) {
				var nav = e.target.closest( '.nhm-pick__nav' );
				if ( nav && ! nav.disabled ) {
					view = new Date( view.getFullYear(), view.getMonth() + +nav.getAttribute( 'data-m' ), 1 );
					focus = new Date( view );
					draw( false );
					return;
				}
				var day = e.target.closest( '[data-d]' );
				if ( day && ! day.disabled ) {
					set( fromIso( day.getAttribute( 'data-d' ) ) );
					open.close();
					refocus( f );
				}
			} );
			pop.addEventListener( 'keydown', function ( e ) {
				var steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
				if ( steps[ e.key ] || 'PageUp' === e.key || 'PageDown' === e.key ) {
					e.preventDefault();
					var n = new Date( focus );
					if ( steps[ e.key ] ) { n.setDate( n.getDate() + steps[ e.key ] ); } else { n.setMonth( n.getMonth() + ( 'PageUp' === e.key ? -1 : 1 ) ); }
					if ( ! allowed( n ) ) { return; }
					focus = n;
					view = new Date( n.getFullYear(), n.getMonth(), 1 );
					draw( true );
				} else if ( 'Escape' === e.key ) {
					e.preventDefault();
					open.close();
					refocus( f );
				}
			} );
			if ( phone() ) { draw( true ); }
		}
		f.open = openCal;
		f.text.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || ( 'Enter' === e.key && ! f.text.value ) ) {
				e.preventDefault();
				openCal();
				var b = open && open.pop.querySelector( '.nhm-pick__day[tabindex="0"]' );
				if ( b ) { b.focus(); }
			}
		} );
	}

	/* ---------- Time ---------- */

	function timeField( input ) {
		var f = wrapField( input, 'time' );
		f.text.placeholder = 'HH:mm';
		f.text.setAttribute( 'inputmode', 'numeric' );
		f.text.setAttribute( 'maxlength', '5' );
		f.text.value = parseTime( input.value );

		function set( v ) {
			input.value = v;
			f.text.value = v;
			f.text.setCustomValidity( '' );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		function check() {
			var raw = f.text.value.trim();
			if ( '' === raw ) { set( '' ); return; }
			var v = parseTime( raw );
			if ( ! v ) { f.text.setCustomValidity( 'Please enter the time as HH:mm, e.g. 14:35.' ); return; }
			set( v );
		}
		f.text.addEventListener( 'input', function () { f.text.setCustomValidity( '' ); } );
		f.text.addEventListener( 'change', check );
		f.text.addEventListener( 'blur', check );

		function col( kind, n, sel ) {
			var h = '<div class="nhm-pick__col" role="listbox" aria-label="' + ( 'h' === kind ? 'Hour' : 'Minute' ) + '"><span class="nhm-pick__colhead">' + ( 'h' === kind ? 'HH' : 'MM' ) + '</span>';
			for ( var i = 0; i < n; i++ ) {
				h += '<button type="button" role="option" class="nhm-pick__t' + ( i === sel ? ' is-on' : '' ) + '" data-' + kind + '="' + i + '" aria-selected="' + ( i === sel ) + '">' + pad( i ) + '</button>';
			}
			return h + '</div>';
		}
		function openTime() {
			var v = parseTime( input.value ) || '';
			var hh = v ? +v.slice( 0, 2 ) : -1, mm = v ? +v.slice( 3 ) : -1;
			var pop = popup( f, 'nhm-pick--time', f.label );
			var body = document.createElement( 'div' );
			body.className = 'nhm-pick__body';
			pop.appendChild( body );
			function draw() {
				body.innerHTML = '<div class="nhm-pick__cols">' + col( 'h', 24, hh ) + col( 'm', 60, mm ) + '</div>'
					+ '<div class="nhm-pick__tfoot"><span>Or type 1435</span><button type="button" class="nhm-pick__now">Now</button></div>';
				body.querySelectorAll( '.nhm-pick__col' ).forEach( function ( c ) {
					var on = c.querySelector( '.is-on' ) || c.querySelector( '[data-h="9"],[data-m="0"]' );
					if ( on ) { c.scrollTop = on.offsetTop - c.clientHeight / 2 + on.offsetHeight / 2; }
				} );
			}
			draw();
			pop.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( 'button' );
				if ( ! b ) { return; }
				if ( b.classList.contains( 'nhm-pick__now' ) ) {
					var n = new Date();
					set( pad( n.getHours() ) + ':' + pad( n.getMinutes() ) );
					open.close();
					refocus( f );
					return;
				}
				if ( b.hasAttribute( 'data-h' ) ) { hh = +b.getAttribute( 'data-h' ); if ( mm < 0 ) { mm = 0; } }
				if ( b.hasAttribute( 'data-m' ) ) { mm = +b.getAttribute( 'data-m' ); if ( hh < 0 ) { hh = 9; } }
				var keepH = body.querySelectorAll( '.nhm-pick__col' )[0].scrollTop, keepM = body.querySelectorAll( '.nhm-pick__col' )[1].scrollTop;
				set( pad( hh ) + ':' + pad( mm ) );
				draw();
				body.querySelectorAll( '.nhm-pick__col' )[0].scrollTop = keepH;
				body.querySelectorAll( '.nhm-pick__col' )[1].scrollTop = keepM;
				if ( b.hasAttribute( 'data-m' ) ) { open.close(); refocus( f ); }
			} );
			pop.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key ) { e.preventDefault(); open.close(); refocus( f ); }
			} );
		}
		f.open = openTime;
	}

	/* ---------- Shared ---------- */

	function wrapField( input, kind ) {
		var wrap = document.createElement( 'span' );
		wrap.className = 'nhm-pickfield nhm-pickfield--' + kind;
		input.parentNode.insertBefore( wrap, input );
		var text = document.createElement( 'input' );
		text.type = 'text';
		text.className = input.className;
		text.id = input.id;
		input.removeAttribute( 'id' );
		text.autocomplete = 'off';
		text.setAttribute( 'aria-haspopup', 'dialog' );
		text.setAttribute( 'aria-expanded', 'false' );
		if ( input.required ) { text.required = true; input.required = false; }
		if ( input.disabled ) { text.disabled = true; }
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'nhm-pickfield__btn';
		btn.tabIndex = -1;
		btn.setAttribute( 'aria-label', 'date' === kind ? 'Choose a date' : 'Choose a time' );
		btn.innerHTML = 'date' === kind
			? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>'
			: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>';
		wrap.appendChild( btn );
		wrap.appendChild( text );
		input.type = 'hidden';
		wrap.appendChild( input );
		var lab = text.id ? document.querySelector( 'label[for="' + text.id + '"]' ) : null;
		var f = { wrap: wrap, text: text, input: input, label: lab ? lab.textContent.replace( /[*()]|optional/g, '' ).trim() : ( 'date' === kind ? 'Date' : 'Time' ) };
		function toggle( e ) {
			if ( e ) { e.preventDefault(); }
			if ( open && open.field === f ) { open.close(); return; }
			f.open();
			if ( open ) { open.field = f; }
		}
		btn.addEventListener( 'click', toggle );
		text.addEventListener( 'click', function () { if ( ! open || open.field !== f ) { toggle(); } } );
		if ( phone() ) { text.readOnly = true; text.addEventListener( 'focus', function () { if ( ! open ) { toggle(); } } ); }
		return f;
	}

	document.addEventListener( 'mousedown', function ( e ) {
		if ( open && ! open.pop.contains( e.target ) && ! ( open.field && open.field.wrap.contains( e.target ) ) ) { open.close(); }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Tab' === e.key && open && ! phone() && ! open.pop.contains( document.activeElement ) ) { open.close(); }
	} );

	function init( root ) {
		( root || document ).querySelectorAll( '.nhm input[type="date"]:not([data-nhm-native]), .nhm input[type="time"]:not([data-nhm-native])' ).forEach( function ( input ) {
			if ( 'date' === input.type ) { dateField( input ); } else { timeField( input ); }
		} );
	}
	window.nhmPickers = init;
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', function () { init(); } ); } else { init(); }
} )();
