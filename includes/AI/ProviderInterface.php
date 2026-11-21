<?php
/**
 * Extensible AI Provider Contract.
 *
 * @package WPilotAI\AI
 */

declare(strict_types=1);

namespace WPilotAI\AI;

interface ProviderInterface {

	public function get_id(): string;

	public function get_label(): string;

	/**
	 * @return array<int, array{id: string, label: string, context_window: int}>
	 */
	public function get_models(): array;

	public function is_configured(): bool;

	/**
	 * Sends a structured conversation + tool definitions and returns normalized output.
	 *
	 * @param array<string, mixed> $request
	 * @return array{
	 *   response_id: string,
	 *   text: string,
	 *   tool_calls: array<int, array{id: string, name: string, arguments: array<string, mixed>, invalid_arguments?: bool}>,
	 *   usage: array{input_tokens: int, output_tokens: int, total_tokens: int}
	 * }
	 */
	public function respond( array $request ): array;
}
