<?php

namespace App\Domains\Notification\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le jeton d'un appareil, à l'enregistrement comme au retrait.
 *
 * La colonne est un `string` par défaut, donc 255 caractères : la borne haute fait
 * échouer un appel malformé à la validation plutôt qu'au niveau du moteur.
 *
 * ⚠️ Pas de borne BASSE. Les jetons FCM font aujourd'hui de 150 à 200 caractères, mais
 * ce format appartient à Google et rien ne le garantit : un `min:` inventé ici
 * rejetterait un jour des jetons parfaitement valides, et le symptôme serait un appareil
 * qui ne reçoit plus rien sans que personne ne sache pourquoi.
 */
final class DeviceTokenData extends BaseData
{
    public function __construct(
        public string $token,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
        ];
    }
}
