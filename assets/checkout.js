/**
 * The pay screen: open the hosted checkout, listen, and let the server decide.
 *
 * Nothing here decides whether an order is paid. The checkout window reports what a wallet did; the
 * server asks P2Flux, which reads the chain. That split is the whole point - a page can claim
 * anything, and this one deliberately cannot grant itself a payment.
 *
 * The other rule: once a payment MAY exist, never offer to pay again. A closed window is not a
 * failed payment, so the button becomes "Check payment", which asks whether the money arrived.
 */
( function () {
	'use strict';

	var config = window.p2fluxWc;
	if ( ! config ) {
		return;
	}

	var origin = new URL( config.checkout ).origin;
	var button = document.getElementById( 'p2flux-pay' );
	var check = document.getElementById( 'p2flux-check' );
	var statusLine = document.getElementById( 'p2flux-status' );
	var popup = null;
	var settled = false;

	/**
	 * The status line is the only thing that talks.
	 *
	 * `tone` is 'busy' | 'warn' | 'bad' | '' and only changes how it reads - what it says is the whole
	 * message. A blank message hides the line rather than leaving an empty box on the page.
	 */
	function say( text, tone ) {
		if ( ! statusLine ) {
			return;
		}
		statusLine.textContent = text || '';
		statusLine.className = 'p2flux-pay__status' + ( tone ? ' p2flux-pay__status--' + tone : '' );
	}

	/**
	 * Once a payment may exist, the primary button stops being an option.
	 *
	 * Leaving "Pay with your wallet" enabled next to "check my payment" is how somebody pays twice: the
	 * first is the familiar shape, and the customer has no way to know the money already left.
	 */
	function paymentMayExist() {
		if ( button ) {
			button.disabled = true;
			button.hidden = true;
		}
		if ( check ) {
			check.hidden = false;
		}
	}

	function post( url, body ) {
		var form = new FormData();
		form.append( 'nonce', config.nonce );
		form.append( 'order_id', config.orderId );
		form.append( 'order_key', config.orderKey );
		Object.keys( body || {} ).forEach( function ( key ) {
			form.append( key, body[ key ] );
		} );

		return fetch( url, { method: 'POST', body: form, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	function done( redirect ) {
		settled = true;
		if ( popup && ! popup.closed ) {
			popup.close();
		}
		window.location.href = redirect || config.redirect;
	}

	/**
	 * Ask the server about a transaction the checkout reported.
	 *
	 * PAYMENT_CONFIRMING is not a failure: the money is on chain and settling. The page keeps asking
	 * about that same transaction, and never suggests paying again.
	 */
	function verify( txHash, receipt ) {
		say( config.i18n.verifying, 'busy' );

		post( config.ajax.verify, { tx_hash: txHash, settlement_receipt: receipt || '' } )
			.then( function ( response ) {
				var result = response && response.data ? response.data : {};

				if ( 'paid' === result.status ) {
					done( result.redirect );
					return;
				}
				if ( 'confirming' === result.status ) {
					say( config.i18n.confirming, 'busy' );
					paymentMayExist();
					window.setTimeout( function () {
						verify( txHash, '' );
					}, 5000 );
					return;
				}

				say( config.i18n.failed, 'bad' );
				offerCheck();
			} )
			.catch( function () {
				say( config.i18n.retry, 'warn' );
				offerCheck();
			} );
	}

	/**
	 * Drive the subscription's first charge, then tell the still-open checkout window.
	 *
	 * The window is showing "the seller is collecting the first charge" until it hears back. Only
	 * outcomes the store will not quietly recover from are reported as failures - a transient error
	 * is ours to retry, and calling it a failure would ask the customer to fix something that is not
	 * broken.
	 */
	function activate( capability ) {
		say( config.i18n.collecting, 'busy' );

		post( config.ajax.activate, capability ? { subscription: capability } : {} )
			.then( function ( response ) {
				var result = response && response.data ? response.data : {};

				if ( 'finalized' === result.status ) {
					tell( { type: 'p2flux.finalized', tx_hash: result.tx_hash || undefined } );
					done( result.redirect );
					return;
				}
				if ( 'confirming' === result.status || 'pending' === result.status ) {
					say( config.i18n.confirming, 'busy' );
					window.setTimeout( function () {
						activate( '' );
					}, 5000 );
					return;
				}

				// A bare code. The checkout window composes the sentence the customer reads; a
				// merchant page names a failure but never writes on that screen.
				tell( { type: 'p2flux.activation_failed', code: result.code } );
				say( config.i18n.failed, 'bad' );
			} )
			.catch( function () {
				say( config.i18n.retry, 'warn' );
				window.setTimeout( function () {
					activate( '' );
				}, 15000 );
			} );
	}

	function tell( message ) {
		if ( popup && ! popup.closed ) {
			popup.postMessage( message, origin );
		}
	}

	function offerCheck() {
		paymentMayExist();
	}

	function onMessage( event ) {
		if ( event.origin !== origin || event.source !== popup ) {
			return;
		}

		var data = event.data || {};

		// The handshake. The checkout sends results only to the origin this reply came from, so
		// without it nothing is ever delivered anywhere.
		if ( 'p2flux.ready' === data.type ) {
			popup.postMessage( { type: 'p2flux.hello' }, origin );
			return;
		}

		if ( 'p2flux.payment.completed' === data.type ) {
			verify( data.tx_hash, data.settlement_receipt );
			return;
		}

		if ( 'p2flux.subscription.created' === data.type ) {
			activate( data.subscription );
		}
	}

	/**
	 * Watch for the window closing without a result.
	 *
	 * A wallet may already have broadcast a payment by then, so the page offers to CHECK rather than
	 * to pay - and says so, because "try again" in front of someone who has just paid is how a
	 * customer pays twice.
	 */
	function watchPopup() {
		var timer = window.setInterval( function () {
			if ( settled ) {
				window.clearInterval( timer );
				return;
			}
			if ( popup && popup.closed ) {
				window.clearInterval( timer );
				say( config.i18n.closed, 'warn' );
				offerCheck();
			}
		}, 1000 );
	}

	if ( button ) {
		button.addEventListener( 'click', function () {
			// Opened straight from the click: a window opened later, from a callback, is blocked.
			if ( 'collect' === config.mode ) {
				activate( '' );
				return;
			}

			popup = window.open(
				config.checkout + '/#/' + config.mode + '/' + encodeURIComponent( config.token ),
				'p2flux',
				'width=460,height=680'
			);

			if ( ! popup ) {
				say( config.i18n.blocked, 'bad' );
				return;
			}

			say( config.i18n.opening, 'busy' );
			watchPopup();
		} );
	}

	if ( check ) {
		check.addEventListener( 'click', function () {
			check.disabled = true;
			say( config.i18n.checking, 'busy' );

			post( config.ajax.check, {} )
				.then( function ( response ) {
					var result = response && response.data ? response.data : {};

					if ( 'paid' === result.status ) {
						done( result.redirect );
						return;
					}

					say(
						'confirming' === result.status ? config.i18n.confirming : config.i18n.notFound,
						'confirming' === result.status ? 'busy' : 'warn'
					);
					check.disabled = false;
				} )
				.catch( function () {
					say( config.i18n.retry, 'warn' );
					check.disabled = false;
				} );
		} );
	}

	window.addEventListener( 'message', onMessage );
} )();
