<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un agent sur un véhicule « en interne » (spec 2026-10-09) : aucun paiement, invisible du
 * propriétaire. ⚠️ Aucun code des paiements, des pauses ni des fiches ne doit lire ce modèle.
 */
class InternalAssignment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'vehicle_contract_id', 'driver_id', 'start_date', 'end_date', 'notes',
        'ended_reason', 'created_by', 'ended_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function vehicleContract()
    {
        return $this->belongsTo(VehicleContract::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
