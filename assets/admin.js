/**
 * Settings → Honk: the "Send test notification" button, and each event's details with its
 * preview.
 *
 * @package Honk
 */
( function () {
	'use strict';

	/*
	 * Send test notification.
	 */
	function initTest() {
		var button = document.getElementById( 'honk-test' );
		if ( ! button || ! window.honkAdmin ) {
			return;
		}
		var spinner = document.getElementById( 'honk-test-spinner' );
		var result = document.getElementById( 'honk-test-result' );

		function show( ok, text ) {
			result.innerHTML = '';
			var notice = document.createElement( 'div' );
			notice.className = 'notice inline ' + ( ok ? 'notice-success' : 'notice-error' );
			var p = document.createElement( 'p' );
			p.textContent = text;
			notice.appendChild( p );
			result.appendChild( notice );
		}

		button.addEventListener( 'click', function () {
			var data = new window.FormData();
			data.append( 'action', 'honk_test' );
			data.append( 'nonce', window.honkAdmin.nonce );
			data.append( 'server_url', document.getElementById( 'honk-server-url' ).value );
			data.append( 'api_key', document.getElementById( 'honk-api-key' ).value );

			button.disabled = true;
			spinner.classList.add( 'is-active' );
			result.textContent = window.honkAdmin.sending;

			window.fetch( window.honkAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					show( !! json.success, json.data && json.data.message ? json.data.message : window.honkAdmin.failed );
				} )
				.catch( function () {
					show( false, window.honkAdmin.failed );
				} )
				.then( function () {
					button.disabled = false;
					spinner.classList.remove( 'is-active' );
				} );
		} );
	}

	/*
	 * Details and preview.
	 */

	/**
	 * Turns a message outline into its title and text, keeping the parts whose details are on.
	 * The same rules as Honk_Details::compose() in PHP.
	 *
	 * @param {Object} spec Outline: title (parts), lines, fallback.
	 * @param {Object} on   Detail → whether it is on.
	 * @return {{title: string, message: string}}
	 */
	function compose( spec, on ) {
		function keep( part ) {
			var i;
			var when = part[ 'if' ] || [];
			var not = part.not || [];
			for ( i = 0; i < when.length; i++ ) {
				if ( ! on[ when[ i ] ] ) {
					return false;
				}
			}
			for ( i = 0; i < not.length; i++ ) {
				if ( on[ not[ i ] ] ) {
					return false;
				}
			}
			return '' !== part.t;
		}

		var title = ( spec.title || [] ).filter( keep ).map( function ( part ) {
			return part.t;
		} ).join( '' );

		var lines = [];
		( spec.lines || [] ).forEach( function ( line ) {
			if ( line.any && line.any.length && ! line.any.some( function ( fact ) {
				return on[ fact ];
			} ) ) {
				return;
			}
			var texts = line.parts.filter( keep ).map( function ( part ) {
				return part.t;
			} );
			if ( ! texts.length ) {
				if ( undefined === line.empty ) {
					return;
				}
				texts = [ line.empty ];
			}
			var text = texts.join( line.sep );
			lines.push( undefined !== line.wrap ? line.wrap.split( '\u0001' ).join( text ) : text );
		} );

		var message = lines.join( '\n' );
		if ( '' === message && undefined !== spec.fallback ) {
			message = spec.fallback;
		}
		return { title: title, message: message };
	}

	/**
	 * Disables a choice (personal data is off, or the form's answers are left out) or enables it
	 * again. While it's disabled, the hidden field before it keeps the saved choice, because a
	 * disabled checkbox isn't submitted.
	 *
	 * @param {HTMLInputElement} box     Checkbox.
	 * @param {boolean}          blocked Whether it can't be chosen now.
	 */
	function setBlocked( box, blocked ) {
		if ( blocked === box.disabled ) {
			return;
		}
		var item = box.closest( 'li' );
		var saved = item ? item.querySelector( '.honk-choice-saved' ) : null;
		if ( blocked ) {
			if ( saved ) {
				saved.value = box.checked ? '1' : '0';
			}
			box.checked = false;
			box.disabled = true;
		} else {
			box.checked = !! saved && '1' === saved.value;
			if ( saved ) {
				saved.value = '0';
			}
			box.disabled = false;
		}
	}

	function initDetails() {
		var specs = window.honkPreviews || {};
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.honk-details-row' ) );
		var personal = document.getElementById( 'honk-include-pii' );

		function boxes( row, selector ) {
			return Array.prototype.slice.call( row.querySelectorAll( 'input[type="checkbox"]' + ( selector || '' ) ) );
		}

		function render( row ) {
			var id = row.getAttribute( 'data-event' );
			if ( ! specs[ id ] ) {
				return;
			}
			var on = {};
			boxes( row, '[data-fact]' ).forEach( function ( box ) {
				on[ box.getAttribute( 'data-fact' ) ] = box.checked && ! box.disabled;
			} );
			var composed = compose( specs[ id ], on );
			row.querySelector( '.honk-preview-title' ).textContent = composed.title;
			// Honk shows the title as the text of a message that has none.
			row.querySelector( '.honk-preview-message' ).textContent = '' !== composed.message ? composed.message : composed.title;
		}

		function renderLevel( row ) {
			var select = document.querySelector( 'select[data-honk-level="' + row.getAttribute( 'data-event' ) + '"]' );
			var level = row.querySelector( '.honk-preview-level' );
			if ( select && level && select.selectedIndex >= 0 ) {
				level.textContent = select.options[ select.selectedIndex ].text;
			}
		}

		function refreshBlocked( row ) {
			var allowed = ! personal || personal.checked;
			var values = row.querySelector( 'input[type="checkbox"][data-fact="values"]' );
			boxes( row, '[data-pii]' ).forEach( function ( box ) {
				var blocked = ! allowed;
				if ( 'values' === box.getAttribute( 'data-requires' ) ) {
					blocked = blocked || ! values || ! values.checked || values.disabled;
				}
				setBlocked( box, blocked );
				// The note explains a disabled choice only while personal data is off.
				if ( allowed ) {
					box.removeAttribute( 'aria-describedby' );
				} else if ( box.getAttribute( 'data-note' ) ) {
					box.setAttribute( 'aria-describedby', box.getAttribute( 'data-note' ) );
				}
			} );
			var note = row.querySelector( '.honk-pii-note' );
			if ( note ) {
				note.hidden = allowed;
			}
		}

		rows.forEach( function ( row ) {
			row.addEventListener( 'change', function ( event ) {
				if ( event.target && 'values' === event.target.getAttribute( 'data-fact' ) ) {
					refreshBlocked( row );
				}
				render( row );
			} );
			renderLevel( row );
		} );

		Array.prototype.slice.call( document.querySelectorAll( 'select[data-honk-level]' ) ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				var row = document.querySelector( '.honk-details-row[data-event="' + select.getAttribute( 'data-honk-level' ) + '"]' );
				if ( row ) {
					renderLevel( row );
				}
			} );
		} );

		if ( personal ) {
			personal.addEventListener( 'change', function () {
				rows.forEach( function ( row ) {
					refreshBlocked( row );
					render( row );
				} );
			} );
		}

		Array.prototype.slice.call( document.querySelectorAll( '.honk-details-toggle' ) ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var row = document.getElementById( button.getAttribute( 'aria-controls' ) );
				if ( ! row ) {
					return;
				}
				var open = 'true' !== button.getAttribute( 'aria-expanded' );
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				row.hidden = ! open;
				button.closest( 'tr' ).classList.toggle( 'is-open', open );
			} );
		} );
	}

	initTest();
	initDetails();
}() );
