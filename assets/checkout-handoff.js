/**
 * Open the wallet window from the "Place order" click itself.
 *
 * The hosted checkout needs a token that only exists once the order does, so the window cannot be
 * given its address here. But a window can be OPENED here - browsers allow one per real click - and
 * navigated later by the pay page, which finds it again by name. Until then it shows a one-line
 * "preparing" note, and closes itself if nobody ever navigates it (a validation error kept the
 * shopper on the checkout, say).
 *
 * If a browser refuses any part of this, nothing is lost: the pay page still shows its own button,
 * exactly as before.
 */
( function () {
	'use strict';
	var config = window.p2fluxWcHandoff || {};
	var NAME = 'p2flux';
	var KEY = 'p2flux-handoff';
	var opened = null;

	function selectedMethod() {
		var store = window.wp && window.wp.data && window.wp.data.select ? window.wp.data.select( 'wc/store/payment' ) : null;
		if ( store && store.getActivePaymentMethod ) {
			return store.getActivePaymentMethod();
		}
		var radio = document.querySelector( 'input[name="payment_method"]:checked' );
		return radio ? radio.value : '';
	}

	function splash( win ) {
		var doc = win.document;
		doc.open();
		doc.write(
			'<!doctype html><html><head><meta charset="utf-8"><title>' + ( config.title || 'P2Flux' ) + '</title>' +
			'<style>html,body{height:100%;margin:0;font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0c1224;background:#fff}' +
			'.w{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:14px;padding:24px;text-align:center}' +
			'.s{width:28px;height:28px;border:3px solid #e6e9f0;border-top-color:#4636e3;border-radius:50%;animation:r 1s linear infinite}' +
			'@keyframes r{to{transform:rotate(360deg)}}p{margin:0;color:#4a5063}</style></head>' +
			'<body><div class="w"><div class="s"></div><p>' + ( config.preparing || 'Preparing your payment…' ) + '</p></div>' +
			'<script>setTimeout(function(){window.close()},' + ( config.ttl || 120000 ) + ')</script></body></html>'
		);
		doc.close();
	}

	function open() {
		if ( 'p2flux' !== selectedMethod() ) {
			return;
		}
		try {
			opened = window.open( 'about:blank', NAME, 'width=460,height=680' );
			if ( ! opened ) {
				return;
			}
			splash( opened );
			window.sessionStorage.setItem( KEY, String( Date.now() ) );
		} catch ( e ) {
			opened = null;
		}
	}

	function abandon() {
		try {
			window.sessionStorage.removeItem( KEY );
			if ( opened && ! opened.closed ) {
				opened.close();
			}
		} catch ( e ) {
			// Nothing to clean up.
		}
		opened = null;
	}

	// Block checkout: the click on "Place order", before React sees it.
	document.addEventListener(
		'click',
		function ( event ) {
			var target = event.target && event.target.closest ? event.target.closest( '.wc-block-components-checkout-place-order-button' ) : null;
			if ( target ) {
				open();
			}
		},
		true
	);

	// A checkout that stops (validation error, declined, network) never reaches the pay page.
	if ( window.wp && window.wp.data && window.wp.data.subscribe ) {
		var sawProcessing = false;
		window.wp.data.subscribe( function () {
			var checkout = window.wp.data.select( 'wc/store/checkout' );
			if ( ! checkout || ! opened ) {
				return;
			}
			var processing = checkout.isProcessing ? checkout.isProcessing() : false;
			var before = checkout.isBeforeProcessing ? checkout.isBeforeProcessing() : false;
			var hasError = checkout.hasError ? checkout.hasError() : false;
			if ( processing || before ) {
				sawProcessing = true;
			}
			if ( hasError || ( sawProcessing && checkout.isIdle && checkout.isIdle() ) ) {
				sawProcessing = false;
				abandon();
			}
		} );
	}

	// Classic checkout: jQuery events, synchronous with the submit.
	if ( window.jQuery ) {
		window.jQuery( function ( $ ) {
			$( 'form.checkout' ).on( 'checkout_place_order_p2flux', function () {
				open();
				return true;
			} );
			$( document.body ).on( 'checkout_error', abandon );
		} );
	}
} )();
