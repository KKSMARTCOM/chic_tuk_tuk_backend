<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * PUT /admin/settings/vehicle-contracts — la liste COMPLÈTE des durées proposées, et les
 * trois charges par défaut.
 *
 * Une durée absente de la liste est retirée des réglages. C'est sans risque : chaque
 * contrat porte ses propres montants, et un contrat dont la durée n'est plus proposée
 * garde les siens.
 */
final class UpdateVehicleContractSettingsData extends BaseData
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        #[LiteralTypeScriptType('Array<{ months: number; total_amount: number; daily_amount: number; daily_tax: number }>')]
        public array $terms,
        public float $unlimitedInternet,
        public float $spotifyPremium,
        public float $managerRemuneration,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'terms' => ['required', 'array', 'min:1', 'max:12'],
            'terms.*.months' => ['required', 'integer', 'min:1', 'max:120', 'distinct'],
            'terms.*.total_amount' => ['required', 'numeric', 'min:1'],
            'terms.*.daily_amount' => ['required', 'numeric', 'min:1'],
            'terms.*.daily_tax' => ['required', 'numeric', 'min:0', 'lt:terms.*.daily_amount'],
            'unlimited_internet' => ['required', 'numeric', 'min:0'],
            'spotify_premium' => ['required', 'numeric', 'min:0'],
            'manager_remuneration' => ['required', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'terms.required' => 'Proposez au moins une durée de contrat.',
            'terms.min' => 'Proposez au moins une durée de contrat.',
            'terms.*.months.required' => 'La durée est obligatoire.',
            'terms.*.months.integer' => 'La durée est un nombre entier de mois.',
            'terms.*.months.min' => 'La durée est d\'au moins un mois.',
            'terms.*.months.max' => 'La durée ne dépasse pas 120 mois.',
            'terms.*.months.distinct' => 'Cette durée figure deux fois.',
            'terms.*.total_amount.required' => 'Le montant total est obligatoire.',
            'terms.*.total_amount.min' => 'Le montant total doit être positif.',
            'terms.*.daily_amount.required' => 'Le versement journalier est obligatoire.',
            'terms.*.daily_amount.min' => 'Le versement journalier doit être positif.',
            'terms.*.daily_tax.required' => 'La taxe journalière est obligatoire.',
            'terms.*.daily_tax.min' => 'La taxe journalière ne peut pas être négative.',
            'terms.*.daily_tax.lt' => 'La taxe journalière doit rester inférieure au versement journalier.',
            'unlimited_internet.required' => 'L\'internet illimité est obligatoire.',
            'spotify_premium.required' => 'Spotify Premium est obligatoire.',
            'manager_remuneration.required' => 'La rémunération du manager est obligatoire.',
        ];
    }
}
