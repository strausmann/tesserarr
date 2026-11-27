<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Support\TerminalText;
use App\Tokens\TokenRevoker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\PersonalAccessToken;

final class TokenRevoke extends Command
{
    protected $signature = 'tesserarr:token:revoke
        {id : the id of the token (the number before the bar, shown in the token list)}';

    protected $description = 'Revoke an API token: it stops working at once';

    public function handle(TokenRevoker $revoker): int
    {
        $id = (string) $this->argument('id');
        $token = preg_match('/^[1-9]\d{0,18}$/', $id) === 1 ? PersonalAccessToken::query()->find((int) $id) : null;

        if ($token === null) {
            $this->error(sprintf('No token with the id "%s".', TerminalText::safe($id)));

            return self::FAILURE;
        }

        try {
            $revoker->revoke($token, Actor::console());
        } catch (ModelNotFoundException) {
            $this->error(sprintf('No token with the id "%s".', TerminalText::safe($id)));

            return self::FAILURE;
        }

        $this->info(sprintf('Token %d "%s" revoked.', $token->getKey(), TerminalText::safe((string) $token->getAttribute('name'))));

        return self::SUCCESS;
    }
}
