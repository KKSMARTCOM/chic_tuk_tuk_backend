<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\PricingSettings;
use App\Shared\Data\BaseData;

/**
 * GET et PUT /admin/settings/pricing — le prix de base, qui est aussi le minimum d'une
 * course, le prix au kilomètre, et la majoration horaire appliquée hors de la plage
 * sans majoration (bornes incluses, en heures pleines). Entiers : l'application compte en
 * FCFA sans décimales.
 */
final class PricingSettingsData extends BaseData
{
    public function __construct(
        public int $basePrice,
        public int $pricePerKm,
        public int $timeSurcharge,
        public int $surchargeFreeStartHour,
        public int $surchargeFreeEndHour,
    ) {}

    public static function fromModel(PricingSettings $settings): self
    {
        return new self(
            basePrice: $settings->base_price,
            pricePerKm: $settings->price_per_km,
            timeSurcharge: $settings->time_surcharge,
            surchargeFreeStartHour: $settings->surcharge_free_start_hour,
            surchargeFreeEndHour: $settings->surcharge_free_end_hour,
        );
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'base_price' => ['required', 'integer', 'min:1', 'max:1000000'],
            'price_per_km' => ['required', 'integer', 'min:1', 'max:100000'],
            // 0 est permis : c'est supprimer la majoration.
            'time_surcharge' => ['required', 'integer', 'min:0', 'max:1000000'],
            'surcharge_free_start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'surcharge_free_end_hour' => ['required', 'integer', 'min:0', 'max:23', 'gt:surcharge_free_start_hour'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'base_price.required' => 'Le prix de base est obligatoire.',
            'base_price.integer' => 'Le prix de base est un nombre entier de FCFA.',
            'base_price.min' => 'Le prix de base doit être positif.',
            'price_per_km.required' => 'Le prix au kilomètre est obligatoire.',
            'price_per_km.integer' => 'Le prix au kilomètre est un nombre entier de FCFA.',
            'price_per_km.min' => 'Le prix au kilomètre doit être positif.',
            'time_surcharge.required' => 'La majoration est obligatoire (0 pour n\'en appliquer aucune).',
            'time_surcharge.min' => 'La majoration ne peut pas être négative.',
            'surcharge_free_start_hour.required' => 'L\'heure de début est obligatoire.',
            'surcharge_free_start_hour.max' => 'L\'heure de début va de 0 à 23.',
            'surcharge_free_end_hour.required' => 'L\'heure de fin est obligatoire.',
            'surcharge_free_end_hour.max' => 'L\'heure de fin va de 0 à 23.',
            'surcharge_free_end_hour.gt' => 'L\'heure de fin doit suivre l\'heure de début.',
        ];
    }
}
