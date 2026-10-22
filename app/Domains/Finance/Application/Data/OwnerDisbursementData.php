<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\OwnerDisbursement;
use App\Shared\Data\BaseData;

/**
 * Un versement vu par le propriétaire (spec 2026-10-22-versement-proprietaire, §4.5) : la date,
 * le montant et la preuve, décidé avec l'utilisateur. Ni moyen, ni référence, ni note.
 */
final class OwnerDisbursementData extends BaseData
{
    public function __construct(
        public string $id,
        public string $paidOn,
        public float $amount,
        public bool $hasProof,
    ) {}

    public static function fromModel(OwnerDisbursement $d): self
    {
        return new self(id: $d->id, paidOn: $d->paid_on->toDateString(), amount: (float) $d->amount, hasProof: $d->proof_path !== null);
    }
}
