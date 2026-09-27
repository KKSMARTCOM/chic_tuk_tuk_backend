<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;
use App\Shared\Http\ApiException;

/**
 * Supprimer un véhicule — ex-Admin\VehicleController::destroy().
 *
 * La règle, ex-`VehicleService::delete()`, vit dans `deleteVehicle()` ci-dessous.
 */
final class DeleteVehicle
{
    public function __invoke(Vehicle $vehicle): void
    {
        $this->deleteVehicle($vehicle);
    }

    /**
     * Supprimer un véhicule — décidé le 2026-09-25 : seulement s'il n'a AUCUN historique.
     *
     * Les clés étrangères sont en cascade : supprimer le véhicule effaçait ses contrats
     * véhicule, même terminés, les contrats agents qui s'y rattachent et ses pauses, et
     * détachait ses paiements de leur contrat. Seul un agent ACTIF l'empêchait. Un
     * véhicule qui a servi se désactive.
     *
     * Des `ApiException` : le Blade les affiche en message flash comme avant.
     */
    private function deleteVehicle(Vehicle $vehicle): void
    {
        $hasHistory = $vehicle->vehicleContracts()->exists() || $vehicle->driverContracts()->exists();

        if ($hasHistory) {
            throw new ApiException(
                409,
                'VEHICLE_NOT_DELETABLE',
                "Impossible de supprimer le véhicule {$vehicle->vehicle_number} : il a des contrats, en cours ou terminés. Désactivez-le à la place."
            );
        }

        $vehicle->delete();
    }
}
