<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\PlaceSuggestionData;
use App\Domains\Booking\Domain\OsmPlaceFilter;

/**
 * Le repli de la recherche de lieux quand Photon ne répond pas (spec
 * 2026-10-27-recherche-de-lieux, §4.4) : la recherche que le landing faisait lui-même.
 * Lève `RuntimeException` sur tout échec.
 */
final class QueryNominatim
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    /** @return list<PlaceSuggestionData> */
    public function __invoke(string $query): array
    {
        $results = PlacesHttp::getJson(self::URL, [
            'q' => $query,
            'countrycodes' => 'bj',
            'format' => 'json',
            'limit' => 8,
        ], 'Nominatim');

        if (! array_is_list($results)) {
            throw new \RuntimeException('Nominatim : réponse illisible.');
        }

        $places = [];
        foreach ($results as $result) {
            $lat = (float) ($result['lat'] ?? 0);
            $lng = (float) ($result['lon'] ?? 0);
            // « Akpakpa, Cotonou, Littoral, Bénin » : le nom, puis le détail sans le pays.
            $segments = array_values(array_filter(array_map('trim', explode(',', (string) ($result['display_name'] ?? ''))), fn ($s) => $s !== ''));

            if (! $segments || ! OsmPlaceFilter::keeps(null, (string) ($result['class'] ?? ''), (string) ($result['type'] ?? ''), $lat, $lng)) {
                continue;
            }

            $places[] = new PlaceSuggestionData($segments[0], QueryPhoton::detail($segments[0], array_slice($segments, 1, -1)), $lat, $lng, 'osm');
        }

        return $places;
    }
}
