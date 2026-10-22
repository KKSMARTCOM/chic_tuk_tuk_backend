<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Un versement fait au propriétaire pour un contrat (spec 2026-10-22-versement-proprietaire).
 * Il ne se supprime pas : il s'annule (`status = cancelled`), et ne compte plus.
 */
class OwnerDisbursement extends Model
{
    use HasUuid;

    protected $fillable = [
        'vehicle_contract_id', 'paid_on', 'amount', 'method', 'reference', 'note',
        'proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size',
        'status', 'cancelled_at', 'cancellation_reason', 'created_by', 'updated_by', 'cancelled_by', 'owner_notified_at',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'amount' => 'decimal:2',
        'proof_size' => 'integer',
        'cancelled_at' => 'datetime',
        'owner_notified_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(VehicleContract::class, 'vehicle_contract_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
