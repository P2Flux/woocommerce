/**
 * The order screen's P2Flux actions.
 *
 * The refund window is opened synchronously on the click and only THEN pointed at the prepared URL:
 * a window opened after an await is a window the browser blocks, and a merchant who has to disable
 * their pop-up blocker mid-refund is a merchant who does something else instead.
 *
 * Once a transfer has a hash this screen never offers to send another. It re-checks.
 */
( function () {
	'use strict';

	var config = window.p2fluxWcAdmin;
	if ( ! config ) {
		return;
	}

	var statusLine = document.getElementById( 'p2flux-refund-status' );

	function say( text ) {
		if ( statusLine ) {
			statusLine.textContent = text;
		}
	}

	function post( action, body ) {
		var form = new FormData();
		form.append( 'action', action );
		form.append( 'nonce', config.nonce );
		Object.keys( body || {} ).forEach( function ( key ) {
			form.append( key, body[ key ] );
		} );

		return fetch( config.ajax, { method: 'POST', body: form, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	var refund = document.getElementById( 'p2flux-refund' );
	if ( refund ) {
		refund.addEventListener( 'click', function () {
			var orderId = refund.getAttribute( 'data-order' );
			var popup = window.open( 'about:blank', 'p2fluxRefund', 'width=460,height=680' );

			if ( ! popup ) {
				say( config.i18n.blocked );
				return;
			}

			refund.disabled = true;
			say( config.i18n.sending );

			post( 'p2flux_refund_prepare', { order_id: orderId } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						popup.close();
						say( response && response.data ? response.data.message : '' );
						refund.disabled = false;
						return;
					}

					popup.location = response.data.url;
					watch( popup, orderId );
				} )
				.catch( function () {
					popup.close();
					refund.disabled = false;
				} );
		} );
	}

	function watch( popup, orderId ) {
		var origin = null;

		window.addEventListener( 'message', function ( event ) {
			if ( event.source !== popup ) {
				return;
			}
			if ( null === origin ) {
				origin = event.origin;
			}
			if ( event.origin !== origin ) {
				return;
			}

			var data = event.data || {};

			if ( 'p2flux.ready' === data.type ) {
				popup.postMessage( { type: 'p2flux.hello' }, origin );
				return;
			}

			// Sent as soon as the wallet broadcasts, and again when it confirms. Both are recorded:
			// the first is what stops this screen ever offering to send a second transfer.
			if ( 'p2flux.refund.sent' === data.type || 'p2flux.refund.confirmed' === data.type ) {
				verify( orderId, data.tx_hash );
			}
		} );
	}

	function verify( orderId, txHash ) {
		say( config.i18n.confirming );

		post( 'p2flux_refund_verify', { order_id: orderId, refund_tx_hash: txHash || '' } )
			.then( function ( response ) {
				if ( response && response.success && 'refunded' === response.data.status ) {
					say( config.i18n.refunded );
					window.location.reload();
					return;
				}
				if ( response && response.success && 'confirming' === response.data.status ) {
					window.setTimeout( function () {
						verify( orderId, txHash );
					}, 5000 );
					return;
				}

				say( response && response.data ? response.data.message : '' );
			} )
			.catch( function () {
				window.setTimeout( function () {
					verify( orderId, txHash );
				}, 10000 );
			} );
	}

	var recheck = document.getElementById( 'p2flux-refund-recheck' );
	if ( recheck ) {
		recheck.addEventListener( 'click', function () {
			verify( recheck.getAttribute( 'data-order' ), '' );
		} );
	}

	var recover = document.getElementById( 'p2flux-recover' );
	if ( recover ) {
		recover.addEventListener( 'click', function () {
			recover.disabled = true;
			say( config.i18n.recovering );

			post( 'p2flux_recover_charge', { order_id: recover.getAttribute( 'data-order' ) } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function () {
					recover.disabled = false;
				} );
		} );
	}
} )();
