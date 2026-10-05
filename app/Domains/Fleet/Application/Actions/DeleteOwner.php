<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\User;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\Auth;

/**
 * Supprimer un propriétaire — ex-Admin\UserController::destroy(), que la liste Blade
 * des propriétaires appelait.
 *
 * Refus (`OWNER_NOT_DELETABLE`) dès qu'il a un véhicule ou un contrat véhicule. La règle
 * vivait dans `UserService::delete()`, partagée avec le Blade ; déplacée ici sans
 * changement le 2026-09-27.
 */
final class DeleteOwner
{
    public function __invoke(User $owner): void
    {
        // Empêcher la suppression de son propre compte
        if ($owner->id === Auth::id()) {
            throw new \Exception('Vous ne pouvez pas supprimer votre propre compte.');
        }

        // Un propriétaire qui a un véhicule ou un contrat véhicule, même terminé, ne se
        // supprime pas (décidé le 2026-09-25). Les clés étrangères sont en cascade : la
        // suppression emportait ses véhicules, leurs contrats terminés, les contrats
        // agents et les pauses véhicule. Seul un contrat ACTIF l'empêchait jusque-là.
        if ($owner->profil === 'owner' || $owner->hasRole('proprietaire')) {
            $hasFleet = $owner->vehicles()->exists()
                || VehicleContract::where('owner_id', $owner->id)->exists();

            if ($hasFleet) {
                throw new ApiException(
                    409,
                    'OWNER_NOT_DELETABLE',
                    'Impossible de supprimer ce propriétaire : il a des véhicules ou des contrats propriétaires. Désactivez son compte à la place.'
                );
            }
        }

        $owner->delete();
    }
}
