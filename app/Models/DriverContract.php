<?php

namespace App\Models;

use App\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverContract extends Model
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'vehicle_contract_id',
        'start_date',
        'end_date',
        'contract_months',
        'status',
        'end_reason',
        'end_notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vehicleContract()
    {
        return $this->belongsTo(VehicleContract::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function vehiclePauses()
    {
        return $this->hasMany(VehiclePause::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && is_null($this->end_date);
    }

    // Mois écoulés depuis le début du contrat agent
    public function getMonthsElapsedAttribute(): int
    {
        $end = $this->end_date ?? now();
        return min(
            $this->start_date->diffInMonths($end) + 1,
            $this->contract_months
        );
    }

    // Jours de pause acquis sur ce contrat
    public function getAccruedLeaveDaysAttribute(): int
    {
        return 2 * $this->months_elapsed; // 2j/mois
    }

    // Jours de pause restants sur la totalité du contrat (durée totale, pas seulement mois écoulés)
    public function getRemainingContractLeaveDaysAttribute(): int
    {
        $totalAllotted = 2 * $this->contract_months;
        return $totalAllotted - $this->used_leave_days;
    }

    // Jours de pause utilisés sur ce contrat
    public function getUsedLeaveDaysAttribute(): int
    {
        $completed = $this->leaveRequests()->where('status', 'completed')->sum('effective_days');

        $ongoing = $this->leaveRequests()
            ->where('status', 'ongoing')
            ->get()
            ->sum(fn($lr) => $lr->start_date->diffInDays(now()) + 1);

        return $completed + $ongoing;
    }

    // Jours disponibles (sans restriction de dépassement)
    public function getAvailableLeaveDaysAttribute(): int
    {
        return $this->accrued_leave_days - $this->used_leave_days;
        // Peut être négatif si surplus
    }

    // Paiements effectués sur ce contrat agent
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Nombre total de jours de pause alloués sur toute la durée du contrat (2j/mois)
    public function getTotalAllottedLeaveDaysAttribute(): int
    {
        return 2 * $this->contract_months;
    }

    // Pourcentage de jours de pause déjà consommés sur le total alloué
    public function getLeaveUsagePercentageAttribute(): int
    {
        if ($this->total_allotted_leave_days <= 0) {
            return 0;
        }
        return (int) min(100, round(($this->used_leave_days / $this->total_allotted_leave_days) * 100));
    }

    public function getPlannedEndDateAttribute(): ?Carbon
    {
        if (!$this->start_date || !$this->contract_months) {
            return null;
        }
        return $this->start_date->copy()->addMonths($this->contract_months)->subDay();
    }

    public function getMonthsRemainingAttribute(): int
    {
        return max(0, $this->contract_months - $this->months_elapsed);
    }

    // Date de fin réellement prévue, décalée par les jours de pause pris (jours ouvrés uniquement)
    public function getExtendedEndDateAttribute(): ?Carbon
    {
        $planned = $this->planned_end_date;
        if (!$planned) {
            return null;
        }
        return $this->addBusinessDays($planned, $this->used_leave_days);
    }

    // Versement et taxe journaliers de l'agent : ceux que le contrat véhicule a figés.
    public function getDailyPaymentAttribute(): float
    {
        return (float) ($this->vehicleContract?->daily_amount ?? 0);
    }

    public function getDailyTaxAttribute(): float
    {
        return (float) ($this->vehicleContract?->daily_tax ?? 0);
    }

    public function getDailyNetAmountAttribute(): float
    {
        return $this->daily_payment - $this->daily_tax;
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $cursor = $date->copy();
        $remaining = $days;
        while ($remaining > 0) {
            $cursor->addDay();
            if (!$cursor->isWeekend()) {
                $remaining--;
            }
        }
        return $cursor;
    }
}
