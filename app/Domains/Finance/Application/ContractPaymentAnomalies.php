<?php

namespace App\Domains\Finance\Application;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Les anomalies des paiements de contrat, à nettoyer avant une reconstitution des fiches
 * (2026-10-01). SEUL endroit où elles se définissent : l'audit les compte, la liste des
 * paiements les filtre (`filter[anomaly]=…`), et les deux doivent toujours tomber d'accord.
 */
final class ContractPaymentAnomalies
{
    /** Code => libellé, dans l'ordre de l'audit. */
    public const KINDS = [
        'no_month' => 'Sans mois',
        'no_contract' => 'Sans contrat',
        'stopped_day' => 'Sur un jour d\'arrêt',
        'duplicate' => 'Doublons du même jour',
        'pending_past' => 'En attente d\'un mois passé',
    ];

    /** Restreint `$query` (sur `payments`) aux paiements de l'anomalie `$kind`. */
    public static function apply(Builder $query, string $kind): void
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new ApiException(400, 'INVALID_LIST_QUERY', "Anomalie inconnue : {$kind}.");
        }

        $query->where('payments.payment_type', 'contract');

        match ($kind) {
            'no_month' => $query->where('payments.status', '!=', 'cancelled')->whereNull('payments.payment_month'),
            'no_contract' => $query->where('payments.status', '!=', 'cancelled')
                ->where(fn ($q) => $q->whereNull('payments.vehicle_contract_id')->orWhereNull('payments.driver_contract_id')),
            'stopped_day' => $query->whereIn('payments.id', self::stoppedDayIds()),
            'duplicate' => self::duplicates($query),
            'pending_past' => $query->where('payments.status', 'pending')
                ->where('payments.payment_month', '<', now()->startOfMonth()->toDateString()),
        };
    }

    /** @return Collection<int, Payment> */
    public static function payments(string $kind): Collection
    {
        $query = Payment::query();
        self::apply($query, $kind);

        return $query->orderBy('payment_date')->get();
    }

    /**
     * Un véhicule ne rapporte qu'une fois par jour : sur un même contrat véhicule et un même
     * jour, on GARDE un paiement validé de préférence à un en attente, puis le plus ancien ;
     * les autres sont les doublons.
     */
    private static function duplicates(Builder $query): void
    {
        $query->where('payments.status', '!=', 'cancelled')
            ->whereNotNull('payments.vehicle_contract_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payments as kept')
                ->whereColumn('kept.vehicle_contract_id', 'payments.vehicle_contract_id')
                ->whereColumn('kept.payment_date', 'payments.payment_date')
                ->whereColumn('kept.id', '!=', 'payments.id')
                ->where('kept.payment_type', 'contract')
                ->where('kept.status', '!=', 'cancelled')
                ->whereRaw("(
                    (kept.status = 'completed' AND payments.status <> 'completed')
                    OR ((kept.status = 'completed') = (payments.status = 'completed')
                        AND (kept.created_at < payments.created_at
                            OR (kept.created_at = payments.created_at AND kept.id < payments.id)))
                )"));
    }

    /** @return list<string> les paiements vivants tombés un jour de pause ou d'immobilisation */
    private static function stoppedDayIds(): array
    {
        $ids = [];
        VehicleContract::query()->where('status', '!=', 'cancelled')->each(function (VehicleContract $contract) use (&$ids) {
            $calculator = ContractMonthCalculator::for($contract);
            $payments = $contract->payments()->where('payment_type', 'contract')->where('status', '!=', 'cancelled')
                ->whereNotNull('payment_month')->get();
            foreach ($payments->groupBy(fn ($p) => $p->payment_month->format('Y-m')) as $month => $ofMonth) {
                $stopped = $calculator->stoppedDates($month);
                foreach ($ofMonth as $payment) {
                    if (in_array($payment->payment_date->toDateString(), $stopped, true)) {
                        $ids[] = $payment->id;
                    }
                }
            }
        });

        return $ids;
    }
}
