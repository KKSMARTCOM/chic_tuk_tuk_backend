<?php

namespace Tests\Feature\Finance;

use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemunerationStatementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_live_statement_per_contract_and_month(): void
    {
        $contract = VehicleContract::factory()->create();
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);

        $this->expectException(QueryException::class);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);
    }

    public function test_a_cancelled_statement_frees_its_month(): void
    {
        $contract = VehicleContract::factory()->create();
        RemunerationStatement::factory()->for($contract, 'contract')->cancelled()->create(['month' => '2026-10-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-10-01']);

        $this->assertSame(2, RemunerationStatement::count());
    }

    public function test_a_payment_can_be_attached_to_a_statement(): void
    {
        $statement = RemunerationStatement::factory()->create();
        $payment = Payment::factory()->create(['remuneration_statement_id' => $statement->id]);

        $this->assertTrue($statement->payments->contains($payment));
        $this->assertSame('2026-10', RemunerationStatement::factory()->make(['month' => '2026-10-01'])->monthKey());
    }
}
