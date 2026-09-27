<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehicleContract;
use App\Shared\Http\ApiException;

/**
 * Supprimer un contrat véhicule — ex-Admin\VehicleContractController::destroy().
 *
 * La règle vivait dans `VehicleContractService::delete()`, partagée avec le Blade ;
 * déplacée ici sans changement le 2026-09-27.
 */
final class DeleteVehicleContract
{
    public function __invoke(VehicleContract $contract): void
    {
        $this->deleteContract($contract);
    }

    /**
     * Supprime un contrat, et rien d'autre.
     *
     * Décidé le 2026-09-26 : un contrat qui a un contrat agent, un paiement ou une pause
     * véhicule, même terminé, ne se supprime plus — les clés en cascade effaçaient les
     * contrats agents et les pauses, et détachaient les paiements. Et la suppression ne
     * remet plus `vehicles.owner_id` à null : le Blade le faisait même quand le véhicule
     * avait changé de propriétaire depuis. Rattacher ou retirer un véhicule se fait
     * depuis l'écran du propriétaire.
     *
     * Des `ApiException` : le Blade les affiche en message flash comme avant.
     */
    private function deleteContract(VehicleContract $contract): void
    {
        if ($contract->status === 'active') {
            throw new ApiException(
                409,
                'VEHICLE_CONTRACT_ACTIVE',
                'Impossible de supprimer un contrat actif. Passez-le à « Soldé » ou « Annulé » d\'abord.'
            );
        }

        $hasHistory = $contract->driverContracts()->exists()
            || $contract->payments()->exists()
            || $contract->pauses()->exists();

        if ($hasHistory) {
            throw new ApiException(
                409,
                'VEHICLE_CONTRACT_NOT_DELETABLE',
                'Impossible de supprimer ce contrat : il a des agents, des paiements ou des pauses véhicule, en cours ou terminés.'
            );
        }

        $contract->delete();
    }
}
