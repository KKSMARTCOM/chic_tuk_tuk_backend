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

    /** Un résultat du moteur à moins de cette distance d'un lieu populaire du même nom le répète. */
    private const DUPLICATE_METERS = 300;

    public function __construct(
        private readonly QueryPhoton $photon,
        private readonly QueryNominatim $nominatim,
    ) {}

    public function __invoke(string $query): PlaceSearchData
    {
        $popular = $this->popular($query);
        $engine = array_filter($this->engine($query), fn (PlaceSuggestionData $place) => ! $this->repeats($place, $popular));

        return new PlaceSearchData(array_slice([...$popular, ...array_values($engine)], 0, self::MAX_RESULTS));
    }

    /** @return list<PlaceSuggestionData> */
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
            ->map(fn (array $match) => new PlaceSuggestionData(
                $match['place']->name, $match['place']->landmark, $match['place']->lat, $match['place']->lng, 'popular',
            ))
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

    /** @param  list<PlaceSuggestionData>  $popular */
    private function repeats(PlaceSuggestionData $place, array $popular): bool
    {
        foreach ($popular as $known) {
            if (PlaceText::normalize($known->label) === PlaceText::normalize($place->label)
                && PlaceText::distanceMeters($known->lat, $known->lng, $place->lat, $place->lng) < self::DUPLICATE_METERS) {
                return true;
            }
        }

        return false;
    }
}
