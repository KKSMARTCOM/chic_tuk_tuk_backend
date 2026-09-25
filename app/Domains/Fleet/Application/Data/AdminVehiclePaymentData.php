<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\Payment;
use App\Shared\Data\BaseData;

/**
 * Un paiement récent sur la fiche d'un véhicule.
 *
 * `amount` est le montant BRUT, comme l'affichait le Blade — le total payé du contrat,
 * lui, somme les montants nets.
 */
final class AdminVehiclePaymentData extends BaseData
{
    public function __construct(
        public string $id,
        public float $amount,
        public ?string $paymentDate,
        public ?string $paymentMethod,
        public ?string $referenceNumber,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            id: $payment->id,
            amount: (float) $payment->amount,
            paymentDate: $payment->payment_date?->toDateString(),
            paymentMethod: $payment->payment_method,
            referenceNumber: $payment->reference_number,
        );
    }
}
