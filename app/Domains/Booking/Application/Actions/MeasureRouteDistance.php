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
