/**
 * The block checkout's payment method entry.
 *
 * Deliberately thin: the flow is the same as the classic checkout - the order-pay screen opens the
 * hosted window - so this only has to put the method in the list and tell the truth about when it
 * can be used. `canMakePayment` mirrors the PHP `is_available()` decision, so a cart the gateway
 * cannot honour is refused identically in both checkouts rather than offered and then failing.
 */
( function () {
	'use strict';

	var settings = window.wc.wcSettings.getSetting( 'p2flux_data', {} );
	var element = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;

	var label = decode( settings.title || 'Pay with USDC' );

	function Description() {
		var text = decode( settings.description || '' );
		var nodes = [ element( 'span', { key: 'description' }, text ) ];

		if ( settings.testMode ) {
			nodes.push(
				element(
					'strong',
					{ key: 'test' },
					' ' + window.wp.i18n.__( 'Test mode: no real money moves.', 'p2flux-for-woocommerce' )
				)
			);
		}

		return element( 'div', {}, nodes );
	}

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'p2flux',
		label: element( 'span', {}, label ),
		ariaLabel: label,
		content: element( Description, null ),
		edit: element( Description, null ),
		canMakePayment: function ( args ) {
			if ( ! settings.available ) {
				return false;
			}

			// A cart carrying a subscription is only offered this method when the gateway can
			// actually honour a subscription: USD, no trial, no sign-up fee, one per order.
			var requirements = ( args && args.paymentRequirements ) || [];
			if ( requirements.indexOf( 'subscriptions' ) !== -1 ) {
				return !! settings.recurring;
			}

			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
