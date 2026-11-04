<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasUuid, HasRoles, HasApiTokens;

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'profil',
        'profile_photo',
        'is_active',
        'adresse',
        'notification_preferences',
        'failed_login_attempts',
        'locked_until',
        'last_failed_login'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_failed_login' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function driver()
    {
        return $this->hasOne(Driver::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function driverBookings()
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    public function testimonials()
    {
        return $this->hasMany(Testimonial::class);
    }

    public function fcmTokens()
    {
        return $this->hasMany(FcmToken::class);
    }

    public function isAdmin()
    {
        return $this->profil === 'admin';
    }

    public function isDriver()
    {
        return $this->profil === 'driver';
    }

    public function isClient()
    {
        return $this->profil === 'client';
    }

    public function isOwner(): bool
    {
        return $this->profil === 'owner';
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function vehicleContracts()
    {
        return $this->hasMany(VehicleContract::class, 'owner_id');
    }
}
