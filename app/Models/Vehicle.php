<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'owner_id',
        'vehicle_number',
        'vehicle_type',
        'is_active',
        'notes',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function vehicleContracts()
    {
        return $this->hasMany(VehicleContract::class);
    }

    public function activeVehicleContract()
    {
        return $this->hasOne(VehicleContract::class)->where('status', 'active');
    }

    /** Le contrat vivant : actif, ou en attente de son premier agent (2026-10-09). */
    public function liveVehicleContract()
    {
        return $this->hasOne(VehicleContract::class)->whereIn('status', ['active', 'pending']);
    }

    public function driverContracts()
    {
        return $this->hasMany(DriverContract::class);
    }

    public function activeDriverContract()
    {
        return $this->hasOne(DriverContract::class)->where('status', 'active');
    }

    public function pauses()
    {
        return $this->hasMany(VehiclePause::class);
    }

    public function activePause()
    {
        return $this->hasOne(VehiclePause::class)->whereNull('end_date');
    }

    public function isOnPause(): bool
    {
        return $this->activePause()->exists();
    }

    // Nombre total de jours de pause du véhicule
    public function getTotalPauseDaysAttribute(): int
    {
        return $this->pauses()
            ->whereNotNull('end_date')
            ->get()
            ->sum(function ($pause) {
                return $pause->start_date->diffInDays($pause->end_date);
            });
    }
}
