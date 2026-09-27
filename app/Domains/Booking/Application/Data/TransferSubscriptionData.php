<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

/** Le corps de POST /admin/bookings/{booking}/transfer-subscription — l'identifiant d'AGENT. */
final class TransferSubscriptionData extends BaseData
{
    public function __construct(public string $driverId) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['driver_id' => ['required', 'string', 'exists:drivers,id']];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'driver_id.required' => 'Sélectionnez le nouvel agent.',
            'driver_id.exists' => "Cet agent n'existe pas.",
        ];
    }
}
