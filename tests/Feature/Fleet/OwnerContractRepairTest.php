<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Data\OwnerContractDetailData;
use App\Domains\Fleet\Application\Data\OwnerContractSummaryData;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La réparation du tricycle (spec 2026-11-09, §2) : pour le propriétaire, les recettes retenues
 * n'ont pas existé — son « payé », sa progression et son reste les ignorent. Relecture finale.
 */
class OwnerContractRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_owner_progress_leaves_out_what_the_repair_withheld(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-08-03', 'total_amount' => 100_000]);
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $day) {
            Payment::factory()->onDay($day)->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 10_000]);
        }
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-08-01', 'figures' => ['repair_opened' => 45_000, 'repair_deducted' => 30_000],
        ]);

        $summary = OwnerContractSummaryData::fromModel($contract);
        $detail = OwnerContractDetailData::fromModel($contract);

        $this->assertEquals(20_000, $summary->paidAmount);
        $this->assertSame(20, $summary->revenueProgress);
        $this->assertSame(20, $summary->progressPercentage);
        $this->assertEquals(80_000, $summary->remainingAmount);
        $this->assertEquals(20_000, $detail->totalPaid);
        $this->assertEquals(80_000, $detail->remainingAmount);
        $this->assertSame(20, $detail->progressPercentage);
        // L'administration garde l'argent réellement payé par l'agent.
        $this->assertEquals(50_000, $contract->total_paid);
    }
}
