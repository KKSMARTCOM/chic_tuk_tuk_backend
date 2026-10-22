<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un contrat proposé au type « Versement propriétaire » de l'ajout d'un paiement (essai du 2026-10-22). */
final class DisbursableContractData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $ownerName,
        public ?string $vehicleNumber,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
        /** Brut, comme sur la fiche du contrat : négatif quand une fiche a été annulée après des versements. */
        public float $outstanding,
    ) {}
}
