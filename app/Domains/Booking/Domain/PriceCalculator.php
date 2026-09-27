<?php

namespace App\Domains\Booking\Domain;

use App\Consts\Price;
use Carbon\Carbon;

/**
 * Le prix d'une course à partir de sa distance et de son heure — les constantes de
 * `Price`, sans effet de bord.
 *
 * Déplacé de `App\Services\PricingService` le 2026-09-27, sans changement : la
 * distance, elle, vient d'OpenRouteService (`MeasureRouteDistance`).
 */
final class PriceCalculator
{
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
        if (! $time) {
            return $price;
        }

        return $this->isNormalPriceWindow($time) ? $price : $price + Price::TIME_SURCHARGE;
    }

    private function isNormalPriceWindow($time): bool
    {
        $minutes = $this->extractMinutesSinceMidnight($time);

        $start = Price::NORMAL_WINDOW_START_HOUR * 60;
        $end = Price::NORMAL_WINDOW_END_HOUR * 60;

        return $minutes >= $start && $minutes <= $end;
    }

    private function extractMinutesSinceMidnight($time): int
    {
        if ($time instanceof Carbon) {
            return $time->hour * 60 + $time->minute;
        }

        [$h, $m] = array_pad(explode(':', (string) $time), 2, 0);

        return ((int) $h) * 60 + ((int) $m);
    }
}
