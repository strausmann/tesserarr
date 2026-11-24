<?php

namespace App\Concurrency;

/**
 * A named point in a critical section where a test can hold several
 * processes and release them at once, to force a race that would otherwise
 * be a matter of luck.
 *
 * Production binds NullConcurrencyBarrier, which does nothing.
 */
interface ConcurrencyBarrier
{
    /**
     * The closed list of barrier points. A new point is a plan change, not
     * something a task adds on the side.
     */
    public const POINTS = [
        'invitation.consume',
        'invitation.reserve',
        'operators.check',
        'email.claim',
        'identity.create',
        'login-methods.check',
    ];

    public function reached(string $point): void;
}
