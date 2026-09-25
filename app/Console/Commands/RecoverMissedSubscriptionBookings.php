<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

/**
 * Reprise, à lancer UNE FOIS après le déploiement du statut « non traitée ».
 *
 * Avant ce statut, une course enfant d'abonnement restée en attente passait « expirée »,
 * et le client perdait le trajet. Pour les abonnements ENCORE EN COURS seulement —
 * décision de l'utilisateur —, ces courses passent « non traitée » et sont rattrapées.
 * Les abonnements terminés restent en l'état.
 */
class RecoverMissedSubscriptionBookings extends Command
{
    protected $signature = 'app:recover-missed-subscription-bookings {--dry-run : Lister sans rien modifier}';

    protected $description = 'Convertit en « non traitée » les courses enfants expirées des abonnements en cours, et les rattrape';

    public function __construct(private BookingService $bookingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $bookings = $this->bookingService->expiredChildrenOfOngoingSubscriptions();

        $this->table(
            ['Abonnement', 'Course', 'Sens', 'Date'],
            $bookings->map(fn ($b) => [
                $b->parentBooking->booking_number,
                $b->booking_number,
                $b->trip_type === 'return' ? 'retour' : 'aller',
                $b->pickup_date->toDateString(),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->info("Mode essai : {$bookings->count()} course(s) seraient converties. Rien n'a été modifié.");

            return Command::SUCCESS;
        }

        foreach ($bookings as $booking) {
            $this->bookingService->recordMissedChild($booking->id);
        }

        $this->info("✓ {$bookings->count()} course(s) passée(s) « non traitée », rattrapage programmé.");

        return Command::SUCCESS;
    }
}
