<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Shared\Http\ApiException;

/**
 * Annuler un paiement — ex-`PaymentService::cancelPayment()`, déplacé sans changement le
 * 2026-09-27.
 */
final class CancelPayment
{
    /**
     * Annuler un paiement, en attente ou validé, et le dire à l'agent.
     *
     * Décidé le 2026-09-26 : un paiement validé ne se supprime pas, il s'annule — il sort
     * des sommes payées et garde sa trace. On n'annule pas deux fois.
     */
    public function __invoke(Payment $payment): Payment
    {
        if ($payment->status === 'cancelled') {
            throw new ApiException(409, 'PAYMENT_ALREADY_CANCELLED', 'Ce paiement est déjà annulé.');
        }

        $payment->update(['status' => 'cancelled']);

        // Un agent qui comptait sur cette somme a le droit de l'apprendre autrement
        // qu'en s'en apercevant.
        app(Notifier::class)->paymentCancelled($payment->fresh()->load('driver.user'));

        return $payment->refresh();
    }
}
