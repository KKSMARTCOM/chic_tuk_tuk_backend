<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Une fiche de rémunération récente dans le dossier propriétaire. */
final class AdminOwnerDossierStatementData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $number,
        /** `Y-m` */
        public string $month,
        public string $vehicleNumber,
        /** La colonne figée : `null` pour un brouillon, qui se recalcule à la lecture. */
        public ?float $balanceDue,
        #[TypeScriptType(RemunerationStatementStatus::class)]
        public string $status,
        public string $statusLabel,
    ) {}
}
