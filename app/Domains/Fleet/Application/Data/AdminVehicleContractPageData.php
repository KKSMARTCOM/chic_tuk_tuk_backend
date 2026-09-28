<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/**
 * GET /admin/vehicle-contracts — la liste, et les véhicules vers lesquels la modale de
 * modification peut déplacer un contrat.
 */
final class AdminVehicleContractPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminVehicleContractListItemData> */
        public array $contracts,
        public PaginationData $pagination,
        /** @var array<int, AdminAvailableVehicleData> */
        public array $availableVehicles,
    ) {}
}
