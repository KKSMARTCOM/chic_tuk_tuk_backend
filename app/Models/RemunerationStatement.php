<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Une fiche de rémunération : un contrat véhicule, un mois (spec 2026-09-30, §4).
 *
 * ⚠️ Une fiche validée ne se modifie JAMAIS : elle s'annule, et un brouillon la remplace.
 */
class RemunerationStatement extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'vehicle_contract_id', 'month', 'status', 'number',
        'deducted_internet', 'deducted_spotify', 'deducted_manager',
        'opening_internet', 'opening_spotify', 'opening_manager', 'note',
        'figures', 'balance_due', 'validated_by', 'validated_at', 'pdf_path', 'sent_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'replaces_id',
    ];

    protected $casts = [
        'month' => 'date',
        'deducted_internet' => 'decimal:2',
        'deducted_spotify' => 'decimal:2',
        'deducted_manager' => 'decimal:2',
        'opening_internet' => 'decimal:2',
        'opening_spotify' => 'decimal:2',
        'opening_manager' => 'decimal:2',
        'figures' => 'array',
        'balance_due' => 'decimal:2',
        'validated_at' => 'datetime',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(VehicleContract::class, 'vehicle_contract_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function monthKey(): string
    {
        return $this->month->format('Y-m');
    }
}
