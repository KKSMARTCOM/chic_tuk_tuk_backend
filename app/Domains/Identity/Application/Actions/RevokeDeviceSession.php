<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\User;
use App\Shared\Http\ApiException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Couper la session d'un autre appareil du compte — un téléphone perdu, un poste prêté.
 *
 * La session d'un autre compte ressort en 404 (`firstOrFail`), sans dire qu'elle existe.
 * La session courante est refusée : pour elle, c'est la déconnexion qui sert, et la
 * garder distincte évite qu'un geste de ménage déconnecte l'utilisateur par surprise.
 *
 * L'appareil cesse aussi d'être notifié : `fcm_tokens` part en cascade avec le jeton.
 */
final class RevokeDeviceSession
{
    public function __invoke(User $user, int $sessionId): void
    {
        $token = $user->tokens()->whereKey($sessionId)->firstOrFail();
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken && $current->is($token)) {
            throw new ApiException(
                status: 409,
                errorCode: 'SESSION_IS_CURRENT',
                message: 'C\'est la session de cet appareil : utilisez la déconnexion.',
            );
        }

        $token->delete();
    }
}
