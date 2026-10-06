<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehicleContract;
use App\Models\VehicleContractTerm;
use App\Shared\Http\ApiException;

/**
 * Le montant total d'un contrat propriétaire suit le réglage de sa durée (2026-10-06).
 *
 * Il se saisissait librement, alors que le versement journalier suivait la durée : quatre
 * contrats de production portaient un total sans rapport avec leur durée. Le total attendu
 * est celui du réglage de la durée — ou, durée inchangée, celui du contrat, qui peut avoir
 * été négocié. S'en écarter demande `override-contract-amount`, que seul l'administrateur
 * porte : c'est ce qui couvre un contrat négocié.
 */
final class CheckContractTotal
{
    public function __invoke(int $months, mixed $total, ?VehicleContract $current = null): void
    {
        $expected = $current !== null && (int) $current->contract_months === $months
            ? $current->total_amount
            : VehicleContractTerm::query()->where('months', $months)->value('total_amount');

        // Une durée absente des réglages n'a pas de total attendu : elle est refusée pour
        // elle-même par `ContractTerms::dailyAmountsFor()`, avec le bon message.
        if ($expected === null || (float) $total === (float) $expected) {
            return;
        }

        if (request()->user()?->can('override-contract-amount')) {
            return;
        }

        throw new ApiException(
            403,
            'CONTRACT_AMOUNT_LOCKED',
            'Le montant total suit la durée du contrat : seul un administrateur peut le modifier.'
        );
    }
}
