<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Workforce\Domain\LeaveBalance;
use App\Models\Driver;
use App\Shared\Data\BaseData;
use Carbon\Carbon;

/**
 * Le dossier de pauses d'UN agent, vu de l'administration — ex-Admin\LeaveController::show().
 *
 * Reprend les trois listes de la vue Blade — demandes en attente, pause en cours,
 * historique — et le solde. Les demandes REFUSÉES n'y figurent pas : la vue Blade ne les
 * montre pas non plus, et l'écran sert à décider, pas à archiver.
 */
final class AdminDriverLeaveDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public bool $hasActiveContract,
        /** Début du contrat de référence — actif s'il y en a un, sinon le dernier. */
        public ?string $contractStart,
        public ?int $contractMonths,
        public int $leaveDaysPerMonth,
        public int $totalLeaveDays,
        public int $leaveDaysUsed,
        public int $availableLeaveDays,
        public int $remainingLeaveDays,
        /** @var array<int, LeaveRequestData> */
        public array $pending,
        public ?LeaveRequestData $ongoing,
        /** @var array<int, LeaveRequestData> */
        public array $history,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        $contrat = $driver->contratDeReference();
        $solde = $driver->soldeDeReference();

        $parStatut = fn (string $statut) => $driver->leaveRequests()->where('status', $statut);

        return new self(
            id: $driver->id,
            userId: $driver->user_id,
            name: $driver->user?->name,
            email: $driver->user?->email,
            phone: $driver->user?->phone,
            hasActiveContract: $driver->activeDriverContract !== null,
            contractStart: $contrat?->start_date
                ? Carbon::parse($contrat->start_date)->format('Y-m-d')
                : null,
            contractMonths: $contrat ? $solde->contractMonths : null,
            leaveDaysPerMonth: LeaveBalance::JOURS_PAR_MOIS,
            totalLeaveDays: $solde->totalDays,
            leaveDaysUsed: $solde->usedDays,
            availableLeaveDays: $solde->availableDays,
            remainingLeaveDays: $solde->remainingDays,
            // La vue Blade trie les demandes par date de DÉBUT et l'historique par date de
            // début décroissante : on décide dans l'ordre où les pauses arrivent, et on
            // relit l'historique en partant du plus récent.
            pending: $parStatut('pending')->orderBy('start_date')->get()
                ->map(fn ($d) => LeaveRequestData::fromModel($d))->all(),
            ongoing: ($enCours = $parStatut('ongoing')->first())
                ? LeaveRequestData::fromModel($enCours)
                : null,
            history: $parStatut('completed')->orderByDesc('start_date')->get()
                ->map(fn ($d) => LeaveRequestData::fromModel($d))->all(),
        );
    }
}
