<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildStatementFiguresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01 09:00:00');
        config(['remuneration.first_month' => '2026-07']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidOn(VehicleContract $contract, array $dates, float $net, string $status = 'completed', array $extra = []): void
    {
        foreach ($dates as $date) {
            Payment::factory()->onDay($date)->status($status)
                ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => $net] + $extra);
        }
    }

    private function businessDays(string $from, string $to): array
    {
        return collect(Carbon::parse($from)->toPeriod($to))->reject->isWeekend()->map->toDateString()->values()->all();
    }

    private function assogba(): VehicleContract
    {
        $owner = User::factory()->create(['name' => 'ASSOGBA BALE . E. Eude']);
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => '2GC4662 RB']);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 30, 'start_date' => '2026-05-28', 'daily_amount' => 5691, 'daily_tax' => 229,
        ]);
        foreach ([['2026-05-28', '2026-05-29'], ['2026-06-01', '2026-06-11'], ['2026-07-01', '2026-07-24']] as [$start, $end]) {
            VehiclePause::factory()->forContract($contract)->create(['start_date' => $start, 'end_date' => $end, 'reason_type' => 'agent_change']);
        }
        $this->paidOn($contract, $this->businessDays('2026-06-12', '2026-06-30'), 5462);
        $this->paidOn($contract, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30'], 5462);

        return $contract;
    }

    public function test_the_assogba_july_sheet_with_charges_waived(): void
    {
        $contract = $this->assogba();
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create([
            'month' => '2026-07-01', 'deducted_internet' => 0, 'deducted_spotify' => 0, 'deducted_manager' => 0,
        ]);

        $f = app(BuildStatementFigures::class)($contract, '2026-07', $draft);

        $this->assertSame('ASSOGBA BALE . E. Eude', $f->ownerName);
        $this->assertSame('2GC4662 RB', $f->vehicleNumber);
        $this->assertSame([23, 0, 18, 5], [$f->businessDays, $f->pauseDays, $f->immobilizationDays, $f->countedDays]);
        $this->assertEquals(5462, $f->dailyAmount);
        $this->assertEquals(21_848, $f->revenue);
        $this->assertEquals(0, $f->deductedTotal);
        $this->assertEquals(21_848, $f->balanceDue);
        // Les cumuls de la fiche : juin (historique) + juillet.
        $this->assertEquals(92_854, $f->cumulativeRevenue);
        $this->assertEquals(27_500, $f->cumulativeCharges);
        $this->assertEquals(65_354, $f->cumulativeNet);
        $this->assertSame(2, $f->workedMonths);
        $this->assertSame(60, $f->pauseAllowance);
        $this->assertContains('1 jour(s) comptabilisé(s) sans paiement.', $f->anomalies);
        // Les charges de juillet, non prélevées, restent à recouvrer.
        $this->assertEquals(27_500, collect($f->charges)->sum('outstanding'));
    }

    public function test_the_hounkanrin_july_sheet_with_the_proposed_charges(): void
    {
        $owner = User::factory()->create(['name' => 'HOUNKANRIN Emmanuel']);
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 24, 'start_date' => '2026-07-20']);
        $this->paidOn($contract, $this->businessDays('2026-07-20', '2026-07-31'), 5871);
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-07-01']);

        $f = app(BuildStatementFigures::class)($contract, '2026-07', $draft);

        $this->assertSame(10, $f->countedDays);
        $this->assertEquals(58_710, $f->revenue);
        $this->assertEquals([5_000, 2_500, 20_000], collect($f->charges)->pluck('deducted')->all());
        $this->assertEquals(31_210, $f->balanceDue);
        $this->assertEquals(58_710, $f->cumulativeRevenue);
        $this->assertSame(1, $f->workedMonths);
        $this->assertSame([], $f->anomalies);
    }

    public function test_a_late_payment_counts_once_as_recovered(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $july = RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2026-07-01']);
        // Déjà compté par la fiche de juillet : rattaché.
        $this->paidOn($contract, ['2026-07-30'], 5871, 'completed', ['remuneration_statement_id' => $july->id]);
        // Validé après la fiche de juillet : non rattaché, donc recouvré en août.
        $this->paidOn($contract, ['2026-07-31'], 5871);
        $august = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-08-01']);
        Carbon::setTestNow('2026-09-01 09:00:00');

        $f = app(BuildStatementFigures::class)($contract, '2026-08', $august);

        $this->assertEquals(5871, $f->recovered);
        $this->assertCount(1, $f->recoveredPaymentIds);
    }

    public function test_payments_before_the_first_month_are_never_recovered(): void
    {
        config(['remuneration.first_month' => '2026-08']);
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $this->paidOn($contract, ['2026-07-31'], 5871);
        $august = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-08-01']);
        Carbon::setTestNow('2026-09-01 09:00:00');

        $this->assertEquals(0, app(BuildStatementFigures::class)($contract, '2026-08', $august)->recovered);
    }

    public function test_an_unworked_month_has_no_charge_due(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'reason_type' => 'agent_change']);
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-07-01']);

        $f = app(BuildStatementFigures::class)($contract, '2026-07', $draft);

        $this->assertEquals(0, collect($f->charges)->sum('due'));
        $this->assertSame(0, $f->workedMonths);
    }

    public function test_the_outstanding_carries_what_earlier_sheets_did_not_deduct(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-07-01',
            'figures' => ['charges' => [
                ['key' => 'internet', 'due' => 5_000, 'deducted' => 5_000],
                ['key' => 'spotify', 'due' => 2_500, 'deducted' => 0],
                ['key' => 'manager', 'due' => 20_000, 'deducted' => 10_000],
            ]],
        ]);
        $august = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-08-01']);
        Carbon::setTestNow('2026-09-01 09:00:00');

        $f = app(BuildStatementFigures::class)($contract, '2026-08', $august);

        $this->assertEquals(
            ['internet' => 5_000, 'spotify' => 5_000, 'manager' => 30_000],
            collect($f->charges)->pluck('outstanding', 'key')->all(),
        );
    }

    public function test_a_payment_without_month_is_left_out_as_by_the_calculator(): void
    {
        // Un paiement saisi à la main peut n'avoir aucun `payment_month` : le calculateur du
        // mois l'écarte, la fiche aussi — sans planter.
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $this->paidOn($contract, ['2026-07-06'], 5871);
        Payment::factory()->create(['vehicle_contract_id' => $contract->id, 'payment_month' => null, 'payment_date' => '2026-07-07', 'status' => 'completed', 'net_amount' => 9_999]);

        $this->assertEquals(5871, app(BuildStatementFigures::class)($contract, '2026-07')->revenue);
    }

    public function test_a_month_awaiting_its_sheet_is_not_recovered_by_the_next(): void
    {
        // Juillet n'a pas encore de fiche validée : ses paiements sont SES recettes, pas du
        // « recouvré » d'août — sinon l'estimation d'août les compterait en plus.
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $this->paidOn($contract, ['2026-07-30'], 5871);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-07-01']);
        Carbon::setTestNow('2026-08-20 09:00:00');

        $this->assertEquals(0, app(BuildStatementFigures::class)($contract, '2026-08')->recovered);
    }

    public function test_figures_survive_the_json_snapshot(): void
    {
        $f = app(BuildStatementFigures::class)($this->assogba(), '2026-07');

        $this->assertEquals($f->toArray(), StatementFigures::fromArray($f->toArray())->toArray());
    }

    /** L'exemple de la spec 2026-10-01, §4 : 20 jours de mars à temps, 2 réglés le 12/05. */
    private function marchScenario(): array
    {
        Carbon::setTestNow('2026-06-05 09:00:00');
        config(['remuneration.first_month' => '2026-03']);
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        $days = $this->businessDays('2026-03-02', '2026-03-31'); // 22 jours
        foreach (array_slice($days, 0, 20) as $day) {
            Payment::factory()->onDay($day)->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5871, 'collected_on' => '2026-03-31']);
        }
        foreach (array_slice($days, 20) as $day) {
            Payment::factory()->onDay($day)->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5871, 'collected_on' => '2026-05-12']);
        }

        return [$contract, RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01', 'issued_on' => '2026-04-03'])];
    }

    private function freeze(RemunerationStatement $statement, StatementFigures $f): void
    {
        $statement->update(['status' => 'validated', 'number' => 'FR-'.$statement->monthKey().'-001', 'figures' => $f->toArray()]);
        Payment::whereIn('id', [...$f->revenuePaymentIds, ...$f->recoveredPaymentIds])->update(['remuneration_statement_id' => $statement->id]);
    }

    public function test_the_issue_date_decides_revenue_pending_and_recovered(): void
    {
        [$contract, $march] = $this->marchScenario();
        $build = app(BuildStatementFigures::class);

        $f = $build($contract, '2026-03', $march);
        $this->assertEquals(20 * 5871, $f->revenue);
        $this->assertSame(2, $f->pendingCount);
        $this->assertEquals(2 * 5871, $f->pendingAmount);
        $this->freeze($march, $f);

        $april = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-04-01', 'issued_on' => '2026-05-04']);
        $f = $build($contract, '2026-04', $april);
        $this->assertEquals(0, $f->recovered);
        $this->freeze($april, $f);

        $may = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-05-01', 'issued_on' => '2026-06-03']);
        $this->assertEquals(2 * 5871, $build($contract, '2026-05', $may)->recovered);
    }

    public function test_a_pending_payment_still_counts_as_pending(): void
    {
        [$contract, $march] = $this->marchScenario();
        Payment::factory()->onDay('2026-03-31')->status('pending')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5871]);

        $this->assertSame(3, app(BuildStatementFigures::class)($contract, '2026-03', $march)->pendingCount);
    }

    public function test_a_payment_without_collection_date_counts_as_collected(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        config(['remuneration.first_month' => '2026-03']);
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        Payment::factory()->onDay('2026-03-02')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5871, 'collected_on' => null]);
        $march = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01', 'issued_on' => '2026-04-03']);

        $this->assertEquals(5871, app(BuildStatementFigures::class)($contract, '2026-03', $march)->revenue);
    }

    public function test_a_draft_without_issue_date_reads_up_to_today(): void
    {
        Carbon::setTestNow('2026-04-05 09:00:00');
        config(['remuneration.first_month' => '2026-03']);
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);
        Payment::factory()->onDay('2026-03-02')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5871, 'collected_on' => '2026-04-05']);
        $march = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-03-01']);

        $this->assertEquals(5871, app(BuildStatementFigures::class)($contract, '2026-03', $march)->revenue);
    }
}
