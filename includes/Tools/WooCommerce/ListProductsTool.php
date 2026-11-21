<?php
/**
 * WooCommerce List Products Tool (`woocommerce.list_products`).
 *
 * @package WPilotAI\Tools\WooCommerce
 */

declare(strict_types=1);

namespace WPilotAI\Tools\WooCommerce;

use WPilotAI\Core\WPilotException;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class ListProductsTool implements ToolInterface {

	public function get_name(): string {
		return 'woocommerce.list_products';
	}

	public function get_description(): string {
		return 'List WooCommerce products with SKU, pricing, stock status, and completeness flags (read-only).';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_products';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return ! isset( $arguments['limit'] ) || ( is_int( $arguments['limit'] ) && $arguments['limit'] >= 1 && $arguments['limit'] <= 50 );
	}

	public function execute( array $arguments ): array {
		if ( ! current_user_can( 'edit_products' ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user lacks permission to inspect WooCommerce products.', 403 );
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'available' => false,
				'message'   => 'WooCommerce is not active on this website.',
			);
		}

		$limit    = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 20;
		$products = wc_get_products( array( 'limit' => $limit ) );
		$items    = array();

		foreach ( $products as $product ) {
			if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
				continue;
			}
			$items[] = array(
				'id'               => $product->get_id(),
				'name'             => $product->get_name(),
				'sku'              => $product->get_sku(),
				'price'            => $product->get_price(),
				'stock_status'     => $product->get_stock_status(),
				'has_short_desc'   => '' !== trim( (string) $product->get_short_description() ),
				'has_featured_img' => (int) $product->get_image_id() > 0,
			);
		}

		return array(
			'available' => true,
			'count'     => count( $items ),
			'products'  => $items,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['available'] );
	}
}
