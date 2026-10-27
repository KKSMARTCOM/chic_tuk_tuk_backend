<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

/** La réponse de `GET /public/places` (spec 2026-10-27-recherche-de-lieux, §4.1). */
final class PlaceSearchData extends BaseData
{
    public const ATTRIBUTION = '© contributeurs OpenStreetMap';

    public function __construct(
        /** @var PlaceSuggestionData[] */
        public array $places,
        public string $attribution = self::ATTRIBUTION,
    ) {}
}
