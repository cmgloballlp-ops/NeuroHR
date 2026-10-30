/* NeuroHR Members — admin: drag levels, +1 year, confirm, delete-level dialog. Bound on click/ready, no globals needed. */
( function ( $ ) {
	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }

	function plusYear( value ) {
		var today = ( window.NHM_ADMIN && NHM_ADMIN.today ) || new Date().toISOString().slice( 0, 10 );
		var from  = value && value > today ? value : today;
		var p     = from.split( '-' );
		var y     = parseInt( p[0], 10 ) + 1, m = parseInt( p[1], 10 ), d = parseInt( p[2], 10 );
		var last  = new Date( y, m, 0 ).getDate(); // 29 Feb → 28 Feb
		return y + '-' + pad( m ) + '-' + pad( Math.min( d, last ) );
	}

	$( document ).on( 'click', '[data-nhm-plus-year]', function ( e ) {
		e.preventDefault();
		var input = document.getElementById( this.getAttribute( 'data-nhm-plus-year' ) );
		if ( input ) { input.value = plusYear( input.value ); input.dispatchEvent( new Event( 'change' ) ); }
	} );

	// "+1 term": the term of the level picked in the linked select (else 1 year).
	$( document ).on( 'click', '[data-nhm-plus-term]', function ( e ) {
		e.preventDefault();
		var input = document.getElementById( this.getAttribute( 'data-nhm-plus-term' ) );
		var sel   = document.getElementById( this.getAttribute( 'data-nhm-level' ) || '' );
		var terms = ( window.NHM_ADMIN && NHM_ADMIN.terms ) || {};
		var t     = sel ? terms[ sel.value ] : null;
		if ( ! input ) { return; }
		if ( t && 'lifetime' === t.access ) { input.value = ''; return; }
		if ( t && 'fixed' === t.access ) { input.value = t.fixed || ''; return; }
		input.value = addTerm( input.value, t ? t.n : 1, t ? t.unit : 'year' );
	} );

	function addTerm( value, n, unit ) {
		var today = ( window.NHM_ADMIN && NHM_ADMIN.today ) || new Date().toISOString().slice( 0, 10 );
		var from  = value && value > today ? value : today;
		var p     = from.split( '-' ), y = +p[0], m = +p[1], d = +p[2];
		if ( 'year' === unit || 'month' === unit ) {
			var months = 'year' === unit ? 12 * n : n;
			var idx = ( m - 1 ) + months;
			y += Math.floor( idx / 12 ); m = ( idx % 12 ) + 1;
			var last = new Date( y, m, 0 ).getDate();
			return y + '-' + pad( m ) + '-' + pad( Math.min( d, last ) );
		}
		var date = new Date( Date.UTC( +p[0], +p[1] - 1, +p[2] ) );
		date.setUTCDate( date.getUTCDate() + ( 'week' === unit ? 7 * n : n ) );
		return date.toISOString().slice( 0, 10 );
	}

	// Members list: select all, bulk plan fields.
	$( document ).on( 'change', '[data-nhm-check-all]', function () {
		$( this ).closest( 'table' ).find( 'tbody input[type=checkbox]' ).prop( 'checked', this.checked );
	} );
	$( document ).on( 'change', '[data-nhm-bulk]', function () {
		$( '.nhm-bulk__plan' ).prop( 'hidden', 'set_plan' !== this.value );
		$( '.nhm-bulk__delete' ).prop( 'hidden', 'delete' !== this.value );
	} );

	// Show / hide (email editors).
	$( document ).on( 'click', '[data-nhm-toggle]', function ( e ) {
		e.preventDefault();
		var el = document.getElementById( this.getAttribute( 'data-nhm-toggle' ) );
		if ( el ) { el.hidden = ! el.hidden; }
	} );

	// Media picker (bound on click: wp.media loads late).
	$( document ).on( 'click', '[data-nhm-media]', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) { return; }
		var input = document.getElementById( this.getAttribute( 'data-nhm-media' ) );
		var frame = wp.media( { title: ( window.NHM_ADMIN && NHM_ADMIN.choose ) || 'Choose', library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			if ( input ) { input.value = a.url; }
		} );
		frame.open();
	} );

	// Level term fields: show the ones for the chosen access.
	function syncTerm( box ) {
		var access = $( box ).find( '[data-nhm-access]' ).val();
		$( box ).find( '[data-nhm-show]' ).each( function () {
			this.hidden = this.getAttribute( 'data-nhm-show' ) !== access;
		} );
	}
	$( document ).on( 'change', '[data-nhm-access]', function () { syncTerm( $( this ).closest( '[data-nhm-term]' ) ); } );

	// Field builder.
	$( document ).on( 'click', '[data-nhm-add-field]', function ( e ) {
		e.preventDefault();
		var tpl  = document.getElementById( 'nhm-field-template' );
		var list = $( '[data-nhm-fieldlist]' );
		var i    = 'n' + Date.now();
		list.append( tpl.innerHTML.replace( /__i__/g, i ) );
		list.children().last().find( 'input[type=text]' ).first().trigger( 'focus' );
	} );
	$( document ).on( 'change', '[data-nhm-field-type]', function () {
		var choice = [ 'select', 'radio', 'checkboxes' ].indexOf( this.value ) !== -1;
		$( this ).closest( '.nhm-frow' ).find( '[data-nhm-options]' ).prop( 'hidden', ! choice );
	} );
	$( document ).on( 'input', '[data-nhm-field-label]', function () {
		$( this ).closest( '.nhm-frow' ).find( '.nhm-frow__label' ).text( this.value || '…' );
	} );
	$( document ).on( 'click', '.nhm-frow__handle', function ( e ) { e.preventDefault(); } );

	// Invoice editor: lines, totals, bill to.
	function money( n ) { return ( Math.round( n * 100 ) / 100 ).toFixed( 2 ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' ); }
	function num( v ) { var n = parseFloat( String( v || '' ).replace( /[^0-9.\-]/g, '' ) ); return isNaN( n ) ? 0 : n; }
	function recalc() {
		var form = $( '[data-nhm-invoice]' );
		if ( ! form.length ) { return; }
		var plan  = form.find( '[data-nhm-plan]' );
		var terms = parseInt( form.find( '[name=terms]' ).val() || '1', 10 );
		var sub   = 0;
		if ( plan.length ) {
			sub = Math.round( num( form.find( '[name=unit_price]' ).val() ) * terms * 100 ) / 100;
			var opt = plan.find( 'option:selected' );
			var lbl = opt.attr( 'data-term' ) || '';
			form.find( '[data-nhm-term-label]' ).text( lbl ? ( terms > 1 ? terms + ' × ' + lbl : lbl ) : '' );
		}
		var disc  = Math.min( sub, num( form.find( '[name=discount]' ).val() ) );
		var rate  = num( form.find( '[name=tax_rate]' ).val() );
		var incl  = form.find( '[name=tax_inclusive]' ).is( ':checked' );
		var total = incl ? sub - disc : sub - disc + Math.round( ( sub - disc ) * rate ) / 100;
		var out   = form.find( '[data-nhm-total]' );
		if ( plan.length ) { out.text( ( out.attr( 'data-currency' ) || '' ) + ' ' + money( total ) ); }
	}
	$( document ).on( 'input change', '[data-nhm-calc]', recalc );
	// Picking a plan fills in its price.
	$( document ).on( 'change', '[data-nhm-plan]', function () {
		var amt = $( this ).find( 'option:selected' ).attr( 'data-amount' );
		if ( amt !== undefined ) { $( '[name=unit_price]' ).val( amt ); }
		recalc();
	} );
	function syncBill() {
		var v = $( '[data-nhm-bill]:checked' ).val();
		$( '[data-nhm-bill-user]' ).prop( 'hidden', 'company' === v );
		$( '[data-nhm-bill-company]' ).prop( 'hidden', 'company' !== v );
	}
	$( document ).on( 'change', '[data-nhm-bill]', syncBill );

	$( document ).on( 'click', '[data-nhm-clear]', function ( e ) {
		e.preventDefault();
		var input = document.getElementById( this.getAttribute( 'data-nhm-clear' ) );
		if ( input ) { input.value = ''; }
	} );

	$( document ).on( 'click', '[data-nhm-confirm]', function ( e ) {
		if ( ! window.confirm( this.getAttribute( 'data-nhm-confirm' ) ) ) { e.preventDefault(); }
	} );

	$( document ).on( 'click', '[data-nhm-open]', function ( e ) {
		e.preventDefault();
		var modal = document.getElementById( this.getAttribute( 'data-nhm-open' ) );
		if ( modal ) { modal.hidden = false; $( modal ).find( 'select' ).trigger( 'focus' ); }
	} );

	$( document ).on( 'click', '[data-nhm-close]', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.nhm-modal' ).prop( 'hidden', true );
	} );

	$( document ).on( 'click', '.nhm-modal', function ( e ) {
		if ( e.target === this ) { this.hidden = true; }
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) { $( '.nhm-modal' ).prop( 'hidden', true ); }
	} );

	// Dragging by the handle should not open or close the row.
	$( document ).on( 'click', '.nhm-level__handle', function ( e ) { e.preventDefault(); } );

	$( function () {
		$( '[data-nhm-term]' ).each( function () { syncTerm( this ); } );
		syncBill();
		recalc();
		var $fields = $( '[data-nhm-fieldlist]' );
		if ( $fields.length && $.fn.sortable ) {
			$fields.sortable( { items: '> .nhm-frow', handle: '.nhm-frow__handle', axis: 'y', placeholder: 'nhm-level-placeholder' } );
		}
		var $list = $( '[data-nhm-sortable]' );
		if ( $list.length && $.fn.sortable ) {
			$list.sortable( {
				items: '> .nhm-level:not(.is-base)',
				handle: '.nhm-level__handle',
				placeholder: 'nhm-level-placeholder',
				axis: 'y',
				update: function () {
					$( '.nhm-levels-form' ).addClass( 'is-dirty' );
				}
			} );
		}
	} );
} )( jQuery );
