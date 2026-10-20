<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUuid, HasFactory;

    protected $fillable = [
        'driver_id',
        'payment_type',         // nouveau
        'vehicle_contract_id',  // nouveau
        'driver_contract_id',   // nouveau
        'payment_month',        // nouveau
        'net_amount',         // nouveau
        'status',               // nouveau
        'amount',
        'payment_method',
        'payment_date',
        'notes',
        'reference_number',
        'remuneration_statement_id',
        'collected_on',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'payment_month' => 'date', // nouveau
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2', // nouveau
        'collected_on' => 'date',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicleContract()
    {
        return $this->belongsTo(VehicleContract::class);
    }

    /**
     * Ce que le propriétaire voit : tout ce qui ne revient pas à l'agence (spec 2026-10-12, §5).
     *
     * ⚠️ La version SQL de `isAgency()` : les deux doivent rester d'accord, sinon un paiement
     * serait à la fois chez le propriétaire et « Agence » chez l'admin.
     */
    public function scopeForOwner(Builder $query): Builder
    {
        return $query->whereHas('vehicleContract', fn (Builder $contract) => $contract->where(fn (Builder $q) => $q
            ->whereNull('vehicle_contracts.end_date')
            ->orWhere(fn (Builder $settled) => $settled
                ->whereColumn('vehicle_contracts.end_date', '>=', 'payments.payment_date')
                ->where(fn (Builder $counted) => $counted
                    ->whereNotNull('payments.remuneration_statement_id')
                    ->orWhereRaw('('.self::FINAL_ISSUED_ON_SQL.') IS NULL')
                    ->orWhere(fn (Builder $collected) => $collected
                        ->where('payments.status', '!=', 'pending')
                        ->whereRaw("NOT (payments.status = 'completed' AND payments.collected_on IS NOT NULL AND payments.collected_on > (".self::FINAL_ISSUED_ON_SQL.'))'))))));
    }

    /** La date d'établissement de la dernière fiche validée du contrat (la fiche finale d'un contrat soldé). */
    private const FINAL_ISSUED_ON_SQL = "SELECT MAX(COALESCE(rs.issued_on, rs.validated_at::date)) FROM remuneration_statements rs WHERE rs.vehicle_contract_id = vehicle_contracts.id AND rs.status = 'validated'";

    /**
     * Revient à l'agence (spec 2026-10-12, §5) : daté après la fin du contrat soldé ; ou, du
     * dernier mois, laissé hors de la fiche finale — encore en attente, ou encaissé après elle
     * (relecture du 2026-10-12, réglé le 2026-10-20). Aucune fiche ne le versera plus au
     * propriétaire, puisque le contrat ne se solde qu'une fois les deux cumuls atteints.
     */
    public function isAgency(): bool
    {
        $contract = $this->vehicleContract;
        if (! $contract?->end_date) {
            return false;
        }
        if ($this->payment_date->gt($contract->end_date)) {
            return true;
        }
        if ($this->remuneration_statement_id !== null) {
            return false;
        }
        $final = $contract->finalStatementIssuedOn();
        if ($final === null) {
            return false;
        }

        return $this->status === 'pending'
            || ($this->status === 'completed' && $this->collected_on !== null && $this->collected_on->gt($final));
    }

    public function driverContract()
    {
        return $this->belongsTo(DriverContract::class);
    }

    public function remunerationStatement()
    {
        return $this->belongsTo(RemunerationStatement::class);
    }
}
