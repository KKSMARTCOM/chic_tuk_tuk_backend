<?php

namespace App\Domains\Booking\Application\Actions;

/**
 * Rattrape les journées d'un abonnement accepté après l'heure de génération — ex-
 * `BookingService::catchUpRecurringBookings()`, déplacé sans changement le 2026-09-27.
 */
final class CatchUpSubscriptionDays
{
    public function __construct(private readonly GenerateNextSubscriptionDay $generateNextDay) {}

    /**
     * Génère les journées d'un abonnement dont l'heure de génération est déjà passée.
     *
     * ⚠️ Appelée à l'acceptation : la commande de 1h ne voit que les abonnements déjà
     * acceptés. Accepté le jour de son démarrage APRÈS 1h, un abonnement attendait le
     * passage suivant — le jour J lui-même — pour générer la course du lendemain.
     */
    public function __invoke(string $bookingId): int
    {
        $generated = 0;

        // Borné par le nombre de jours de l'abonnement : chaque tour en consomme un.
        while (($this->generateNextDay)($bookingId)) {
            $generated++;
        }

        return $generated;
    }
}
