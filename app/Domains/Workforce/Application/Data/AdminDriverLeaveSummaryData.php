<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Workforce\Domain\LeaveBalance;
use App\Models\Driver;
use App\Shared\Data\BaseData;

/**
 * Une ligne du tableau « Pauses » de l'administration : un agent et son solde.
 *
 * ⚠️ `id` est l'identifiant de l'AGENT (`drivers.id`), et `userId` celui de son compte.
 * La vue Blade expose le second et construit ses liens avec, ce qui oblige le lecteur à
 * deviner de quel identifiant on parle. L'API expose les DEUX explicitement : les routes
 * de ce domaine prennent l'identifiant d'agent, et `user_id` reste disponible pour
 * pointer vers la fiche du compte.
 */
final class AdminDriverLeaveSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $name,
        /**
         * L'agent est-il SOUS CONTRAT aujourd'hui ?
         *
         * ⚠️ La liste montre aussi les anciens agents, ceux qui ne sont pas allés au bout
         * de leur contrat : sans ce drapeau, rien ne les distinguerait à l'écran, et on
         * lirait leur solde comme un droit en cours.
         */
        public bool $hasActiveContract,
        /** Durée du contrat de RÉFÉRENCE en mois — actif s'il y en a un, sinon le dernier. */
        public ?int $contractMonths,
        public int $leaveDaysPerMonth,
        public int $totalLeaveDays,
        public int $leaveDaysUsed,
        /**
         * Acquis à date moins pris. Peut être NÉGATIF : le dépassement est permis, et le
         * négatif dit « cet agent a pris d'avance sur son acquisition ». Ne pas le
         * ramener à zéro — c'est une information, pas une erreur de calcul.
         */
        public int $availableLeaveDays,
        public int $remainingLeaveDays,
        public bool $isOnLeave,
        /** Date de début de la pause en cours, quand il y en a une. */
        public ?string $ongoingSince,
        public int $pendingRequests,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        $enCours = $driver->leaveRequests()->where('status', 'ongoing')->first();
        $contrat = $driver->contratDeReference();
        // ⚠️ Le solde est rapporté au contrat de RÉFÉRENCE et non au contrat actif : sans
        // cela, un ancien agent afficherait des zéros et son dossier serait illisible.
        $solde = $driver->soldeDeReference();

        return new self(
            id: $driver->id,
            userId: $driver->user_id,
            name: $driver->user?->name,
            hasActiveContract: $driver->activeDriverContract !== null,
            contractMonths: $contrat ? $solde->contractMonths : null,
            leaveDaysPerMonth: LeaveBalance::JOURS_PAR_MOIS,
            totalLeaveDays: $solde->totalDays,
            leaveDaysUsed: $solde->usedDays,
            availableLeaveDays: $solde->availableDays,
            remainingLeaveDays: $solde->remainingDays,
            isOnLeave: (bool) $enCours,
            ongoingSince: $enCours?->start_date?->format('Y-m-d'),
            pendingRequests: $driver->leaveRequests()->where('status', 'pending')->count(),
        );
    }
}
