<?php
/**
 * Strict Task Execution State Machine.
 *
 * @package WPilotAI\Agent
 */

declare(strict_types=1);

namespace WPilotAI\Agent;

final class TaskStateMachine {

	public const STATE_PENDING           = 'pending';
	public const STATE_PLANNING          = 'planning';
	public const STATE_AWAITING_APPROVAL = 'awaiting_approval';
	public const STATE_EXECUTING         = 'executing';
	public const STATE_VERIFYING         = 'verifying';
	public const STATE_COMPLETED         = 'completed';
	public const STATE_FAILED            = 'failed';
	public const STATE_CANCELLED         = 'cancelled';
	public const STATE_ROLLED_BACK       = 'rolled_back';

	/** @var array<string, array<int, string>> */
	private const TRANSITIONS = array(
		self::STATE_PENDING           => array( self::STATE_PLANNING, self::STATE_CANCELLED ),
		self::STATE_PLANNING          => array( self::STATE_AWAITING_APPROVAL, self::STATE_EXECUTING, self::STATE_FAILED, self::STATE_CANCELLED ),
		self::STATE_AWAITING_APPROVAL => array( self::STATE_EXECUTING, self::STATE_CANCELLED ),
		self::STATE_EXECUTING         => array( self::STATE_VERIFYING, self::STATE_FAILED ),
		self::STATE_VERIFYING         => array( self::STATE_COMPLETED, self::STATE_FAILED ),
		self::STATE_COMPLETED         => array( self::STATE_ROLLED_BACK ),
		self::STATE_FAILED            => array( self::STATE_ROLLED_BACK ),
		self::STATE_CANCELLED         => array(),
		self::STATE_ROLLED_BACK       => array(),
	);

	public function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	public function transition( string $from, string $to ): string {
		if ( ! $this->can_transition( $from, $to ) ) {
			throw new \DomainException( sprintf( 'Invalid task state transition from "%s" to "%s".', esc_html( $from ), esc_html( $to ) ) );
		}
		return $to;
	}
}
