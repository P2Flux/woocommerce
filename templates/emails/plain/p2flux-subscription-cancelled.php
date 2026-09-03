<?php
/**
 * Subscription cancelled email (plain).
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
printf(
	/* translators: %s: product name. */
	esc_html__( 'Your subscription to %s has been cancelled. No future P2Flux charges will be initiated by this store.', 'p2flux-for-woocommerce' ),
	esc_html( (string) $subscription->get( 'product_name' ) )
);
echo "\n\n";
if ( $auth_remains ) {
	echo esc_html__( 'The authorization in your wallet is still in place. You can revoke it from My Account at any time; this store will not use it again.', 'p2flux-for-woocommerce' ) . "\n\n";
}
if ( '' !== $account_url ) {
	echo esc_html__( 'My Account:', 'p2flux-for-woocommerce' ) . ' ' . esc_url( $account_url ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
