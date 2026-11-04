<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Application\Data\AdminDisbursementData;
use App\Shared\Data\BaseData;

/** Un versement récent dans le dossier propriétaire, avec le contrat qui le porte. */
final class AdminOwnerDossierDisbursementData extends BaseData
{
    public function __construct(
        public AdminDisbursementData $disbursement,
        public string $vehicleContractId,
        public ?string $vehicleNumber,
    ) {}
}
