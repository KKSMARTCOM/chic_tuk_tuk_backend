<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le corps de POST /admin/bookings/{booking}/assign-driver.
 *
 * ⚠️ `exists:drivers,id` et non `exists:users,id` : la route prend l'identifiant de
 * l'AGENT. Le JavaScript du Blade envoie parfois celui du compte, faute de relation
 * chargée, et l'erreur ne se voit qu'au message « Le Agent sélectionné n'existe pas ».
 */
final class AssignDriverData extends BaseData
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
        return ['driver_id.exists' => 'L\'agent sélectionné n\'existe pas.'];
    }
}
