<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Audit\Application\Data\ActivityEntryData;
use App\Domains\Finance\Application\Actions\BuildDisbursementSummary;
use App\Domains\Finance\Application\Data\AdminDisbursementData;
use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierContractData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierDisbursementData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierPastContractData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierPaymentData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierStatementData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierTotalsData;
use App\Domains\Fleet\Application\Data\AdminOwnerDossierVehicleData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Domains\Fleet\Application\Data\AdminVehiclePersonData;
use App\Domains\Fleet\Application\Data\OwnerVehicleSummaryData;
use App\Models\OwnerDisbursement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Collection;

/**
 * Le dossier d'un propriétaire (spec 2026-11-04-dossier-proprietaire) : ses véhicules et
 * leurs contrats, ses chiffres, et — chacun sous sa permission — ses dernières fiches,
 * ses derniers versements, les derniers paiements qui lui reviennent et son historique.
 */
final class ShowOwnerDossier
{
    public function __construct(
        private readonly FindOwner $findOwner,
        private readonly BuildDisbursementSummary $disbursementSummary,
    ) {}

    public function __invoke(string $ownerId, User $viewer): AdminOwnerDossierData
    {
        $owner = ($this->findOwner)($ownerId);
        $vehicles = $owner->vehicles()
            ->with(['activeVehicleContract', 'liveVehicleContract', 'settledVehicleContract', 'activePause',
                'activeDriverContract.driver.user', 'vehicleContracts'])
            ->orderBy('vehicle_number')
            ->get();
        $contracts = VehicleContract::where('owner_id', $owner->id)->get();

        // Le payé de chaque contrat montré, en UNE requête : l'accesseur `total_paid` en ferait une
        // par lecture (relecture du 2026-11-04). Même règle : `forOwner`, validés, en net.
        $shown = $vehicles->map(fn (Vehicle $v) => $this->shownContract($v)?->id)->filter();
        $paid = Payment::query()->forOwner()->where('status', 'completed')
            ->whereIn('vehicle_contract_id', $contracts->pluck('id')->merge($shown)->unique()->values())
            ->groupBy('vehicle_contract_id')
            ->selectRaw('vehicle_contract_id, sum(net_amount) as paid')
            ->pluck('paid', 'vehicle_contract_id')
            ->map(fn ($sum) => (float) $sum);

        $rows = $vehicles->map(fn (Vehicle $vehicle) => $this->vehicle($vehicle, $owner, $paid))->values();
        // Ce que l'agence a versé et doit : la permission des versements (2026-11-04).
        $summary = $viewer->can('view-disbursements') ? ($this->disbursementSummary)($contracts->pluck('id')) : null;

        return new AdminOwnerDossierData(
            id: $owner->id,
            name: $owner->name,
            email: $owner->email,
            phone: $owner->phone,
            adresse: $owner->adresse,
            isActive: (bool) $owner->is_active,
            createdAt: $owner->created_at->toIso8601String(),
            lastLoginAt: $owner->last_login_at?->toIso8601String(),
            totals: new AdminOwnerDossierTotalsData(
                disbursed: $summary?->disbursed,
                remaining: round((float) $contracts->whereIn('status', ['pending', 'active'])
                    ->sum(fn (VehicleContract $contract) => max(0, (float) $contract->total_amount - ($paid[$contract->id] ?? 0))), 2),
                outstanding: $summary?->outstanding,
                vehiclesInService: $rows->where('state', 'active')->count(),
                vehiclesTotal: $rows->count(),
            ),
            vehicles: $rows->all(),
            statements: $viewer->can('view-remuneration-statements') ? $this->statements($owner) : null,
            disbursements: $viewer->can('view-disbursements') ? $this->disbursements($owner) : null,
            payments: $viewer->can('view-payments') ? $this->payments($owner) : null,
            activity: $viewer->can('view-activity-log') ? $this->activity($owner) : null,
        );
    }

    private function shownContract(Vehicle $vehicle): ?VehicleContract
    {
        return $vehicle->activeVehicleContract ?? $vehicle->liveVehicleContract ?? $vehicle->settledVehicleContract;
    }

    /** @param  Collection<string, float>  $paid */
    private function vehicle(Vehicle $vehicle, User $owner, Collection $paid): AdminOwnerDossierVehicleData
    {
        $contract = $this->shownContract($vehicle);
        $state = match (true) {
            ! $vehicle->is_active => 'inactive',
            $contract === null => 'no_contract',
            default => OwnerVehicleSummaryData::ownerState($vehicle),
        };
        $pause = in_array($state, ['paused', 'immobilized'], true) ? $vehicle->activePause : null;
        $driver = $vehicle->activeDriverContract?->driver;

        return new AdminOwnerDossierVehicleData(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            state: $state,
            pauseSince: $pause?->start_date?->toDateString(),
            pauseReasonLabel: $pause?->reason_label,
            driver: $driver ? new AdminVehiclePersonData($driver->id, $driver->user?->name, $driver->user?->phone) : null,
            contract: $contract ? AdminOwnerDossierContractData::fromModel($contract, $paid[$contract->id] ?? 0.0) : null,
            pastContracts: $vehicle->vehicleContracts
                ->where('owner_id', $owner->id)
                ->reject(fn (VehicleContract $past) => $past->id === $contract?->id)
                ->sortByDesc(fn (VehicleContract $past) => $past->end_date?->toDateString() ?? '')
                ->map(fn (VehicleContract $past) => new AdminOwnerDossierPastContractData(
                    $past->id, $past->status, $past->end_date?->toDateString()))
                ->values()
                ->all(),
        );
    }

    /** @return array<int, AdminOwnerDossierStatementData> */
    private function statements(User $owner): array
    {
        return RemunerationStatement::query()
            ->whereHas('contract', fn (Builder $q) => $q->where('owner_id', $owner->id))
            ->with('contract.vehicle')
            ->orderByDesc('month')
            ->limit(5)
            ->get()
            ->map(function (RemunerationStatement $statement) {
                $status = RemunerationStatementStatus::from($statement->status);

                return new AdminOwnerDossierStatementData(
                    id: $statement->id,
                    number: $statement->number,
                    month: $statement->monthKey(),
                    vehicleNumber: (string) $statement->contract?->vehicle?->vehicle_number,
                    balanceDue: $statement->status === 'draft' || $statement->balance_due === null ? null : (float) $statement->balance_due,
                    status: $status->value,
                    statusLabel: $status->label(),
                );
            })
            ->all();
    }

    /** @return array<int, AdminOwnerDossierDisbursementData> */
    private function disbursements(User $owner): array
    {
        return OwnerDisbursement::query()
            ->whereHas('contract', fn (Builder $q) => $q->where('owner_id', $owner->id))
            ->with('contract.vehicle', 'creator')
            ->orderByDesc('paid_on')
            ->limit(10)
            ->get()
            ->map(fn (OwnerDisbursement $d) => new AdminOwnerDossierDisbursementData(
                AdminDisbursementData::fromModel($d), $d->vehicle_contract_id, $d->contract?->vehicle?->vehicle_number))
            ->all();
    }

    /** @return array<int, AdminOwnerDossierPaymentData> */
    private function payments(User $owner): array
    {
        return Payment::query()
            ->forOwner()
            ->whereHas('vehicleContract', fn (Builder $q) => $q->where('owner_id', $owner->id))
            ->where('payment_type', 'contract')
            ->where('status', '!=', 'cancelled')
            ->with('vehicleContract.vehicle', 'driver.user')
            ->orderByDesc('payment_date')
            ->limit(10)
            ->get()
            ->map(fn (Payment $payment) => new AdminOwnerDossierPaymentData(
                id: $payment->id,
                paymentDate: $payment->payment_date?->toDateString(),
                vehicleNumber: $payment->vehicleContract?->vehicle?->vehicle_number,
                driver: $payment->driver?->user ? AdminVehicleContractPartyData::fromUser($payment->driver->id, $payment->driver->user) : null,
                amount: (float) $payment->amount,
                status: $payment->status,
            ))
            ->all();
    }

    /** @return array<int, ActivityEntryData> */
    private function activity(User $owner): array
    {
        return Activity::query()
            ->where('subject_id', $owner->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Activity $activity) => ActivityEntryData::fromModel($activity))
            ->all();
    }
}
