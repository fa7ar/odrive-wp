<?php
/**
 * Authentication / Token Storage
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages ODrive API token and URL storage.
 *
 * Tokens are obfuscated at rest using a WP-native key derivation
 * so they are never stored as plain text in the options table.
 */
class ODrive_Auth {

	const OPTION_TOKEN = 'odrive_api_token_encrypted';
	const OPTION_URL   = 'odrive_url';

	/**
	 * Derive an encryption key from WP secrets.
	 *
	 * @return string 32-byte key.
	 */
	private function derive_key(): string {
		$salt = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return substr( hash( 'sha256', $salt, true ), 0, 32 );
	}

	/**
	 * Encrypt a plaintext value.
	 *
	 * Uses AES-256-CBC via openssl when available; falls back to XOR obfuscation.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64-encoded ciphertext.
	 */
	private function encrypt( string $plaintext ): string {
		if ( function_exists( 'openssl_encrypt' ) ) {
			$key    = $this->derive_key();
			$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv     = random_bytes( $iv_len );
			$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Fallback: XOR with derived key (still better than plain text).
		$key    = $this->derive_key();
		$output = '';
		$klen   = strlen( $key );
		for ( $i = 0, $plen = strlen( $plaintext ); $i < $plen; $i++ ) {
			$output .= chr( ord( $plaintext[ $i ] ) ^ ord( $key[ $i % $klen ] ) );
		}
		return base64_encode( $output ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a ciphertext value.
	 *
	 * @param string $ciphertext Base64-encoded ciphertext.
	 * @return string|false Plaintext or false on failure.
	 */
	private function decrypt( string $ciphertext ) {
		$data = base64_decode( $ciphertext, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $data ) {
			return false;
		}

		if ( function_exists( 'openssl_decrypt' ) ) {
			$key    = $this->derive_key();
			$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv     = substr( $data, 0, $iv_len );
			$cipher = substr( $data, $iv_len );
			return openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		}

		// XOR fallback.
		$key    = $this->derive_key();
		$output = '';
		$klen   = strlen( $key );
		for ( $i = 0, $dlen = strlen( $data ); $i < $dlen; $i++ ) {
			$output .= chr( ord( $data[ $i ] ) ^ ord( $key[ $i % $klen ] ) );
		}
		return $output;
	}

	/**
	 * Save the API token (encrypted).
	 *
	 * @param string $token Raw API token.
	 * @return bool
	 */
	public function set_token( string $token ): bool {
		$encrypted = $this->encrypt( sanitize_text_field( $token ) );
		return update_option( self::OPTION_TOKEN, $encrypted );
	}

	/**
	 * Retrieve the decrypted API token.
	 *
	 * @return string Empty string if not set.
	 */
	public function get_token(): string {
		$encrypted = get_option( self::OPTION_TOKEN, '' );
		if ( '' === $encrypted ) {
			return '';
		}
		$plain = $this->decrypt( $encrypted );
		return ( false !== $plain ) ? $plain : '';
	}

	/**
	 * Delete the stored token.
	 *
	 * @return bool
	 */
	public function delete_token(): bool {
		return delete_option( self::OPTION_TOKEN );
	}

	/**
	 * Return a masked version of the token for display (shows last 4 chars only).
	 *
	 * @return string e.g. "••••••••••••abcd" or empty string.
	 */
	public function get_masked_token(): string {
		$token = $this->get_token();
		if ( '' === $token ) {
			return '';
		}
		$len = strlen( $token );
		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}
		return str_repeat( '•', $len - 4 ) . substr( $token, -4 );
	}

	/**
	 * Check whether a token has been stored.
	 *
	 * @return bool
	 */
	public function has_token(): bool {
		return '' !== $this->get_token();
	}

	/**
	 * Save the ODrive base URL.
	 *
	 * @param string $url Raw URL.
	 * @return bool
	 */
	public function set_odrive_url( string $url ): bool {
		return update_option( self::OPTION_URL, esc_url_raw( $url ) );
	}

	/**
	 * Retrieve the ODrive base URL.
	 *
	 * @return string
	 */
	public function get_odrive_url(): string {
		return (string) get_option( self::OPTION_URL, '' );
	}

	/**
	 * Delete the stored ODrive URL.
	 *
	 * @return bool
	 */
	public function delete_odrive_url(): bool {
		return delete_option( self::OPTION_URL );
	}
}
