<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\LeaveRequest;
use App\Shared\Data\BaseData;

/**
 * GET /driver/leaves — les quatre listes, le solde, et le droit de demander.
 *
 * `canRequest` vient de ce que `DriverLeaveController::create()` calculait pour décider
 * d'afficher son formulaire. Le front s'en sert de la même façon ; il ne le recalcule
 * pas, parce que la règle est métier et doit rester d'un seul côté.
 */
final class DriverLeavesData extends BaseData
{
    public function __construct(
        public int $leaveDaysPerMonth,
        public int $totalLeaveDays,
        public int $leaveDaysUsed,
        /** Droits acquis à date, au prorata des mois écoulés du contrat. */
        public int $availableLeaveDays,
        /** Total du contrat moins les jours déjà utilisés. */
        public int $remainingLeaveDays,
        /** @var array<int, LeaveRequestData> */
        public array $pending,
        public ?LeaveRequestData $ongoing,
        /** @var array<int, LeaveRequestData> */
        public array $history,
        /** @var array<int, LeaveRequestData> */
        public array $rejected,
        public bool $canRequest,
    ) {}

    /** @param array<string, mixed> $donnees la sortie de ListDriverLeaves */
    public static function fromArray(array $donnees): self
    {
        $liste = fn (string $cle) => collect($donnees[$cle])
            ->map(fn (LeaveRequest $d) => LeaveRequestData::fromModel($d))
            ->all();

        return new self(
            leaveDaysPerMonth: (int) $donnees['solde']['leave_days_per_month'],
            totalLeaveDays: (int) $donnees['solde']['total_leave_days'],
            leaveDaysUsed: (int) $donnees['solde']['leave_days_used'],
            availableLeaveDays: (int) $donnees['solde']['available_leave_days'],
            remainingLeaveDays: (int) $donnees['solde']['remaining_leave_days'],
            pending: $liste('pending'),
            ongoing: $donnees['ongoing'] ? LeaveRequestData::fromModel($donnees['ongoing']) : null,
            history: $liste('history'),
            rejected: $liste('rejected'),
            canRequest: (bool) $donnees['can_request'],
        );
    }
}
