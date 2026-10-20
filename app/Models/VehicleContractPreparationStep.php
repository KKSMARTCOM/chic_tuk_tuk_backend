<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Une étape de préparation d'un contrat (spec 2026-10-20, §3.2) : copiée de la liste par
 * défaut, puis ajustée pour ce véhicule. `done_on` est la date choisie par l'admin.
 */
class VehicleContractPreparationStep extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'vehicle_contract_id', 'label', 'position', 'is_agent_arrival', 'done_on', 'done_by', 'done_at',
        'proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_agent_arrival' => 'boolean',
        'done_on' => 'date',
        'done_at' => 'datetime',
        'proof_size' => 'integer',
    ];

    public function vehicleContract()
    {
        return $this->belongsTo(VehicleContract::class);
    }

    public function doneBy()
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
