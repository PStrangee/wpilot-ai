<?php
/**
 * Structured Plugin Exception carrying safe user-facing messages and HTTP status codes.
 *
 * Internal diagnostic details are never sent directly to REST clients.
 *
 * @package WPilotAI\Core
 */

declare(strict_types=1);

namespace WPilotAI\Core;

final class WPilotException extends \RuntimeException {

	private string $error_code;
	private int $http_status;

	public function __construct(
		string $error_code,
		string $public_message,
		int $http_status = 400,
		?\Throwable $previous = null
	) {
		parent::__construct( $public_message, $http_status, $previous );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
	}

	public function get_error_code(): string {
		return $this->error_code;
	}

	public function get_http_status(): int {
		return $this->http_status;
	}
}
