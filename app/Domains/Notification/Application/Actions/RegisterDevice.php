<?php

namespace App\Domains\Notification\Application\Actions;

use App\Models\FcmToken;
use App\Models\User;

/**
 * Enregistrer l'appareil courant pour les notifications push.
 *
 * ⚠️ `updateOrCreate` sur le JETON, jamais sur le couple (utilisateur, jeton). La
 * colonne `token` est UNIQUE, et le cas réel est qu'un même téléphone serve à deux
 * comptes successifs : l'agent se déconnecte, un autre se connecte, FCM rend le même
 * jeton. Une insertion violerait la contrainte ; une recherche par utilisateur créerait
 * une seconde ligne impossible. La ligne doit être RÉATTRIBUÉE, faute de quoi l'ancien
 * propriétaire continuerait de recevoir les notifications du nouveau.
 */
final class RegisterDevice
{
    public function __invoke(User $user, string $token): void
    {
        FcmToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $user->id],
        );
    }
}
