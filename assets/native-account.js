/**
 * Cancel a native subscription from the account page. One request, then the page reloads to show
 * the new state; the wallet authorization is revoked separately, through the existing revoke flow.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'p2flux-native-cancel' );
	var status = document.getElementById( 'p2flux-native-cancel-status' );
	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		if ( ! window.confirm( button.getAttribute( 'data-confirm' ) || 'Cancel this subscription? No further payments will be collected.' ) ) {
			return;
		}
		button.disabled = true;

		var form = new FormData();
		form.append( 'nonce', button.getAttribute( 'data-nonce' ) );
		form.append( 'subscription', button.getAttribute( 'data-subscription' ) );

		fetch( button.getAttribute( 'data-url' ), { method: 'POST', body: form, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
					return;
				}
				button.disabled = false;
				if ( status ) {
					status.textContent = response && response.data && response.data.message ? response.data.message : 'That did not work. Please try again.';
				}
			} )
			.catch( function () {
				button.disabled = false;
				if ( status ) {
					status.textContent = 'That did not work. Please try again.';
				}
			} );
	} );
} )();
