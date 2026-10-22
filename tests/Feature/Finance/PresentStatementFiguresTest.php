<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\PresentStatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 2026-10-22, §5 : une fiche validée se relit dans la présentation de sa formule. */
class PresentStatementFiguresTest extends TestCase
{
    use RefreshDatabase;

    /** Une fiche de septembre validée avant la formule : 20 jours du calendrier, 1 en instance. */
    private function oldStatement(float $revenue, float $recovered, int $recoveredPayments): RemunerationStatement
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        $statement = RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-09-01',
            'balance_due' => 77_500,
            'figures' => [
                'month' => '2026-09', 'business_days' => 22, 'pause_days' => 2, 'immobilization_days' => 0,
                'counted_days' => 20, 'daily_amount' => 5_000, 'revenue' => $revenue, 'recovered' => $recovered,
                'pending_count' => 1, 'pending_amount' => 5_000, 'deducted_total' => 27_500, 'balance_due' => 77_500,
            ],
        ]);
        foreach (range(1, $recoveredPayments) as $i) {
            Payment::factory()->onDay('2026-08-2'.$i)->create(['vehicle_contract_id' => $contract->id, 'remuneration_statement_id' => $statement->id]);
        }
        Payment::factory()->onDay('2026-09-01')->create(['vehicle_contract_id' => $contract->id, 'remuneration_statement_id' => $statement->id]);

        return $statement;
    }

    public function test_a_new_statement_is_read_as_frozen(): void
    {
        $statement = RemunerationStatement::factory()->validated()->create([
            'figures' => ['counted_days' => 21, 'total_revenue' => 105_000, 'recovered_count' => 2, 'balance_due' => 90_000],
        ]);

        $f = app(PresentStatementFigures::class)($statement);

        $this->assertSame([21, 2], [$f->countedDays, $f->recoveredCount]);
        $this->assertEquals(105_000, $f->totalRevenue);
        $this->assertFalse($f->legacyLayout);
    }

    public function test_an_old_statement_gets_the_new_layout_when_its_total_matches(): void
    {
        $statement = $this->oldStatement(95_000, 10_000, 2);

        $f = app(PresentStatementFigures::class)($statement);

        $this->assertSame([20, 2, 21], [$f->calendarDays, $f->recoveredCount, $f->countedDays]);
        $this->assertEquals(105_000, $f->totalRevenue);
        $this->assertEquals(105_000, $f->receivedAmount);
        $this->assertEquals(0, $f->gap);
        $this->assertFalse($f->legacyLayout);
        $this->assertEquals(77_500, $f->balanceDue);
    }

    public function test_the_recovered_count_can_be_given(): void
    {
        $statement = $this->oldStatement(95_000, 10_000, 1);

        $this->assertSame(21, app(PresentStatementFigures::class)($statement, 2)->countedDays);
    }

    public function test_an_old_statement_keeps_the_old_layout_when_its_total_does_not_match(): void
    {
        $statement = $this->oldStatement(90_000, 10_000, 2);

        $f = app(PresentStatementFigures::class)($statement);

        $this->assertTrue($f->legacyLayout);
        $this->assertSame(20, $f->countedDays);
        $this->assertEquals(90_000, $f->totalRevenue);
        $this->assertEquals(77_500, $f->balanceDue);
    }

    public function test_a_recomposed_statement_drops_the_old_pending_anomaly(): void
    {
        $statement = $this->oldStatement(95_000, 10_000, 2);
        $statement->update(['figures' => [...$statement->figures, 'anomalies' => ['1 paiement(s) du mois encore en attente.', '1 jour(s) comptabilisé(s) sans paiement.']]]);

        $f = app(PresentStatementFigures::class)($statement->fresh());

        $this->assertSame(['1 jour(s) comptabilisé(s) sans paiement.'], $f->anomalies);
    }
}
