<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Une période de pause : sa date de début et sa durée en jours.
 *
 * Sert aux quatre écritures qui posent ou corrigent une pause. Les contraintes
 * PROPRES à chacune — entièrement passée pour une historique, contrat actif requis —
 * vivent dans les actions, pas ici : elles dépendent de l'agent et de l'état de son
 * dossier, ce qu'une règle de validation de champ ne sait pas voir.
 */
final class LeavePeriodData extends BaseData
{
    public function __construct(
        public string $startDate,
        public int $requestedDays,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'requested_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
