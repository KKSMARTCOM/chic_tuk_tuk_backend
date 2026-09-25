<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\LeaveRequest;
use App\Shared\Data\BaseData;
use App\Domains\Workforce\Domain\Enums\LeaveStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Une demande de pause, telle que les quatre listes de l'écran l'affichent.
 *
 * Les champs suivent ce que la vue Blade montre section par section : la date de la
 * demande et la fin prévue pour les demandes en attente et refusées, les dates réelles
 * et les jours effectifs pour l'historique, le dépassement pour la pause en cours.
 */
final class LeaveRequestData extends BaseData
{
    public function __construct(
        public string $id,
        /** `pending` | `ongoing` | `completed` | `rejected`. */
        #[TypeScriptType(LeaveStatus::class)]
        public string $status,
        /** `YYYY-MM-DD` : une date de calendrier, jamais un instant. */
        public string $startDate,
        /** Renseignée seulement quand la pause est terminée. */
        public ?string $endDate,
        public int $requestedDays,
        /**
         * Jours réellement pris, connus une fois la pause terminée.
         * L'historique affiche ceux-ci, pas les jours demandés.
         */
        public ?int $effectiveDays,
        /**
         * Fin prévue, week-ends exclus — `LeaveRequest::addBusinessDays()`.
         * Le front la recalcule à la saisie pour prévisualiser, comme le fait le
         * JavaScript du formulaire Blade.
         */
        public ?string $expectedEndDate,
        /** La pause en cours dépasse-t-elle sa durée prévue ? */
        public bool $isOverdue,
        /**
         * Pause TERMINÉE saisie par un administrateur (`admin_historical`, `legacy`).
         *
         * ⚠️ C'est ce drapeau — et non le statut — qui dit si une pause terminée se
         * corrige et se supprime : une pause terminée issue d'une demande d'agent a été
         * vécue, et `DeleteLeave` la refuse. Sans ce champ, l'écran d'administration
         * proposerait « Modifier » et « Supprimer » sur des pauses que l'API rejette,
         * et l'administrateur ne découvrirait le refus qu'au clic.
         */
        public bool $isHistorical,
        public ?string $rejectionReason,
        /** Quand la demande a été déposée — « Demande du … » dans la vue. */
        public string $requestedAt,
    ) {}

    public static function fromModel(LeaveRequest $demande): self
    {
        return new self(
            id: $demande->id,
            status: $demande->status,
            startDate: $demande->start_date?->format('Y-m-d') ?? '',
            endDate: $demande->end_date?->format('Y-m-d'),
            requestedDays: (int) $demande->requested_days,
            effectiveDays: $demande->effective_days !== null ? (int) $demande->effective_days : null,
            expectedEndDate: $demande->expected_end_date?->format('Y-m-d'),
            isOverdue: $demande->is_overdue,
            isHistorical: $demande->is_historical,
            rejectionReason: $demande->rejection_reason,
            requestedAt: $demande->created_at->toIso8601String(),
        );
    }
}
