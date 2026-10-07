<?php

namespace Tests\Feature\Workforce;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Le paiement journalier part à 23:30 heure du Bénin, le jour même — et non à 23:30 UTC,
 * soit 00:30 le lendemain au Bénin, comme avant le 2026-10-07.
 *
 * Seule cette tâche suit le fuseau du Bénin : l'application reste en UTC. Les tâches de
 * 01:00 ne doivent pas suivre — `next_recurring_date` est enregistrée à 01:00 UTC, et la
 * génération des abonnements, lancée à 00:00 UTC, ne trouverait rien à faire.
 */
class DailyContractPaymentScheduleTest extends TestCase
{
    private function event(string $command): Event
    {
        // `withSchedule()` ne déclare les tâches qu'au démarrage de la console.
        Artisan::all();

        return collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, $command));
    }

    public function test_le_paiement_journalier_part_a_23h30_heure_du_benin(): void
    {
        $event = $this->event('app:generate-daily');

        // Vendredi 9 octobre 2026, 23:30 au Bénin = 22:30 UTC.
        $this->travelTo(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
        $this->assertSame('2026-10-09', Carbon::today()->toDateString(), 'le paiement porte sur le jour même');

        $this->travelTo(Carbon::parse('2026-10-09 23:30:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }

    public function test_le_paiement_journalier_suit_les_jours_ouvres_du_benin(): void
    {
        $event = $this->event('app:generate-daily');

        // Samedi 10 octobre, 23:30 au Bénin.
        $this->travelTo(Carbon::parse('2026-10-10 22:30:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));

        // Lundi 12 octobre, 23:30 au Bénin.
        $this->travelTo(Carbon::parse('2026-10-12 22:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
    }

    public function test_la_generation_des_abonnements_reste_a_01h00_utc(): void
    {
        $event = $this->event('app:process-recurring-bookings');

        $this->travelTo(Carbon::parse('2026-10-09 01:00:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));

        $this->travelTo(Carbon::parse('2026-10-09 00:00:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }
}
