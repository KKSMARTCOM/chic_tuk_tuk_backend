<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;
use App\Shared\Http\ApiException;

/**
 * Supprimer un paiement — ex-`PaymentService::delete()`, déplacé sans changement le
 * 2026-09-27.
 */
final class DeletePayment
{
    /**
     * Supprimer un paiement EN ATTENTE.
     *
     * Décidé le 2026-09-26 : supprimer un paiement validé modifiait en silence les soldes
     * du contrat véhicule et de l'agent. Il s'annule.
     */
    public function __invoke(Payment $payment): void
    {
        if ($payment->status !== 'pending') {
            throw new ApiException(
                409,
                'PAYMENT_NOT_DELETABLE',
                'Seul un paiement en attente se supprime. Un paiement validé s\'annule.'
            );
        }

        $payment->delete();
    }
}
