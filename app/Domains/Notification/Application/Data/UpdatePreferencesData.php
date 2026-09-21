<?php

namespace App\Domains\Notification\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Optional;

/**
 * La modification des préférences — un PATCH, pas un PUT.
 *
 * ⚠️ Les deux champs sont `Optional` et non `?bool`. La différence est décisive :
 * `null` signifierait « mets ce réglage à rien », alors qu'un champ ABSENT doit laisser
 * l'autre réglage intact. L'écran envoie l'interrupteur qui vient de bouger, pas les
 * deux, et un PUT écraserait silencieusement celui qu'il n'a pas envoyé.
 */
final class UpdatePreferencesData extends BaseData
{
    public function __construct(
        public bool|Optional $pushNotifications,
        public bool|Optional $emailNotifications,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'push_notifications' => ['sometimes', 'boolean'],
            'email_notifications' => ['sometimes', 'boolean'],
        ];
    }
}
