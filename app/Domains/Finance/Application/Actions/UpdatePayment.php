<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Payment;
use App\Shared\Http\ApiException;

/**
 * Modifier un paiement — ex-`PaymentService::update()`, déplacé sans changement le
 * 2026-09-27.
 */
final class UpdatePayment
{
    public function __construct(private readonly CheckPaymentData $checkPaymentData) {}

    /**
     * Modifier un paiement EN ATTENTE : montant, moyen, date, notes et référence.
     *
     * Corrigé le 2026-09-26. Le formulaire ne portant pas le type, un paiement de contrat
     * redevenait une commission ; le statut retombait à « en attente » faute d'être
     * envoyé ; et le paiement était rattaché au contrat ACTUEL de l'agent au lieu du sien.
     * Le type, l'agent, les contrats et le statut ne bougent plus ; seul un paiement en
     * attente se modifie — un paiement validé s'annule.
     */
    public function __invoke(Payment $payment, array $data): Payment
    {
        if ($payment->status !== 'pending') {
            throw new ApiException(
                409,
                'PAYMENT_NOT_EDITABLE',
                'Seul un paiement en attente se modifie. Un paiement validé s\'annule.'
            );
        }

        $check = [
            'driver_id' => $payment->driver_id,
            'payment_type' => $payment->payment_type,
            'amount' => $data['amount'],
            'driver_contract_id' => $payment->driver_contract_id,
            'vehicle_contract_id' => $payment->vehicle_contract_id,
        ];
        ($this->checkPaymentData)($check, $payment->id);

        $payment->update([
            'amount' => $data['amount'],
            'net_amount' => $check['net_amount'] ?? $payment->net_amount,
            'payment_method' => $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'notes' => $data['notes'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
        ]);

        return $payment->refresh();
    }
}
