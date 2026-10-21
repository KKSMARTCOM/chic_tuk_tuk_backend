<?php

namespace Tests\Feature\Workforce;

use App\Domains\Workforce\Application\Actions\DeleteLeave;
use App\Domains\Workforce\Application\Actions\EndLeave;
use App\Domains\Workforce\Application\Actions\SyncDriverAvailability;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\InternalAssignment;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La disponibilité d'un agent (spec 2026-10-21, §4.1) : une seule règle, recalculée par tous
 * les flux. Disponible ⇔ (contrat agent actif OU affectation interne en cours) ET aucune
 * pause agent COMMENCÉE.
 */
class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function agentUnderContract(): Driver
    {
        $driver = Driver::factory()->create();
        DriverContract::factory()->create(['driver_id' => $driver->id]);

        return $driver;
    }

    private function sync(Driver $driver): bool
    {
        return app(SyncDriverAvailability::class)($driver->refresh());
    }

    public function test_an_agent_under_contract_without_started_pause_is_available(): void
    {
        $driver = $this->agentUnderContract();
        $driver->update(['is_available' => false]);

        $this->assertTrue($this->sync($driver));
        $this->assertTrue($driver->refresh()->is_available);
    }

    public function test_a_pause_starting_tomorrow_does_not_make_the_agent_unavailable(): void
    {
        $driver = $this->agentUnderContract();
        LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->addDay()]);

        $this->assertTrue($this->sync($driver));
    }

    public function test_a_started_pause_makes_the_agent_unavailable(): void
    {
        $driver = $this->agentUnderContract();
        LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->subDay()]);

        $this->assertFalse($this->sync($driver));
        $this->assertFalse($driver->refresh()->is_available);
    }

    public function test_an_agent_without_contract_is_unavailable(): void
    {
        $driver = Driver::factory()->create();
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'ended', 'end_date' => now()->subDay()]);

        $this->assertFalse($this->sync($driver));
    }

    public function test_an_internal_assignment_counts_as_work(): void
    {
        $driver = Driver::factory()->create(['is_available' => false]);
        InternalAssignment::factory()->create(['driver_id' => $driver->id]);

        $this->assertTrue($this->sync($driver));
    }

    public function test_ending_a_pause_of_an_agent_without_contract_keeps_him_unavailable(): void
    {
        $driver = Driver::factory()->create(['is_available' => false]);
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'ended', 'end_date' => now()->subDay()]);
        $leave = LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->subDays(3)]);

        app(EndLeave::class)($leave, now()->toDateString());

        $this->assertFalse($driver->refresh()->is_available);
    }

    public function test_ending_a_pause_while_a_future_one_exists_makes_the_agent_available(): void
    {
        $driver = $this->agentUnderContract();
        $driver->update(['is_available' => false]);
        $leave = LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->subDays(3)]);
        LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->addDays(3)]);

        app(EndLeave::class)($leave, now()->toDateString());

        $this->assertTrue($driver->refresh()->is_available);
    }

    public function test_deleting_a_pause_of_an_agent_without_contract_keeps_him_unavailable(): void
    {
        $driver = Driver::factory()->create(['is_available' => false]);
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'ended', 'end_date' => now()->subDay()]);
        $leave = LeaveRequest::factory()->ongoing()->create(['driver_id' => $driver->id, 'start_date' => now()->subDays(3)]);

        app(DeleteLeave::class)($leave);

        $this->assertFalse($driver->refresh()->is_available);
    }
}
