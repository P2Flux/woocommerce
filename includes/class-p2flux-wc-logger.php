<?php
/**
 * Logging that cannot leak a capability.
 *
 * Debug logs are copied into support tickets, pasted into forums and shipped to log aggregators. A
 * `p2s2` reference in one of those is a standing permission to collect somebody's subscription,
 * sitting in a text file nobody is treating as a secret. So every message goes through a redactor
 * rather than relying on each call site to remember - the call site that forgets is the one that
 * ends up in the ticket.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * A redacting wrapper over WooCommerce's logger.
 */
class P2Flux_WC_Logger {

	/**
	 * Token prefixes P2Flux issues.
	 *
	 * All of them, not just the dangerous one: an intent or a refund token in a log is a smaller
	 * problem than a capability, and there is no reason to publish either.
	 */
	const PREFIXES = array( 'p2s2', 'p2setup2', 'p2f1', 'p2paid1', 'p2refund1', 'p2cancel1', 'p2approve1', 'p2fwc1' );

	/**
	 * Replace any token in a string with a marker naming only its kind.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	public static function redact( $text ) {
		$pattern = '/\b(' . implode( '|', self::PREFIXES ) . ')\.[A-Za-z0-9._\-]+/';

		return preg_replace( $pattern, '[$1 redacted]', (string) $text );
	}

	/**
	 * Write a line, if the merchant turned logging on.
	 *
	 * @param string $message What happened.
	 * @param array  $context Extra fields; redacted like the message.
	 * @param string $level   WooCommerce log level.
	 * @return void
	 */
	public static function log( $message, array $context = array(), $level = 'info' ) {
		$settings = get_option( 'woocommerce_p2flux_settings', array() );
		if ( empty( $settings['debug'] ) || 'yes' !== $settings['debug'] ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$line = self::redact( $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . self::redact( wp_json_encode( $context ) );
		}

		wc_get_logger()->log( $level, $line, array( 'source' => 'p2flux' ) );
	}

	/**
	 * Something went wrong and a human should know.
	 *
	 * Errors are logged whether or not debug logging is on: a merchant should not have to have
	 * predicted a failure in order to have a record of it.
	 *
	 * @param string $message What happened.
	 * @param array  $context Extra fields.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$line = self::redact( $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . self::redact( wp_json_encode( $context ) );
		}

		wc_get_logger()->log( 'error', $line, array( 'source' => 'p2flux' ) );
	}
}
