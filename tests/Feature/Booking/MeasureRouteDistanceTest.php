<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\MeasureRouteDistance;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La distance routière par OpenRouteService.
 *
 * Par défaut, le service ne cherche une route qu'à 350 m du point choisi : un départ posé
 * loin d'une route cartographiée (vu le 2026-10-15, au nord d'Abomey-Calavi) répondait
 * 404 et le devis était indisponible. Le rayon est élargi à 2 km pour les deux points.
 */
class MeasureRouteDistanceTest extends TestCase
{
    public function test_the_search_radius_is_widened_for_both_points(): void
    {
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => 35_432.7]]],
            ], 200),
        ]);

        $distance = (new MeasureRouteDistance)(2.3871465, 6.5959935, 2.42, 6.37);

        $this->assertSame(36.0, $distance);
        Http::assertSent(fn (Request $request) => $request['radiuses'] === [2000, 2000]);
    }
}
