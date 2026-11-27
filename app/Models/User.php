<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\Permission;
use App\Authorization\PermissionSubject;
use App\Authorization\Roles;
use App\Identity\Identity;
use App\Privacy\EncryptedPii;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
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
 * with "@"). Permissions come from roles only.
 *
 * The account address (Spec 16.16, task 8a) is optional: `email` is the
 * confirmed one, valid for mail and (task 11) as a sign-in name once
 * `email_confirmed_at` is set; `pending_email` waits for its confirmation
 * link and holds the address for at most 24 hours. Both are encrypted at rest
 * (EncryptedPii) and found by their blind index. Only
 * App\Privacy\EmailConfirmation writes them: they are neither fillable nor
 * mass-assignable (guarded as well), and a PHPStan rule
 * (OnlyEmailConfirmationWritesAccountEmail) reports any other write. No
 * sign-in ever writes them; the address a provider reports goes to the
 * identity. They are hidden from serialisation: a page or API response names
 * an address only in a field of its own, for whom may see it.
 *
 * @property int $id
 * @property string $username
 * @property string $display_name
 * @property string|null $email the confirmed account address (decrypted)
 * @property string|null $email_bidx
 * @property Carbon|null $email_confirmed_at
 * @property string|null $pending_email the address waiting for confirmation (decrypted)
 * @property string|null $pending_email_bidx
 * @property Carbon|null $pending_email_reserved_until
 * @property string|null $pgp_public_key (task 10a; decrypted)
 * @property Carbon|null $email_verified_at the starter kit's column, unused
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
#[Fillable(['username', 'display_name', 'password'])]
#[Guarded(self::ACCOUNT_EMAIL_FIELDS)]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', ...self::ACCOUNT_EMAIL_FIELDS, 'pgp_public_key'])]
class User extends Authenticatable implements PasskeyUser, PermissionSubject
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** The columns only App\Privacy\EmailConfirmation writes. */
    public const ACCOUNT_EMAIL_FIELDS = [
        'email',
        'email_bidx',
        'email_confirmed_at',
        'email_verified_at',
        'pending_email',
        'pending_email_bidx',
        'pending_email_reserved_until',
    ];

    /** Roles belong to the session guard; tokens carry abilities instead. */
    protected string $guard_name = Roles::GUARD;

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /** The account address that counts: the confirmed one, or none. */
    public function confirmedEmail(): ?string
    {
        return $this->email_confirmed_at !== null ? $this->email : null;
    }

    /**
     * Mail to the account goes to the confirmed address only; an address
     * waiting for its link receives nothing but that link
     * (EmailConfirmation sends it directly).
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->confirmedEmail();
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
            'email' => EncryptedPii::class,
            'email_confirmed_at' => 'datetime',
            'pending_email' => EncryptedPii::class,
            'pending_email_reserved_until' => 'datetime',
            'pgp_public_key' => EncryptedPii::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'ui_collapsed' => 'array',
        ];
    }
}
