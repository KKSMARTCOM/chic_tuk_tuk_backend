<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehiclePause;
use App\Shared\Data\BaseData;
use App\Domains\Fleet\Domain\Enums\VehiclePauseReason;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

final class VehiclePauseData extends BaseData
{
    public function __construct(
        public string $id,
        public string $startDate,
        public ?string $endDate,
        #[TypeScriptType(VehiclePauseReason::class)]
        public string $reasonType,
        public string $reasonLabel,
        public ?string $reasonNotes,
        public bool $isAuto,
        public ?int $daysCount,
    ) {}

    public static function fromModel(VehiclePause $pause): self
    {
        return new self(
            id: $pause->id,
            startDate: $pause->start_date->toDateString(),
            endDate: $pause->end_date?->toDateString(),
            reasonType: $pause->reason_type,
            reasonLabel: $pause->reason_label,
            reasonNotes: $pause->reason_notes,
            isAuto: (bool) $pause->is_auto,
            // ⚠️ NE PAS utiliser l'accesseur $pause->days : il omet le « + 1 » et
            // substitue now() à une date de fin absente, donc il renvoie un autre
            // nombre que celui affiché aujourd'hui. Les deux formules coexistent dans
            // le code ; celle de l'écran est figée ici.
            daysCount: $pause->end_date
                ? (int) $pause->start_date->diffInDays($pause->end_date) + 1
                : null,
        );
    }
}
