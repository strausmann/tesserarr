<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Identity\Rules\DisplayName;
use App\Identity\Rules\Username;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * The fields of the own profile: username and display name. The contact
     * address arrives with task 8a, with its confirmation.
     *
     * @return array<string, array<int, Unique|ValidationRule|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'username' => $this->usernameRules($userId),
            'display_name' => ['present', 'nullable', new DisplayName],
        ];
    }

    /**
     * @return array<int, Unique|ValidationRule|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'required',
            new Username,
            $userId === null
                ? Rule::unique(User::class, 'username')
                : Rule::unique(User::class, 'username')->ignore($userId),
        ];
    }
}
