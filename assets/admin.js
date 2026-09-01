/* Lookit Page Watch — admin behaviour. Prefix: lpw */
( function () {
	'use strict';

	if ( typeof window.lpwData === 'undefined' ) {
		return;
	}

	var data = window.lpwData;

	function post( action, body ) {
		var form = new FormData();
		form.append( 'action', action );
		form.append( 'nonce', data.nonce );

		Object.keys( body || {} ).forEach( function ( key ) {
			form.append( key, body[ key ] );
		} );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// Tell the server the run is over so the "after every capture run" digest
	// can fire. Manual captures happen one page at a time, so there is no
	// other point at which the run is known to have finished.
	function finishRun() {
		return post( 'lpw_finish_run', {} ).catch( function () {
			// A failed digest must never block the page from refreshing.
		} );
	}

	function busy( button, on, label ) {
		if ( ! button ) {
			return;
		}
		if ( on ) {
			button.dataset.lpwLabel = button.textContent;
			button.textContent = label || data.capturing;
			button.classList.add( 'lpw-busy' );
		} else {
			button.textContent = button.dataset.lpwLabel || button.textContent;
			button.classList.remove( 'lpw-busy' );
		}
	}

	function capture( pageId, button ) {
		busy( button, true );
		return post( 'lpw_capture_page', { page_id: pageId } )
			.then( function ( result ) {
				busy( button, false );
				if ( ! result.success ) {
					window.alert( ( result.data && result.data.message ) || 'Capture failed.' );
				}
				return result;
			} )
			.catch( function () {
				busy( button, false );
			} );
	}

	// Capture a single page.
	document.querySelectorAll( '.lpw-capture' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			capture( button.dataset.page, button ).then( function () {
				return finishRun();
			} ).then( function () {
				window.location.reload();
			} );
		} );
	} );

	// Capture every listed page, one at a time so nothing times out.
	var captureAll = document.querySelector( '.lpw-capture-all' );
	if ( captureAll ) {
		captureAll.addEventListener( 'click', function () {
			var rows = Array.prototype.slice.call( document.querySelectorAll( 'tr[data-page]' ) );
			if ( ! rows.length ) {
				return;
			}

			var index = 0;
			busy( captureAll, true, data.capturing );

			function next() {
				if ( index >= rows.length ) {
					captureAll.textContent = data.sending;
					finishRun().then( function () {
						window.location.reload();
					} );
					return;
				}
				var pageId = rows[ index ].dataset.page;
				index++;
				captureAll.textContent = data.capturing + ' ' + index + '/' + rows.length;
				capture( pageId, null ).then( next );
			}

			next();
		} );
	}

	// Replace the baseline. Destructive, so confirm first.
	document.querySelectorAll( '.lpw-baseline' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			if ( ! window.confirm( data.confirmBaseline ) ) {
				return;
			}
			busy( button, true, data.capturing );
			post( 'lpw_set_baseline', { page_id: button.dataset.page } ).then( function ( result ) {
				if ( ! result.success ) {
					busy( button, false );
					window.alert( ( result.data && result.data.message ) || 'Could not set the baseline.' );
					return;
				}
				window.location.reload();
			} );
		} );
	} );

	// Add-page form toggle.
	var toggle = document.querySelector( '.lpw-toggle-add' );
	var addForm = document.querySelector( '.lpw-addform' );
	if ( toggle && addForm ) {
		toggle.addEventListener( 'click', function () {
			addForm.hidden = ! addForm.hidden;
			if ( ! addForm.hidden ) {
				var field = addForm.querySelector( 'input[type="url"]' );
				if ( field ) {
					field.focus();
				}
			}
		} );
	}

	// Select all checkbox.
	var checkAll = document.querySelector( '.lpw-check-all' );
	if ( checkAll ) {
		checkAll.addEventListener( 'change', function () {
			document.querySelectorAll( 'input[name="lpw_ids[]"]' ).forEach( function ( box ) {
				box.checked = checkAll.checked;
			} );
		} );
	}

	// Confirm bulk delete.
	document.querySelectorAll( 'select[name="lpw_bulk_action"]' ).forEach( function ( select ) {
		var form = select.closest( 'form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( event ) {
			if ( 'delete' === select.value && ! window.confirm( data.confirmDelete ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
