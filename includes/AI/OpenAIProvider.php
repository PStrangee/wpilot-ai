<?php
/**
 * OpenAI Responses API Provider Implementation.
 *
 * Uses the official OpenAI Responses API (`POST /v1/responses`) with structured
 * function tool calling, model allowlist enforcement, timeout management, retry
 * with exponential backoff, and server-side encrypted API key resolution.
 *
 * Note: Requires OpenAI API billing credits (separate from ChatGPT subscriptions).
 *
 * @package WPilotAI\AI
 */

declare(strict_types=1);

namespace WPilotAI\AI;

use WPilotAI\Core\Settings;
use WPilotAI\Core\WPilotException;

final class OpenAIProvider implements ProviderInterface {

	private const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';
	private const MAX_RETRIES        = 2;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function get_id(): string {
		return 'openai';
	}

	public function get_label(): string {
		return 'OpenAI (Responses API)';
	}

	public function get_models(): array {
		return array(
			array(
				'id'             => 'gpt-4.1',
				'label'          => 'GPT-4.1 (Flagship Tool Calling)',
				'context_window' => 1047576,
			),
			array(
				'id'             => 'gpt-4.1-mini',
				'label'          => 'GPT-4.1 Mini (Fast & Balanced)',
				'context_window' => 1047576,
			),
			array(
				'id'             => 'o4-mini',
				'label'          => 'o4-mini (Reasoning & Code Audits)',
				'context_window' => 200000,
			),
		);
	}

	public function is_configured(): bool {
		return $this->settings->has_openai_api_key();
	}

	/**
	 * @param array<string, mixed> $request
	 */
	public function respond( array $request ): array {
		$api_key = $this->settings->get_openai_api_key();
		if ( '' === $api_key ) {
			throw new WPilotException(
				'wpilot_api_key_missing',
				'OpenAI API key is not configured on the server.',
				503
			);
		}

		$config  = $this->settings->all();
		$raw_model = isset( $request['model'] ) ? (string) $request['model'] : (string) $config['model'];
		$model   = $this->settings->assert_allowed_model( $raw_model );
		$timeout = (int) $config['request_timeout'];

		$payload = array(
			'model'             => $model,
			'instructions'      => (string) ( $request['system_instruction'] ?? '' ),
			'input'             => $request['input'] ?? array(),
			'max_output_tokens' => (int) $config['max_tokens_per_request'],
			'store'             => false,
		);

		if ( ! empty( $request['tools'] ) && is_array( $request['tools'] ) ) {
			$payload['tools'] = $request['tools'];
		}

		if ( ! empty( $request['previous_response_id'] ) ) {
			$payload['previous_response_id'] = (string) $request['previous_response_id'];
		}

		$response = $this->post_with_retry( $api_key, $payload, $timeout );
		return $this->normalize_response( $response );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function post_with_retry( string $api_key, array $payload, int $timeout ): array {
		$attempt   = 0;
		$last_code = 0;

		while ( $attempt <= self::MAX_RETRIES ) {
			$res = wp_remote_post(
				self::RESPONSES_ENDPOINT,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'User-Agent'    => 'WPilotAI/' . ( defined( 'WPILOT_AI_VERSION' ) ? WPILOT_AI_VERSION : '1.0.0' ),
					),
					'body'    => wp_json_encode( $payload ),
				)
			);

			if ( ! is_wp_error( $res ) ) {
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = (string) wp_remote_retrieve_body( $res );
				$json = json_decode( $body, true );
				$last_code = $code;

				if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
					return $json;
				}

				if ( 401 === $code || 403 === $code ) {
					throw new WPilotException(
						'wpilot_provider_auth_failed',
						'AI provider authentication failed. Verify the configured server-side API key.',
						502
					);
				}

				if ( 429 !== $code && $code < 500 ) {
					throw new WPilotException(
						'wpilot_provider_rejected',
						sprintf( 'AI provider rejected the request (HTTP %d).', absint( $code ) ),
						502
					);
				}
			}

			++$attempt;
			if ( $attempt <= self::MAX_RETRIES ) {
				usleep( $attempt * 400000 );
			}
		}

		$status_note = $last_code > 0 ? sprintf( ' (HTTP %d)', absint( $last_code ) ) : '';
		throw new WPilotException(
			'wpilot_provider_unavailable',
			'AI provider request failed after retries' . esc_html( $status_note ) . '. Please try again.',
			503
		);
	}

	/**
	 * Normalizes Responses API output items into text + structured function calls.
	 * Flags malformed JSON tool arguments explicitly so callers never execute with empty defaults.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{
	 *   response_id: string,
	 *   text: string,
	 *   tool_calls: array<int, array{id: string, name: string, arguments: array<string, mixed>, invalid_arguments?: bool}>,
	 *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int}
	 * }
	 */
	private function normalize_response( array $raw ): array {
		$text_parts = array();
		$tool_calls = array();

		$output = isset( $raw['output'] ) && is_array( $raw['output'] ) ? $raw['output'] : array();
		foreach ( $output as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
				continue;
			}
			if ( 'message' === $item['type'] && ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
				foreach ( $item['content'] as $chunk ) {
					if ( is_array( $chunk ) && 'output_text' === ( $chunk['type'] ?? '' ) && isset( $chunk['text'] ) ) {
						$text_parts[] = (string) $chunk['text'];
					}
				}
			} elseif ( 'function_call' === $item['type'] ) {
				$raw_args = isset( $item['arguments'] ) ? (string) $item['arguments'] : '{}';
				$decoded_args = json_decode( $raw_args, true );
				$invalid_json = JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded_args );

				$call = array(
					'id'        => (string) ( $item['call_id'] ?? $item['id'] ?? '' ),
					'name'      => (string) ( $item['name'] ?? '' ),
					'arguments' => $invalid_json ? array() : $decoded_args,
				);
				if ( $invalid_json ) {
					$call['invalid_arguments'] = true;
				}
				$tool_calls[] = $call;
			}
		}

		$usage = isset( $raw['usage'] ) && is_array( $raw['usage'] ) ? $raw['usage'] : array();

		return array(
			'response_id' => (string) ( $raw['id'] ?? '' ),
			'text'        => trim( implode( "\n", $text_parts ) ),
			'tool_calls'  => $tool_calls,
			'usage'       => array(
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				'total_tokens'  => (int) ( $usage['total_tokens'] ?? 0 ),
			),
		);
	}
}
