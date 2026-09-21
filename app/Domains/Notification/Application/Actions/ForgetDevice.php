<?php

namespace App\Domains\Notification\Application\Actions;

use App\Models\FcmToken;
use App\Models\User;

/**
 * Oublier un appareil — à la déconnexion, ou quand la permission est retirée.
 *
 * ⚠️ La suppression est bornée à l'utilisateur courant. Sans cette borne, n'importe
 * quel compte authentifié pourrait faire taire le téléphone d'un autre en rejouant un
 * jeton vu passer. C'est une porte discrète mais réelle : le jeton n'est pas un secret
 * côté client, il transite en clair dans le code de la page.
 *
 * Un jeton absent ou appartenant à autrui ne lève pas : la déconnexion ne doit jamais
 * échouer pour cette raison, et distinguer les deux cas révélerait l'existence du jeton.
 */
final class ForgetDevice
{
    public function __invoke(User $user, string $token): void
    {
        FcmToken::where('user_id', $user->id)
            ->where('token', $token)
            ->delete();
    }
}
