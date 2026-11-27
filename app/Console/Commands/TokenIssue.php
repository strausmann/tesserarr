<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\TokenPreset;
use App\Console\Commands\Concerns\FindsAccounts;
use App\Console\Support\TerminalText;
use App\Tokens\InvalidTokenRequest;
use App\Tokens\TokenIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class TokenIssue extends Command
{
    use FindsAccounts;

    protected $signature = 'tesserarr:token:issue
        {username : the owner of the token, by username (never an e-mail address)}
        {--name= : what the token is for, shown in the token list (1 to 100 characters)}
        {--permission=* : a permission of the catalogue the token carries; repeat for several}
        {--preset= : a ready-made selection instead of --permission: arr (catalog.browse) or invitations (invitations.create)}
        {--expires=90 : days until the token expires, or "never"}';

    protected $description = 'Issue an API token for an account, capped at what the account holds; the token is printed once';

    public function handle(TokenIssuer $issuer): int
    {
        $owner = $this->findAccount((string) $this->argument('username'));
        if ($owner === null) {
            return self::FAILURE;
        }

        $permissions = $this->permissions();
        $expiresAt = $this->expiry();
        if ($permissions === null || $expiresAt === false) {
            return self::FAILURE;
        }

        try {
            $new = $issuer->issue($owner, Actor::console(), (string) $this->option('name'), $permissions, $expiresAt);
        } catch (PrivilegeEscalation $refused) {
            $this->error(sprintf(
                'Refused: %s does not hold %s, and a token never carries more than its owner.',
                $this->describe($owner),
                implode(', ', Permission::values($refused->permissions)),
            ));

            return self::FAILURE;
        } catch (InvalidTokenRequest $invalid) {
            $this->error(match ($invalid->reason) {
                InvalidTokenRequest::NAME => 'Give the token a --name of 1 to 100 characters, without control characters.',
                default => 'Name a permission with --permission or a --preset.',
            });

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Token %d "%s" for %s: %s, %s.',
            $new->accessToken->getKey(),
            TerminalText::safe((string) $new->accessToken->getAttribute('name')),
            $this->describe($owner),
            implode(', ', Permission::values($permissions)),
            $expiresAt === null ? 'never expires' : 'expires '.$expiresAt->toDateString(),
        ));
        $this->line('Copy it now; Tesserarr keeps only its hash and cannot show it again:');
        // Alone on its line, so a script can take it (the only line that
        // starts with a digit and a bar).
        $this->line($new->plainTextToken);

        return self::SUCCESS;
    }

    /** @return list<Permission>|null */
    private function permissions(): ?array
    {
        $preset = $this->option('preset');
        /** @var list<string> $names */
        $names = array_values(array_filter((array) $this->option('permission'), 'is_string'));

        if (is_string($preset) && $preset !== '') {
            $found = TokenPreset::tryFrom($preset);
            if ($found === null) {
                $this->error(sprintf('Unknown preset "%s". Presets: %s.', TerminalText::safe($preset), implode(', ', array_map(fn (TokenPreset $p) => $p->value, TokenPreset::cases()))));

                return null;
            }
            $names = [...$names, ...Permission::values($found->permissions())];
        }

        if ($names === []) {
            $this->error('Name a permission with --permission or a --preset.');

            return null;
        }

        $permissions = [];
        foreach ($names as $name) {
            $permission = Permission::tryFrom($name);
            if ($permission === null) {
                $this->error(sprintf('Unknown permission "%s".', TerminalText::safe($name)));

                return null;
            }
            $permissions[] = $permission;
        }

        return $permissions;
    }

    /** @return CarbonImmutable|null|false null for "never", false when invalid */
    private function expiry(): CarbonImmutable|null|false
    {
        $expires = (string) $this->option('expires');

        if ($expires === 'never') {
            return null;
        }
        if (preg_match('/^[1-9]\d{0,4}$/', $expires) !== 1) {
            $this->error('--expires takes a number of days (1 to 99999) or "never".');

            return false;
        }

        return CarbonImmutable::now()->addDays((int) $expires);
    }
}
