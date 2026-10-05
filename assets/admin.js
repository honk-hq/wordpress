/**
 * Settings → Honk: the "Send test notification" button.
 *
 * @package Honk
 */
( function () {
	'use strict';

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
}() );
