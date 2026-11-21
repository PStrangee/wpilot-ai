<?php
/**
 * WooCommerce Update Product Copy Tool (`woocommerce.update_product`).
 *
 * Updates non-financial copy (name, description, short_description) only;
 * pricing and inventory changes are strictly excluded by schema and execution logic.
 *
 * @package WPilotAI\Tools\WooCommerce
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WooCommerce;

use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class UpdateProductTool implements ToolInterface {

	private BackupManager $backups;

	public function __construct( BackupManager $backups ) {
		$this->backups = $backups;
	}

	public function get_name(): string {
		return 'woocommerce.update_product';
	}

	public function get_description(): string {
		return 'Update a WooCommerce product name, description, or short description without altering pricing or inventory.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'product_id' ),
			'properties'           => array(
				'product_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'              => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 300,
				),
				'short_description' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'description'       => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_products';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_MEDIUM;
	}

	public function validate( array $arguments ): bool {
		if ( ! isset( $arguments['product_id'] ) || ! is_int( $arguments['product_id'] ) || $arguments['product_id'] <= 0 ) {
			return false;
		}
		return isset( $arguments['name'] ) || isset( $arguments['short_description'] ) || isset( $arguments['description'] );
	}

	public function execute( array $arguments ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			throw new WPilotException( 'wpilot_woocommerce_inactive', 'WooCommerce is not active.', 400 );
		}

		$product_id = (int) $arguments['product_id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new WPilotException( 'wpilot_product_not_found', 'WooCommerce product not found.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot edit this WooCommerce product.', 403 );
		}

		$post = get_post( $product_id );
		$previous = array(
			'post_type'    => $post ? $post->post_type : 'product',
			'post_status'  => $post ? $post->post_status : ( method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish' ),
			'post_name'    => $post ? $post->post_name : '',
			'post_title'   => (string) $product->get_name(),
			'post_content' => (string) $product->get_description(),
			'post_excerpt' => (string) $product->get_short_description(),
		);

		$backup_id = $this->backups->create_snapshot( 'post', (string) $product_id, $this->get_name(), $previous, $arguments );

		if ( isset( $arguments['name'] ) ) {
			$product->set_name( sanitize_text_field( (string) $arguments['name'] ) );
		}
		if ( isset( $arguments['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $arguments['short_description'] ) );
		}
		if ( isset( $arguments['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $arguments['description'] ) );
		}

		$saved_id = $product->save();
		if ( empty( $saved_id ) ) {
			throw new WPilotException( 'wpilot_product_save_failed', 'Failed to persist WooCommerce product changes.', 500 );
		}

		return array(
			'product_id' => $product_id,
			'backup_id'  => $backup_id,
			'updated'    => true,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		if ( empty( $result['updated'] ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( (int) $arguments['product_id'] );
		if ( ! $product ) {
			return false;
		}
		if ( isset( $arguments['name'] ) && (string) $product->get_name() !== sanitize_text_field( (string) $arguments['name'] ) ) {
			return false;
		}
		if ( isset( $arguments['short_description'] ) && (string) $product->get_short_description() !== wp_kses_post( (string) $arguments['short_description'] ) ) {
			return false;
		}
		if ( isset( $arguments['description'] ) && (string) $product->get_description() !== wp_kses_post( (string) $arguments['description'] ) ) {
			return false;
		}
		return true;
	}
}
