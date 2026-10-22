<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Application\Data\DisbursementSummaryData;
use App\Shared\Data\BaseData;

/**
 * GET /admin/vehicle-contracts/{id} — ex-`pages.admin.contracts.owner-show`.
 *
 * `currentDriver` est l'agent du contrat agent ACTIF rattaché à ce contrat véhicule,
 * et `currentDriverSince` la date de début de ce contrat agent.
 */
final class AdminVehicleContractDetailData extends BaseData
{
    public function __construct(
        public AdminVehicleContractListItemData $contract,
        /** Les deux cumuls, à la place d'une date de fin (spec 2026-10-12, §7.1). */
        public AdminVehicleContractTargetsData $progress,
        public int $paymentsCount,
        /** @var array<int, AdminVehicleContractMonthData> */
        public array $paymentsByMonth,
        /** @var array<int, AdminVehicleContractDriverData> */
        public array $driverContracts,
        /** @var array<int, VehiclePauseData> */
        public array $pauses,
        public ?AdminVehicleContractPartyData $currentDriver,
        public ?string $currentDriverSince,
        /** L'agent interne en cours, s'il y en a un (2026-10-09). */
        public ?AdminInternalAssignmentData $currentInternalAssignment,
        /** @var array<int, AdminInternalAssignmentData> les affectations terminées, de la plus récente à la plus ancienne */
        public array $internalAssignments,
        /** @var AdminPreparationStepData[] les étapes de préparation (spec 2026-10-20, §5.2) */
        public array $preparationSteps,
        /** Le contrat est en attente : ses étapes se modifient encore. */
        public bool $preparationEditable,
        /** Les trois chiffres du versement au propriétaire (2026-10-22). */
        public DisbursementSummaryData $disbursementSummary,
    ) {}
}
