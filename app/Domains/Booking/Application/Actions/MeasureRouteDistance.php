<?php

namespace App\Domains\Booking\Application\Actions;

use Illuminate\Support\Facades\Http;

/**
 * La distance routière entre deux points, en kilomètres arrondis au supérieur, par
 * OpenRouteService.
 *
 * Déplacé de `App\Services\PricingService::getDistance()` le 2026-09-27, sans
 * changement.
 */
final class MeasureRouteDistance
{
    private const SNAP_RADIUS_METERS = 2000;

    public function __invoke(float $from_lng, float $from_lat, float $to_lng, float $to_lat): float
    {
        // Calcul de la distance
        $response = Http::withHeaders([
            'Authorization' => config('services.openrouteservice.key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openrouteservice.org/v2/directions/driving-car', [
            'coordinates' => [
                [(float) $from_lng, (float) $from_lat],
                [(float) $to_lng, (float) $to_lat],
            ],
            // Rayon de recherche de la route la plus proche, en mètres, pour chaque point :
            // 350 par défaut, trop court là où les routes sont mal cartographiées (2026-10-15).
            'radiuses' => [self::SNAP_RADIUS_METERS, self::SNAP_RADIUS_METERS],
        ]);

        if (! $response->successful()) {

            $errorMessage = 'Erreur inconnue';

            // essayer de récupérer le message de l'API
            if ($response->json()) {
                $errorMessage = $response->json()['error']['message']
                    ?? $response->json()['message']
                    ?? json_encode($response->json());
            }

            throw new \Exception(
                "Erreur OpenRouteService ({$response->status()}): ".$errorMessage
            );
        }

        $data = $response->json();
        $distanceFloat = $data['routes'][0]['summary']['distance'] / 1000;
        $distance = ceil($distanceFloat);

        return $distance;
    }
}
