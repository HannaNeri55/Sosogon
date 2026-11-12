<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use App\Services\AccountEnforcementService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'role_id', 'name', 'email', 'password', 'phone', 'avatar', 'status', 'business_type',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_until' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // ==================== ACCOUNT STATE (enforcement) ====================

    /** Account states shown to admins and to the user (status values themselves are unchanged). */
    public const STATE_ACTIVE = 'active';

    public const STATE_WARNING = 'warning';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_DEACTIVATED = 'deactivated';

    public const STATE_PENDING = 'pending';

    public const STATE_CLOSED = 'closed';

    public function violations(): HasMany
    {
        return $this->hasMany(AccountViolation::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(AccountAppeal::class);
    }

    /**
     * The account's current state, derived from status, suspended_until, closed_at and
     * active warnings. A suspension whose end has passed reads as no longer suspended even
     * before liftExpiredSuspension() has written that back.
     */
    public function accountState(): string
    {
        if ($this->closed_at) {
            return self::STATE_CLOSED;
        }
        if ($this->status === 'pending') {
            return self::STATE_PENDING;
        }
        if ($this->status === 'suspended') {
            if (! $this->suspended_until) {
                return self::STATE_DEACTIVATED;
            }
            if ($this->suspended_until->isFuture()) {
                return self::STATE_SUSPENDED;
            }
        }

        return $this->violations()->where('action', AccountViolation::ACTION_WARNING)->current()->exists()
            ? self::STATE_WARNING
            : self::STATE_ACTIVE;
    }

    /** Suspended, deactivated or closed: only the account-status area and logout are allowed. */
    public function isRestricted(): bool
    {
        // Same outcome as checking accountState(), without the warnings query (runs on every request).
        return (bool) $this->closed_at
            || ($this->status === 'suspended' && (! $this->suspended_until || $this->suspended_until->isFuture()));
    }

    /**
     * Automatic end of a temporary suspension: once suspended_until has passed and no other
     * restriction remains, the account is active again (logged as a system action).
     */
    public function liftExpiredSuspension(): bool
    {
        if ($this->status !== 'suspended' || ! $this->suspended_until || $this->suspended_until->isFuture() || $this->closed_at) {
            return false;
        }

        app(AccountEnforcementService::class)->expireSuspension($this);

        // The stored state may have changed: callers should re-read the user.
        return true;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function businessSubmissions(): HasMany
    {
        return $this->hasMany(BusinessSubmission::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function ownerApplication(): HasOne
    {
        return $this->hasOne(BusinessOwnerApplication::class);
    }

    /**
     * A rejected registration keeps users.status = pending; the application
     * row records the rejection. Such an owner may sign in, but only reaches
     * the application screen to correct and resubmit (see EnsureOwnerApproved).
     */
    public function hasRejectedApplication(): bool
    {
        return $this->ownerApplication()->where('status', 'rejected')->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role->name === 'admin';
    }

    public function isTourist(): bool
    {
        return $this->role->name === 'tourist';
    }

    public function isBusinessOwner(): bool
    {
        return $this->role->name === 'business_owner';
    }

    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $resetUrl = $frontendUrl.'/reset-password?token='.$token.'&email='.urlencode($this->email);

        try {
            Mail::to($this->email)->send(new PasswordResetMail($this->name, $resetUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset email', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
