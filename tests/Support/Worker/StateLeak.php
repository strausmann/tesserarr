<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Worker;

use Closure;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The check behind assertNoStateLeakBetweenUsers() (tests/Pest.php).
 *
 * Two runs, each through its own worker: A, then B, then B's request without
 * a user; and the same with B first. In each run:
 *
 *   1. the first user's response shows the first user's secrets; otherwise
 *      the pair proves nothing, because there was nothing to leak;
 *   2. the second user's response shows none of the first user's secrets,
 *      and does show its own;
 *   3. the anonymous response shows none of either user's secrets. It catches
 *      a leak the second user's own data would cover.
 */
final class StateLeak
{
    /**
     * @param  Closure(): UserRequest  $requestAsA
     * @param  Closure(): UserRequest  $requestAsB
     * @param  (Closure(Application): void)|null  $boot
     */
    public static function assertNone(Closure $requestAsA, Closure $requestAsB, ?Closure $boot = null): void
    {
        foreach ([[$requestAsA, $requestAsB], [$requestAsB, $requestAsA]] as [$firstRequest, $secondRequest]) {
            $first = $firstRequest();
            $second = $secondRequest();
            $guest = $second->anonymous();

            [$firstResponse, $secondResponse, $guestResponse] = OneWorker::run([$first, $second, $guest], $boot);

            self::assertShows($first, $firstResponse);
            self::assertHidden($first, $second, $secondResponse);
            self::assertShows($second, $secondResponse);
            self::assertHidden($first, $guest, $guestResponse);
            self::assertHidden($second, $guest, $guestResponse);
        }
    }

    private static function assertShows(UserRequest $request, Response $response): void
    {
        Assert::assertNotSame([], $request->secrets, sprintf('The request as %s names no secrets to look for.', $request->label()));

        foreach ($request->secrets as $secret) {
            Assert::assertStringContainsString($secret, (string) $response->getContent(), sprintf(
                'The request as %1$s does not show %1$s\'s own "%2$s" (status %3$d), so it cannot show a leak either.',
                $request->label(),
                $secret,
                $response->getStatusCode(),
            ));
        }
    }

    private static function assertHidden(UserRequest $owner, UserRequest $viewer, Response $response): void
    {
        foreach ($owner->secrets as $secret) {
            Assert::assertStringNotContainsString($secret, (string) $response->getContent(), sprintf(
                'The request as %s shows data of %s ("%s") from an earlier request of the same worker.',
                $viewer->label(),
                $owner->label(),
                $secret,
            ));
        }
    }
}
