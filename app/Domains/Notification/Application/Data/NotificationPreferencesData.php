<?php

namespace App\Domains\Notification\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Les préférences de notification d'un compte.
 *
 * ⚠️ **L'absence vaut ACCORD.** `users.notification_preferences` vaut `{}` pour tous les
 * comptes existants — l'écran de réglages du Blade n'a jamais été ouvert par grand
 * monde — et lire l'absence comme un refus afficherait des interrupteurs éteints à des
 * gens qui reçoivent bel et bien des notifications, puis les couperait pour de bon au
 * premier enregistrement. Seul un `false` explicite désactive.
 *
 * C'est la même règle que celle appliquée à l'envoi, dans `FcmNotificationService` :
 * les deux doivent rester d'accord, sans quoi l'écran mentirait.
 */
final class NotificationPreferencesData extends BaseData
{
    public function __construct(
        /** Notifications système, envoyées par FCM sur les appareils enregistrés. */
        public bool $pushNotifications,
        /**
         * Notifications par e-mail.
         *
         * ⚠️ Aucun envoi d'e-mail de notification n'existe aujourd'hui : la préférence
         * est stockée et respectée d'avance, mais elle ne gouverne rien encore. Ne pas
         * la retirer de l'écran pour autant — elle existe dans le Blade, et la faire
         * disparaître ferait croire à une perte de réglage.
         */
        public bool $emailNotifications,
    ) {}

    public static function fromUser(User $user): self
    {
        $preferences = $user->notification_preferences ?? [];

        return new self(
            pushNotifications: ($preferences['push_notifications'] ?? true) !== false,
            emailNotifications: ($preferences['email_notifications'] ?? true) !== false,
        );
    }
}
