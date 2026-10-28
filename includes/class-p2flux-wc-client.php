<?php
/**
 * Talking to P2Flux, on the environment an order was created in.
 *
 * The environment is a property of the ORDER, never of the current settings. A merchant who tests
 * on Base Sepolia and then flips the gateway to mainnet still has test orders on the books, and a
 * capability minted by one deployment is refused by the other - so a verification, a charge or a
 * refund for an old order has to go back to the API that issued it. Passing the current setting
 * instead is how a test order becomes permanently unverifiable.
 *
 * The transport is `wp_remote_post`, always. WordPress.org rejects plugins that call curl directly,
 * and the vendored SDK ships without its curl transport for exactly that reason.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use P2FluxWC\Vendor\P2Flux\P2FluxClient;
use P2FluxWC\Vendor\P2Flux\P2FluxException;

/**
 * SDK client factory.
 */
class P2Flux_WC_Client {

	/** Base Sepolia. Faucet money, real cryptography, nothing at stake. */
	const TEST = 'test';
	/** Base Mainnet. Real USDC. */
	const LIVE = 'mainnet';

	/**
	 * How long one API call may take.
	 *
	 * A charge waits for on-chain confirmation, which the SDK documents as tens of seconds on a busy
	 * public RPC. 25 seconds sits under the 30-second `max_execution_time` that shared hosts still
	 * ship, so a slow charge fails as a timeout the plugin can retry rather than as a fatal that
	 * leaves an order half-written. A timed-out charge is safe: the next call answers
	 * ALREADY_CHARGED.
	 */
	const TIMEOUT = 25;

	/**
	 * API base for an environment.
	 *
	 * @param string $environment TEST | LIVE.
	 * @return string
	 */
	public static function api_url( $environment ) {
		$url = self::LIVE === $environment ? 'https://api.p2flux.com' : 'https://api-test.p2flux.com';

		/**
		 * Filter the API base. For local development against a checkout and API on localhost.
		 *
		 * @param string $url         Default API base.
		 * @param string $environment TEST | LIVE.
		 */
		return apply_filters( 'p2flux_wc_api_url', $url, $environment );
	}

	/**
	 * Hosted checkout base for an environment.
	 *
	 * @param string $environment TEST | LIVE.
	 * @return string
	 */
	public static function checkout_url( $environment ) {
		$url = self::LIVE === $environment ? 'https://pay.p2flux.com' : 'https://pay-test.p2flux.com';

		/**
		 * Filter the hosted checkout base.
		 *
		 * @param string $url         Default checkout base.
		 * @param string $environment TEST | LIVE.
		 */
		return apply_filters( 'p2flux_wc_checkout_url', $url, $environment );
	}

	/**
	 * Block explorer base, for order notes a human can follow.
	 *
	 * @param string $environment TEST | LIVE.
	 * @return string
	 */
	public static function explorer_url( $environment ) {
		return self::LIVE === $environment ? 'https://basescan.org' : 'https://sepolia.basescan.org';
	}

	/**
	 * A client for one environment.
	 *
	 * @param string $environment TEST | LIVE.
	 * @param int    $timeout     Seconds one call may take. Only the capabilities lookup shortens it.
	 * @return P2FluxClient
	 */
	public static function for_environment( $environment, $timeout = self::TIMEOUT ) {
		$environment = self::LIVE === $environment ? self::LIVE : self::TEST;

		return new P2FluxClient(
			array(
				'apiUrl'    => self::api_url( $environment ),
				'timeout'   => (int) $timeout,
				'transport' => self::transport(),
			)
		);
	}

	/**
	 * A client for whatever environment an order was created in.
	 *
	 * @param WC_Order|WC_Subscription $object Order or subscription.
	 * @return P2FluxClient
	 */
	public static function for_object( $object ) {
		$stored = $object ? (string) $object->get_meta( '_p2flux_env' ) : '';

		return self::for_environment( '' !== $stored ? $stored : self::current_environment() );
	}

	/**
	 * The environment new payments are created in.
	 *
	 * @return string
	 */
	public static function current_environment() {
		$settings = get_option( 'woocommerce_p2flux_settings', array() );

		return ( isset( $settings['environment'] ) && self::LIVE === $settings['environment'] ) ? self::LIVE : self::TEST;
	}

	/**
	 * The WordPress HTTP transport the SDK calls.
	 *
	 * @return callable
	 */
	public static function transport() {
		/**
		 * Filter the transport. Tests replace it with a stub; nothing else should.
		 *
		 * @param callable|null $transport Replacement transport.
		 */
		$override = apply_filters( 'p2flux_wc_transport', null );

		return self::merchant_ref_fallback( is_callable( $override ) ? $override : self::wp_transport() );
	}

	/**
	 * What P2Flux accepts as a merchant_ref. A value that does not match is left out, never altered.
	 */
	const MERCHANT_REF = '~^[A-Za-z0-9._:/#-]{1,64}$~D';

	/**
	 * The order number as P2Flux's merchant_ref, or null when it cannot be sent as is.
	 *
	 * @param WC_Order $order Order (a renewal order for a renewal charge).
	 * @return string|null
	 */
	public static function merchant_ref( $order ) {
		$number = trim( (string) $order->get_order_number() );

		return 1 === preg_match( self::MERCHANT_REF, $number ) ? $number : null;
	}

	/**
	 * " Merchant reference: X." when the API echoed a valid one, else ''. For order notes.
	 *
	 * @param mixed $ref merchant_ref from a verified answer.
	 * @return string
	 */
	public static function merchant_ref_note( $ref ) {
		return is_string( $ref ) && 1 === preg_match( self::MERCHANT_REF, $ref )
			/* translators: %s: the order number P2Flux recorded with the payment. */
			? ' ' . sprintf( __( 'Merchant reference: %s.', 'p2flux-for-woocommerce' ), $ref )
			: '';
	}

	/**
	 * An API older than merchant_ref refuses the unknown field (400 INVALID_REQUEST, without naming
	 * it) and exposes no flag that says it is supported. So a request carrying merchant_ref that is
	 * refused that way is sent once more without it: a 400 created nothing, and a request that was
	 * wrong for another reason is refused again. The newer API accepts it the first time.
	 *
	 * When the resend goes through, that API (per host, so test and live apart) is remembered for an
	 * hour as one without merchant_ref, and it is left out meanwhile - one request per payment, not two.
	 *
	 * @param callable $send Transport.
	 * @return callable
	 */
	private static function merchant_ref_fallback( callable $send ) {
		return static function ( $url, $payload, $timeout ) use ( $send ) {
			$memo    = 'p2flux_wc_no_mr_' . md5( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
			$has_ref = is_array( $payload ) && isset( $payload['merchant_ref'] );
			if ( $has_ref && get_transient( $memo ) ) {
				unset( $payload['merchant_ref'] );
				$has_ref = false;
			}
			$answer = $send( $url, $payload, $timeout );
			if ( $has_ref && 400 === (int) $answer[0]
				&& isset( $answer[1]['error'] ) && 'INVALID_REQUEST' === $answer[1]['error'] ) {
				unset( $payload['merchant_ref'] );
				$answer = $send( $url, $payload, $timeout );
				if ( 400 !== (int) $answer[0] ) {
					set_transient( $memo, 1, HOUR_IN_SECONDS );
				}
			}

			return $answer;
		};
	}

	/**
	 * wp_remote_post as an SDK transport.
	 *
	 * @return callable
	 */
	private static function wp_transport() {
		return static function ( $url, $payload, $timeout ) {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( empty( $payload ) ? (object) array() : $payload ),
					// WordPress defaults to five seconds, which would abandon most charges while
					// they are still confirming - and the SDK's own timeout would never apply.
					'timeout' => (int) $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				/*
				 * The request never reached the API, so nothing is known about whether the charge
				 * landed. NETWORK_ERROR is RETRY_LATER, never a decline: treating an unreachable
				 * API as a failed payment is how a subscription that just paid gets cancelled.
				 */
				throw new P2FluxException( 'NETWORK_ERROR', 'RETRY_LATER', array( 'detail' => esc_html( $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $body ) ? $body : array() );
		};
	}
}
