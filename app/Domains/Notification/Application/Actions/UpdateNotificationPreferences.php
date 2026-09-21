<?php

namespace App\Domains\Notification\Application\Actions;

use App\Domains\Notification\Application\Data\UpdatePreferencesData;
use App\Models\User;
use Spatie\LaravelData\Optional;

/**
 * Modifier les préférences de notification, champ par champ.
 *
 * ⚠️ Fusion, pas remplacement. Le tableau existant est repris et seuls les champs
 * réellement transmis sont écrasés — un `Optional` non fourni laisse l'autre réglage
 * intact. Remplacer le tableau entier ferait disparaître la préférence e-mail dès qu'on
 * touche à l'interrupteur push, et le symptôme ne se verrait qu'au rechargement suivant.
 *
 * On conserve aussi les clés inconnues : le Blade peut en ajouter, et cette action ne
 * doit pas devenir l'endroit où elles se perdent.
 */
final class UpdateNotificationPreferences
{
    public function __invoke(User $user, UpdatePreferencesData $data): User
    {
        $preferences = $user->notification_preferences ?? [];

        if (! $data->pushNotifications instanceof Optional) {
            $preferences['push_notifications'] = $data->pushNotifications;
        }

        if (! $data->emailNotifications instanceof Optional) {
            $preferences['email_notifications'] = $data->emailNotifications;
        }

        $user->update(['notification_preferences' => $preferences]);

        return $user->refresh();
    }
}
