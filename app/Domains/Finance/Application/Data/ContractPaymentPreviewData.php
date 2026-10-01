<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\PaymentPlan;
use App\Models\DriverContract;
use App\Shared\Data\BaseData;

/** L'aperçu d'une génération : chaque jour classé, le montant et les totaux. */
final class ContractPaymentPreviewData extends BaseData
{
    public function __construct(
        /** La période ramenée aux dates du contrat agent. */
        public string $from,
        public string $to,
        public float $dailyAmount,
        public float $dailyTax,
        public float $dailyNetAmount,
        public float $totalNet,
        /** @var array<string, int> */
        public array $counts,
        /** @var PlannedDayData[] */
        public array $days,
    ) {}

    public static function fromPlan(DriverContract $contract, PaymentPlan $plan): self
    {
        $vehicleContract = $contract->vehicleContract;
        $amount = (float) $vehicleContract->daily_amount;
        $tax = (float) ($vehicleContract->daily_tax ?? 0);

        return new self(
            from: $plan->days[0]->date ?? '',
            to: $plan->days === [] ? '' : $plan->days[array_key_last($plan->days)]->date,
            dailyAmount: $amount,
            dailyTax: $tax,
            dailyNetAmount: $amount - $tax,
            totalNet: count($plan->toGenerate()) * ($amount - $tax),
            counts: $plan->counts(),
            days: array_map(fn ($d) => PlannedDayData::fromDay($d), $plan->days),
        );
    }
}
