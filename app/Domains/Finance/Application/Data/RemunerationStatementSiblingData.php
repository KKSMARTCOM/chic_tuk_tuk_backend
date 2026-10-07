<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Models\RemunerationStatement;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Une fiche du même contrat, pour passer d'un mois à l'autre depuis le détail (2026-10-07). */
final class RemunerationStatementSiblingData extends BaseData
{
    public function __construct(
        public string $id,
        /** `Y-m` */
        public string $month,
        public ?string $number,
        #[TypeScriptType(RemunerationStatementStatus::class)]
        public string $status,
        public string $statusLabel,
    ) {}

    public static function fromStatement(RemunerationStatement $statement): self
    {
        $status = RemunerationStatementStatus::from($statement->status);

        return new self(
            id: $statement->id,
            month: $statement->monthKey(),
            number: $statement->number,
            status: $status->value,
            statusLabel: $status->label(),
        );
    }
}
