<?php

namespace App\Domains\Booking\Application\Actions;

use App\Consts\Price;
use App\Domains\Booking\Application\Data\CalculatePriceData;
use App\Domains\Booking\Application\Data\PriceQuoteData;
use App\Domains\Booking\Domain\PriceCalculator;
use Illuminate\Support\Facades\Cache;

/**
 * Calcule le devis d'une course.
 *
 * Reproduit exactement la logique de tarification de BookingService::create()
 * (prix de base, majoration horaire sur l'aller et sur le retour, multiplication
 * par le nombre de jours d'un abonnement), afin que le devis affiché au client
 * corresponde au prix réellement enregistré à la création.
 */
final class QuotePrice
{
    public function __construct(
        private readonly PriceCalculator $pricing,
        private readonly MeasureRouteDistance $measureDistance,
    ) {}

    public function execute(CalculatePriceData $data): PriceQuoteData
    {
        // Le front redemande un devis à chaque changement d'horaire, d'aller-retour ou de
        // durée, mais la distance ne dépend que du trajet : elle est mise en cache pour ne
        // consommer le quota OpenRouteService qu'une fois par couple de coordonnées.
        // (Arrondi à 5 décimales ≈ 1 m.) La création de la réservation, elle, recalcule
        // toujours la distance via BookingService::create().
        $distance = Cache::remember(
            sprintf('pricing:distance:%.5f,%.5f:%.5f,%.5f', $data->fromLng, $data->fromLat, $data->toLng, $data->toLat),
            now()->addDays(7),
            fn () => ($this->measureDistance)($data->fromLng, $data->fromLat, $data->toLng, $data->toLat),
        );
        $basePrice = $this->pricing->getPrice($distance);

        $goPrice = $this->pricing->applyTimeSurcharge($basePrice, $data->pickupTime);

        // Comme à la création : sans heure de retour explicite, le retour est
        // facturé au même tarif que l'aller.
        $returnPrice = $data->roundTrip
            ? $this->pricing->applyTimeSurcharge($basePrice, $data->returnTime ?: $data->pickupTime)
            : null;

        $tripPrice = $goPrice + ($returnPrice ?? 0);
        $isRecurring = $data->days > 1;
        $totalPrice = $tripPrice * ($isRecurring ? $data->days : 1);

        return new PriceQuoteData(
            distanceKm: $distance,
            basePrice: $basePrice,
            goPrice: $goPrice,
            returnPrice: $returnPrice,
            tripPrice: $tripPrice,
            days: $data->days,
            totalPrice: $totalPrice,
            surchargeAmount: Price::TIME_SURCHARGE,
            surchargeFreeWindow: Price::NORMAL_WINDOW_START_HOUR.'h–'.Price::NORMAL_WINDOW_END_HOUR.'h',
        );
    }
}
