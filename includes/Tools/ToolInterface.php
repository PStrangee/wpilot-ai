<?php
/**
 * Structured Tool Contract for WPilot AI Agent.
 *
 * @package WPilotAI\Tools
 */

declare(strict_types=1);

namespace WPilotAI\Tools;

interface ToolInterface {

	public function get_name(): string;

	public function get_description(): string;

	/**
	 * JSON Schema object describing accepted input parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array;

	public function get_required_capability(): string;

	/**
	 * Must return one of PermissionEngine::RISK_LOW | RISK_MEDIUM | RISK_HIGH.
	 */
	public function get_risk_level(): string;

	/**
	 * @param array<string, mixed> $arguments
	 */
	public function validate( array $arguments ): bool;

	/**
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	public function execute( array $arguments ): array;

	/**
	 * Post-execution verification procedure confirming real persisted changes.
	 *
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	public function verify( array $arguments, array $result ): bool;
}
