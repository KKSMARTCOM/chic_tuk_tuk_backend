<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les chiffres mensuels d'un contrat véhicule. Les deux premiers scénarios reproduisent
 * les fiches réelles de juillet 2026 (spec §8), la date du jour figée au 1er août.
 */
class ContractMonthCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un paiement journalier de `net`, à chacune de ces dates. */
    private function paidOn(VehicleContract $contract, array $dates, float $net, string $status = 'completed'): void
    {
        foreach ($dates as $date) {
            Payment::factory()->onDay($date)->status($status)
                ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => $net]);
        }
    }

    private function businessDays(string $from, string $to): array
    {
        return collect(Carbon::parse($from)->toPeriod($to))->reject->isWeekend()->map->toDateString()->values()->all();
    }

    /** ASSOGBA BALE : 30 mois depuis le 28/05/2026, mai immobilisé, juin et juillet en partie. */
    private function assogba(): VehicleContract
    {
        $contract = VehicleContract::factory()->create([
            'contract_months' => 30, 'start_date' => '2026-05-28',
            'daily_amount' => 5691, 'daily_tax' => 229,
        ]);
        foreach ([['2026-05-28', '2026-05-29'], ['2026-06-01', '2026-06-11'], ['2026-07-01', '2026-07-24']] as [$start, $end]) {
            VehiclePause::factory()->forContract($contract)->create(['start_date' => $start, 'end_date' => $end, 'reason_type' => 'agent_change']);
        }

        // Juin : les 13 jours ouvrés du 12 au 30. Juillet : 4 des 5 jours comptabilisés.
        $this->paidOn($contract, $this->businessDays('2026-06-12', '2026-06-30'), 5462);
        $this->paidOn($contract, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30'], 5462);

        return $contract;
    }

    public function test_the_assogba_july_sheet(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $july = ContractMonthCalculator::for($this->assogba())->month('2026-07');

        $this->assertSame(23, $july->businessDays);
        $this->assertSame(0, $july->pauseDays);
        $this->assertSame(18, $july->immobilizationDays);
        $this->assertSame(5, $july->countedDays);
        $this->assertEquals(21_848, $july->validatedAmount);
        // Le 31, comptabilisé, n'a aucun paiement : l'écart que la fiche manuelle a
        // vraisemblablement absorbé en affichant 22 jours ouvrés (spec §10).
        $this->assertSame(1, $july->countedDaysWithoutPayment);
        $this->assertTrue($july->isWorked);
    }

    public function test_the_assogba_contract_has_two_worked_months_in_july(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $calculator = ContractMonthCalculator::for($this->assogba());

        // Mai n'a que deux jours ouvrés dans le contrat, tous deux immobilisés.
        $this->assertFalse($calculator->month('2026-05')->isWorked);
        // Août : le 1er est un samedi, aucun jour comptabilisé encore.
        $this->assertFalse($calculator->month('2026-08')->isWorked);
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08'], $calculator->monthKeys());
        $this->assertSame(2, $calculator->workedMonths());
    }

    public function test_the_hounkanrin_july_sheet(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $contract = VehicleContract::factory()->create(['contract_months' => 24, 'start_date' => '2026-07-20']);
        $this->paidOn($contract, $this->businessDays('2026-07-20', '2026-07-31'), 5871);

        $july = ContractMonthCalculator::for($contract)->month('2026-07');

        // Le contrat démarre le lundi 20 : les jours d'avant ne sont pas ouvrés POUR LUI.
        $this->assertSame(10, $july->businessDays);
        $this->assertSame(10, $july->countedDays);
        $this->assertEquals(58_710, $july->validatedAmount);
        $this->assertSame(0, $july->countedDaysWithoutPayment);
    }

    public function test_amounts_are_split_by_status_and_payments_on_stopped_days_are_flagged(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-07-06', 'end_date' => '2026-07-06', 'reason_type' => 'agent_leave']);
        $this->paidOn($contract, ['2026-07-01'], 5871);
        $this->paidOn($contract, ['2026-07-02', '2026-07-03'], 5871, 'pending');
        $this->paidOn($contract, ['2026-07-07'], 5871, 'cancelled');
        // Généré sur un jour de pause d'agent : l'anomalie de relecture.
        $this->paidOn($contract, ['2026-07-06'], 5871, 'pending');

        $july = ContractMonthCalculator::for($contract)->month('2026-07');

        $this->assertEquals(5871, $july->validatedAmount);
        $this->assertSame(3, $july->pendingCount);
        $this->assertEquals(3 * 5871, $july->pendingAmount);
        $this->assertEquals(5871, $july->cancelledAmount);
        $this->assertSame(1, $july->paymentsOnStoppedDays);
        $this->assertSame(1, $july->pauseDays);
    }

    public function test_a_historical_agent_pause_without_vehicle_pause_is_a_pause(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $driverContract = DriverContract::factory()->forVehicleContract($contract)->create();
        // Saisie après coup (`AddHistoricalLeave`) : aucune pause véhicule, par construction.
        // Décision du 2026-09-30 (spec §3.2) : ses jours sont des jours de PAUSE.
        LeaveRequest::factory()->create([
            'driver_contract_id' => $driverContract->id,
            'start_date' => '2026-07-06', 'end_date' => '2026-07-08', 'status' => 'completed',
        ]);
        // Une demande refusée ne compte pas.
        LeaveRequest::factory()->create([
            'driver_contract_id' => $driverContract->id,
            'start_date' => '2026-07-13', 'end_date' => '2026-07-13', 'status' => 'rejected',
        ]);

        $july = ContractMonthCalculator::for($contract)->month('2026-07');

        $this->assertSame(3, $july->pauseDays);
        $this->assertSame(20, $july->countedDays);
    }

    public function test_the_current_month_stops_today(): void
    {
        Carbon::setTestNow('2026-07-15 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);

        $july = ContractMonthCalculator::for($contract)->month('2026-07');

        $this->assertTrue($july->isCurrent);
        // Du mercredi 1er au mercredi 15 : 11 jours ouvrés.
        $this->assertSame(11, $july->businessDays);
    }

    public function test_an_ended_contract_stops_at_its_end_date(): void
    {
        Carbon::setTestNow('2026-09-15 09:00:00');
        $contract = VehicleContract::factory()->create([
            'start_date' => '2026-06-01', 'end_date' => '2026-07-10', 'status' => 'completed',
        ]);
        $calculator = ContractMonthCalculator::for($contract);

        $this->assertSame(['2026-06', '2026-07'], $calculator->monthKeys());
        $this->assertSame(8, $calculator->month('2026-07')->businessDays);
    }

    public function test_pause_days_taken_add_up_every_month(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-06-01']);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-06-29', 'end_date' => '2026-07-02', 'reason_type' => 'agent_leave']);

        // Lundi 29 et mardi 30 juin, mercredi 1er et jeudi 2 juillet.
        $this->assertSame(4, ContractMonthCalculator::for($contract)->pauseDaysTaken());
    }

    public function test_a_contract_starting_later_has_no_month(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);

        $this->assertSame([], ContractMonthCalculator::for($contract)->monthKeys());
        $this->assertSame(0, ContractMonthCalculator::for($contract)->workedMonths());
    }
}
