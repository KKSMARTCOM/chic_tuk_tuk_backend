<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\DisbursementMethod;
use App\Models\OwnerDisbursement;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un versement sur la fiche admin du contrat propriétaire (§5.1), annulés compris. */
final class AdminDisbursementData extends BaseData
{
    public function __construct(
        public string $id,
        public string $paidOn,
        public float $amount,
        #[TypeScriptType(DisbursementMethod::class)]
        public string $method,
        public string $methodLabel,
        public ?string $reference,
        public ?string $note,
        public bool $hasProof,
        #[LiteralTypeScriptType("'recorded' | 'cancelled'")]
        public string $status,
        public ?string $cancellationReason,
        public ?string $cancelledAt,
        public ?string $createdByName,
    ) {}

    public static function fromModel(OwnerDisbursement $d): self
    {
        return new self(
            id: $d->id,
            paidOn: $d->paid_on->toDateString(),
            amount: (float) $d->amount,
            method: $d->method,
            methodLabel: DisbursementMethod::from($d->method)->label(),
            reference: $d->reference,
            note: $d->note,
            hasProof: $d->proof_path !== null,
            status: $d->status,
            cancellationReason: $d->cancellation_reason,
            cancelledAt: $d->cancelled_at?->toIso8601String(),
            createdByName: $d->creator?->name,
        );
    }
}
