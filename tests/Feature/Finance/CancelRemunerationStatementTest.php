<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Application\Actions\CancelRemunerationStatement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelRemunerationStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelling_detaches_payments_and_opens_a_replacement_draft(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        $owner = User::factory()->create();
        $contract = VehicleContract::factory()->forVehicle(Vehicle::factory()->create(['owner_id' => $owner->id]))->create(['start_date' => '2026-10-01']);
        $statement = RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2026-10-01', 'number' => 'FR-2026-10-001', 'deducted_manager' => 10_000]);
        $payment = Payment::factory()->onDay('2026-10-05')->create(['vehicle_contract_id' => $contract->id, 'remuneration_statement_id' => $statement->id]);

        $replacement = app(CancelRemunerationStatement::class)($statement, User::factory()->create(), 'Erreur sur les charges');

        $this->assertSame('cancelled', $statement->fresh()->status);
        $this->assertSame('FR-2026-10-001', $statement->fresh()->number);
        $this->assertNull($payment->fresh()->remuneration_statement_id);
        $this->assertSame('draft', $replacement->status);
        $this->assertSame($statement->id, $replacement->replaces_id);
        $this->assertEquals(10_000, $replacement->deducted_manager);
        // Le paiement détaché revient dans les recettes du brouillon de remplacement.
        $this->assertEquals($payment->net_amount, app(BuildStatementFigures::class)($contract, '2026-10', $replacement)->revenue);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'title' => 'Fiche de rémunération annulée']);
    }

    public function test_only_a_validated_statement_is_cancelled(): void
    {
        $this->expectException(ApiException::class);
        app(CancelRemunerationStatement::class)(RemunerationStatement::factory()->create(), User::factory()->create(), 'x');
    }
}
