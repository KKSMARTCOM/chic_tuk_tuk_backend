<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\PopularPlace;
use App\Shared\Data\BaseData;

/** La liste entière des lieux populaires, rendue par chaque route des Réglages : elle est petite. */
final class AdminPopularPlacesData extends BaseData
{
    public function __construct(
        /** @var AdminPopularPlaceData[] */
        public array $places,
    ) {}

    public static function current(): self
    {
        return new self(PopularPlace::orderBy('name')->get()->map(fn (PopularPlace $p) => AdminPopularPlaceData::fromModel($p))->all());
    }
}
