<?php

namespace App\Domains\Booking\Domain;

use Illuminate\Support\Str;

/**
 * La comparaison des noms de lieux (spec 2026-10-27-recherche-de-lieux, §4.2) : sans
 * casse ni accents, mot par mot — « crac » trouve « Complexe scolaire Les Cracks », et
 * « Fidjrosse » trouve « Fidjrossè ».
 */
final class PlaceText
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))));
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** 0 : le nom commence par la saisie ; 1 : un de ses mots ; null : aucun. */
    public static function matchRank(string $query, string $candidate): ?int
    {
        $query = implode(' ', self::words($query));
        if ($query === '') {
            return null;
        }

        $words = self::words($candidate);
        if (str_starts_with(implode(' ', $words), $query)) {
            return 0;
        }
        // Une saisie de plusieurs mots peut commencer au milieu du nom (« les crac »).
        foreach (array_keys($words) as $i) {
            if (str_starts_with(implode(' ', array_slice($words, $i)), $query)) {
                return 1;
            }
        }

        return null;
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1, sqrt($a)));
    }
}
