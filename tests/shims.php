<?php
/**
 * Just enough WordPress to run the plugin's logic offline.
 *
 * These tests deliberately do not boot WordPress. The classes worth testing hardest - the money
 * arithmetic, the charge-outcome mapping, the authorization history, the encryption - are the ones
 * that are pure or nearly so, and a test suite that needs a database to prove that 12.99 EUR is
 * 14119565 micro-USDC is a test suite nobody runs.
 *
 * Anything WordPress-shaped that the plugin genuinely depends on is stubbed here, in memory, with
 * the same contract the real function has. The store-shaped tests (HPOS, Blocks, WooCommerce
 * Subscriptions) live in tests/integration.php and run inside a real install.
 *
 * @package P2Flux_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['p2flux_test_options'] = array();

/**
 * Read an option.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['p2flux_test_options'] ) ? $GLOBALS['p2flux_test_options'][ $name ] : $default;
}

/**
 * Create an option, refusing if it exists - the behaviour the lock depends on.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param string $deprecated Unused.
 * @param string $autoload Unused.
 * @return bool
 */
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['p2flux_test_options'] ) ) {
		return false;
	}
	$GLOBALS['p2flux_test_options'][ $name ] = $value;

	return true;
}

/**
 * Write an option.
 *
 * @param string $name     Option name.
 * @param mixed  $value    Value.
 * @param bool   $autoload Unused.
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['p2flux_test_options'][ $name ] = $value;

	return true;
}

/**
 * Delete an option.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
	unset( $GLOBALS['p2flux_test_options'][ $name ] );

	return true;
}

/**
 * JSON encode, WordPress-style.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode( $data ) {
	return json_encode( $data );
}

/**
 * A password-shaped random string.
 *
 * @param int  $length  Length.
 * @param bool $special Unused.
 * @param bool $extra   Unused.
 * @return string
 */
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}

/**
 * Current time, UTC only - which is all this plugin ever asks for.
 *
 * @param string $type Format.
 * @param bool   $gmt  Unused.
 * @return string|int
 */
function current_time( $type, $gmt = 0 ) {
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

/**
 * No object cache in the harness.
 *
 * @param string $key   Key.
 * @param string $group Group.
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ) {
	return true;
}

/**
 * The in-memory stand-in for a WooCommerce data object.
 *
 * Only the surface the plugin actually uses: meta, status, id, and saving. HPOS and the classic
 * post-meta store both present exactly this, which is why the plugin never touches post meta
 * directly and why this shim is honest.
 */
class P2Flux_Test_Object {

	/** @var array<string,mixed> */
	private $meta = array();

	/** @var int */
	private $id;

	/** @var string */
	private $status;

	/** @var int */
	public $saves = 0;

	/**
	 * @param int    $id     Object id.
	 * @param string $status Woo status.
	 */
	public function __construct( $id = 1, $status = 'active' ) {
		$this->id     = $id;
		$this->status = $status;
	}

	/** @return int */
	public function get_id() {
		return $this->id;
	}

	/** @return string */
	public function get_status() {
		return $this->status;
	}

	/**
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( $status ) {
		$this->status = $status;
	}

	/**
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	/**
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * @param string $key Meta key.
	 * @return void
	 */
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	/** @return int */
	public function save() {
		$this->saves++;

		return $this->id;
	}
}
