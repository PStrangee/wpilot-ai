<?php
/**
 * Native WordPress SEO Metadata Adapter (fallback when Yoast/Rank Math are inactive).
 *
 * @package WPilotAI\SEO
 */

declare(strict_types=1);

namespace WPilotAI\SEO;

final class NativeSeoAdapter implements SeoAdapterInterface {

	public function get_id(): string {
		return 'native';
	}

	public function is_active(): bool {
		return true;
	}

	public function get_meta_keys(): array {
		return array(
			'title'         => '_wpilot_seo_title',
			'description'   => '_wpilot_seo_description',
			'focus_keyword' => '_wpilot_seo_focus_keyword',
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
