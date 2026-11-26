<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\Permission;
use App\Authorization\PermissionSubject;
use App\Authorization\Roles;
use App\Identity\Identity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account. It is identified by its username (unique, lower case, never
 * with "@"); `email` is an optional contact address and never identifies the
 * account (Spec 16.16). Permissions come from roles only.
 *
 * @property int $id
 * @property string $username
 * @property string $display_name
 * @property string|null $email the contact address, not a sign-in name
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property Carbon|null $locked_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $locale
 * @property array<string, bool>|null $ui_collapsed (null until the row is read back: the column default)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['username', 'display_name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser, PermissionSubject
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** Roles belong to the session guard; tokens carry abilities instead. */
    protected string $guard_name = Roles::GUARD;

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /** The name to show: the display name, or the username when it is empty. */
    public function shownName(): string
    {
        return trim((string) $this->display_name) !== '' ? (string) $this->display_name : $this->username;
    }

    /**
     * The permissions of the account's roles, read from the database on each
     * call (never from a loaded relation or spatie's cache): a change of
     * roles takes effect on the next check.
     *
     * @return list<Permission>
     */
    public function grantedPermissions(): array
    {
        /** @var list<string> $names */
        $names = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('permissions.guard_name', Roles::GUARD)
            ->distinct()
            ->pluck('permissions.name')
            ->all();

        return Permission::ordered(array_values(array_filter(array_map(
            fn (string $name) => Permission::tryFrom($name),
            $names,
        ))));
    }

    /** Locked now, read from the database. */
    public function isBlocked(): bool
    {
        if (! $this->exists) {
            return true;
        }

        // A deleted account counts as blocked as well.
        $row = DB::table('users')->where('id', $this->getKey())->first(['locked_at']);

        return $row === null || $row->locked_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'ui_collapsed' => 'array',
        ];
    }
}
