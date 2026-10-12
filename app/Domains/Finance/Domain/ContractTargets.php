<?php

namespace App\Domains\Finance\Domain;

use App\Models\VehicleContract;

/**
 * Les deux totaux qui terminent un contrat propriétaire (spec 2026-10-12, §2) : le revenu
 * total et le total des charges, connus dès la signature. Classe pure.
 */
final class ContractTargets
{
    public static function revenue(VehicleContract $contract): float
    {
        return (float) $contract->total_amount;
    }

    /** Durée × charges mensuelles : au-delà de la durée, plus rien n'est dû. */
    public static function charges(VehicleContract $contract): float
    {
        return round(array_sum(self::chargeLines($contract)), 2);
    }

    /** @return array{internet: float, spotify: float, manager: float} */
    public static function chargeLines(VehicleContract $contract): array
    {
        $months = (int) $contract->contract_months;

        return [
            'internet' => round($months * (float) ($contract->unlimited_internet ?? 0), 2),
            'spotify' => round($months * (float) ($contract->spotify_premium ?? 0), 2),
            'manager' => round($months * (float) ($contract->manager_remuneration ?? 0), 2),
        ];
    }

    /** La durée restante proposée au renouvellement : la durée moins les mois TRAVAILLÉS (spec 2026-10-12, §6). */
    public static function remainingMonths(VehicleContract $contract): int
    {
        return max(0, (int) $contract->contract_months - ContractMonthCalculator::for($contract)->workedMonths());
    }

    public static function isReached(float $cumulative, float $target): bool
    {
        return $cumulative >= $target - 0.005;
    }
}
