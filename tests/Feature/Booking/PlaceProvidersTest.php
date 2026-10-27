<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\QueryNominatim;
use App\Domains\Booking\Application\Actions\QueryPhoton;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Photon et Nominatim (spec 2026-10-27-recherche-de-lieux, §4.3 et §4.4). */
class PlaceProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Un résultat Photon : `geometry.coordinates` est [lng, lat]. */
    public static function feature(?string $name = null, string $key = 'place', string $value = 'suburb', array $props = [], float $lat = 6.37, float $lng = 2.45): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'properties' => array_filter([
                'name' => $name, 'osm_key' => $key, 'osm_value' => $value, 'countrycode' => 'BJ',
                'city' => 'Cotonou', 'state' => 'Littoral', ...$props,
            ], fn ($v) => $v !== null),
        ];
    }

    private function photon(array $features): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['type' => 'FeatureCollection', 'features' => $features])]);
    }

    public function test_photon_is_called_with_the_cotonou_bias(): void
    {
        $this->photon([]);

        app(QueryPhoton::class)('Akpak');

        Http::assertSent(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://photon.komoot.io/api/')
                && $query == ['q' => 'Akpak', 'lat' => '6.3703', 'lon' => '2.3912', 'lang' => 'fr', 'limit' => '15']
                && $request->header('User-Agent')[0] !== '';
        });
    }

    public function test_photon_keeps_places_schools_and_markets(): void
    {
        $this->photon([
            self::feature('Akpakpa'),
            self::feature('Complexe scolaire Les Cracks', 'amenity', 'school'),
            self::feature('Marché Dantokpa', 'amenity', 'marketplace'),
        ]);

        $places = app(QueryPhoton::class)('a');

        $this->assertSame(['Akpakpa', 'Complexe scolaire Les Cracks', 'Marché Dantokpa'], array_map(fn ($p) => $p->label, $places));
        $this->assertSame('osm', $places[0]->source);
        $this->assertSame(6.37, $places[0]->lat);
        $this->assertSame(2.45, $places[0]->lng);
    }

    public function test_photon_drops_shops_foreign_out_of_zone_and_unnamed(): void
    {
        $this->photon([
            self::feature('Erevan', 'shop', 'supermarket'),
            self::feature('Chez Maman', 'amenity', 'restaurant'),
            self::feature('Bureau', 'office', 'company'),
            self::feature('Lomé', props: ['countrycode' => 'TG']),
            self::feature('Loin', lat: 14.0),
            self::feature(null, 'highway', 'residential'),
            self::feature('Gare routière', 'amenity', 'bus_station'),
        ]);

        $this->assertSame(['Gare routière'], array_map(fn ($p) => $p->label, app(QueryPhoton::class)('a')));
    }

    /** Essai du 2026-10-27 : « Akpak » proposait d'abord deux maisons nommées « AKPAKI ». */
    public function test_photon_drops_dwellings_but_keeps_named_public_buildings(): void
    {
        $this->photon([
            self::feature('AKPAKI', 'building', 'house'),
            self::feature('AKPAKI', 'building', 'allotment_house'),
            self::feature('Résidence', 'building', 'apartments'),
            self::feature('Église Saint-Michel', 'building', 'church'),
        ]);

        $this->assertSame(['Église Saint-Michel'], array_map(fn ($p) => $p->label, app(QueryPhoton::class)('a')));
    }

    /** Essai du 2026-10-27 : « Calav » proposait cinq maisons d'hôtes au nom de leur propriétaire. */
    public function test_photon_drops_guest_houses_and_boundaries_but_keeps_hotels(): void
    {
        $this->photon([
            self::feature('CALAVI SEME LEONIE', 'tourism', 'guest_house'),
            self::feature('Abomey-Calavi', 'boundary', 'administrative'),
            self::feature('Hôtel du Lac', 'tourism', 'hotel'),
        ]);

        $this->assertSame(['Hôtel du Lac'], array_map(fn ($p) => $p->label, app(QueryPhoton::class)('a')));
    }

    public function test_photon_detail_line(): void
    {
        $this->photon([
            self::feature('Akpakpa', props: ['district' => 'Akpakpa']),
            self::feature('Godomey', props: ['city' => null, 'county' => 'Abomey-Calavi', 'state' => 'Atlantique']),
            self::feature('Cotonou', props: ['city' => null, 'state' => null]),
        ]);

        $places = app(QueryPhoton::class)('a');

        $this->assertSame('Cotonou, Littoral', $places[0]->detail);
        $this->assertSame('Abomey-Calavi, Atlantique', $places[1]->detail);
        $this->assertNull($places[2]->detail);
    }

    public function test_photon_failures_throw(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::sequence()
            ->push('erreur', 500)
            ->push('<html>Ralentissez</html>', 200)
            ->push(['type' => 'FeatureCollection'], 200)
            ->pushFailedConnection()]);

        foreach (range(1, 4) as $case) {
            try {
                app(QueryPhoton::class)('Akpak');
                $this->fail("Le cas {$case} aurait dû lever.");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nominatim_reads_display_name(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Akpakpa, Cotonou, Littoral, Bénin', 'lat' => '6.37', 'lon' => '2.45', 'class' => 'place', 'type' => 'suburb'],
            ['display_name' => 'Erevan, Cotonou, Bénin', 'lat' => '6.36', 'lon' => '2.42', 'class' => 'shop', 'type' => 'supermarket'],
            ['display_name' => 'Bénin', 'lat' => '9.5', 'lon' => '2.25', 'class' => 'place', 'type' => 'country'],
        ])]);

        $places = app(QueryNominatim::class)('Akpakpa');

        $this->assertCount(2, $places);
        $this->assertSame('Akpakpa', $places[0]->label);
        $this->assertSame('Cotonou, Littoral', $places[0]->detail);
        $this->assertSame(6.37, $places[0]->lat);
        $this->assertNull($places[1]->detail);
        Http::assertSent(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query == ['q' => 'Akpakpa', 'countrycodes' => 'bj', 'format' => 'json', 'limit' => '8'];
        });
    }

    public function test_nominatim_failures_throw(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('<html>', 200)]);

        $this->expectException(\RuntimeException::class);
        app(QueryNominatim::class)('Akpakpa');
    }
}
