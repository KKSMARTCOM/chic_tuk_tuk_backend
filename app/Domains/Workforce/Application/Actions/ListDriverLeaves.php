<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

/**
 * Les pauses d'un agent, et son solde — ex-DriverLeaveController::index() et create().
 *
 * Les deux méthodes Blade sont réunies : `create()` ne servait qu'à afficher un
 * formulaire, et son seul apport était `canRequest`. Une API n'a pas besoin d'un
 * endpoint pour ouvrir un formulaire — la donnée voyage avec la liste.
 */
final class ListDriverLeaves
{
    /** @return array<string, mixed> */
    public function __invoke(Driver $driver): array
    {
        $demandes = fn (string $statut) => $driver->leaveRequests()->where('status', $statut);

        $enAttente = $demandes('pending')->orderByDesc('created_at')->get();
        $enCours = $demandes('ongoing')->first();

        return [
            'solde' => [
                'leave_days_per_month' => $driver->getLeaveDaysPerMonth(),
                'total_leave_days' => $driver->getTotalLeaveDays(),
                // Recompté depuis les pauses terminées : la colonne `leave_days_used` avait
                // dérivé de la réalité sur des agents existants.
                'leave_days_used' => $driver->getLeaveDaysTaken(),
                'available_leave_days' => $driver->available_leave_days,
                'remaining_leave_days' => $driver->getRemainingLeaveDays(),
            ],
            'pending' => $enAttente,
            'ongoing' => $enCours,
            'history' => $demandes('completed')->orderByDesc('start_date')->get(),
            'rejected' => $demandes('rejected')->orderByDesc('created_at')->get(),
            // Ce que `create()` calculait pour décider d'afficher le formulaire.
            'can_request' => $enAttente->isEmpty() && ! $enCours,
        ];
    }
}
