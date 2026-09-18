<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\LeaveRequest;
use App\Shared\Data\BaseData;

/**
 * Une demande de congé, telle que les quatre listes de l'écran l'affichent.
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
            rejectionReason: $demande->rejection_reason,
            requestedAt: $demande->created_at->toIso8601String(),
        );
    }
}
