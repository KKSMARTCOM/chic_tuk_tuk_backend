<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\PlaceSuggestionData;
use App\Domains\Booking\Domain\OsmPlaceFilter;
use App\Domains\Booking\Domain\PlaceText;

/**
 * Les propositions de Photon pendant la frappe (spec 2026-10-27-recherche-de-lieux,
 * §4.3), classées près du centre de Cotonou. Lève `RuntimeException` sur tout échec :
 * `SearchPlaces` retombe alors sur Nominatim.
 */
final class QueryPhoton
{
    private const COTONOU_LAT = '6.3703';

    private const COTONOU_LNG = '2.3912';

    /** @return list<PlaceSuggestionData> */
    public function __invoke(string $query): array
    {
        $features = PlacesHttp::getJson(config('services.photon.url'), [
            'q' => $query,
            'lat' => self::COTONOU_LAT,
            'lon' => self::COTONOU_LNG,
            'lang' => 'fr',
            'limit' => 15,
        ], 'Photon')['features'] ?? null;

        if (! is_array($features)) {
            throw new \RuntimeException('Photon : réponse sans « features ».');
        }

        $places = [];
        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];
            [$lng, $lat] = array_map('floatval', $feature['geometry']['coordinates'] ?? [0, 0]);
            $name = trim((string) ($p['name'] ?? ''));

            if ($name === '' || ! OsmPlaceFilter::keeps($p['countrycode'] ?? '', (string) ($p['osm_key'] ?? ''), (string) ($p['osm_value'] ?? ''), $lat, $lng)) {
                continue;
            }

            $places[] = new PlaceSuggestionData($name, self::detail($name, [
                $p['district'] ?? null, ($p['city'] ?? null) ?: ($p['county'] ?? null), $p['state'] ?? null,
            ]), $lat, $lng, 'osm');
        }

        return $places;
    }

    /** @param  list<string|null>  $parts  les valeurs non vides, sans répéter le nom */
    public static function detail(string $label, array $parts): ?string
    {
        $seen = [PlaceText::normalize($label)];
        $kept = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ! in_array(PlaceText::normalize($part), $seen, true)) {
                $seen[] = PlaceText::normalize($part);
                $kept[] = $part;
            }
        }

        return $kept ? implode(', ', $kept) : null;
    }
}
