<?php

namespace App\Services;

use App\Consts\Price;
use Illuminate\Support\Facades\Http;

class PricingService
{
    public function getDistance(float $from_lng, float $from_lat, float $to_lng, float $to_lat): float
    {
        // Calcul de la distance
        $response = Http::withHeaders([
            'Authorization' => config('services.openrouteservice.key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openrouteservice.org/v2/directions/driving-car', [
            'coordinates' => [
                [(float)$from_lng, (float)$from_lat],
                [(float)$to_lng, (float)$to_lat],
            ]
        ]);

        if (!$response->successful()) {

            $errorMessage = 'Erreur inconnue';

            // essayer de récupérer le message de l'API
            if ($response->json()) {
                $errorMessage = $response->json()['error']['message']
                    ?? $response->json()['message']
                    ?? json_encode($response->json());
            }

            throw new \Exception(
                "Erreur OpenRouteService ({$response->status()}): " . $errorMessage
            );
        }

        $data = $response->json();
        $distanceFloat = $data['routes'][0]['summary']['distance'] / 1000;
        $distance = ceil($distanceFloat);

        return $distance;
    }

    public function getPrice(float $distance): int
    {
        if ($distance <= 1) {
            return Price::BASE_PRICE;
        }

        $price = $distance * Price::PRICE_PER_KM;

        return (int) max($price, Price::MINIMUM_PRICE);
    }

    /**
     * Applique la majoration horaire à un prix donné, selon l'heure de la course.
     * Accepte une heure sous forme de string "H:i" ou d'instance Carbon.
     */
    public function applyTimeSurcharge(int $price, $time): int
    {
        if (!$time) {
            return $price;
        }

        return $this->isNormalPriceWindow($time) ? $price : $price + Price::TIME_SURCHARGE;
    }

    /**
     * Le prix brut d'une course, avant majoration : l'inverse de `applyTimeSurcharge`. Le
     * prix enregistré la contient déjà, et c'est le prix brut que l'enregistrement attend.
     */
    public function removeTimeSurcharge(int $price, $time): int
    {
        if (!$time || $this->isNormalPriceWindow($time)) {
            return $price;
        }

        return max(0, $price - Price::TIME_SURCHARGE);
    }

    private function isNormalPriceWindow($time): bool
    {
        $minutes = $this->extractMinutesSinceMidnight($time);

        $start = Price::NORMAL_WINDOW_START_HOUR * 60;
        $end   = Price::NORMAL_WINDOW_END_HOUR * 60;

        return $minutes >= $start && $minutes <= $end;
    }

    private function extractMinutesSinceMidnight($time): int
    {
        if ($time instanceof \Carbon\Carbon) {
            return $time->hour * 60 + $time->minute;
        }

        [$h, $m] = array_pad(explode(':', (string) $time), 2, 0);
        return ((int) $h) * 60 + ((int) $m);
    }
}
