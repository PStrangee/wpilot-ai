<?php
/**
 * SEO Plugin Adapter Interface (Yoast SEO, Rank Math, Native Fallback).
 *
 * @package WPilotAI\SEO
 */

declare(strict_types=1);

namespace WPilotAI\SEO;

interface SeoAdapterInterface {

	public function get_id(): string;

	public function is_active(): bool;

	/**
	 * Returns the mapping of logical SEO fields to WordPress post_meta keys.
	 *
	 * @return array{title: string, description: string, focus_keyword: string}
	 */
	public function get_meta_keys(): array;

	/**
	 * @return array{title: string, description: string, focus_keyword: string}
	 */
	public function read_meta( int $post_id ): array;

	/**
	 * @param array{title?: string, description?: string, focus_keyword?: string} $meta
	 */
	public function write_meta( int $post_id, array $meta ): void;
}
