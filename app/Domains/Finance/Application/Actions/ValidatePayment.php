<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Shared\Http\ApiException;

/**
 * Valider un paiement — ex-`PaymentService::validatePayment()`, déplacé sans changement
 * le 2026-09-27.
 */
final class ValidatePayment
{
    /**
     * Valider un paiement EN ATTENTE, et le dire à l'agent.
     *
     * Corrigé le 2026-09-26 : rien n'empêchait de valider un paiement annulé — l'écran ne
     * le proposait pas, le serveur l'acceptait, et l'agent était notifié.
     */
    public function __invoke(Payment $payment): Payment
    {
        if ($payment->status !== 'pending') {
            throw new ApiException(409, 'PAYMENT_NOT_PENDING', 'Seul un paiement en attente se valide.');
        }

        $payment->update(['status' => 'completed']);

        // C'est l'ACTION qui est notifiée, pas la création du paiement : celle-ci est
        // majoritairement automatique et quotidienne.
        app(Notifier::class)->paymentValidated($payment->fresh()->load('driver.user'));

        return $payment->refresh();
    }
}
