<?php

namespace App\Domains\Booking\Application;

use App\Models\PopularPlace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Les lieux populaires actifs, lus une fois puis gardés en cache (spec
 * 2026-10-27-recherche-de-lieux, §4.4). Chaque écriture de l'admin appelle `forget()`,
 * pour qu'un lieu ajouté sorte à la recherche suivante.
 */
final class PopularPlacesCache
{
    private const KEY = 'popular-places.active';

    /** @return Collection<int, PopularPlace> */
    public static function active(): Collection
    {
        return Cache::rememberForever(self::KEY, fn () => PopularPlace::where('is_active', true)->orderBy('name')->get());
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
