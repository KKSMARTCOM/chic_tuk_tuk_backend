<?php

namespace Tests\Unit\Finance;

use App\Domains\Finance\Domain\ContractMonthCalendar;
use App\Domains\Finance\Domain\MonthDays;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Le classement des jours d'un mois : pause, immobilisation, comptabilisé.
 *
 * Classe pure : ni base ni application. ⚠️ Des objets simples et non des modèles
 * `VehiclePause` : le cast de date d'Eloquent passe par une façade, qui exige
 * l'application démarrée.
 */
class ContractMonthCalendarTest extends TestCase
{
    private function pause(string $start, ?string $end, string $reason = 'technical'): object
    {
        return (object) [
            'start_date' => Carbon::parse($start),
            'end_date' => $end !== null ? Carbon::parse($end) : null,
            'reason_type' => $reason,
        ];
    }

    private function july(array $pauses = []): MonthDays
    {
        return ContractMonthCalendar::classify(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), $pauses);
    }

    public function test_a_month_without_pause_counts_every_business_day(): void
    {
        $days = $this->july();

        // Juillet 2026 : du mercredi 1er au vendredi 31, 23 jours ouvrés.
        $this->assertSame(23, $days->businessDays);
        $this->assertSame(23, $days->countedDays);
        $this->assertSame(0, $days->pauseDays + $days->immobilizationDays);
    }

    public function test_an_immobilization_removes_its_business_days(): void
    {
        // Du 1er au 24 juillet : 18 jours ouvrés, le cas de la fiche d'ASSOGBA BALE.
        $days = $this->july([$this->pause('2026-07-01', '2026-07-24')]);

        $this->assertSame(18, $days->immobilizationDays);
        $this->assertSame(5, $days->countedDays);
        $this->assertSame(['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31'], $days->countedDates);
    }

    public function test_an_agent_pause_overlapping_an_immobilization_counts_once_as_a_pause(): void
    {
        $days = $this->july([
            $this->pause('2026-07-06', '2026-07-10', 'agent_leave'),
            $this->pause('2026-07-06', '2026-07-10', 'technical'),
        ]);

        $this->assertSame(5, $days->pauseDays);
        $this->assertSame(0, $days->immobilizationDays);
        $this->assertSame(18, $days->countedDays);
    }

    public function test_an_open_pause_stops_at_the_bound(): void
    {
        $days = ContractMonthCalendar::classify(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-15'),
            [$this->pause('2026-07-13', null)],
        );

        // Du 1er au 15 : 11 jours ouvrés, dont les 13, 14 et 15 immobilisés.
        $this->assertSame(11, $days->businessDays);
        $this->assertSame(3, $days->immobilizationDays);
        $this->assertSame(8, $days->countedDays);
    }

    public function test_weekends_are_never_counted(): void
    {
        // Du samedi 4 au dimanche 5 juillet.
        $days = ContractMonthCalendar::classify(Carbon::parse('2026-07-04'), Carbon::parse('2026-07-05'), []);

        $this->assertSame(0, $days->businessDays);
        $this->assertSame(0, $days->countedDays);
    }

    public function test_an_empty_range_counts_nothing(): void
    {
        $days = ContractMonthCalendar::classify(Carbon::parse('2026-07-20'), Carbon::parse('2026-07-10'), []);

        $this->assertSame(0, $days->businessDays);
        $this->assertSame([], $days->countedDates);
    }

    public function test_stopped_dates_list_every_pause_and_immobilization_day(): void
    {
        $days = $this->july([
            $this->pause('2026-07-01', '2026-07-01', 'agent_leave'),
            $this->pause('2026-07-02', '2026-07-02'),
        ]);

        $this->assertSame(['2026-07-01', '2026-07-02'], $days->stoppedDates);
    }
}
