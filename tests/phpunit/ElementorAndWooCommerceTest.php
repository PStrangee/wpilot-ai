<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use PHPUnit\Framework\TestCase;
use WPTestState;
use WPilotTestProduct;
use WPilotAI\Backup\BackupManager;
use WPilotAI\Core\WPilotException;
use WPilotAI\Logging\AuditLogger;
use WPilotAI\Tools\ArgumentValidator;
use WPilotAI\Tools\Elementor\UpdateWidgetTool;
use WPilotAI\Tools\WooCommerce\ListProductsTool;
use WPilotAI\Tools\WooCommerce\UpdateProductTool;

final class ElementorAndWooCommerceTest extends TestCase {

	private BackupManager $backups;
	private ArgumentValidator $validator;

	protected function setUp(): void {
		WPTestState::reset();
		$this->backups   = new BackupManager( new AuditLogger() );
		$this->validator = new ArgumentValidator();
	}

	public function test_elementor_update_widget_patches_target_and_preserves_unknown_widgets(): void {
		WPTestState::seed_post( 70, array( 'post_type' => 'page' ) );
		$tree = array(
			array(
				'id'       => 'container-1',
				'elType'   => 'container',
				'elements' => array(
					array(
						'id'         => 'hero-title',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => 'Old Heading',
							'header_size' => 'h1',
						),
					),
					array(
						'id'         => 'third-party-slider',
						'elType'     => 'widget',
						'widgetType' => 'custom_vendor_carousel',
						'settings'   => array(
							'slides' => array( 'a', 'b' ),
						),
					),
				),
			),
		);
		update_post_meta( 70, '_elementor_data', wp_slash( (string) wp_json_encode( $tree ) ) );

		$tool = new UpdateWidgetTool( $this->backups );
		$args = array(
			'page_id'        => 70,
			'widget_id'      => 'hero-title',
			'settings_patch' => array( 'title' => 'Refined Heading' ),
		);
		$this->validator->assert_valid( $tool, $args );

		$res = $tool->execute( $args );
		self::assertTrue( $tool->verify( $args, $res ) );

		$updated = json_decode( (string) get_post_meta( 70, '_elementor_data', true ), true );
		self::assertSame( 'Refined Heading', $updated[0]['elements'][0]['settings']['title'] );
		self::assertSame( 'h1', $updated[0]['elements'][0]['settings']['header_size'] );
		self::assertSame( 'custom_vendor_carousel', $updated[0]['elements'][1]['widgetType'] );
		self::assertSame( array( 'a', 'b' ), $updated[0]['elements'][1]['settings']['slides'] );
	}

	public function test_elementor_update_widget_enforces_per_page_edit_capability(): void {
		WPTestState::seed_post( 71, array( 'post_type' => 'page' ) );
		update_post_meta( 71, '_elementor_data', wp_slash( '[{"id":"w1","elType":"widget","settings":{"title":"A"}}]' ) );
		WPTestState::$current_user_id = 3; // Subscriber without edit_post

		$tool = new UpdateWidgetTool( $this->backups );
		$this->expectException( WPilotException::class );
		$tool->execute(
			array(
				'page_id'        => 71,
				'widget_id'      => 'w1',
				'settings_patch' => array( 'title' => 'Unauthorized' ),
			)
		);
	}

	public function test_woocommerce_list_and_update_product_copy_without_changing_price(): void {
		WPTestState::seed_post( 301, array( 'post_type' => 'product', 'post_title' => 'Fjord Oak Lamp', 'post_status' => 'publish' ) );
		WPTestState::$products[301] = new WPilotTestProduct(
			301,
			'Fjord Oak Lamp',
			'NCS-001',
			'189.00',
			'',
			'<p>Original product description.</p>'
		);

		$list_tool = new ListProductsTool();
		$listed    = $list_tool->execute( array( 'limit' => 10 ) );
		self::assertTrue( $listed['available'] );
		self::assertCount( 1, $listed['products'] );
		self::assertFalse( $listed['products'][0]['has_short_desc'] );

		$update_tool = new UpdateProductTool( $this->backups );
		$args        = array(
			'product_id'        => 301,
			'short_description' => '<p>Handcrafted oak lamp with warm dimmable LED.</p>',
		);
		$this->validator->assert_valid( $update_tool, $args );

		$res = $update_tool->execute( $args );
		self::assertTrue( $update_tool->verify( $args, $res ) );

		$after = wc_get_product( 301 );
		self::assertSame( '189.00', $after->get_price() );
		self::assertSame( '<p>Handcrafted oak lamp with warm dimmable LED.</p>', $after->get_short_description() );
	}

	public function test_woocommerce_update_product_rejects_price_or_unknown_fields(): void {
		$update_tool = new UpdateProductTool( $this->backups );

		$this->expectException( WPilotException::class );
		$this->validator->assert_valid(
			$update_tool,
			array(
				'product_id'    => 301,
				'regular_price' => '0.01',
			)
		);
	}
}
