/**
 * The customer's own controls: restore an approval, retry a payment, revoke.
 *
 * Each wallet window is opened synchronously from the click and pointed at its URL afterwards,
 * because a window opened after a fetch is a window the browser blocks.
 */
( function () {
	'use strict';

	var config = window.p2fluxWcAccount;
	if ( ! config ) {
		return;
	}

	var origin = new URL( config.checkout ).origin;
	var statusLine = document.getElementById( 'p2flux-account-status' );

	function say( text ) {
		if ( statusLine ) {
			statusLine.textContent = text;
		}
	}

	function post( url, body ) {
		var form = new FormData();
		form.append( 'nonce', config.nonce );
		form.append( 'subscription', config.subscription );
		Object.keys( body || {} ).forEach( function ( key ) {
			form.append( key, body[ key ] );
		} );

		return fetch( url, { method: 'POST', body: form, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	function walletFlow( button, endpoint, path, onResult ) {
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var popup = window.open( 'about:blank', 'p2fluxWallet', 'width=460,height=680' );
			if ( ! popup ) {
				say( config.i18n.blocked );
				return;
			}

			button.disabled = true;
			say( config.i18n.waiting );

			post( endpoint, {} )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						popup.close();
						button.disabled = false;
						say( response && response.data ? response.data.message : config.i18n.failed );
						return;
					}

					popup.location = config.checkout + '/#/' + path + '/' + encodeURIComponent( response.data.token );

					window.addEventListener( 'message', function ( event ) {
						if ( event.origin !== origin || event.source !== popup ) {
							return;
						}

						var data = event.data || {};

						if ( 'p2flux.ready' === data.type ) {
							popup.postMessage( { type: 'p2flux.hello' }, origin );
							return;
						}

						onResult( data, button );
					} );
				} )
				.catch( function () {
					popup.close();
					button.disabled = false;
					say( config.i18n.failed );
				} );
		} );
	}

	walletFlow( document.getElementById( 'p2flux-restore' ), config.ajax.restore, 'approve', function ( data, button ) {
		if ( 'p2flux.allowance.restored' !== data.type ) {
			return;
		}

		say( config.i18n.restored );
		button.disabled = false;

		// The approval is in place; collecting the outstanding payment is the store's job, and it
		// can happen immediately rather than at the next scheduled attempt.
		post( config.ajax.retry, {} ).then( function ( response ) {
			if ( response && response.success && response.data.message ) {
				say( response.data.message );
			}
		} );
	} );

	walletFlow( document.getElementById( 'p2flux-revoke' ), config.ajax.session, 'cancel', function ( data ) {
		if ( 'p2flux.subscription.revoked' !== data.type ) {
			return;
		}

		post( config.ajax.revoked, { tx_hash: data.tx_hash || '' } ).then( function () {
			say( config.i18n.revoked );
			window.location.reload();
		} );
	} );

	var retry = document.getElementById( 'p2flux-retry' );
	if ( retry ) {
		retry.addEventListener( 'click', function () {
			retry.disabled = true;
			say( config.i18n.retrying );

			post( config.ajax.retry, {} )
				.then( function ( response ) {
					say( response && response.success && response.data.message ? response.data.message : config.i18n.failed );
					retry.disabled = false;
				} )
				.catch( function () {
					say( config.i18n.failed );
					retry.disabled = false;
				} );
		} );
	}
} )();
