/**
 * The block checkout's payment method entry.
 *
 * Deliberately thin: the flow is the same as the classic checkout - the order-pay screen opens the
 * hosted window - so this only has to present the method honestly and tell the truth about when it
 * can be used. `canMakePayment` mirrors the PHP `is_available()` decision, so a cart the gateway
 * cannot honour is refused identically in both checkouts rather than offered and then failing.
 *
 * The label carries the brand mark itself: the block checkout ignores the classic gateway `icon`
 * property, so a method that does not draw its own logo here is the one plain-text line in a list
 * of logos.
 */
( function () {
	'use strict';

	var settings = window.wc.wcSettings.getSetting( 'p2flux_data', {} );
	var element = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var __ = window.wp.i18n.__;

	var title = decode( settings.title || 'Pay with USDC' );

	function Label() {
		var parts = [];

		if ( settings.icon ) {
			parts.push(
				element( 'img', { key: 'icon', src: settings.icon, alt: '', width: 22, height: 22 } )
			);
		}
		parts.push( element( 'span', { key: 'title' }, title ) );

		if ( settings.testMode ) {
			parts.push(
				element(
					'span',
					{ key: 'badge', className: 'p2flux-method__badge' },
					__( 'Test mode', 'p2flux-for-woocommerce' )
				)
			);
		}

		return element( 'span', { className: 'p2flux-method' }, parts );
	}

	function Description() {
		var lines = [];
		var text = decode( settings.description || '' );

		if ( text ) {
			lines.push( element( 'p', { key: 'description', className: 'p2flux-method__note' }, text ) );
		}

		// The one thing a first-time buyer cannot guess, and the reason a "place order" click does
		// not finish the payment.
		lines.push(
			element(
				'p',
				{ key: 'flow', className: 'p2flux-method__note' },
				__(
					'After placing the order you will be asked to confirm the payment in your own wallet.',
					'p2flux-for-woocommerce'
				)
			)
		);

		if ( settings.testMode ) {
			lines.push(
				element(
					'p',
					{ key: 'test', className: 'p2flux-method__note' },
					element(
						'strong',
						null,
						__( 'Test mode: settles on Base Sepolia and moves no real money.', 'p2flux-for-woocommerce' )
					)
				)
			);
		}

		return element( 'div', null, lines );
	}

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'p2flux',
		label: element( Label, null ),
		ariaLabel: title,
		content: element( Description, null ),
		edit: element( Description, null ),
		canMakePayment: function ( args ) {
			if ( ! settings.available ) {
				return false;
			}

			// A cart carrying a subscription is only offered this method when the gateway can
			// actually honour THIS cart: USD, no trial, no sign-up fee, one subscription, and a
			// first payment equal to the renewals. That answer comes with the cart, because it is a
			// question about the cart - the data baked in when this script was registered cannot
			// know what the shopper added since.
			var requirements = ( args && args.paymentRequirements ) || [];
			if ( requirements.indexOf( 'subscriptions' ) === -1 ) {
				return true;
			}

			var extensions = ( args && args.cart && args.cart.extensions ) || {};
			if ( extensions.p2flux && typeof extensions.p2flux.recurring === 'boolean' ) {
				return extensions.p2flux.recurring;
			}

			return !! settings.recurring;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
