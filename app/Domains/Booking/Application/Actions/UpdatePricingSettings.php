<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\PricingSettingsData;
use App\Models\PricingSettings;

/**
 * Enregistrer le prix des courses. Les réservations déjà faites
 * gardent leur prix ; les devis suivants et les réservations suivantes prennent le
 * nouveau.
 *
 * Le cache des devis ne porte que la DISTANCE d'un trajet, jamais un prix : rien à
 * invalider.
 */
final class UpdatePricingSettings
{
    public function __invoke(PricingSettingsData $data): PricingSettingsData
    {
        $settings = PricingSettings::current();
        $settings->update([
            'base_price' => $data->basePrice,
            'price_per_km' => $data->pricePerKm,
            'time_surcharge' => $data->timeSurcharge,
            'surcharge_free_start_hour' => $data->surchargeFreeStartHour,
            'surcharge_free_end_hour' => $data->surchargeFreeEndHour,
        ]);

        return PricingSettingsData::fromModel($settings);
    }
}
