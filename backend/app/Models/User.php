<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        ];
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
