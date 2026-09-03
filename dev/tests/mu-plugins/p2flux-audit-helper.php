<?php
/**
 * Audit helper - test harness only, never shipped.
 *
 * Lets the auditor act on a stored authorization without the capability ever leaving the PHP
 * process: load the encrypted record, decrypt in memory, make one API call, drop every reference,
 * return only non-secret evidence (auth id, period index, status, tx hash, balances).
 *
 * Install into wp-content/mu-plugins/ for the audit; remove afterwards.
 */
defined( 'ABSPATH' ) || exit;

/**
 * @param string $ref  'native:<id>' | 'wcs:<id>'.
 * @param string $what 'status' | 'charge'.
 * @return array<string,mixed> Sanitized evidence, never a capability.
 */
function p2flux_audit( $ref, $what ) {
	$subscription = P2Flux_WC_Subscriptions::load( $ref );
	if ( ! $subscription ) {
		return array( 'error' => 'no subscription' );
	}
	$auth = P2Flux_WC_Auth_History::active( $subscription );
	if ( ! $auth ) {
		return array( 'error' => 'no active authorization' );
	}
	$capability = P2Flux_WC_Auth_History::capability( $subscription, $auth['id'] );
	if ( null === $capability ) {
		return array( 'error' => 'capability unavailable' );
	}
	$client = P2Flux_WC_Client::for_environment( $auth['environment'] );
	$out    = array( 'auth_id' => $auth['id'], 'start' => (int) $auth['start'], 'period' => (int) $auth['period'] );
	try {
		if ( 'charge' === $what ) {
			$r = $client->charge( $capability );
			$out += array( 'status' => $r->status, 'period_index' => $r->periodIndex, 'tx_hash' => $r->txHash );
		} else {
			$s = $client->status( $capability );
			$out += array(
				'period_index'        => $s['period_index'],
				'charged_this_period' => $s['charged_this_period'],
				'due'                 => $s['due'],
				'next_period_at'      => $s['next_period_at'],
				'balance_usdc'        => (int) $s['balance_units'] / 1000000,
				'allowance_unlimited' => $s['allowance_unlimited'],
				'allowance_usdc'      => isset( $s['allowance_units'] ) ? (int) $s['allowance_units'] / 1000000 : null,
				'revoked'             => $s['revoked'],
			);
		}
	} catch ( \Exception $e ) {
		$out['error'] = preg_replace( '/p2s2\.[A-Za-z0-9_.-]+/', '[redacted]', $e->getMessage() );
	} finally {
		unset( $capability );
	}

	return $out;
}

/**
 * A controlled page that asks the connected wallet to send exactly the `approve(recurring, 0)`
 * transaction the P2Flux API prepares - the spender comes from the API, never from the page, and
 * nothing else can be sent from here. Admin only; audit only.
 *
 * Open: /?p2flux-audit-page=allowance-zero
 */
add_action( 'template_redirect', static function () {
	if ( ! isset( $_GET['p2flux-audit-page'] ) || 'allowance-zero' !== $_GET['p2flux-audit-page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'admin only' );
	}
	$client = P2Flux_WC_Client::for_environment( P2Flux_WC_Client::TEST );
	try {
		$prepared = $client->prepareAllowanceRevoke();
	} catch ( \Exception $e ) {
		wp_die( esc_html( 'could not prepare: ' . preg_replace( '/p2[a-z0-9]+\.[A-Za-z0-9_.-]+/', '[redacted]', $e->getMessage() ) ) );
	}
	$tx = array( 'to' => $prepared['to'], 'data' => $prepared['data'], 'chainId' => (int) $prepared['chain_id'] );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html><meta charset="utf-8"><title>Audit: set USDC allowance to zero</title>
<body style="font:16px system-ui;max-width:640px;margin:40px auto">
<h1>Set the USDC allowance for the P2Flux recurring contract to zero</h1>
<p>Test aid for the audit. It asks your wallet to send one transaction, prepared by the P2Flux test API: <code>approve(spender, 0)</code> on Base Sepolia USDC. Nothing else.</p>
<p>Spender (from the API): <code id="spender"></code><br>Token: <code><?php echo esc_html( $prepared['to'] ); ?></code></p>
<button id="go" style="font-size:18px;padding:10px 18px">Connect wallet and send approve(…, 0)</button>
<p id="out"></p>
<script>
const tx = <?php echo wp_json_encode( $tx ); ?>;
document.getElementById('spender').textContent = '0x' + tx.data.slice(34, 74);
document.getElementById('go').onclick = async () => {
  const out = document.getElementById('out');
  try {
    if (!window.ethereum) throw new Error('no wallet');
    const [from] = await window.ethereum.request({ method: 'eth_requestAccounts' });
    const chain = await window.ethereum.request({ method: 'eth_chainId' });
    if (parseInt(chain, 16) !== tx.chainId) throw new Error('switch the wallet to Base Sepolia (chain ' + tx.chainId + ')');
    out.textContent = 'Confirm in your wallet…';
    const hash = await window.ethereum.request({ method: 'eth_sendTransaction', params: [{ from, to: tx.to, data: tx.data }] });
    out.innerHTML = 'Sent: <code>' + hash + '</code>';
  } catch (e) { out.textContent = 'Not sent: ' + (e.message || e); }
};
</script></body>
	<?php
	exit;
} );
