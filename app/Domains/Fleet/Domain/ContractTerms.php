<?php

namespace App\Domains\Fleet\Domain;

use App\Models\VehicleContractChargeDefaults;
use App\Models\VehicleContractTerm;
use Illuminate\Validation\ValidationException;

/**
 * Les montants réglés par l'administration, tels qu'un contrat véhicule les reçoit.
 *
 * Remplace l'ex-`VehicleContractConsts` le 2026-09-29. ⚠️ Un contrat COPIE ces montants à
 * sa création — versement et taxe journaliers compris — et ne les relit jamais ensuite :
 * modifier un réglage ne vaut que pour les contrats créés après.
 */
final class ContractTerms
{
    /**
     * Le versement et la taxe journaliers d'une durée, à figer sur le contrat.
     *
     * Une durée absente des réglages est refusée : un contrat sans versement journalier
     * faisait générer chaque soir un paiement de 0 FCFA.
     *
     * @return array{daily_amount: string, daily_tax: string}
     */
    public static function dailyAmountsFor(int $months): array
    {
        $term = VehicleContractTerm::query()->where('months', $months)->first();

        if (! $term) {
            $offered = VehicleContractTerm::query()->orderBy('months')->pluck('months')->implode(', ');

            throw ValidationException::withMessages([
                'contract_months' => ["Aucun contrat de {$months} mois n'est proposé. Durées disponibles : {$offered} mois."],
            ]);
        }

        return ['daily_amount' => $term->daily_amount, 'daily_tax' => $term->daily_tax];
    }

    /**
     * Les charges mensuelles, complétées par les valeurs par défaut quand elles sont
     * laissées vides.
     *
     * @param  array<string, mixed>  $data
     * @return array{unlimited_internet: mixed, spotify_premium: mixed, manager_remuneration: mixed}
     */
    public static function chargesFrom(array $data): array
    {
        $defaults = self::chargeDefaults();

        return [
            'unlimited_internet' => $data['unlimited_internet'] ?? $defaults->unlimited_internet,
            'spotify_premium' => $data['spotify_premium'] ?? $defaults->spotify_premium,
            'manager_remuneration' => $data['manager_remuneration'] ?? $defaults->manager_remuneration,
        ];
    }

    /** La ligne unique des charges par défaut, amorcée par la migration. */
    public static function chargeDefaults(): VehicleContractChargeDefaults
    {
        return VehicleContractChargeDefaults::query()->firstOrFail();
    }
}
