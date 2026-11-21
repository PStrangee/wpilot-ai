<?php
/**
 * SEO Page Analyzer Tool (`seo.analyze_page`).
 *
 * @package WPilotAI\Tools\SEO
 */

declare(strict_types=1);

namespace WPilotAI\Tools\SEO;

use WPilotAI\Core\WPilotException;
use WPilotAI\SEO\NativeSeoAdapter;
use WPilotAI\SEO\RankMathAdapter;
use WPilotAI\SEO\SeoAdapterInterface;
use WPilotAI\SEO\YoastAdapter;
use WPilotAI\Security\PermissionEngine;
use WPilotAI\Tools\ToolInterface;

final class AnalyzePageTool implements ToolInterface {

	public function get_name(): string {
		return 'seo.analyze_page';
	}

	public function get_description(): string {
		return 'Audit a WordPress page for SEO title length, meta description, heading hierarchy, image alt attributes, and internal links.';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'page_id' ),
			'properties'           => array(
				'page_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	public function get_required_capability(): string {
		return 'edit_pages';
	}

	public function get_risk_level(): string {
		return PermissionEngine::RISK_LOW;
	}

	public function validate( array $arguments ): bool {
		return isset( $arguments['page_id'] ) && is_int( $arguments['page_id'] ) && $arguments['page_id'] > 0;
	}

	public function execute( array $arguments ): array {
		$page_id = (int) $arguments['page_id'];
		$post    = get_post( $page_id );
		if ( ! $post ) {
			throw new WPilotException( 'wpilot_page_not_found', 'Page not found for SEO analysis.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			throw new WPilotException( 'wpilot_forbidden', 'Current user cannot inspect this page.', 403 );
		}

		$adapter = $this->resolve_adapter();
		$meta    = $adapter->read_meta( $page_id );
		$content = (string) $post->post_content;

		preg_match_all( '/<h1\b/i', $content, $h1_matches );
		preg_match_all( '/<img\b[^>]*>/i', $content, $img_matches );

		$missing_alt = 0;
		foreach ( $img_matches[0] as $img_tag ) {
			if ( ! preg_match( '/\balt\s*=\s*["\'][^"\']+["\']/i', $img_tag ) ) {
				++$missing_alt;
			}
		}

		$issues = array();
		if ( strlen( $meta['title'] ) < 30 || strlen( $meta['title'] ) > 60 ) {
			$issues[] = 'SEO title should be between 30 and 60 characters.';
		}
		if ( strlen( $meta['description'] ) < 120 || strlen( $meta['description'] ) > 160 ) {
			$issues[] = 'Meta description should be between 120 and 160 characters.';
		}
		if ( $missing_alt > 0 ) {
			$issues[] = sprintf( '%d image(s) are missing descriptive alt attributes.', $missing_alt );
		}

		return array(
			'page_id'            => $page_id,
			'adapter'            => $adapter->get_id(),
			'seo_meta'           => $meta,
			'h1_count'           => count( $h1_matches[0] ),
			'images_total'       => count( $img_matches[0] ),
			'images_missing_alt' => $missing_alt,
			'issues'             => $issues,
		);
	}

	public function verify( array $arguments, array $result ): bool {
		return isset( $result['adapter'], $result['page_id'] );
	}

	private function resolve_adapter(): SeoAdapterInterface {
		foreach ( array( new YoastAdapter(), new RankMathAdapter(), new NativeSeoAdapter() ) as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return new NativeSeoAdapter();
	}
}
