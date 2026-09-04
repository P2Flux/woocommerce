<?php
/**
 * Action required email (plain).
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $reason_text ) . "\n\n";
printf(
	/* translators: 1: product name, 2: amount, 3: interval. */
	esc_html__( 'Subscription: %1$s — %2$s USDC per %3$s.', 'p2flux-for-woocommerce' ),
	esc_html( (string) $subscription->get( 'product_name' ) ),
	esc_html( P2Flux_WC_Money::display( (int) $subscription->get( 'amount_units' ) ) ),
	esc_html( $subscription->interval_label() )
);
echo "\n\n";
if ( '' !== $account_url ) {
	echo esc_html__( 'Manage your subscription:', 'p2flux-for-woocommerce' ) . ' ' . esc_url( $account_url ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hook.
