<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
// Never sent in any response or footprint: password hash, remember token, 2FA secret and recovery codes
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * Footprints: never the password / remember token (hidden attributes are skipped automatically),
     * and not the login time (it changes at every login).
     *
     * @var list<string>
     */
    protected array $activityIgnored = ['last_login_at'];

    /**
     * A password change is recorded as "password: (hidden)" — that it changed, never what it is.
     *
     * @var list<string>
     */
    protected array $activityMasked = ['password'];

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
            'role' => Role::class,
            'must_change_password' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Cut every open session (all devices) and cancel "remember me": used when an account is
     * deactivated or its password is reset by the owner.
     */
    public function logoutEverywhere(): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    /**
     * Footprints only for changes made by someone in the app (owner managing staff),
     * not for accounts created by seeders or the agency:create command.
     */
    public function shouldLogActivity(): bool
    {
        return Auth::check();
    }

    public function activityLabel(): string
    {
        return 'User '.$this->name;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        return match (true) {
            array_key_exists('two_factor_confirmed_at', $changes) => $changes['two_factor_confirmed_at']['new'] === null ? 'two_factor_disabled' : 'two_factor_enabled',
            array_key_exists('deactivated_at', $changes) => $changes['deactivated_at']['new'] === null ? 'reactivated' : 'deactivated',
            ($changes['must_change_password']['new'] ?? false) === true => 'password_reset',
            array_key_exists('password', $changes) => 'password_changed',
            array_key_exists('role', $changes) => 'role_changed',
            default => 'updated',
        };
    }
}
