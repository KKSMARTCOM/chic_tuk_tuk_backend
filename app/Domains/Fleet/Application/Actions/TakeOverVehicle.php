<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Driver;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Un agent sous contrat prend le véhicule (spec 2026-10-09, §4) : l'affectation interne en
 * cours se termine la VEILLE, et un contrat propriétaire en attente commence ce jour-là.
 *
 * ⚠️ À appeler dans la transaction qui crée le contrat agent, avant sa création : un refus
 * ici annule tout.
 */
final class TakeOverVehicle
{
    public function __construct(private readonly EndInternalAssignment $endAssignment) {}

    public function __invoke(VehicleContract $contract, string $driverStartDate, Driver $driver): TakeOverResult
    {
        $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
        $start = Carbon::parse($driverStartDate)->startOfDay();

        $ended = null;
        $assignment = $contract->activeInternalAssignment;
        if ($assignment) {
            if ($start->lte($assignment->start_date->copy()->startOfDay())) {
                throw ValidationException::withMessages(['start_date' => 'Le contrat doit commencer après le début de l\'affectation interne en cours ('.$assignment->start_date->format('d/m/Y').').']);
            }
            $ended = ($this->endAssignment)($assignment, $start->copy()->subDay()->toDateString(), 'driver_contract', null);
        }

        $activated = false;
        if ($contract->status === 'pending') {
            $contract->update(['status' => 'active', 'start_date' => $start->toDateString()]);
            $activated = true;
        }

        return new TakeOverResult($ended, $activated);
    }
}
