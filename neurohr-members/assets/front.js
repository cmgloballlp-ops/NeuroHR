/* NeuroHR Members — show/hide password, confirm before remove/leave, one submit per form. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '.nhm-password__toggle' );
		if ( toggle ) {
			var input = toggle.parentNode.querySelector( 'input' );
			var show  = input.type === 'password';
			input.type = show ? 'text' : 'password';
			toggle.textContent = show ? toggle.getAttribute( 'data-nhm-hide' ) : toggle.getAttribute( 'data-nhm-show' );
			toggle.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			input.focus();
			return;
		}
		// Pop-up windows (forgot password): <dialog>, with a normal link as the fallback.
		var opener = e.target.closest( '[data-nhm-dialog]' );
		if ( opener ) {
			var dlg = document.getElementById( opener.getAttribute( 'data-nhm-dialog' ) );
			if ( dlg && dlg.showModal ) {
				e.preventDefault();
				if ( ! dlg.open ) { dlg.showModal(); }
				var first = dlg.querySelector( 'input:not([type=hidden])' );
				if ( first ) { first.focus(); }
			}
			return;
		}
		var closer = e.target.closest( '[data-nhm-close]' );
		if ( closer && closer.closest( 'dialog' ) ) {
			e.preventDefault();
			closer.closest( 'dialog' ).close();
			var back = closer.getAttribute( 'href' );
			if ( back && back !== '#' && window.history && history.replaceState ) {
				history.replaceState( null, '', back );
			}
			return;
		}
		if ( e.target.tagName === 'DIALOG' && e.target.classList.contains( 'nhm-modal' ) ) {
			e.target.close(); // Click on the backdrop.
			return;
		}
		// Copy account number / reference.
		var copy = e.target.closest( '[data-nhm-copy]' );
		if ( copy ) {
			var text = copy.getAttribute( 'data-nhm-copy' );
			var done = function () { copy.classList.add( 'is-done' ); var old = copy.textContent; copy.textContent = '✓'; setTimeout( function () { copy.textContent = old; copy.classList.remove( 'is-done' ); }, 1500 ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			}
			return;
		}
		var confirmBtn = e.target.closest( '[data-nhm-confirm]' );
		if ( confirmBtn && ! window.confirm( confirmBtn.getAttribute( 'data-nhm-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// A window that should already be open (reset link, result) opens as a real modal.
	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'dialog[data-nhm-autoopen]' ).forEach( function ( d ) {
			if ( d.showModal ) {
				d.close();
				d.showModal();
			}
		} );
		// Photo: show the chosen file at once.
		document.querySelectorAll( '[data-nhm-photo-input]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var img = document.querySelector( input.getAttribute( 'data-nhm-photo-input' ) );
				if ( img && input.files && input.files[0] && window.URL ) {
					img.src = URL.createObjectURL( input.files[0] );
				}
			} );
		} );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'nhm-form' ) ) {
			return;
		}
		if ( form.getAttribute( 'data-nhm-sent' ) ) {
			e.preventDefault();
			return;
		}
		form.setAttribute( 'data-nhm-sent', '1' );
		var btn = form.querySelector( 'button[type="submit"]' );
		if ( btn ) {
			btn.setAttribute( 'aria-busy', 'true' );
			btn.style.opacity = '.7';
		}
		// Allow another try if the browser stays on the page (e.g. validation in another script).
		setTimeout( function () { form.removeAttribute( 'data-nhm-sent' ); if ( btn ) { btn.style.opacity = ''; } }, 8000 );
	} );
} )();
