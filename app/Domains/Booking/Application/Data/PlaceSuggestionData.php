<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Une proposition de la recherche de lieux (spec 2026-10-27-recherche-de-lieux, §4.1). */
final class PlaceSuggestionData extends BaseData
{
    public function __construct(
        /** Le nom en gras, recopié dans le champ quand on le choisit. */
        public string $label,
        /** La ligne grise : le repère d'un lieu populaire, ou « quartier, ville ». */
        public ?string $detail,
        public float $lat,
        public float $lng,
        #[LiteralTypeScriptType('"popular" | "osm"')]
        public string $source,
    ) {}
}
