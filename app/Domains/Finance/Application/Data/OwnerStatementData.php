<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\RemunerationStatement;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

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
        /** `expired` : effacé au bout d'un an (2026-10-01). */
        #[LiteralTypeScriptType("'ready' | 'preparing' | 'expired'")]
        public string $pdfState = 'preparing',
        /** Il en a trois : la fiche lui a déjà été envoyée par e-mail. */
        public int $downloadsLeft = 0,
    ) {}

    public static function fromModel(RemunerationStatement $statement): self
    {
        return new self(
            id: $statement->id,
            number: (string) $statement->number,
            month: $statement->monthKey(),
            balanceDue: (float) $statement->balance_due,
            hasPdf: $statement->pdf_path !== null,
            pdfState: $statement->pdfState(),
            downloadsLeft: $statement->ownerDownloadsLeft(),
        );
    }
}
