<?php
/**
 * Fault injection for the audit - test harness only, never shipped.
 *
 * Wraps the real transport. When the option `p2flux_audit_fault` names a fault, the NEXT matching
 * request is altered, once, and the option is cleared. Faults:
 *
 *   confirming        real /v1/charges goes out; the 200 answer is rewritten to CONFIRMING (real hash kept)
 *   confirming_bogus  /v1/charges is NOT sent; answer is CONFIRMING with a hash that does not exist
 *   balance           /v1/charges is NOT sent; answer is 400 INSUFFICIENT_BALANCE
 *   http500           /v1/charges is NOT sent; answer is 500 with an empty body
 *   http429           /v1/charges is NOT sent; answer is 429 RATE_LIMITED
 *   malformed         /v1/charges is NOT sent; transport returns a non-array body
 *   timeout           /v1/charges is NOT sent; transport throws NETWORK_ERROR
 *   recover_inconsistent  /v1/charges/recover answers 502 PAYMENT_RECOVERY_INCONSISTENT
 *   recover_unavailable   /v1/charges/recover answers 503 RECOVERY_UNAVAILABLE
 *   recover_not_found     /v1/charges/recover answers found:false PAYMENT_NOT_FOUND
 *
 * Every injection is written to wp-content/p2flux-fault.log as `<time> <fault> <path>` (no bodies).
 */
defined( 'ABSPATH' ) || exit;

$p2flux_fault_override = null;
$p2flux_fault_override = static function () use ( &$p2flux_fault_override ) {
	remove_filter( 'p2flux_wc_transport', $p2flux_fault_override );
	$real = P2Flux_WC_Client::transport();
	add_filter( 'p2flux_wc_transport', $p2flux_fault_override );

	return static function ( $url, $payload, $timeout ) use ( $real ) {
		$fault = (string) get_option( 'p2flux_audit_fault', '' );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$note  = static function ( $f, $p ) {
			file_put_contents( WP_CONTENT_DIR . '/p2flux-fault.log', gmdate( 'c' ) . " {$f} {$p}\n", FILE_APPEND );
			delete_option( 'p2flux_audit_fault' );
		};

		if ( '/v1/charges' === $path && '' !== $fault ) {
			switch ( $fault ) {
				case 'confirming':
					list( $code, $body ) = $real( $url, $payload, $timeout );
					$note( $fault, $path );
					if ( 200 === (int) $code && isset( $body['tx_hash'] ) ) {
						$body['status'] = 'CONFIRMING'; $body['ok'] = false; $body['already_paid'] = false; $body['action'] = 'WAIT';
					}
					return array( $code, $body );
				case 'confirming_bogus':
					$note( $fault, $path );
					return array( 200, array( 'status' => 'CONFIRMING', 'ok' => false, 'already_paid' => false, 'action' => 'WAIT', 'tx_hash' => '0x' . str_repeat( '0badc0de', 8 ), 'period_index' => isset( $payload['__period'] ) ? $payload['__period'] : 999999 ) );
				case 'balance':
					$note( $fault, $path );
					return array( 400, array( 'error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED' ) );
				case 'http500':
					$note( $fault, $path );
					return array( 500, array() );
				case 'http429':
					$note( $fault, $path );
					return array( 429, array( 'error' => 'RATE_LIMITED', 'action' => 'RETRY_LATER', 'retry_after' => 60 ) );
				case 'malformed':
					$note( $fault, $path );
					return array( 200, 'this is not json' );
				case 'timeout':
					$note( $fault, $path );
					throw new \P2FluxWC\Vendor\P2Flux\P2FluxException( 'NETWORK_ERROR', 'RETRY_LATER', array( 'detail' => 'injected timeout' ) );
			}
		}
		if ( '/v1/charges/recover' === $path && 0 === strpos( $fault, 'recover_' ) ) {
			$note( $fault, $path );
			switch ( $fault ) {
				case 'recover_inconsistent':
					return array( 502, array( 'error' => 'PAYMENT_RECOVERY_INCONSISTENT', 'action' => 'RETRY_LATER' ) );
				case 'recover_unavailable':
					return array( 503, array( 'error' => 'RECOVERY_UNAVAILABLE', 'action' => 'RETRY_LATER' ) );
				case 'recover_not_found':
					return array( 200, array( 'found' => false, 'code' => 'PAYMENT_NOT_FOUND' ) );
			}
		}

		return $real( $url, $payload, $timeout );
	};
};
add_filter( 'p2flux_wc_transport', $p2flux_fault_override );
