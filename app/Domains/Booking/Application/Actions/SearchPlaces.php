<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\PlaceSearchData;
use App\Domains\Booking\Application\Data\PlaceSuggestionData;
use App\Domains\Booking\Application\PopularPlacesCache;
use App\Domains\Booking\Domain\PlaceText;
use App\Models\PopularPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La recherche de lieux pendant la frappe (spec 2026-10-27-recherche-de-lieux, §4.2 à
 * §4.4) : les lieux populaires d'abord, puis Photon — ou Nominatim quand Photon ne
 * répond pas. Elle ne lève jamais : sans moteur, les lieux populaires sortent seuls.
 */
final class SearchPlaces
{
    private const MAX_RESULTS = 8;

    private const MAX_POPULAR = 5;

    private const PHOTON_CACHE_HOURS = 6;

    /**
     * Nominatim interdit l'autocomplétion au-delà d'une requête par seconde, et le repli
     * part de la seule IP du serveur (relecture finale du 2026-10-27) : au-delà, les lieux
     * populaires sortent seuls.
     */
    private const NOMINATIM_PER_SECOND = 1;

    /**
     * Photon pèse peu la distance : « Togbin » sortait Togbin (Zogbodomey) avant ceux
     * d'Abomey-Calavi (essai du 2026-10-27). Les lieux à moins de 25 km du centre de
     * Cotonou — Calavi et Godomey compris — passent devant, dans l'ordre de Photon.
     */
    private const NEAR_COTONOU_METERS = 25_000;

    /** Même nom et même ligne grise : le même lieu sous deux objets OSM (la ville et le bourg d'Abomey-Calavi). */
    private const SAME_DETAIL_DUPLICATE_METERS = 3_000;

    /** Un résultat du moteur à moins de cette distance d'un lieu populaire du même nom le répète. */
    private const DUPLICATE_METERS = 300;

    public function __construct(
        private readonly QueryPhoton $photon,
        private readonly QueryNominatim $nominatim,
    ) {}

    public function __invoke(string $query): PlaceSearchData
    {
        $popular = $this->popular($query);
        $engine = array_filter($this->nearFirst($this->distinct($this->engine($query))), fn (PlaceSuggestionData $place) => ! $this->repeats($place, $popular));

        return new PlaceSearchData(array_slice([...array_column($popular, 'suggestion'), ...array_values($engine)], 0, self::MAX_RESULTS));
    }

    /**
     * Les lieux populaires retenus, avec tous leurs noms : un résultat du moteur qui
     * reprend un AUTRE nom du lieu le répète aussi (relecture finale).
     *
     * @return list<array{suggestion: PlaceSuggestionData, names: list<string>}>
     */
    private function popular(string $query): array
    {
        return PopularPlacesCache::active()
            ->map(function (PopularPlace $place) use ($query) {
                $ranks = array_filter(array_map(
                    fn (string $name) => PlaceText::matchRank($query, $name),
                    [$place->name, ...$place->aliases],
                ), fn ($rank) => $rank !== null);

                return $ranks ? ['rank' => min($ranks), 'place' => $place] : null;
            })
            ->filter()
            ->sortBy([['rank', 'asc'], fn ($a, $b) => strcmp(PlaceText::normalize($a['place']->name), PlaceText::normalize($b['place']->name))])
            ->take(self::MAX_POPULAR)
            ->map(fn (array $match) => [
                'suggestion' => new PlaceSuggestionData(
                    $match['place']->name, $match['place']->landmark, $match['place']->lat, $match['place']->lng, 'popular',
                ),
                'names' => array_map([PlaceText::class, 'normalize'], [$match['place']->name, ...$match['place']->aliases]),
            ])
            ->values()
            ->all();
    }

    /** @return list<PlaceSuggestionData> */
    private function engine(string $query): array
    {
        $key = 'places.photon.'.sha1(PlaceText::normalize($query));
        // Des tableaux en cache, jamais des objets : le cache `database` désérialise.
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return array_map(fn (array $place) => PlaceSuggestionData::from($place), $cached);
        }

        try {
            $places = ($this->photon)($query);
            Cache::put($key, array_map(fn (PlaceSuggestionData $place) => $place->toArray(), $places), now()->addHours(self::PHOTON_CACHE_HOURS));

            return $places;
        } catch (\Throwable $e) {
            // \Throwable : une forme de réponse inattendue lève une TypeError, pas une
            // RuntimeException, et ne doit pas emporter les lieux populaires.
            Log::warning('Recherche de lieux : Photon en échec, repli sur Nominatim. '.$e->getMessage());
        }

        $fallback = [];
        $allowed = RateLimiter::attempt('places-nominatim-fallback', self::NOMINATIM_PER_SECOND, function () use ($query, &$fallback) {
            try {
                $fallback = ($this->nominatim)($query);
            } catch (\Throwable $e) {
                Log::warning('Recherche de lieux : Nominatim en échec aussi, lieux populaires seuls. '.$e->getMessage());
            }
        }, 1);
        if (! $allowed) {
            Log::warning('Recherche de lieux : repli Nominatim limité à une requête par seconde, lieux populaires seuls.');
        }

        return $fallback;
    }

    /**
     * Photon rend parfois deux fois le même lieu (deux objets OSM) : même nom à moins de
     * 300 m, ou même nom et même ligne grise à moins de 3 km.
     *
     * @param  list<PlaceSuggestionData>  $places
     * @return list<PlaceSuggestionData>
     */
    private function distinct(array $places): array
    {
        $kept = [];
        foreach ($places as $place) {
            foreach ($kept as $other) {
                if (PlaceText::normalize($other->label) !== PlaceText::normalize($place->label)) {
                    continue;
                }
                $meters = PlaceText::distanceMeters($other->lat, $other->lng, $place->lat, $place->lng);
                $sameDetail = PlaceText::normalize((string) $other->detail) === PlaceText::normalize((string) $place->detail);
                if ($meters < self::DUPLICATE_METERS || ($sameDetail && $meters < self::SAME_DETAIL_DUPLICATE_METERS)) {
                    continue 2;
                }
            }
            $kept[] = $place;
        }

        return $kept;
    }

    /**
     * @param  list<PlaceSuggestionData>  $places
     * @return list<PlaceSuggestionData>
     */
    private function nearFirst(array $places): array
    {
        $isNear = fn (PlaceSuggestionData $place) => PlaceText::distanceMeters(
            (float) QueryPhoton::COTONOU_LAT, (float) QueryPhoton::COTONOU_LNG, $place->lat, $place->lng,
        ) < self::NEAR_COTONOU_METERS;

        return [...array_values(array_filter($places, $isNear)), ...array_values(array_filter($places, fn ($p) => ! $isNear($p)))];
    }

    /** @param  list<array{suggestion: PlaceSuggestionData, names: list<string>}>  $popular */
    private function repeats(PlaceSuggestionData $place, array $popular): bool
    {
        foreach ($popular as $known) {
            if (in_array(PlaceText::normalize($place->label), $known['names'], true)
                && PlaceText::distanceMeters($known['suggestion']->lat, $known['suggestion']->lng, $place->lat, $place->lng) < self::DUPLICATE_METERS) {
                return true;
            }
        }

        return false;
    }
}
