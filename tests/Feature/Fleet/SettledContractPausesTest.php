<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\CreateAgentLeavePause;
use App\Models\DriverContract;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

/** Relecture finale : les pauses restent possibles sur un contrat soldé tant qu'un agent y roule (spec 2026-10-12, §5). */
class SettledContractPausesTest extends TestCase
{
    use RefreshDatabase;

    private DriverContract $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        $contract = VehicleContract::factory()->completed()->create(['start_date' => '2026-01-01', 'end_date' => '2026-08-31']);
        $this->agent = DriverContract::factory()->forVehicleContract($contract)->create(['start_date' => '2026-01-01', 'status' => 'active']);
    }

    public function test_an_agent_leave_pause_belongs_to_the_settled_contract(): void
    {
        $pause = app(CreateAgentLeavePause::class)($this->agent->vehicle_id, $this->agent->id, '2026-09-14', '2026-09-15');

        $this->assertSame($this->agent->vehicle_contract_id, $pause->vehicle_contract_id);
    }
}
