<?php

namespace App\Domains\Finance\Domain;

/** Le classement d'une période : ce qui se génère, ce qui est sauté, et pourquoi. */
final class PaymentPlan
{
    /** @param  list<PlannedDay>  $days */
    public function __construct(public readonly array $days) {}

    /** @return list<string> */
    public function toGenerate(): array
    {
        return $this->datesOf(ContractPaymentPlanner::TO_GENERATE);
    }

    /** @return list<string> */
    public function cancelled(): array
    {
        return $this->datesOf(ContractPaymentPlanner::CANCELLED);
    }

    /** @return array<string, int> chaque classe, même à zéro */
    public function counts(): array
    {
        $counts = array_fill_keys(ContractPaymentPlanner::CLASSES, 0);
        foreach ($this->days as $day) {
            $counts[$day->class]++;
        }

        return $counts;
    }

    /** @return list<string> */
    private function datesOf(string $class): array
    {
        return array_values(array_map(fn (PlannedDay $d) => $d->date, array_filter($this->days, fn (PlannedDay $d) => $d->class === $class)));
    }
}
