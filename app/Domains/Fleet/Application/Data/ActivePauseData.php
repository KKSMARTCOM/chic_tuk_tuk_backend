<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehiclePause;
use App\Shared\Data\BaseData;
use App\Domains\Fleet\Domain\Enums\VehiclePauseReason;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * La pause en cours d'un véhicule, telle que le bandeau de la fiche l'affiche.
 *
 * `reasonLabel` vient de l'accesseur du modèle, qui rend « Pause agent » là où la vue
 * Blade bricolait `ucfirst(str_replace('_', ' ', $reason_type))` et affichait donc
 * « Agent leave » à des utilisateurs francophones. Seule entorse assumée à la règle de
 * transposition à l'identique.
 */
final class ActivePauseData extends BaseData
{
    public function __construct(
        public string $startDate,
        #[TypeScriptType(VehiclePauseReason::class)]
        public string $reasonType,
        public string $reasonLabel,
        public ?string $reasonNotes,
        public bool $isAuto,
    ) {}

    public static function fromModel(VehiclePause $pause): self
    {
        return new self(
            startDate: $pause->start_date->toDateString(),
            reasonType: $pause->reason_type,
            reasonLabel: $pause->reason_label,
            reasonNotes: $pause->reason_notes,
            isAuto: (bool) $pause->is_auto,
        );
    }
}
