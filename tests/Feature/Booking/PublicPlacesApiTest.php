<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\PopularPlacesCache;
use App\Models\PopularPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** La recherche publique des lieux (spec 2026-10-27-recherche-de-lieux, §4). */
class PublicPlacesApiTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, array $attributes = []): PopularPlace
    {
        return PopularPlace::create(['name' => $name, 'lat' => 6.36, 'lng' => 2.40, ...$attributes]);
    }

    /** @param  list<array>  $features */
    private function photon(array $features = [], int $status = 200): void
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response(['type' => 'FeatureCollection', 'features' => $features], $status),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);
    }

    private function search(string $q)
    {
        return $this->getJson('/api/v1/public/places?q='.urlencode($q));
    }

    private function labels($response): array
    {
        return array_column($response->json('places'), 'label');
    }

    public function test_short_or_missing_query_returns_an_empty_list_without_calling_anyone(): void
    {
        Http::fake();

        foreach (['/api/v1/public/places', '/api/v1/public/places?q=a', '/api/v1/public/places?q=%20%20a%20'] as $url) {
            $this->getJson($url)->assertOk()->assertExactJson(['places' => [], 'attribution' => '© contributeurs OpenStreetMap']);
        }
        Http::assertNothingSent();
    }

    public function test_a_too_long_query_is_refused(): void
    {
        Http::fake();

        $this->search(str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_popular_places_come_first_start_before_middle(): void
    {
        $this->place('Complexe scolaire Les Cracks', ['landmark' => 'Fidjrossè, Cotonou']);
        $this->place('Les Cracks');
        $this->photon([PlaceProvidersTest::feature('Lesbos')]);

        $response = $this->search('les')->assertOk();

        $this->assertSame(['Les Cracks', 'Complexe scolaire Les Cracks', 'Lesbos'], $this->labels($response));
        $this->assertSame(['popular', 'popular', 'osm'], array_column($response->json('places'), 'source'));
        $this->assertSame('Fidjrossè, Cotonou', $response->json('places.1.detail'));
        $this->assertSame('© contributeurs OpenStreetMap', $response->json('attribution'));
    }

    public function test_at_most_five_popular_places_and_eight_in_all(): void
    {
        foreach (range(1, 7) as $i) {
            $this->place("Gare {$i}");
        }
        $this->photon(array_map(fn ($i) => PlaceProvidersTest::feature("Garage {$i}", lat: 6.0 + $i / 10), range(1, 10)));

        $places = $this->search('gar')->assertOk()->json('places');

        $this->assertCount(8, $places);
        $this->assertSame([5, 3], [
            count(array_filter($places, fn ($p) => $p['source'] === 'popular')),
            count(array_filter($places, fn ($p) => $p['source'] === 'osm')),
        ]);
    }

    public function test_an_alias_finds_the_place_under_its_name(): void
    {
        $this->place('Complexe scolaire Les Cracks', ['aliases' => ['CS Les Cracks']]);
        $this->photon();

        $response = $this->search('cs');

        $this->assertSame(['Complexe scolaire Les Cracks'], $this->labels($response));
        $this->assertSame('popular', $response->json('places.0.source'));
    }

    public function test_accents_and_case_are_ignored(): void
    {
        $this->place('Fidjrossè');
        $this->photon();

        $this->assertSame(['Fidjrossè'], $this->labels($this->search('FIDJROSSE')));
    }

    public function test_a_hidden_place_never_shows(): void
    {
        $this->place('Fidjrossè', ['is_active' => false]);
        $this->photon();

        $this->assertSame([], $this->labels($this->search('fidj')));
    }

    public function test_a_photon_duplicate_of_a_popular_place_is_dropped(): void
    {
        $this->place('Dantokpa', ['lat' => 6.3700, 'lng' => 2.4300]);
        $this->photon([
            // À une centaine de mètres : le même lieu.
            PlaceProvidersTest::feature('Dantokpa', lat: 6.3709, lng: 2.4300),
            // À plus d'un kilomètre, dans un autre quartier : un autre.
            PlaceProvidersTest::feature('Dantokpa', props: ['district' => 'Missèbo'], lat: 6.3800, lng: 2.4300),
        ]);

        $places = $this->search('danto')->json('places');

        $this->assertSame(['popular', 'osm'], array_column($places, 'source'));
        $this->assertSame(6.38, $places[1]['lat']);
    }

    public function test_photon_is_cached_per_normalized_query(): void
    {
        $this->photon([PlaceProvidersTest::feature('Akpakpa')]);

        $this->search('Akpakpa')->assertOk();
        $this->search(' AKPAKPA ')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_photon_failure_falls_back_to_nominatim_and_is_not_cached(): void
    {
        Http::fake([
            'photon.komoot.io/*' => Http::sequence()->push('erreur', 500)
                ->push(['type' => 'FeatureCollection', 'features' => [PlaceProvidersTest::feature('Akpakpa Photon')]]),
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Akpakpa, Cotonou, Littoral, Bénin', 'lat' => '6.37', 'lon' => '2.45', 'class' => 'place', 'type' => 'suburb'],
            ]),
        ]);

        $this->assertSame(['Akpakpa'], $this->labels($this->search('akpakpa')));
        $this->assertSame(['Akpakpa Photon'], $this->labels($this->search('akpakpa')));
        Http::assertSentCount(3);
    }

    public function test_both_engines_down_still_returns_popular_places(): void
    {
        $this->place('Akpakpa Dodomè');
        Http::fake([
            'photon.komoot.io/*' => Http::response('erreur', 500),
            'nominatim.openstreetmap.org/*' => Http::response('erreur', 503),
        ]);

        $this->search('akpakpa')->assertOk()->assertJsonPath('places.0.label', 'Akpakpa Dodomè')->assertJsonCount(1, 'places');
    }

    public function test_a_new_popular_place_shows_at_once(): void
    {
        $this->photon();
        $this->search('godo')->assertJsonCount(0, 'places');

        $this->place('Godomey échangeur');
        PopularPlacesCache::forget();

        $this->assertSame(['Godomey échangeur'], $this->labels($this->search('godo')));
    }

    public function test_the_route_is_throttled_at_120_per_minute(): void
    {
        Http::fake();

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/public/places?q=a')->assertOk();
        }
        $this->getJson('/api/v1/public/places?q=a')->assertStatus(429);
    }

    /** Relecture finale : le cache `database` désérialise ; un objet en cache casse au prochain changement de classe. */
    public function test_the_caches_hold_plain_arrays_only(): void
    {
        $this->place('Akpakpa Dodomè');
        $this->photon([PlaceProvidersTest::feature('Akpakpa')]);

        $this->search('akpakpa')->assertOk();

        $photon = Cache::get('places.photon.'.sha1('akpakpa'));
        $popular = Cache::get('popular-places.active');
        $this->assertIsArray($photon);
        $this->assertIsArray($photon[0]);
        $this->assertIsArray($popular);
        $this->assertIsArray($popular[0]);
        // Et la réponse suivante, tirée du cache, est la même.
        $this->assertSame(['Akpakpa Dodomè', 'Akpakpa'], $this->labels($this->search('akpakpa')));
    }

    /** Relecture finale : une forme inattendue de Photon ne doit pas emporter les lieux populaires. */
    public function test_an_unexpected_photon_payload_falls_back(): void
    {
        $this->place('Akpakpa Dodomè');
        Http::fake([
            'photon.komoot.io/*' => Http::response(['features' => [['geometry' => ['coordinates' => 'cassé'], 'properties' => ['name' => ['x']]]]]),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $this->search('akpakpa')->assertOk()->assertJsonPath('places.0.label', 'Akpakpa Dodomè');
    }

    /** Relecture finale : Nominatim interdit l'autocomplétion au-delà d'une requête par seconde, et tout part de notre IP. */
    public function test_the_nominatim_fallback_is_limited_to_one_call_per_second(): void
    {
        $this->place('Akpakpa Dodomè');
        Http::fake([
            'photon.komoot.io/*' => Http::response('erreur', 500),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $this->search('akpak')->assertOk();
        $this->search('akpakp')->assertOk()->assertJsonPath('places.0.label', 'Akpakpa Dodomè');

        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'nominatim')));
    }

    /** Relecture finale : un `q` en tableau (robots) ne doit pas finir en 500. */
    public function test_a_non_text_query_returns_an_empty_list(): void
    {
        Http::fake();

        $this->getJson('/api/v1/public/places?q[]=akpakpa')->assertOk()->assertJsonCount(0, 'places');
        Http::assertNothingSent();
    }

    /** Relecture finale : Photon qui rend l'autre nom d'un lieu populaire, au même endroit, le répète. */
    public function test_a_photon_duplicate_of_an_alias_is_dropped(): void
    {
        $this->place('CS Les Cracks', ['aliases' => ['Complexe scolaire Les Cracks'], 'lat' => 6.3561, 'lng' => 2.3728]);
        $this->photon([PlaceProvidersTest::feature('Complexe scolaire Les Cracks', 'amenity', 'school', lat: 6.3562, lng: 2.3728)]);

        $this->assertSame(['CS Les Cracks'], $this->labels($this->search('complexe')));
    }

    /** Essai du 2026-10-27 : Photon rend parfois deux fois le même lieu, à quelques mètres. */
    public function test_engine_duplicates_are_merged(): void
    {
        $this->photon([
            PlaceProvidersTest::feature('Godomey', lat: 6.3854, lng: 2.3432),
            PlaceProvidersTest::feature('Godomey', lat: 6.3855, lng: 2.3433),
            PlaceProvidersTest::feature('Godomey', props: ['city' => 'Agbocodji'], lat: 6.3794, lng: 2.3398),
        ]);

        $this->assertCount(2, $this->search('godom')->json('places'));
    }

    /** Essai du 2026-10-27 : « Togbin » proposait Togbin (Zogbodomey) avant ceux d'Abomey-Calavi. */
    public function test_places_near_cotonou_come_first(): void
    {
        $this->photon([
            PlaceProvidersTest::feature('Togbin Zou', lat: 7.0053, lng: 2.0267),
            PlaceProvidersTest::feature('Togbin', lat: 6.3732, lng: 2.3089),
            PlaceProvidersTest::feature('Fidjrossè Dangbo', lat: 6.5813, lng: 2.5449),
            PlaceProvidersTest::feature('Togbin Plage', lat: 6.3477, lng: 2.3099),
        ]);

        $this->assertSame(['Togbin', 'Togbin Plage', 'Togbin Zou', 'Fidjrossè Dangbo'], $this->labels($this->search('togb')));
    }

    /** Essai du 2026-10-27 : « Abomey-Calavi » sortait deux fois (la ville et le bourg, à 1 km). */
    public function test_the_same_name_and_detail_close_by_is_merged(): void
    {
        $this->photon([
            PlaceProvidersTest::feature('Abomey-Calavi', props: ['city' => null, 'state' => 'Atlantique'], lat: 6.4485, lng: 2.3557),
            PlaceProvidersTest::feature('Abomey-Calavi', props: ['city' => null, 'state' => 'Atlantique'], lat: 6.4560, lng: 2.3500),
            PlaceProvidersTest::feature('Godomey', props: ['city' => 'Abomey-Calavi', 'state' => 'Atlantique'], lat: 6.3854, lng: 2.3432),
            PlaceProvidersTest::feature('Godomey', props: ['city' => 'Agbocodji', 'state' => 'Atlantique'], lat: 6.3794, lng: 2.3398),
        ]);

        $this->assertSame(['Abomey-Calavi', 'Godomey', 'Godomey'], $this->labels($this->search('abom')));
    }
}
