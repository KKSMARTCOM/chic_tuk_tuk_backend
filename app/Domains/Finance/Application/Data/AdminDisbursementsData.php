<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Application\Actions\BuildDisbursementSummary;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/** Les versements d'un contrat et ses trois chiffres, rendus par chaque route admin (§5.1). */
final class AdminDisbursementsData extends BaseData
{
    public function __construct(
        /** @var AdminDisbursementData[] */
        public array $disbursements,
        public DisbursementSummaryData $summary,
    ) {}

    public static function forContract(VehicleContract $contract): self
    {
        return new self(
            disbursements: $contract->disbursements()->with('creator')->get()
                ->map(fn ($d) => AdminDisbursementData::fromModel($d))->all(),
            summary: app(BuildDisbursementSummary::class)([$contract->id]),
        );
    }
}
