<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Domain\DriverContractRules;
use App\Models\DriverContract;
use App\Shared\Http\ApiException;

/** Supprimer un contrat agent — la règle, ex-`DriverContractService::delete()`, vit ci-dessous. */
final class DeleteDriverContract
{
    public function __invoke(DriverContract $contract): void
    {
        $this->deleteContract($contract);
    }

    /**
     * Supprime un contrat terminé qui n'a pas servi.
     *
     * Décidé le 2026-09-26, comme pour les contrats véhicule : un contrat qui a des pauses
     * agent ou des paiements ne se supprime plus. Leurs clés passeraient à null, et les
     * pauses sortiraient du solde de l'agent, calculé contrat par contrat.
     *
     * Des `ApiException` : le Blade les affiche en message flash comme avant.
     */
    private function deleteContract(DriverContract $contract): void
    {
        if ($contract->status === 'active') {
            throw new ApiException(
                409,
                'DRIVER_CONTRACT_ACTIVE',
                'Un contrat actif ne peut pas être supprimé. Terminez-le d\'abord.'
            );
        }

        if (DriverContractRules::hasHistory($contract)) {
            throw new ApiException(
                409,
                'DRIVER_CONTRACT_NOT_DELETABLE',
                'Impossible de supprimer ce contrat : il a des pauses ou des paiements.'
            );
        }

        $contract->delete();
    }
}
