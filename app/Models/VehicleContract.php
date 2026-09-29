<?php

namespace App\Models;

use App\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleContract extends Model
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'vehicle_id',
        'owner_id',
        'total_amount',
        'monthly_payment',
        'start_date',
        'end_date',
        'status',
        'notes',
        'contract_months',
        'unlimited_internet',
        'spotify_premium',
        'manager_remuneration',
        // Figés à la création depuis les réglages (2026-09-29), jamais relus ensuite.
        'daily_amount',
        'daily_tax',
    ];

    protected $casts = [
        'total_amount'    => 'decimal:2',
        'monthly_payment' => 'decimal:2',
        'contract_months' => 'integer',
        'unlimited_internet' => 'decimal:2',
        'spotify_premium' => 'decimal:2',
        'manager_remuneration' => 'decimal:2',
        'daily_amount' => 'decimal:2',
        'daily_tax' => 'decimal:2',
        'start_date'      => 'date',
        'end_date'        => 'date',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driverContracts()
    {
        return $this->hasMany(DriverContract::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function pauses()
    {
        return $this->hasMany(VehiclePause::class);
    }

    // Montant total déjà payé
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()->where('status', 'completed')->sum('net_amount');
    }

    // Montant restant à payer
    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - $this->total_paid);
    }

    // Surplus si trop payé
    public function getSurplusAttribute(): float
    {
        $diff = (float) $this->total_paid - (float) $this->total_amount;
        return max(0, $diff);
    }

    // Pourcentage remboursé
    public function getProgressPercentageAttribute(): int
    {
        if ($this->total_amount <= 0) return 0;
        return (int) min(100, round(($this->total_paid / $this->total_amount) * 100));
    }

    // ── Charges du contrat ──
    public function getTotalChargesAttribute(): float
    {
        return (float) ($this->unlimited_internet ?? 0)
            + (float) ($this->spotify_premium ?? 0)
            + (float) ($this->manager_remuneration ?? 0);
    }

    // ── Mois écoulés / restants sur le contrat véhicule ──
    public function getMonthsElapsedAttribute(): int
    {
        if (!$this->start_date) {
            return 0;
        }

        $end = $this->end_date ?? now();
        $elapsed = $this->start_date->diffInMonths($end) + 1;

        return min($elapsed, $this->contract_months);
    }

    public function getMonthsRemainingAttribute(): int
    {
        return max(0, $this->contract_months - $this->months_elapsed);
    }

    // ── Montant journalier net, depuis les montants FIGÉS sur le contrat ──
    // ⚠️ Plus d'accesseur `daily_tax` ni `daily_payment` : depuis le 2026-09-29 ce sont des
    // colonnes (`daily_amount`, `daily_tax`), et un accesseur du même nom masquerait la
    // colonne en relisant autre chose.
    public function getDailyNetAmountAttribute(): float
    {
        return (float) ($this->daily_amount ?? 0) - (float) ($this->daily_tax ?? 0);
    }

    // ── Interprétation A : pauses cumulées de TOUS les agents ayant travaillé sur ce contrat ──
    public function getTotalContractDaysAttribute(): int
    {
        if ($this->start_date && $this->end_date) {
            return $this->start_date->diffInDays($this->end_date) + 1;
        }
        return $this->contract_months * 30;
    }

    public function getTotalPauseDaysTakenAttribute(): int
    {
        $driverContractIds = $this->driverContracts()->pluck('id');

        $completed = LeaveRequest::whereIn('driver_contract_id', $driverContractIds)
            ->where('status', 'completed')
            ->sum('effective_days');

        $ongoing = LeaveRequest::whereIn('driver_contract_id', $driverContractIds)
            ->where('status', 'ongoing')
            ->get()
            ->sum(fn($lr) => LeaveRequest::countBusinessDays($lr->start_date, now()));

        return (int) ($completed + $ongoing);
    }

    public function getRemainingContractDaysAttribute(): int
    {
        return max(0, $this->total_contract_days - $this->total_pause_days_taken);
    }

    public function getPauseUsagePercentageAttribute(): int
    {
        if ($this->total_contract_days <= 0) return 0;
        return (int) min(100, round(($this->total_pause_days_taken / $this->total_contract_days) * 100));
    }

    // ── Date de fin ajustée par le cumul des pauses (tous agents), jours ouvrés uniquement ──
    public function getPlannedEndDateAttribute(): ?Carbon
    {
        if (!$this->start_date || !$this->contract_months) {
            return null;
        }

        return $this->start_date->copy()->addMonths($this->contract_months)->subDay();
    }

    public function getExtendedEndDateAttribute(): ?Carbon
    {
        if (!$this->planned_end_date) {
            return null;
        }
        return LeaveRequest::addBusinessDays($this->planned_end_date, $this->total_pause_days_taken);
    }
}
