<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Domain\ChargeLedger;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 2026-10-12, §3 : les recettes coupées au revenu total, les charges plafonnées. */
class StatementTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 09:00:00');
        config(['remuneration.first_month' => '2026-07']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(int $months, float $total): VehicleContract
    {
        return VehicleContract::factory()->create([
            'contract_months' => $months, 'total_amount' => $total, 'start_date' => '2026-07-01',
            'unlimited_internet' => 5_000, 'spotify_premium' => 2_500, 'manager_remuneration' => 20_000,
        ]);
    }

    /** Une fiche validée : `$due` et `$deducted` par ligne, dans l'ordre internet, spotify, manager. */
    private function validated(VehicleContract $contract, string $month, float $revenue, array $due, array $deducted): void
    {
        $keys = ['internet', 'spotify', 'manager'];
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => $month.'-01',
            'figures' => [
                'revenue' => $revenue, 'recovered' => 0, 'deducted_total' => array_sum($deducted),
                'charges' => collect($keys)->map(fn ($k, $i) => ['key' => $k, 'due' => $due[$i], 'deducted' => $deducted[$i]])->all(),
            ],
        ]);
    }

    /** Un mois travaillé de recettes `$amount`, en un paiement validé le 1er jour ouvré. */
    private function earned(VehicleContract $contract, string $date, float $amount): void
    {
        Payment::factory()->onDay($date)->status('completed')
            ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => $amount]);
    }

    private function figures(VehicleContract $contract, string $month): StatementFigures
    {
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => $month.'-01']);

        return app(BuildStatementFigures::class)($contract, $month, $draft);
    }

    public function test_revenue_reached_this_month_with_three_months_of_charges_left(): void
    {
        // 4 mois, 1 prélevé ; il manquait 50 000 au revenu total ; le mois rapporte 150 000.
        $contract = $this->contract(4, 1_000_000);
        $this->validated($contract, '2026-07', 950_000, [5_000, 2_500, 20_000], [5_000, 2_500, 20_000]);
        $this->earned($contract, '2026-08-03', 150_000);

        $f = $this->figures($contract, '2026-08');

        $this->assertEquals(50_000, $f->revenueWithinTarget);
        $this->assertEquals(100_000, $f->revenueBeyondTarget);
        $this->assertEquals(82_500, $f->deductedTotal);
        $this->assertEquals(22_500, $f->balanceDue);
        $this->assertEquals(45_000, $f->agencyShare);
        $this->assertEquals([15_000, 7_500, 60_000], collect($f->charges)->pluck('ceiling')->all());
    }

    public function test_revenue_already_reached_settles_the_charges_left(): void
    {
        // 1 mois de contrat, dû en entier en juillet, 10 000 restés à recouvrer sur la ligne manager.
        $contract = $this->contract(1, 100_000);
        $this->validated($contract, '2026-07', 120_000, [5_000, 2_500, 20_000], [5_000, 2_500, 10_000]);
        $this->earned($contract, '2026-08-03', 150_000);

        $f = $this->figures($contract, '2026-08');

        $this->assertEquals(0, $f->revenueWithinTarget);
        // Au-delà de la durée : plus rien n'est dû ce mois-ci.
        $this->assertEquals([0, 0, 0], collect($f->charges)->pluck('due')->all());
        $this->assertEquals(10_000, $f->deductedTotal);
        $this->assertEquals(0, $f->balanceDue);
        $this->assertEquals(140_000, $f->agencyShare);
    }

    public function test_charges_reached_everything_goes_to_the_owner(): void
    {
        $contract = $this->contract(1, 1_000_000);
        $this->validated($contract, '2026-07', 100_000, [5_000, 2_500, 20_000], [5_000, 2_500, 20_000]);
        $this->earned($contract, '2026-08-03', 150_000);

        $f = $this->figures($contract, '2026-08');

        $this->assertEquals(0, $f->deductedTotal);
        $this->assertEquals(150_000, $f->balanceDue);
        $this->assertEquals(0, $f->agencyShare);
    }

    public function test_no_more_overrun_anomaly(): void
    {
        $contract = $this->contract(1, 100_000);
        $this->validated($contract, '2026-07', 120_000, [5_000, 2_500, 20_000], [5_000, 2_500, 20_000]);
        $this->earned($contract, '2026-08-03', 150_000);

        $this->assertNotContains('Le cumul des recettes dépasse le revenu cible du contrat.', $this->figures($contract, '2026-08')->anomalies);
    }

    public function test_a_manual_deduction_beyond_the_outstanding_cannot_come_from_the_owner_share(): void
    {
        // a = 50 000, b = 10 000, restes 27 500, plafond 82 500 : 60 000 saisis rendraient la part de l'agence négative.
        $contract = $this->contract(4, 1_000_000);
        $this->validated($contract, '2026-07', 950_000, [5_000, 2_500, 20_000], [5_000, 2_500, 20_000]);
        $this->earned($contract, '2026-08-03', 60_000);
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create([
            'month' => '2026-08-01', 'deducted_internet' => 15_000, 'deducted_spotify' => 7_500, 'deducted_manager' => 37_500,
        ]);

        $f = app(BuildStatementFigures::class)($contract, '2026-08', $draft);

        $this->assertArrayHasKey('deducted', ChargeLedger::violationsFor($f));
    }

    public function test_a_sheet_validated_before_the_change_reads_a_zero_agency_share(): void
    {
        $this->assertEquals(0, StatementFigures::fromArray(['revenue' => 10])->agencyShare);
    }
}
