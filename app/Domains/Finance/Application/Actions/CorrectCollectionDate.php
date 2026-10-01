<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;
use App\Shared\Http\ApiException;
use Carbon\Carbon;

/**
 * Corriger la date d'encaissement d'un paiement validé, tant qu'aucune fiche validée ne le
 * compte (spec 2026-10-01, §4.5). La modification d'un paiement, elle, reste réservée aux
 * paiements en attente.
 */
final class CorrectCollectionDate
{
    public function __construct(private readonly CheckCollectionDate $checkCollectionDate) {}

    public function __invoke(Payment $payment, Carbon $collectedOn): Payment
    {
        if ($payment->status !== 'completed') {
            throw new ApiException(409, 'PAYMENT_NOT_COLLECTED', 'Seul un paiement validé a une date d\'encaissement.');
        }
        if ($payment->remuneration_statement_id !== null) {
            throw new ApiException(409, 'PAYMENT_IN_VALIDATED_STATEMENT', 'Ce paiement est compté dans une fiche de rémunération validée : annulez d\'abord la fiche.');
        }

        ($this->checkCollectionDate)($payment, $collectedOn);
        $payment->update(['collected_on' => $collectedOn->toDateString()]);

        return $payment->refresh();
    }
}
