<?php
/**
 * Immutable Audit Logger for all WPilot AI tool executions and approvals.
 *
 * @package WPilotAI\Logging
 */

declare(strict_types=1);

namespace WPilotAI\Logging;

final class AuditLogger {

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function log(
		int $user_id,
		string $action,
		string $risk_level,
		string $status,
		string $summary,
		array $metadata = array()
	): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_audit_logs';

		$safe_summary  = self::redact_secrets( sanitize_text_field( $summary ) );
		$safe_metadata = self::redact_array( $metadata );

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'action'     => sanitize_text_field( $action ),
				'risk_level' => sanitize_key( $risk_level ),
				'status'     => sanitize_key( $status ),
				'summary'    => $safe_summary,
				'metadata'   => wp_json_encode( $safe_metadata ),
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Redacts API keys and bearer tokens from free-form log strings.
	 */
	public static function redact_secrets( string $text ): string {
		$redacted = preg_replace( '/\b(sk-[A-Za-z0-9_\-]{8,})\b/', '[REDACTED_API_KEY]', $text );
		$text     = is_string( $redacted ) ? $redacted : $text;
		$redacted = preg_replace( '/\b(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1[REDACTED]', $text );
		return is_string( $redacted ) ? $redacted : $text;
	}

	/**
	 * Recursively scrubs sensitive fields from structured log metadata.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function redact_array( array $data ): array {
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/api[_-]?key|secret|token|password|authorization/i', $key ) === 1 ) {
				$clean[ $key ] = '[REDACTED]';
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact_array( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = self::redact_secrets( $value );
			} else {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Returns recent audit logs. Administrators may view all logs; other users are scoped to their own entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50, ?int $only_user_id = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpilot_audit_logs';
		$limit = max( 1, min( 200, $limit ) );

		if ( null !== $only_user_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
					$table,
					$only_user_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}
}
