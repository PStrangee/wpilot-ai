<?php
/**
 * Strict Tool Argument Schema & Custom Validator.
 *
 * Enforces JSON-Schema type, required-property, enum, bounds, and unknown-property
 * checks before invoking each tool's custom validate() hook.
 *
 * @package WPilotAI\Tools
 */

declare(strict_types=1);

namespace WPilotAI\Tools;

use WPilotAI\Core\WPilotException;

final class ArgumentValidator {

	private const MAX_STRING_BYTES = 100000;
	private const MAX_ARRAY_DEPTH  = 8;

	/**
	 * Validates tool arguments strictly against the tool's JSON schema and custom validate() method.
	 *
	 * @param array<string, mixed> $arguments
	 * @throws WPilotException When schema or custom validation fails.
	 */
	public function assert_valid( ToolInterface $tool, array $arguments ): void {
		$schema = $tool->get_input_schema();
		$errors = $this->validate_object_schema( $schema, $arguments, 0 );

		if ( ! empty( $errors ) ) {
			throw new WPilotException(
				'wpilot_invalid_tool_arguments',
				sprintf( 'Invalid arguments for tool "%s": %s', esc_html( $tool->get_name() ), esc_html( implode( '; ', $errors ) ) ),
				400
			);
		}

		if ( ! $tool->validate( $arguments ) ) {
			throw new WPilotException(
				'wpilot_tool_validation_failed',
				sprintf( 'Tool "%s" rejected the provided arguments.', esc_html( $tool->get_name() ) ),
				400
			);
		}
	}

	/**
	 * @param array<string, mixed> $schema
	 * @param array<string, mixed> $data
	 * @return array<int, string>
	 */
	private function validate_object_schema( array $schema, array $data, int $depth ): array {
		if ( $depth > self::MAX_ARRAY_DEPTH ) {
			return array( 'Maximum nested object depth exceeded.' );
		}

		$errors     = array();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$allow_extra = isset( $schema['additionalProperties'] ) ? (bool) $schema['additionalProperties'] : false;

		foreach ( $required as $req_key ) {
			if ( ! is_string( $req_key ) ) {
				continue;
			}
			if ( ! array_key_exists( $req_key, $data ) || null === $data[ $req_key ] ) {
				$errors[] = sprintf( 'Missing required property "%s".', $req_key );
			}
		}

		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				$errors[] = 'Arguments must use string property names.';
				continue;
			}

			if ( ! array_key_exists( $key, $properties ) ) {
				if ( ! $allow_extra ) {
					$errors[] = sprintf( 'Unexpected property "%s".', $key );
				}
				continue;
			}

			$prop_schema = $properties[ $key ];
			if ( ! is_array( $prop_schema ) ) {
				continue;
			}

			$prop_errors = $this->validate_value( $key, $value, $prop_schema, $depth + 1 );
			foreach ( $prop_errors as $err ) {
				$errors[] = $err;
			}
		}

		return $errors;
	}

	/**
	 * @param mixed                $value
	 * @param array<string, mixed> $schema
	 * @return array<int, string>
	 */
	private function validate_value( string $field, mixed $value, array $schema, int $depth ): array {
		$errors = array();
		$type   = isset( $schema['type'] ) ? (string) $schema['type'] : '';

		if ( 'integer' === $type ) {
			if ( ! is_int( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be an integer.', $field );
				return $errors;
			}
			if ( isset( $schema['minimum'] ) && $value < (int) $schema['minimum'] ) {
				$errors[] = sprintf( 'Property "%s" must be >= %d.', $field, (int) $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $value > (int) $schema['maximum'] ) {
				$errors[] = sprintf( 'Property "%s" must be <= %d.', $field, (int) $schema['maximum'] );
			}
		} elseif ( 'string' === $type ) {
			if ( ! is_string( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be a string.', $field );
				return $errors;
			}
			$max_len = isset( $schema['maxLength'] ) ? (int) $schema['maxLength'] : self::MAX_STRING_BYTES;
			if ( strlen( $value ) > $max_len ) {
				$errors[] = sprintf( 'Property "%s" exceeds maximum length of %d bytes.', $field, $max_len );
			}
			if ( isset( $schema['minLength'] ) && strlen( trim( $value ) ) < (int) $schema['minLength'] ) {
				$errors[] = sprintf( 'Property "%s" is shorter than minimum length %d.', $field, (int) $schema['minLength'] );
			}
		} elseif ( 'boolean' === $type ) {
			if ( ! is_bool( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be a boolean.', $field );
				return $errors;
			}
		} elseif ( 'object' === $type ) {
			if ( ! is_array( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be an object.', $field );
				return $errors;
			}
			if ( ! empty( $value ) && array_is_list( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be an associative object, not a list.', $field );
				return $errors;
			}
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				return $this->validate_object_schema( $schema, $value, $depth );
			}
		} elseif ( 'array' === $type ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				$errors[] = sprintf( 'Property "%s" must be a list array.', $field );
				return $errors;
			}
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = sprintf( 'Property "%s" contains an unsupported enum value.', $field );
		}

		return $errors;
	}
}
