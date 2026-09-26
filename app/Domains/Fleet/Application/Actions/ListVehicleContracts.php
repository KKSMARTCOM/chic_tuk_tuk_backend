<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminAvailableVehicleData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractListItemData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPageData;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * La liste des contrats propriétaire-véhicule — ex-Admin\VehicleContractController::index().
 *
 * Le Blade appelait `getStats()` pour chaque contrat, soit deux requêtes par ligne ; le
 * payé et les compteurs d'historique viennent ici de sous-requêtes.
 */
final class ListVehicleContracts
{
    public function __invoke(): AdminVehicleContractPageData
    {
        return new AdminVehicleContractPageData(
            contracts: self::query()
                ->latest()
                ->get()
                ->map(fn (VehicleContract $contract) => AdminVehicleContractListItemData::fromModel($contract))
                ->all(),
            // Comme le Blade : actifs et sans contrat en cours. Sans propriétaire en plus,
            // puisqu'un contrat est au nom du propriétaire du véhicule.
            availableVehicles: Vehicle::query()
                ->with('owner')
                ->where('is_active', true)
                ->whereNotNull('owner_id')
                ->whereDoesntHave('activeVehicleContract')
                ->orderBy('vehicle_number')
                ->get()
                ->map(fn (Vehicle $vehicle) => AdminAvailableVehicleData::fromModel($vehicle))
                ->all(),
        );
    }

    /** Ce qu'attend `AdminVehicleContractListItemData::fromModel()`. */
    public static function query(): Builder
    {
        return VehicleContract::query()
            ->with(['owner', 'vehicle.activeDriverContract'])
            ->withSum(['payments as completed_paid' => fn ($query) => $query->where('status', 'completed')], 'net_amount')
            ->withCount(['driverContracts', 'payments', 'pauses']);
    }
}
