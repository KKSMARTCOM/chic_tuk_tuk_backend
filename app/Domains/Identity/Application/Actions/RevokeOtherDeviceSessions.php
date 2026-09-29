<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Couper toutes les sessions du compte sauf la courante, sans quoi l'utilisateur serait
 * déconnecté par sa propre action. Sert au bouton « Déconnecter les autres appareils »
 * comme au changement de mot de passe.
 *
 * L'effacement passe par la requête : `fcm_tokens` suit par la cascade de la base.
 */
final class RevokeOtherDeviceSessions
{
    public function __invoke(User $user): void
    {
        $current = $user->currentAccessToken();

        $user->tokens()
            ->when(
                $current instanceof PersonalAccessToken,
                fn ($query) => $query->whereKeyNot($current->getKey()),
            )
            ->delete();
    }
}
