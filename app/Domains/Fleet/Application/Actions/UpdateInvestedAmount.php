<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehicleContract;
use App\Shared\Http\ApiException;

/**
 * Corriger le montant investi d'un contrat (2026-10-14).
 *
 * Permis même quand le véhicule a un agent actif, contrairement à la modification du
 * contrat : ce montant ne sert qu'à l'affichage, aucun calcul ne le lit. Refusé sur un
 * contrat annulé, qui n'est plus montré au propriétaire.
 */
final class UpdateInvestedAmount
{
    /** @return array{0: float|null, 1: float} l'ancien et le nouveau montant */
    public function __invoke(VehicleContract $contract, float $amount): array
    {
        if ($contract->status === 'cancelled') {
            throw new ApiException(409, 'VEHICLE_CONTRACT_CANCELLED', 'Le montant investi d\'un contrat annulé ne se corrige pas.');
        }

        $previous = $contract->invested_amount !== null ? (float) $contract->invested_amount : null;
        $contract->update(['invested_amount' => $amount]);

        return [$previous, $amount];
    }
}
