<?php

namespace App\Domains\Booking\Domain;

use App\Models\PricingSettings;
use Carbon\Carbon;

/**
 * Le prix d'une course à partir de sa distance et de son heure.
 *
 * Tous les montants viennent des réglages de l'administration (`PricingSettings`,
 * depuis le 2026-09-29 ; avant, des constantes de l'ex-`Price`).
 *
 * Déplacé de `App\Services\PricingService` le 2026-09-27, sans changement : la
 * distance, elle, vient d'OpenRouteService (`MeasureRouteDistance`).
 */
final class PriceCalculator
{
    /** Lu une fois par instance : un devis consulte les réglages jusqu'à cinq fois. */
    private ?PricingSettings $settings = null;

    private function settings(): PricingSettings
    {
        return $this->settings ??= PricingSettings::current();
    }

    /**
     * Le prix de base vaut pour une course de 1 km ou moins, et reste le minimum au-delà.
     * Avant le 2026-09-29, deux constantes égales portaient ces deux rôles.
     */
    public function getPrice(float $distance): int
    {
        $settings = $this->settings();

        if ($distance <= 1) {
            return $settings->base_price;
        }

        return (int) max($distance * $settings->price_per_km, $settings->base_price);
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

        return $this->isNormalPriceWindow($time) ? $price : $price + $this->settings()->time_surcharge;
    }

    /**
     * Le prix brut d'une course, avant majoration : l'inverse de `applyTimeSurcharge`. Le
     * prix enregistré la contient déjà, et c'est le prix brut que la modification attend.
     *
     * Calculé avec les réglages ACTUELS : si la majoration a changé depuis la création de
     * la course, le prix brut est décalé d'autant — il reste visible et corrigeable.
     */
    public function removeTimeSurcharge(int $price, $time): int
    {
        if (! $time || $this->isNormalPriceWindow($time)) {
            return $price;
        }

        return max(0, $price - $this->settings()->time_surcharge);
    }

    private function isNormalPriceWindow($time): bool
    {
        $minutes = $this->extractMinutesSinceMidnight($time);

        $settings = $this->settings();
        $start = $settings->surcharge_free_start_hour * 60;
        $end = $settings->surcharge_free_end_hour * 60;

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
