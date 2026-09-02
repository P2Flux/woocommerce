<?php
/**
 * `wp p2flux` — the commands a merchant needs a terminal for.
 *
 * Only one so far, and it exists because key rotation without it is a trap: change the key, and
 * every stored authorization becomes unreadable at the next renewal, silently, one subscription at
 * a time as they come due.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands.
 */
class P2Flux_WC_CLI {

	/**
	 * Re-encrypt every stored authorization with the current key.
	 *
	 * Safe to run repeatedly, and safe to interrupt: each subscription is finished before the next
	 * is started, and one already on the current key is skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp p2flux rekey
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function rekey( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		if ( null === P2Flux_WC_Crypto::current_key() ) {
			WP_CLI::error( 'No encryption key is configured, so there is nothing to rotate to.' );
		}
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
		}

		$moved   = 0;
		$skipped = 0;
		$stuck   = 0;

		$subscriptions = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => -1,
				'subscription_status'    => 'any',
			)
		);

		foreach ( $subscriptions as $subscription ) {
			if ( 'p2flux' !== $subscription->get_payment_method() ) {
				continue;
			}

			$items   = P2Flux_WC_Auth_History::all( $subscription );
			$changed = false;

			foreach ( $items as $index => $item ) {
				if ( empty( $item['cap'] ) || P2Flux_WC_Crypto::is_current( $item['cap'] ) ) {
					$skipped++;
					continue;
				}

				$plain = P2Flux_WC_Crypto::decrypt( $item['cap'] );
				if ( null === $plain ) {
					// No key on this site can open it. Left exactly as it is: overwriting would
					// destroy the only copy, and a key may still turn up in a wp-config backup.
					WP_CLI::warning( sprintf( 'subscription %d: an authorization could not be decrypted and was left alone', $subscription->get_id() ) );
					$stuck++;
					continue;
				}

				$sealed = P2Flux_WC_Crypto::encrypt( $plain );
				unset( $plain );

				if ( null === $sealed ) {
					$stuck++;
					continue;
				}

				$items[ $index ]['cap'] = $sealed;
				$changed                = true;
				$moved++;
			}

			if ( $changed ) {
				$subscription->update_meta_data( P2Flux_WC_Auth_History::META, wp_json_encode( array( 'v' => 1, 'items' => $items ) ) );
				$subscription->save();
			}
		}

		WP_CLI::success( sprintf( 're-encrypted %d, already current %d, could not read %d', $moved, $skipped, $stuck ) );

		if ( $stuck > 0 ) {
			WP_CLI::warning( 'Some authorizations could not be read. Put the previous key in P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS and run this again before removing it.' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'p2flux', 'P2Flux_WC_CLI' );
}
