<?php
/**
 * Secure Server-Side Settings & Encrypted API Key Storage.
 *
 * API keys are encrypted at rest using libsodium (XChaCha20-Poly1305) or
 * OpenSSL AES-256-GCM derived from wp_salt('auth') and are never serialized
 * back to browser clients.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

final class Settings {

	public const OPTION_KEY   = 'wpilot_ai_settings';
	public const API_KEY_OPT  = 'wpilot_ai_openai_key_enc';
	private const CIPHER_ALGO = 'aes-256-gcm';

	/**
	 * Server-side allowlisted OpenAI model IDs.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_MODELS = array(
		'gpt-4.1',
		'gpt-4.1-mini',
		'o4-mini',
	);

	/**
	 * @return array<int, string>
	 */
	public function get_allowed_models(): array {
		$models = self::ALLOWED_MODELS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'wpilot_allowed_models', $models );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$clean = array();
				foreach ( $filtered as $item ) {
					if ( is_string( $item ) && '' !== trim( $item ) ) {
						$clean[] = sanitize_text_field( $item );
					}
				}
				if ( ! empty( $clean ) ) {
					return array_values( array_unique( $clean ) );
				}
			}
		}
		return $models;
	}

	public function is_allowed_model( string $model ): bool {
		return in_array( $model, $this->get_allowed_models(), true );
	}

	/**
	 * Validates and returns an allowlisted model ID, or throws a 400 WPilotException.
	 */
	public function assert_allowed_model( string $model ): string {
		$clean = sanitize_text_field( trim( $model ) );
		if ( '' === $clean || ! $this->is_allowed_model( $clean ) ) {
			throw new WPilotException(
				'wpilot_invalid_model',
				sprintf(
					'Unsupported AI model ID. Allowed models: %s.',
					esc_html( implode( ', ', $this->get_allowed_models() ) )
				),
				400
			);
		}
		return $clean;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'               => 'openai',
			'model'                  => 'gpt-4.1',
			'max_requests_per_hour'  => 60,
			'max_tokens_per_request' => 4096,
			'request_timeout'        => 45,
			'require_approval_med'   => true,
			'require_approval_high'  => true,
			'allowed_roles'          => array( 'administrator' ),
			'locale_override'        => 'auto',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = array_merge( self::defaults(), $stored );
		if ( ! is_string( $merged['model'] ) || ! $this->is_allowed_model( $merged['model'] ) ) {
			$merged['model'] = self::defaults()['model'];
		}
		return $merged;
	}

	/**
	 * Public-safe settings payload returned to the React Admin UI (never includes raw API keys).
	 *
	 * @return array<string, mixed>
	 */
	public function public_config(): array {
		$config                   = $this->all();
		$config['allowed_models'] = $this->get_allowed_models();
		$config['has_api_key']    = $this->has_openai_api_key();
		$config['key_source']     = defined( 'WPILOT_OPENAI_API_KEY' ) && '' !== (string) WPILOT_OPENAI_API_KEY
			? 'wp-config.php'
			: ( $config['has_api_key'] ? 'encrypted_option' : 'unconfigured' );
		return $config;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$current = $this->all();

		if ( array_key_exists( 'model', $input ) ) {
			if ( ! is_string( $input['model'] ) ) {
				throw new WPilotException( 'wpilot_invalid_model', 'Model ID must be a string.', 400 );
			}
			$current['model'] = $this->assert_allowed_model( $input['model'] );
		}
		if ( isset( $input['max_requests_per_hour'] ) ) {
			$current['max_requests_per_hour'] = max( 5, min( 500, (int) $input['max_requests_per_hour'] ) );
		}
		if ( isset( $input['max_tokens_per_request'] ) ) {
			$current['max_tokens_per_request'] = max( 512, min( 16384, (int) $input['max_tokens_per_request'] ) );
		}
		if ( isset( $input['request_timeout'] ) ) {
			$current['request_timeout'] = max( 10, min( 120, (int) $input['request_timeout'] ) );
		}
		if ( isset( $input['require_approval_med'] ) ) {
			$current['require_approval_med'] = (bool) $input['require_approval_med'];
		}
		// High-risk actions always require explicit user approval.
		$current['require_approval_high'] = true;

		if ( isset( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] ) ) {
			$roles = array_values( array_unique( array_map( 'sanitize_key', $input['allowed_roles'] ) ) );
			if ( function_exists( 'wp_roles' ) ) {
				$valid_roles = array_keys( wp_roles()->roles );
				if ( ! empty( $valid_roles ) ) {
					$roles = array_values( array_intersect( $roles, $valid_roles ) );
				}
			}
			if ( ! in_array( 'administrator', $roles, true ) ) {
				array_unshift( $roles, 'administrator' );
			}
			$current['allowed_roles'] = $roles;
		}

		update_option( self::OPTION_KEY, $current, false );

		if ( ! empty( $input['clear_openai_api_key'] ) ) {
			delete_option( self::API_KEY_OPT );
		} elseif ( isset( $input['openai_api_key'] ) && is_string( $input['openai_api_key'] ) && '' !== trim( $input['openai_api_key'] ) ) {
			$this->store_openai_api_key( trim( $input['openai_api_key'] ) );
		}

		return $this->public_config();
	}

	public function has_openai_api_key(): bool {
		return '' !== $this->get_openai_api_key();
	}

	public function store_openai_api_key( string $plaintext_key ): bool {
		$trimmed = trim( $plaintext_key );
		if ( '' === $trimmed || preg_match( '/[\r\n\0]/', $trimmed ) === 1 || strlen( $trimmed ) > 512 ) {
			throw new WPilotException( 'wpilot_invalid_api_key', 'Invalid API key format.', 400 );
		}
		$encrypted = $this->encrypt( $trimmed );
		return update_option( self::API_KEY_OPT, $encrypted, false );
	}

	public function get_openai_api_key(): string {
		if ( defined( 'WPILOT_OPENAI_API_KEY' ) && is_string( WPILOT_OPENAI_API_KEY ) && '' !== WPILOT_OPENAI_API_KEY ) {
			return WPILOT_OPENAI_API_KEY;
		}
		$stored = get_option( self::API_KEY_OPT, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		return $this->decrypt( $stored );
	}

	private function derive_key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
		} elseif ( defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ) {
			$salt = (string) AUTH_KEY . ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );
		} else {
			throw new WPilotException( 'wpilot_crypto_unavailable', 'WordPress authentication salt is unavailable for credential encryption.', 500 );
		}
		return hash( 'sha256', $salt . '|wpilot-ai-secret-v1', true );
	}

	private function encrypt( string $plaintext ): string {
		$key = $this->derive_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return 'sodium:' . base64_encode( $nonce . $cipher );
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plaintext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			throw new \RuntimeException( 'Failed to encrypt WPilot AI credentials.' );
		}
		return 'openssl:' . base64_encode( $iv . $tag . $ct );
	}

	private function decrypt( string $payload ): string {
		$key = $this->derive_key();
		if ( str_starts_with( $payload, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $payload, 7 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( str_starts_with( $payload, 'openssl:' ) ) {
			$raw = base64_decode( substr( $payload, 8 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$ct    = substr( $raw, 28 );
			$plain = openssl_decrypt( $ct, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		return '';
	}
}
