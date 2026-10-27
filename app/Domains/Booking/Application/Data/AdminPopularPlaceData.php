<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\PopularPlace;
use App\Shared\Data\BaseData;

/** Un lieu populaire, côté Réglages (spec 2026-10-27-recherche-de-lieux, §5). */
final class AdminPopularPlaceData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $landmark,
        /** @var string[] */
        public array $aliases,
        public float $lat,
        public float $lng,
        public bool $isActive,
    ) {}

    public static function fromModel(PopularPlace $place): self
    {
        return new self($place->id, $place->name, $place->landmark, $place->aliases, $place->lat, $place->lng, $place->is_active);
    }
}
