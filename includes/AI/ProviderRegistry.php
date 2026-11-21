<?php
/**
 * AI Provider Registry for multi-provider extensibility.
 *
 * @package WPilotAI\AI
 */

declare(strict_types=1);

namespace WPilotAI\AI;

final class ProviderRegistry {

	/** @var array<string, ProviderInterface> */
	private array $providers = array();

	public function register( ProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	public function get( string $id ): ProviderInterface {
		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown AI provider: %s', esc_html( $id ) ) );
		}
		return $this->providers[ $id ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_providers(): array {
		$list = array();
		foreach ( $this->providers as $provider ) {
			$list[] = array(
				'id'         => $provider->get_id(),
				'label'      => $provider->get_label(),
				'configured' => $provider->is_configured(),
				'models'     => $provider->get_models(),
			);
		}
		return $list;
	}
}
