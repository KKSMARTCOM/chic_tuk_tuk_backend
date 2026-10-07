<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\Payment;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un paiement de contrat, vu par le propriétaire du véhicule (2026-10-07).
 *
 * ⚠️ Le NET seulement — ce qui lui revient —, ni versement brut, ni taxe, ni agent : c'est
 * une règle de la structure, pas des champs facultatifs. Ne rien y ajouter de tout cela.
 */
final class OwnerPaymentListItemData extends BaseData
{
    public function __construct(
        public string $id,
        /** `Y-m-d` */
        public string $paymentDate,
        public float $netAmount,
        #[LiteralTypeScriptType("'completed' | 'pending'")]
        public string $status,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            id: $payment->id,
            paymentDate: $payment->payment_date->toDateString(),
            netAmount: (float) $payment->net_amount,
            status: $payment->status,
        );
    }
}
