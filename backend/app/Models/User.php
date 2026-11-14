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

    // ==================== ADMIN SCOPE (see App\Support\AdminScope) ====================
    // admin_scope and municipality_id are not fillable: only the admin:create / admin:assign
    // commands set them.

    /** Manages every municipality (the Provincial Tourism Office, or the system administrator). */
    public const ADMIN_SCOPE_PROVINCIAL = 'provincial';

    /** Manages one municipality (users.municipality_id). */
    public const ADMIN_SCOPE_MUNICIPAL = 'municipal';

    /** A municipal admin's municipality. */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * An admin with authority over every municipality: scope 'provincial', or not assigned yet
     * (null), which keeps the full access admins had before scopes existed. Any other value is
     * not provincial (see hasInvalidAdminScope).
     */
    public function isProvincialAdmin(): bool
    {
        return $this->isAdmin() && ($this->admin_scope === null || $this->admin_scope === self::ADMIN_SCOPE_PROVINCIAL);
    }

    public function isMunicipalAdmin(): bool
    {
        return $this->isAdmin() && $this->admin_scope === self::ADMIN_SCOPE_MUNICIPAL;
    }

    /**
     * An admin whose admin_scope holds something other than null, 'provincial' or 'municipal'
     * (e.g. a mistyped value written to the database by hand). Fail-closed: such an account has
     * no admin authority at all until admin:assign sets a valid scope.
     */
    public function hasInvalidAdminScope(): bool
    {
        return $this->isAdmin() && ! $this->isProvincialAdmin() && ! $this->isMunicipalAdmin();
    }

    /**
     * What the admin area needs to know about an admin's authority, returned at login and by
     * /user; null for other roles. 'assigned' is false while the scope hasn't been set (the
     * account keeps full access meanwhile). An invalid scope reads 'invalid' (no access).
     *
     * @return array{scope: string, assigned: bool, municipality: array{id: int, name: string}|null, designation: string}|null
     */
    public function adminProfile(): ?array
    {
        if (! $this->isAdmin()) {
            return null;
        }
        $municipality = $this->isMunicipalAdmin() ? $this->municipality : null;
        if ($this->hasInvalidAdminScope()) {
            return ['scope' => 'invalid', 'assigned' => true, 'municipality' => null, 'designation' => $this->admin_title ?? 'Administrator'];
        }

        return [
            'scope' => $this->isMunicipalAdmin() ? self::ADMIN_SCOPE_MUNICIPAL : self::ADMIN_SCOPE_PROVINCIAL,
            'assigned' => $this->admin_scope !== null,
            'municipality' => $municipality ? ['id' => $municipality->id, 'name' => $municipality->name] : null,
            'designation' => $this->admin_title
                ?? ($municipality ? "{$municipality->name} Tourism Office" : ($this->isMunicipalAdmin() ? 'Municipal Administrator' : 'Provincial Administrator')),
        ];
    }

    /**
     * Whether this admin may manage a record of $municipalityId. A record without a
     * municipality (province-wide) belongs to provincial admins only. A municipal admin with no
     * municipality set, and an admin with an invalid scope, manage nothing.
     */
    public function managesMunicipality(?int $municipalityId): bool
    {
        if ($this->isProvincialAdmin()) {
            return true;
        }
        if (! $this->isMunicipalAdmin()) {
            return false;
        }

        return $municipalityId !== null && $this->municipality_id !== null && (int) $this->municipality_id === $municipalityId;
    }

    /**
     * The municipality of a business owner's business: their listing's, else their registration
     * application's, else their latest business submission's. Null when there is none (such an
     * owner is managed by provincial admins).
     */
    public function businessMunicipalityId(): ?int
    {
        foreach ([Accommodation::class, FoodBeverage::class, RetailShop::class] as $listing) {
            $id = $listing::where('user_id', $this->id)->orderBy('id')->value('municipality_id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        $id = $this->ownerApplication()->value('municipality_id')
            ?? $this->businessSubmissions()->latest('id')->value('municipality_id');

        return $id !== null ? (int) $id : null;
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
