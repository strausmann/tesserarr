<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\SelfService;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

#[SelfService]
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $before = $user->username;

        try {
            DB::transaction(function () use ($request, $user, $before): void {
                $user->fill([
                    'username' => $request->validated('username'),
                    'display_name' => (string) $request->validated('display_name'),
                ])->save();

                if ($before !== $user->username) {
                    app(AuditLogger::class)->record(AuditAction::UserRenamed, Actor::user($user), $user, ['from' => $before, 'to' => $user->username]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Taken between the validation and the save: the index decides.
            $user->username = $before;

            throw ValidationException::withMessages(['username' => __('validation.unique', ['attribute' => 'username'])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
