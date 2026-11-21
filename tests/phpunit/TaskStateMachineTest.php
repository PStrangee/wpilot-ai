<?php
declare(strict_types=1);

namespace WPilotAI\Tests;

use DomainException;
use PHPUnit\Framework\TestCase;
use WPilotAI\Agent\TaskStateMachine;

final class TaskStateMachineTest extends TestCase {

	public function test_valid_approval_lifecycle(): void {
		$sm    = new TaskStateMachine();
		$state = TaskStateMachine::STATE_PENDING;
		$state = $sm->transition( $state, TaskStateMachine::STATE_PLANNING );
		$state = $sm->transition( $state, TaskStateMachine::STATE_AWAITING_APPROVAL );
		$state = $sm->transition( $state, TaskStateMachine::STATE_EXECUTING );
		$state = $sm->transition( $state, TaskStateMachine::STATE_VERIFYING );
		$state = $sm->transition( $state, TaskStateMachine::STATE_COMPLETED );
		$state = $sm->transition( $state, TaskStateMachine::STATE_ROLLED_BACK );

		self::assertSame( TaskStateMachine::STATE_ROLLED_BACK, $state );
	}

	public function test_blocks_skipping_verification(): void {
		$sm = new TaskStateMachine();
		$this->expectException( DomainException::class );
		$sm->transition( TaskStateMachine::STATE_EXECUTING, TaskStateMachine::STATE_COMPLETED );
	}
}
