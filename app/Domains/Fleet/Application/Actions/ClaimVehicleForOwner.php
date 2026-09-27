<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;

/**
 * Rattache un véhicule existant au propriétaire.
 *
 * Déplacé de `OwnerService::claimVehicle()` le 2026-09-27, sans changement : la création
 * et l'édition d'un propriétaire le partagent.
 *
 * Règle décidée le 2026-09-25, la même à la création et à l'édition : un véhicule
 * qui appartient déjà à un autre propriétaire ne change de mains que si
 * l'administrateur a CONFIRMÉ le transfert. Auparavant, la création le transférait
 * en silence et l'édition le refusait.
 *
 * Un véhicule sous contrat propriétaire-véhicule en cours ne se transfère jamais :
 * le contrat lie son propriétaire actuel.
 *
 * Des `ApiException` : elles héritent d'`Exception`, le Blade les affiche donc en
 * message flash comme avant. Il n'envoie jamais `confirm_transfer`, et refuse
 * désormais le transfert dans les deux écrans.
 */
final class ClaimVehicleForOwner
{
    public function __invoke(Vehicle $vehicle, User $owner, bool $transferConfirmed): void
    {
        if ($vehicle->owner_id === $owner->id) {
            return;
        }

        $runningContract = $vehicle->activeVehicleContract;
        if ($runningContract !== null) {
            throw new ApiException(
                409,
                'VEHICLE_UNDER_CONTRACT',
                "Le véhicule {$vehicle->vehicle_number} est sous contrat avec son propriétaire actuel : il ne peut pas être transféré.",
                ['vehicle_number' => $vehicle->vehicle_number],
            );
        }

        if ($vehicle->owner_id !== null && ! $transferConfirmed) {
            $currentOwnerName = $vehicle->owner?->name;

            throw new ApiException(
                409,
                'VEHICLE_TRANSFER_UNCONFIRMED',
                "Le véhicule {$vehicle->vehicle_number} appartient déjà à {$currentOwnerName}. Confirmez le transfert pour le lui retirer.",
                [
                    'vehicle_number' => $vehicle->vehicle_number,
                    'current_owner_name' => $currentOwnerName,
                ],
            );
        }

        $vehicle->update(['owner_id' => $owner->id]);
    }
}
