<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\StatementFigures;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Les chiffres d'une fiche de rémunération : recalculés pour un brouillon, figés pour une
 * fiche validée ou annulée. Les listes de paiements rattachés ne sortent pas : elles
 * vivent dans `payments.remuneration_statement_id`.
 */
final class StatementFiguresData extends BaseData
{
    public function __construct(
        public string $month,
        public string $ownerName,
        public string $vehicleNumber,
        public int $contractMonths,
        public string $startDate,
        public int $businessDays,
        public int $pauseDays,
        public int $immobilizationDays,
        public int $countedDays,
        public float $dailyAmount,
        public float $revenue,
        public float $recovered,
        public int $pendingCount,
        public float $pendingAmount,
        #[LiteralTypeScriptType('Array<{ key: "internet" | "spotify" | "manager"; label: string; due: number; outstanding: number; proposed: number; deducted: number }>')]
        public array $charges,
        public float $deductedTotal,
        public float $balanceDue,
        public float $cumulativeRevenue,
        public float $cumulativeCharges,
        public float $cumulativeNet,
        public int $workedMonths,
        public int $pauseDaysTaken,
        public int $pauseAllowance,
        public bool $isFirstStatement,
        /** @var string[] */
        public array $anomalies,
    ) {}

    public static function fromFigures(StatementFigures $f): self
    {
        return new self(
            month: $f->month,
            ownerName: $f->ownerName,
            vehicleNumber: $f->vehicleNumber,
            contractMonths: $f->contractMonths,
            startDate: $f->startDate,
            businessDays: $f->businessDays,
            pauseDays: $f->pauseDays,
            immobilizationDays: $f->immobilizationDays,
            countedDays: $f->countedDays,
            dailyAmount: $f->dailyAmount,
            revenue: $f->revenue,
            recovered: $f->recovered,
            pendingCount: $f->pendingCount,
            pendingAmount: $f->pendingAmount,
            charges: $f->charges,
            deductedTotal: $f->deductedTotal,
            balanceDue: $f->balanceDue,
            cumulativeRevenue: $f->cumulativeRevenue,
            cumulativeCharges: $f->cumulativeCharges,
            cumulativeNet: $f->cumulativeNet,
            workedMonths: $f->workedMonths,
            pauseDaysTaken: $f->pauseDaysTaken,
            pauseAllowance: $f->pauseAllowance,
            isFirstStatement: $f->isFirstStatement,
            anomalies: $f->anomalies,
        );
    }
}
