<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\RemunerationStatement;
use App\Shared\Data\BaseData;

/** Une fiche de rémunération validée, telle que son propriétaire la liste. */
final class OwnerStatementData extends BaseData
{
    public function __construct(
        public string $id,
        public string $number,
        /** `Y-m` */
        public string $month,
        public float $balanceDue,
        /** Faux tant que la tâche en file n'a pas rangé le PDF : la fiche est « en préparation ». */
        public bool $hasPdf,
    ) {}

    public static function fromModel(RemunerationStatement $statement): self
    {
        return new self(
            id: $statement->id,
            number: (string) $statement->number,
            month: $statement->monthKey(),
            balanceDue: (float) $statement->balance_due,
            hasPdf: $statement->pdf_path !== null,
        );
    }
}
