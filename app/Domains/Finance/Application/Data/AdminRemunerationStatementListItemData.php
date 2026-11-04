<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Une ligne de la liste des fiches de rémunération.
 *
 * `balance_due` et `anomaly_count` sont figés pour une fiche validée ou annulée, et
 * recalculés pour un brouillon : un paiement validé entre-temps doit s'y voir.
 */
final class AdminRemunerationStatementListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $number,
        #[TypeScriptType(RemunerationStatementStatus::class)]
        public string $status,
        public string $statusLabel,
        /** `Y-m` */
        public string $month,
        public string $ownerName,
        public string $vehicleNumber,
        /** Les liens de la liste (2026-11-04) : le dossier du propriétaire, le véhicule, le contrat. */
        public ?string $ownerId,
        public ?string $vehicleId,
        public string $vehicleContractId,
        public float $balanceDue,
        public int $anomalyCount,
    ) {}
}
