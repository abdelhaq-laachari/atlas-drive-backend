<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Settings → Users (owner only): create staff with a temporary password, edit, deactivate /
 * reactivate (never delete), reset a password. The agency always keeps at least one active owner.
 */
class StaffController extends Controller
{
    /**
     * GET /api/settings/users
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manageStaff', User::class);

        $users = $request->user()->agency->users()
            ->orderByRaw('deactivated_at is not null') // active first
            ->orderBy('name')
            ->get();

        return StaffResource::collection($users);
    }

    /**
     * POST /api/settings/users  { name, email, role } → temporary password shown once.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageStaff', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'email.unique' => 'This email is already used by another account.',
        ]);

        $password = $this->temporaryPassword();

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $password,
        ]);
        $user->agency_id = $request->user()->agency_id;
        $user->role = Role::from($validated['role']);
        $user->must_change_password = true;
        $user->save();

        return $this->withTemporaryPassword($user, $password, 201);
    }

    /**
     * PUT /api/settings/users/{id}  { name, email, role }
     */
    public function update(Request $request, int $id): StaffResource
    {
        Gate::authorize('manageStaff', User::class);
        $member = $this->member($request, $id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'email.unique' => 'This email is already used by another account.',
        ]);

        $newRole = Role::from($validated['role']);
        if ($member->role === Role::Owner && $newRole !== Role::Owner) {
            $this->keepAnActiveOwner($member, 'role');
        }

        $member->fill(['name' => $validated['name'], 'email' => $validated['email']]);
        $member->role = $newRole;
        $member->save();

        return new StaffResource($member);
    }

    /**
     * POST /api/settings/users/{id}/deactivate — cannot log in any more; open sessions are cut.
     */
    public function deactivate(Request $request, int $id): StaffResource
    {
        Gate::authorize('manageStaff', User::class);
        $member = $this->member($request, $id);

        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['user' => "You can't deactivate your own account."]);
        }
        abort_unless($member->isActive(), 409, 'This account is already deactivated.');
        if ($member->role === Role::Owner) {
            $this->keepAnActiveOwner($member, 'user');
        }

        DB::transaction(function () use ($member) {
            $member->deactivated_at = now();
            $member->save();
            $member->logoutEverywhere();
        });

        return new StaffResource($member);
    }

    /**
     * POST /api/settings/users/{id}/reactivate
     */
    public function reactivate(Request $request, int $id): StaffResource
    {
        Gate::authorize('manageStaff', User::class);
        $member = $this->member($request, $id);

        abort_if($member->isActive(), 409, 'This account is already active.');

        $member->deactivated_at = null;
        $member->save();

        return new StaffResource($member);
    }

    /**
     * POST /api/settings/users/{id}/reset-password — new temporary password (shown once),
     * to change at the next login; open sessions are cut.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manageStaff', User::class);
        $member = $this->member($request, $id);

        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Change your own password from "My account".']);
        }

        $password = $this->temporaryPassword();

        DB::transaction(function () use ($member, $password) {
            $member->password = $password;
            $member->must_change_password = true;
            $member->save();
            $member->logoutEverywhere();
        });

        return $this->withTemporaryPassword($member, $password);
    }

    /**
     * POST /api/settings/users/{id}/reset-two-factor — the staff member lost their phone and their
     * recovery codes: turn two-step verification off so they can log in and set it up again.
     */
    public function resetTwoFactor(Request $request, int $id, DisableTwoFactorAuthentication $disable): StaffResource
    {
        Gate::authorize('manageStaff', User::class);
        $member = $this->member($request, $id);

        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Manage your own two-step verification from "My account".']);
        }
        abort_unless($member->hasEnabledTwoFactorAuthentication(), 409, 'Two-step verification is not on for this account.');

        $disable($member);

        return new StaffResource($member);
    }

    /**
     * A user of the owner's own agency, or 404 (another agency's user "does not exist").
     */
    private function member(Request $request, int $id): User
    {
        return $request->user()->agency->users()->findOrFail($id);
    }

    /**
     * The agency must never be left without an active owner (nobody could manage it).
     */
    private function keepAnActiveOwner(User $leaving, string $field): void
    {
        $otherActiveOwners = $leaving->agency->users()
            ->where('role', Role::Owner)
            ->whereNull('deactivated_at')
            ->whereKeyNot($leaving->id)
            ->exists();

        if (! $otherActiveOwners) {
            throw ValidationException::withMessages([$field => 'The agency needs at least one active owner.']);
        }
    }

    private function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /**
     * The temporary password travels once, in this answer only (never stored in clear, never logged).
     */
    private function withTemporaryPassword(User $user, string $password, int $status = 200): JsonResponse
    {
        return (new StaffResource($user))
            ->additional(['meta' => ['temporary_password' => $password]])
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
