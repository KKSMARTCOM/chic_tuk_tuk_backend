<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehiclePause extends Model
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'vehicle_id',
        'vehicle_contract_id',
        'driver_contract_id',
        'start_date',
        'end_date',
        'reason_type',
        'reason_notes',
        'is_auto',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_auto'    => 'boolean',
    ];

    // Labels lisibles
    public static array $reasonTypes = [
        'agent_leave'  => 'Pause agent',
        'agent_change' => 'Changement d\'agent',
        'technical'    => 'Problème technique',
        'accident'     => 'Accident',
        'legal'        => 'Litige / problème légal',
        'other'        => 'Autre',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vehicleContract()
    {
        return $this->belongsTo(VehicleContract::class);
    }

    public function driverContract()
    {
        return $this->belongsTo(DriverContract::class);
    }

    public function isActive(): bool
    {
        return is_null($this->end_date);
    }

    public function getDaysAttribute(): int
    {
        $end = $this->end_date ?? now();
        return $this->start_date->diffInDays($end);
    }

    public function getReasonLabelAttribute(): string
    {
        return self::$reasonTypes[$this->reason_type] ?? $this->reason_type;
    }
}
