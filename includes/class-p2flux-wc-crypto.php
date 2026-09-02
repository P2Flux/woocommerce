<?php
/**
 * Encrypting the one secret this plugin stores.
 *
 * A `p2s2` capability is bearer authorization: whoever holds it can ask P2Flux to collect the
 * customer's next period. It can only ever pay the recipient the customer signed for, so a leak is
 * not a theft primitive - but it is still the customer's standing permission, and it belongs
 * encrypted, out of logs, and out of anything a browser can read.
 *
 * The honest limit of this, stated plainly because the readme states it too: with the key in the
 * options table, an attacker who reads the whole database reads the capabilities. What that buys is
 * everything short of that - a leaked order export, a stolen table dump, a plugin that logs meta,
 * a backup on someone's laptop. Set P2FLUX_WC_ENCRYPTION_KEY in wp-config.php and even a full
 * database read comes up empty.
 *
 * @package P2Flux_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption for stored capabilities, with a key ring.
 */
class P2Flux_WC_Crypto {

	/** Ciphertext format marker. Bumped only if the construction itself changes. */
	const PREFIX = 'p2fwc1';

	/** Where the fallback key lives when wp-config.php does not carry one. */
	const KEY_OPTION = 'p2flux_wc_key';

	/**
	 * Every key this site can decrypt with, newest first.
	 *
	 * The first is what new writes use. The rest exist so a rotation is survivable: change the
	 * constant, keep the old value in P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS, and every stored
	 * capability still opens while `wp p2flux rekey` moves them across. Without that second slot a
	 * merchant who changes the key has silently made every active subscription uncollectable.
	 *
	 * @return array<string,string> Key id => raw 32-byte key.
	 */
	public static function keyring() {
		$keys = array();

		foreach ( array( 'P2FLUX_WC_ENCRYPTION_KEY', 'P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}
			$raw = self::decode_key( (string) constant( $constant ) );
			if ( null !== $raw ) {
				$keys[ self::key_id( $raw ) ] = $raw;
			}
		}

		$stored = self::stored_key();
		if ( null !== $stored ) {
			$id = self::key_id( $stored );
			if ( ! isset( $keys[ $id ] ) ) {
				$keys[ $id ] = $stored;
			}
		}

		return $keys;
	}

	/**
	 * The key new ciphertext is written with.
	 *
	 * @return string|null Raw key, or null when this site cannot encrypt at all.
	 */
	public static function current_key() {
		$keys = self::keyring();
		if ( empty( $keys ) ) {
			return null;
		}

		return reset( $keys );
	}

	/**
	 * Short, non-secret identifier for a key, stamped into the ciphertext.
	 *
	 * It is what makes rotation legible: a stored value announces which key opens it, so the admin
	 * screen can say how many capabilities are still on the old one instead of finding out when a
	 * renewal fails.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function key_id( $key ) {
		return substr( hash( 'sha256', $key ), 0, 8 );
	}

	/**
	 * Encrypt a capability for storage.
	 *
	 * @param string $plaintext The capability.
	 * @return string|null Ciphertext, or null when there is no key (the caller must not store it).
	 */
	public static function encrypt( $plaintext ) {
		$key = self::current_key();
		if ( null === $key || '' === $plaintext ) {
			return null;
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sealed = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		return self::PREFIX . '.' . self::key_id( $key ) . '.' . base64_encode( $nonce . $sealed );
	}

	/**
	 * Decrypt a stored capability.
	 *
	 * Fails closed, always. A wrong key, a truncated value, a site restored without its wp-config
	 * constant: every one of them returns null, and the renewal path turns that into "cannot
	 * collect, tell the merchant" rather than into a charge attempt with a broken reference.
	 *
	 * @param string $ciphertext Stored value.
	 * @return string|null
	 */
	public static function decrypt( $ciphertext ) {
		$parts = explode( '.', (string) $ciphertext, 3 );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}

		$keys = self::keyring();
		$raw  = base64_decode( $parts[2], true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sealed = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		/*
		 * The stamped id first, because that is the ordinary case and it is one operation. Every
		 * other key is still tried afterwards: a site that lost track of which key wrote what should
		 * recover if any key it has can open the value, rather than refusing on a bookkeeping detail.
		 */
		$ordered = array();
		if ( isset( $keys[ $parts[1] ] ) ) {
			$ordered[] = $keys[ $parts[1] ];
		}
		foreach ( $keys as $key ) {
			$ordered[] = $key;
		}

		foreach ( $ordered as $key ) {
			$plaintext = sodium_crypto_secretbox_open( $sealed, $nonce, $key );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return null;
	}

	/**
	 * Which key id a stored value was written with, without opening it.
	 *
	 * @param string $ciphertext Stored value.
	 * @return string|null
	 */
	public static function key_id_of( $ciphertext ) {
		$parts = explode( '.', (string) $ciphertext, 3 );

		return ( 3 === count( $parts ) && self::PREFIX === $parts[0] ) ? $parts[1] : null;
	}

	/**
	 * Is this value already written with the key new writes use?
	 *
	 * @param string $ciphertext Stored value.
	 * @return bool
	 */
	public static function is_current( $ciphertext ) {
		$key = self::current_key();

		return null !== $key && self::key_id_of( $ciphertext ) === self::key_id( $key );
	}

	/**
	 * The database fallback key, generated once on first use.
	 *
	 * @return string|null
	 */
	private static function stored_key() {
		$stored = get_option( self::KEY_OPTION );
		if ( is_string( $stored ) && '' !== $stored ) {
			return self::decode_key( $stored );
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_keygen' ) ) {
			return null;
		}

		$key = sodium_crypto_secretbox_keygen();
		// Never autoloaded: this is read on the renewal path, not on every page of the site.
		add_option( self::KEY_OPTION, base64_encode( $key ), '', 'no' );

		// Re-read rather than trusting the write: a concurrent request may have won the race, and
		// then ITS key is the one already stamped into whatever was just stored.
		$saved = get_option( self::KEY_OPTION );

		return is_string( $saved ) ? self::decode_key( $saved ) : $key;
	}

	/**
	 * Accept a key as base64 or as 64 hex characters, and refuse anything of the wrong length.
	 *
	 * @param string $value Configured value.
	 * @return string|null Raw 32 bytes.
	 */
	private static function decode_key( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
			return hex2bin( $value );
		}

		$raw = base64_decode( $value, true );

		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : null;
	}
}
