<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le contrat propriétaire-véhicule saisi depuis l'écran d'un propriétaire.
 *
 * Le Blade proposait 24, 30 ou 36 mois, plus « autre » à l'édition : l'API reçoit
 * directement le nombre de mois. Les trois charges mensuelles, laissées vides, prennent
 * les valeurs par défaut de `VehicleContractConsts`.
 */
final class VehicleContractInputData extends BaseData
{
    public function __construct(
        public int $contractMonths,
        public float $totalAmount,
        public string $startDate,
        public ?float $unlimitedInternet = null,
        public ?float $spotifyPremium = null,
        public ?float $managerRemuneration = null,
        public ?string $notes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'contract_months' => ['required', 'integer', 'min:1'],
            'total_amount' => ['required', 'numeric', 'min:1'],
            'start_date' => ['required', 'date'],
            'unlimited_internet' => ['nullable', 'numeric', 'min:0'],
            'spotify_premium' => ['nullable', 'numeric', 'min:0'],
            'manager_remuneration' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'contract_months.required' => 'La durée du contrat est obligatoire.',
            'contract_months.min' => 'La durée du contrat doit être un nombre positif.',
            'total_amount.required' => 'Le montant total du contrat est obligatoire.',
            'total_amount.numeric' => 'Le montant total du contrat doit être un nombre.',
            'start_date.required' => 'La date de début du contrat est obligatoire.',
            'start_date.date' => 'La date de début du contrat doit être une date valide.',
        ];
    }

    /**
     * Les clés qu'attend `OwnerService`, qui les tient du formulaire Blade.
     *
     * @return array<string, mixed>
     */
    public function toServicePayload(): array
    {
        return [
            'contract_months' => $this->contractMonths,
            'contract_total_amount' => $this->totalAmount,
            'contract_start_date' => $this->startDate,
            'unlimited_internet' => $this->unlimitedInternet,
            'spotify_premium' => $this->spotifyPremium,
            'manager_remuneration' => $this->managerRemuneration,
            'contract_notes' => $this->notes,
        ];
    }
}
