<?php

namespace App\Domains\Booking\Domain;

/**
 * Les résultats d'OpenStreetMap gardés dans les propositions (spec
 * 2026-10-27-recherche-de-lieux, §4.3) : au Bénin, dans la zone desservie, et sans les
 * commerces ni les services qui encombrent la liste.
 */
final class OsmPlaceFilter
{
    /** Les bornes de `CreatePublicBookingData`. */
    public const MIN_LAT = 6;

    public const MAX_LAT = 13;

    public const MIN_LNG = 0;

    public const MAX_LNG = 4;

    /** `boundary` : une limite administrative double la ville ou le quartier du même nom. */
    private const DROPPED_KEYS = ['shop', 'office', 'craft', 'boundary'];

    /** Les hébergements privés, au nom de leur propriétaire (essai du 2026-10-27) ; l'hôtel, repère courant, reste. */
    private const DROPPED_TOURISM = ['guest_house', 'apartment', 'chalet', 'hostel', 'motel', 'camp_site', 'caravan_site', 'information'];

    /**
     * Les habitations : une maison nommée « AKPAKI » passait devant les quartiers (essai du
     * 2026-10-27). Un bâtiment public cartographié comme tel (église, école) reste.
     */
    private const DROPPED_BUILDINGS = [
        'house', 'allotment_house', 'residential', 'detached', 'semidetached_house', 'apartments',
        'terrace', 'bungalow', 'hut', 'cabin', 'farm', 'garage', 'garages', 'shed', 'roof', 'construction',
    ];

    private const KEPT_AMENITIES = [
        'school', 'college', 'university', 'hospital', 'clinic', 'place_of_worship', 'marketplace', 'bus_station',
    ];

    /** @param  string|null  $countryCode  null quand le moteur a déjà filtré le pays (Nominatim) */
    public static function keeps(?string $countryCode, string $key, string $value, float $lat, float $lng): bool
    {
        if ($countryCode !== null && strtoupper($countryCode) !== 'BJ') {
            return false;
        }
        if (! self::inZone($lat, $lng)) {
            return false;
        }
        if (in_array($key, self::DROPPED_KEYS, true)) {
            return false;
        }
        if ($key === 'building' && in_array($value, self::DROPPED_BUILDINGS, true)) {
            return false;
        }
        if ($key === 'tourism' && in_array($value, self::DROPPED_TOURISM, true)) {
            return false;
        }

        return $key !== 'amenity' || in_array($value, self::KEPT_AMENITIES, true);
    }

    public static function inZone(float $lat, float $lng): bool
    {
        return $lat >= self::MIN_LAT && $lat <= self::MAX_LAT && $lng >= self::MIN_LNG && $lng <= self::MAX_LNG;
    }
}
