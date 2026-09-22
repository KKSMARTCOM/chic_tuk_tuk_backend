<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** La date à laquelle une pause en cours s'est réellement terminée. */
final class EndLeaveData extends BaseData
{
    public function __construct(public string $endDate) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        // Pas de borne haute : un administrateur régularise souvent après coup, et une
        // date future se refuse au niveau métier, pas ici.
        return ['end_date' => ['required', 'date']];
    }
}
