<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Audit\Application\Data\ActivityEntryData;
use App\Shared\Data\BaseData;

/**
 * GET /admin/owners/{owner}/dossier — tout ce qui concerne un propriétaire, en lecture
 * (spec 2026-11-04-dossier-proprietaire). Chaque bloc financier vaut `null` sans sa
 * permission ; `totals` et `vehicles` suivent `view-owners`.
 */
final class AdminOwnerDossierData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $phone,
        public ?string $adresse,
        public bool $isActive,
        public string $createdAt,
        public ?string $lastLoginAt,
        public AdminOwnerDossierTotalsData $totals,
        /** @var array<int, AdminOwnerDossierVehicleData> */
        public array $vehicles,
        /** @var array<int, AdminOwnerDossierStatementData>|null */
        public ?array $statements,
        /** @var array<int, AdminOwnerDossierDisbursementData>|null */
        public ?array $disbursements,
        /** @var array<int, AdminOwnerDossierPaymentData>|null */
        public ?array $payments,
        /** @var array<int, ActivityEntryData>|null */
        public ?array $activity,
    ) {}
}
