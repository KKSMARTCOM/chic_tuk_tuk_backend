<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\InternalAssignmentRules;
use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Affecter un agent en interne (spec 2026-10-09, §3.2). Aucun paiement n'en découle. */
final class AssignInternalDriver
{
    public function __invoke(VehicleContract $contract, Driver $driver, string $startDate, ?string $notes, ?User $by): InternalAssignment
    {
        return DB::transaction(function () use ($contract, $driver, $startDate, $notes, $by) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);

            InternalAssignmentRules::assertContractOpen($contract);
            InternalAssignmentRules::assertVehicleFree($contract);
            InternalAssignmentRules::assertDriverFree($driver);

            $start = Carbon::parse($startDate)->startOfDay();
            if ($contract->start_date !== null && $start->lt($contract->start_date->copy()->startOfDay())) {
                throw ValidationException::withMessages(['start_date' => 'L\'affectation ne peut pas commencer avant le contrat propriétaire ('.$contract->start_date->format('d/m/Y').').']);
            }

            return InternalAssignment::create([
                'vehicle_contract_id' => $contract->id,
                'driver_id' => $driver->id,
                'start_date' => $start->toDateString(),
                'notes' => $notes,
                'created_by' => $by?->id,
            ]);
        });
    }
}
