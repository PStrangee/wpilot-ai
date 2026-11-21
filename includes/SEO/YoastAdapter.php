<?php
/**
 * Yoast SEO Metadata Adapter.
 *
 * @package WPilotAI\SEO
 */

declare(strict_types=1);

namespace WPilotAI\SEO;

final class YoastAdapter implements SeoAdapterInterface {

	public function get_id(): string {
		return 'yoast';
	}

	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	public function get_meta_keys(): array {
		return array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		);
	}

	public function read_meta( int $post_id ): array {
		$keys = $this->get_meta_keys();
		return array(
			'title'         => (string) get_post_meta( $post_id, $keys['title'], true ),
			'description'   => (string) get_post_meta( $post_id, $keys['description'], true ),
			'focus_keyword' => (string) get_post_meta( $post_id, $keys['focus_keyword'], true ),
		);
	}

	public function write_meta( int $post_id, array $meta ): void {
		$keys = $this->get_meta_keys();
		if ( isset( $meta['title'] ) ) {
			update_post_meta( $post_id, $keys['title'], sanitize_text_field( $meta['title'] ) );
		}
		if ( isset( $meta['description'] ) ) {
			update_post_meta( $post_id, $keys['description'], sanitize_textarea_field( $meta['description'] ) );
		}
		if ( isset( $meta['focus_keyword'] ) ) {
			update_post_meta( $post_id, $keys['focus_keyword'], sanitize_text_field( $meta['focus_keyword'] ) );
		}
	}
}
